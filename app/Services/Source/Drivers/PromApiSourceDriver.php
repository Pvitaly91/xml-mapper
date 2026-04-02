<?php

namespace App\Services\Source\Drivers;

use App\Contracts\Source\PromApiClientInterface;
use App\Data\Source\ParsedSourceCategoryData;
use App\Data\Source\ParsedSourceFeedData;
use App\Data\Source\ParsedSourceOfferData;
use App\Data\Source\SourceConnectionCheckResult;
use App\Exceptions\Source\SourceConfigurationException;
use App\Exceptions\Source\SourceInvalidPayloadException;
use App\Models\SourceConnection;
use App\Models\SourceImport;
use App\Support\Canonicalizer;
use Illuminate\Support\Facades\Storage;

class PromApiSourceDriver extends AbstractSourceDriver
{
    public function __construct(
        private readonly PromApiClientInterface $client,
    ) {
    }

    public function driver(): string
    {
        return SourceConnection::DRIVER_PROM_API;
    }

    public function testConnection(SourceConnection $connection): SourceConnectionCheckResult
    {
        $this->assertConfigured($connection);

        $summary = $this->client->checkConnection($connection);

        return new SourceConnectionCheckResult(
            status: SourceConnection::CHECK_STATUS_OK,
            message: 'Prom API token can access Products and Groups endpoints.',
            meta: $summary,
        );
    }

