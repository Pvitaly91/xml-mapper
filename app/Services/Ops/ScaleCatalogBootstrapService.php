<?php

namespace App\Services\Ops;

use App\Actions\Admin\Shops\BootstrapShopForPilotAction;
use App\Models\FeedProfile;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\SourceConnection;
use App\Models\User;
use App\Services\Dictionaries\KastaDictionaryImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use XMLWriter;

class ScaleCatalogBootstrapService
{
    public function __construct(
        private readonly KastaDictionaryImportService $dictionaryImportService,
        private readonly BootstrapShopForPilotAction $bootstrapAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(
        int $products,
        int $variantsPerProduct,
        bool $fresh = false,
        ?User $user = null,
        ?string $label = null,
    ): array {
        if ($products <= 0 || $variantsPerProduct <= 0) {
            throw new RuntimeException('Scale bootstrap requires positive products and variants-per-product values.');
        }

        $dataset = $this->dataset($products, $variantsPerProduct, $label);

        return DB::transaction(function () use ($dataset, $fresh, $user, $products, $variantsPerProduct): array {
            if ($fresh) {
                $this->deleteExistingShop($dataset['shop_slug']);
            }

            $shop = Shop::query()->firstOrCreate(
                ['slug' => $dataset['shop_slug']],
                [
                    'name' => $dataset['shop_name'],
                    'currency' => 'UAH',
                    'locale' => 'uk',
                    'timezone' => 'Europe/Kiev',
                    'is_active' => true,
                ]
            );

            $operator = $this->ensureOperator($shop, $dataset['operator_email']);
            $xmlPath = $this->generatePromYml(
                $dataset['fixture_directory'],
                $dataset['fixture_basename'],
                $products,
                $variantsPerProduct,
            );

            $connection = SourceConnection::query()->updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'code' => 'scale-prom-yml',
                ],
                [
                    'name' => 'Scale Prom YML',
                    'driver' => SourceConnection::DRIVER_PROM_YML,
                    'status' => SourceConnection::STATUS_ACTIVE,
                    'source_url' => $xmlPath,
                    'sync_interval_minutes' => 60,
                    'last_connection_check_status' => SourceConnection::CHECK_STATUS_OK,
                    'last_sync_status' => SourceConnection::CHECK_STATUS_OK,
                ]
            );

            if (! $this->dictionariesReady()) {
                $this->dictionaryImportService->importBundle(null, $operator->id);
            }

            if ($user instanceof User) {
                $this->ensureAccessForUser($user->fresh(), $shop);
            }

            $feedProfile = $this->bootstrapAction->ensureDefaultFeedProfile($operator);
            $feedProfile->update([
                'source_connection_id' => $connection->id,
                'name' => 'Scale Main',
                'code' => 'scale-main',
            ]);

            $import = $this->bootstrapAction->runFirstSync($operator);
            $mappingSummary = $this->bootstrapAction->applyInitialMappings($operator, $feedProfile->fresh());
            $generation = $this->bootstrapAction->buildReleaseCandidate($operator, $feedProfile->fresh());
            $feedback = $this->generateFeedbackFixtures($dataset['fixture_directory'], $feedProfile->fresh(), $dataset['feedback_rows']);

            $shop->update([
                'settings' => array_merge($shop->settings ?? [], [
                    'scale_fixture' => [
                        'catalog_path' => $xmlPath,
                        'feedback_csv_path' => $feedback['csv'],
                        'feedback_json_path' => $feedback['json'],
                        'dataset' => [
                            'products' => $products,
                            'variants' => $products * $variantsPerProduct,
                            'images' => $products * $variantsPerProduct * 2,
                        ],
                    ],
                ]),
            ]);

            return [
                'shop' => $shop->fresh(),
                'operator' => $operator->fresh(),
                'source_connection' => $connection->fresh(),
                'feed_profile' => $feedProfile->fresh(),
                'source_import' => $import->fresh(),
                'generation' => $generation->fresh(),
                'dataset' => [
                    'products' => $products,
                    'variants' => $products * $variantsPerProduct,
                    'images' => $products * $variantsPerProduct * 2,
                    'feedback_rows' => $dataset['feedback_rows'],
                ],
                'fixture' => [
                    'catalog_path' => $xmlPath,
                    'feedback_csv_path' => $feedback['csv'],
                    'feedback_json_path' => $feedback['json'],
                ],
                'mapping_summary' => $mappingSummary,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function dataset(int $products, int $variantsPerProduct, ?string $label): array
    {
        $slugBase = sprintf(
            '%s-%dp-%dv',
            trim((string) config('feed_mediator.performance.scale.default_shop_slug', 'scale-catalog')),
            $products,
            $variantsPerProduct,
        );
        $fixtureDirectory = rtrim((string) config('feed_mediator.performance.scale.fixtures_directory'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$slugBase;

        return [
            'shop_slug' => $slugBase,
            'shop_name' => trim((string) config('feed_mediator.performance.scale.default_shop_name', 'Scale Catalog Shop')).' '.strtoupper((string) ($label ?: $products.'x'.$variantsPerProduct)),
            'operator_email' => $slugBase.'@example.test',
            'fixture_directory' => $fixtureDirectory,
            'fixture_basename' => $slugBase.'-catalog.xml',
            'feedback_rows' => min(
                max(20, (int) floor(($products * $variantsPerProduct) * 0.02)),
                (int) config('feed_mediator.performance.scale.feedback_sample_limit', 250),
            ),
        ];
    }

    private function deleteExistingShop(string $slug): void
    {
        $shop = Shop::query()->where('slug', $slug)->first();

        if (! $shop instanceof Shop) {
            return;
        }

        User::query()->where('shop_id', $shop->id)->delete();
        $shop->delete();
    }

    private function ensureOperator(Shop $shop, string $email): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'shop_id' => $shop->id,
                'name' => 'Scale Operator',
                'password' => Hash::make(Str::random(32)),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'account_state' => User::STATE_ACTIVE,
            ]
        );

        ShopMembership::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'shop_id' => $shop->id,
            ],
            [
                'role' => ShopMembership::ROLE_SHOP_ADMIN,
                'status' => ShopMembership::STATUS_ACTIVE,
            ]
        );

        return $user;
    }