    public function sync(SourceConnection $connection): SourceImport
    {
        $this->assertConfigured($connection);

        $import = SourceImport::create([
            'shop_id' => $connection->shop_id,
            'source_connection_id' => $connection->id,
            'status' => SourceImport::STATUS_PENDING,
            'started_at' => now(),
            'source_url_snapshot' => $connection->apiEndpointBase(),
            'meta' => [
                'driver' => $this->driver(),
                'api_base_url' => $connection->resolvedApiBaseUrl(),
                'api_version' => $connection->resolvedApiVersion(),
                'assumptions' => [
                    'products_list_is_paginated_via_last_id_and_descending_ids',
                    'groups_list_is_paginated_via_last_id_and_descending_ids',
                    'custom_product_attributes_are_not_exposed_by_read_api_and_are_mapped_from_documented_product_fields',
                ],
            ],
        ]);

        try {
            $groups = $this->client->fetchAllGroups($connection);
            $products = $this->client->fetchAllProducts($connection);

            $snapshot = [
                'driver' => $this->driver(),
                'api_base_url' => $connection->resolvedApiBaseUrl(),
                'api_version' => $connection->resolvedApiVersion(),
                'fetched_at' => now()->toIso8601String(),
                'groups' => $groups['items'],
                'products' => $products['items'],
                'pages' => [
                    'groups' => $groups['pages'],
                    'products' => $products['pages'],
                ],
            ];

            $relativePath = trim(config('feed_mediator.imports_directory'), '/').'/shop-'.$connection->shop_id.'/source-'.$connection->id.'/import-'.$import->id.'.json';
            $payload = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            Storage::disk(config('feed_mediator.storage_disk'))->put($relativePath, $payload);

            $absolutePath = Storage::disk(config('feed_mediator.storage_disk'))->path($relativePath);
            $variationGroups = collect($products['items'])
                ->pluck('variation_group_id')
                ->filter(fn ($value): bool => is_numeric($value))
                ->unique()
                ->count();

            $import->update([
                'status' => SourceImport::STATUS_FETCHED,
                'fetched_at' => now(),
                'temp_path' => $relativePath,
                'source_checksum' => hash_file('sha256', $absolutePath) ?: null,
                'source_size_bytes' => filesize($absolutePath) ?: 0,
                'categories_total' => count($groups['items']),
                'offers_total' => count($products['items']),
                'meta' => array_merge($import->meta ?? [], [
                    'groups_pages_total' => count($groups['pages']),
                    'products_pages_total' => count($products['pages']),
                    'variation_groups_total' => $variationGroups,
                ]),
            ]);

            $this->log($connection, $import, 'info', 'source.synced', 'Prom API catalog snapshot fetched and cached.', [
                'path' => $relativePath,
                'groups_total' => count($groups['items']),
                'products_total' => count($products['items']),
                'variation_groups_total' => $variationGroups,
            ]);

            return $import->refresh();
        } catch (\Throwable $exception) {
            $import->update([
                'status' => SourceImport::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            $this->log($connection, $import, 'error', 'source.sync_failed', $exception->getMessage(), [
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function loadFeedData(SourceConnection $connection, SourceImport $import): ParsedSourceFeedData
    {
        $connection->loadMissing('shop');

        $path = $import->temp_path;

        if (blank($path)) {
            throw new SourceInvalidPayloadException('Prom API import does not have a cached snapshot path.');
        }

        $payload = Storage::disk(config('feed_mediator.storage_disk'))->json($path);

        if (! is_array($payload)) {
            throw new SourceInvalidPayloadException('Prom API import snapshot is unreadable or invalid JSON.');
        }

        $categories = array_map(fn (array $group): ParsedSourceCategoryData => $this->mapGroupToCategory($group), $payload['groups'] ?? []);
        $offers = array_map(fn (array $product): ParsedSourceOfferData => $this->mapProductToOffer($connection, $product), $payload['products'] ?? []);

        return new ParsedSourceFeedData($categories, $offers, 'Prom API');
    }

    private function assertConfigured(SourceConnection $connection): void
    {
        if (blank($connection->api_token)) {
            throw new SourceConfigurationException('Prom API token is not configured.');
        }
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function mapGroupToCategory(array $group): ParsedSourceCategoryData
    {
        $externalId = (string) ($group['id'] ?? '');

        if ($externalId === '') {
            throw new SourceInvalidPayloadException('Prom API group payload does not contain an id.');
        }

        return new ParsedSourceCategoryData(
            externalId: $externalId,
            parentExternalId: isset($group['parent_group_id']) ? (string) $group['parent_group_id'] : null,
            name: Canonicalizer::normalizeText((string) ($group['name'] ?? '')) ?? $externalId,
            rzId: null,
            rawPayload: $group,
        );
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function mapProductToOffer(SourceConnection $connection, array $product): ParsedSourceOfferData
    {
        $productId = $product['id'] ?? null;

        if (! is_numeric($productId)) {
            throw new SourceInvalidPayloadException('Prom API product payload does not contain a numeric id.');
        }

        $variationGroupId = $product['variation_group_id'] ?? null;
        $variationBaseId = $product['variation_base_id'] ?? null;
        $categoryId = data_get($product, 'category.id');
        $vendor = Canonicalizer::normalizeText($product['brand'] ?? null)
            ?? Canonicalizer::normalizeText($connection->options['default_vendor'] ?? null)
            ?? Canonicalizer::normalizeText($connection->shop?->name);
        $images = collect($product['images'] ?? [])
            ->pluck('url')
            ->prepend($product['main_image'] ?? null)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values()
            ->all();

        $params = array_filter([
            'Prom SKU' => Canonicalizer::normalizeText($product['sku'] ?? null),
            'Prom presence' => Canonicalizer::normalizeText($product['presence'] ?? null),
            'Prom status' => Canonicalizer::normalizeText($product['status'] ?? null),
            'Prom selling type' => Canonicalizer::normalizeText($product['selling_type'] ?? null),
            'Prom measure unit' => Canonicalizer::normalizeText($product['measure_unit'] ?? null),
            'Prom in stock flag' => array_key_exists('in_stock', $product) ? (($product['in_stock'] ?? false) ? 'true' : 'false') : null,
            'Prom category' => Canonicalizer::normalizeText(data_get($product, 'category.caption')),
            'Prom group' => Canonicalizer::normalizeText(data_get($product, 'group.name')),
            'Prom external id' => Canonicalizer::normalizeText($product['external_id'] ?? null),
            'Prom vendor fallback' => $vendor,
        ], fn ($value): bool => $value !== null && $value !== '');

        return new ParsedSourceOfferData(
            externalOfferId: (string) $productId,
            externalGroupId: $variationGroupId !== null
                ? (string) $variationGroupId
                : ($variationBaseId !== null ? (string) $variationBaseId : (string) $productId),
            externalSku: Canonicalizer::normalizeText($product['sku'] ?? null),
            title: Canonicalizer::normalizeText((string) ($product['name'] ?? '')) ?? 'Unnamed Prom product',
            categoryExternalId: $categoryId !== null ? (string) $categoryId : null,
            vendor: $vendor,
            article: Canonicalizer::normalizeText($product['sku'] ?? null)
                ?? Canonicalizer::normalizeText($product['external_id'] ?? null),
            description: Canonicalizer::normalizeText($product['description'] ?? null),
            price: isset($product['price']) ? (float) $product['price'] : null,
            oldPrice: $this->resolveOldPrice($product),
            currency: Canonicalizer::normalizeText($product['currency'] ?? null) ?? 'UAH',
            quantity: isset($product['quantity_in_stock']) ? (int) $product['quantity_in_stock'] : null,
            available: $this->resolveAvailability($product),
            images: $images,
            params: $params,
            rawPayload: $product,
        );
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function resolveOldPrice(array $product): ?float
    {
        $discount = $product['discount'] ?? null;

        if (! is_array($discount) || ! isset($discount['value'], $discount['type'], $product['price'])) {
            return null;
        }

        $price = (float) $product['price'];
        $value = (float) $discount['value'];

        if (($discount['type'] ?? null) === 'percent' && $value > 0 && $value < 100) {
            return round($price / (1 - ($value / 100)), 2);
        }

        if (($discount['type'] ?? null) === 'amount' && $value > 0) {
            return round($price + $value, 2);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function resolveAvailability(array $product): bool
    {
        $presence = (string) ($product['presence'] ?? '');

        return in_array($presence, ['available', 'order'], true)
            && ! in_array((string) ($product['status'] ?? ''), ['deleted', 'deleted_by_moderator'], true);
    }
}