    private function ensureAccessForUser(User $user, Shop $shop): void
    {
        if ($user->id === null) {
            return;
        }

        ShopMembership::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'shop_id' => $shop->id,
            ],
            [
                'role' => ShopMembership::ROLE_SHOP_ADMIN,
                'status' => ShopMembership::STATUS_ACTIVE,
            ]
        );
    }

    private function dictionariesReady(): bool
    {
        return \App\Models\KastaCategory::query()->exists()
            && \App\Models\KastaAttribute::query()->exists()
            && \App\Models\KastaAttributeValue::query()->exists();
    }

    private function generatePromYml(string $directory, string $filename, int $products, int $variantsPerProduct): string
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $absolutePath = $directory.DIRECTORY_SEPARATOR.$filename;
        $writer = new XMLWriter();

        if (! $writer->openUri($absolutePath)) {
            throw new RuntimeException('Unable to open scale fixture XML output.');
        }

        $categoryMap = [
            ['id' => '101', 'parent_id' => '100', 'name' => 'Футболки', 'rz_id' => '2001', 'material' => 'Cotton', 'sizes' => ['S', 'M', 'L', 'XL']],
            ['id' => '201', 'parent_id' => '200', 'name' => 'Кросівки', 'rz_id' => '3002', 'material' => 'Mesh', 'sizes' => ['40', '41', '42', '43']],
            ['id' => '301', 'parent_id' => '300', 'name' => 'Туфлі', 'rz_id' => '3001', 'material' => 'Leather', 'sizes' => ['39', '40', '41', '42']],
        ];
        $colors = ['Black', 'White', 'Blue', 'Gray', 'Green', 'Red'];

        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('yml_catalog');
        $writer->writeAttribute('date', now()->format('Y-m-d H:i'));
        $writer->startElement('shop');
        $writer->writeElement('name', 'Scale Catalog Shop');
        $writer->startElement('categories');

        foreach ([['100', 'Одяг'], ['200', 'Взуття'], ['300', 'Класичне взуття']] as [$id, $name]) {
            $writer->startElement('category');
            $writer->writeAttribute('id', $id);
            $writer->text($name);
            $writer->endElement();
        }

        foreach ($categoryMap as $category) {
            $writer->startElement('category');
            $writer->writeAttribute('id', $category['id']);
            $writer->writeAttribute('parentId', $category['parent_id']);
            $writer->writeAttribute('rz_id', $category['rz_id']);
            $writer->text($category['name']);
            $writer->endElement();
        }

        $writer->endElement();
        $writer->startElement('offers');

        for ($productIndex = 1; $productIndex <= $products; $productIndex++) {
            $category = $categoryMap[($productIndex - 1) % count($categoryMap)];
            $article = sprintf('SCALE-%05d', $productIndex);
            $vendor = 'Vendor '.str_pad((string) (($productIndex % 40) + 1), 2, '0', STR_PAD_LEFT);

            for ($variantIndex = 1; $variantIndex <= $variantsPerProduct; $variantIndex++) {
                $color = $colors[($productIndex + $variantIndex) % count($colors)];
                $size = $category['sizes'][($variantIndex - 1) % count($category['sizes'])];
                $offerId = sprintf('%s-V%02d', $article, $variantIndex);

                $writer->startElement('offer');
                $writer->writeAttribute('id', $offerId);
                $writer->writeAttribute('available', (($productIndex + $variantIndex) % 11) !== 0 ? 'true' : 'false');
                $writer->writeElement('name', sprintf('%s %s %s', $category['name'], $color, $size));
                $writer->writeElement('price', (string) (499 + (($productIndex + $variantIndex) % 25) * 25));
                $writer->writeElement('currencyId', 'UAH');
                $writer->writeElement('categoryId', $category['id']);
                $writer->writeElement('vendor', $vendor);
                $writer->writeElement('vendorCode', $article);
                $writer->writeElement('description', sprintf('Scale fixture product %d variant %d.', $productIndex, $variantIndex));
                $writer->writeElement('quantity_in_stock', (string) (5 + (($productIndex + $variantIndex) % 20)));
                $writer->writeElement('picture', sprintf('https://example.test/%s/%s-1.jpg', strtolower($article), strtolower($offerId)));
                $writer->writeElement('picture', sprintf('https://example.test/%s/%s-2.jpg', strtolower($article), strtolower($offerId)));
                $writer->startElement('param');
                $writer->writeAttribute('name', 'Color');
                $writer->text($color);
                $writer->endElement();
                $writer->startElement('param');
                $writer->writeAttribute('name', 'Size');
                $writer->text($size);
                $writer->endElement();
                $writer->startElement('param');
                $writer->writeAttribute('name', 'Material');
                $writer->text($category['material']);
                $writer->endElement();
                $writer->endElement();
            }
        }

        $writer->endElement();
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();

        return $absolutePath;
    }

    /**
     * @return array{csv:string,json:string}
     */
    private function generateFeedbackFixtures(string $directory, FeedProfile $feedProfile, int $rowsLimit): array
    {
        $variants = $feedProfile->items()
            ->with('sourceVariant')
            ->whereIn('status', [\App\Models\FeedItem::STATUS_READY, \App\Models\FeedItem::STATUS_PUBLISHED])
            ->orderBy('id')
            ->limit($rowsLimit)
            ->get()
            ->pluck('sourceVariant')
            ->filter()
            ->values();

        $csvPath = $directory.DIRECTORY_SEPARATOR.'feedback-sample.csv';
        $jsonPath = $directory.DIRECTORY_SEPARATOR.'feedback-sample.json';
        $csvHandle = fopen($csvPath, 'w');

        if ($csvHandle === false) {
            throw new RuntimeException('Unable to create scale feedback CSV fixture.');
        }

        fputcsv($csvHandle, ['offer_id', 'vendor_code', 'status', 'rejection_reason_code', 'rejection_reason_message']);
        $jsonRows = [];

        foreach ($variants as $index => $variant) {
            $status = ($index % 5) === 0 ? 'rejected' : (($index % 7) === 0 ? 'warning' : 'accepted');
            $payload = [
                'offer_id' => $variant->stable_offer_id,
                'vendor_code' => $variant->external_sku,
                'status' => $status,
                'rejection_reason_code' => $status === 'rejected' ? 'image_count' : null,
                'rejection_reason_message' => $status === 'rejected' ? 'Image count below marketplace expectation.' : null,
            ];

            fputcsv($csvHandle, [
                $payload['offer_id'],
                $payload['vendor_code'],
                $payload['status'],
                $payload['rejection_reason_code'],
                $payload['rejection_reason_message'],
            ]);

            $jsonRows[] = $payload;
        }

        fclose($csvHandle);
        file_put_contents($jsonPath, json_encode(['items' => $jsonRows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ['csv' => $csvPath, 'json' => $jsonPath];
    }
}
