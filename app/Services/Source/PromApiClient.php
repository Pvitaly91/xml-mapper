<?php

namespace App\Services\Source;

use App\Contracts\Source\PromApiClientInterface;
use App\Exceptions\Source\SourceInvalidPayloadException;
use App\Exceptions\Source\SourceRemoteException;
use App\Models\SourceConnection;
use Illuminate\Support\Collection;

class PromApiClient implements PromApiClientInterface
{
    public function __construct(
        private readonly PromApiHttpTransport $transport,
    ) {
    }

    public function checkConnection(SourceConnection $connection): array
    {
        $groups = $this->transport->get($connection, '/groups/list', ['limit' => 1]);
        $products = $this->transport->get($connection, '/products/list', ['limit' => 1]);

        if (! isset($groups['groups']) || ! is_array($groups['groups'])) {
            throw new SourceInvalidPayloadException('Prom API groups check did not return a groups array.');
        }

        if (! isset($products['products']) || ! is_array($products['products'])) {
            throw new SourceInvalidPayloadException('Prom API products check did not return a products array.');
        }

        return [
            'groups_sample_count' => count($groups['groups']),
            'products_sample_count' => count($products['products']),
        ];
    }

    public function fetchAllGroups(SourceConnection $connection): array
    {
        return $this->paginate($connection, '/groups/list', 'groups');
    }

    public function fetchAllProducts(SourceConnection $connection): array
    {
        return $this->paginate($connection, '/products/list', 'products');
    }

    /**
     * @return array{items:list<array<string,mixed>>,pages:list<array<string,mixed>>}
     */
    private function paginate(SourceConnection $connection, string $path, string $itemsKey): array
    {
        $limit = max(1, (int) ($connection->options['page_limit'] ?? config('feed_mediator.prom_api.page_limit', 100)));
        $maxPages = max(1, (int) ($connection->options['max_pages'] ?? config('feed_mediator.prom_api.max_pages', 500)));
        $lastId = null;
        $page = 0;
        $items = [];
        $pages = [];

        while (true) {
            $page++;

            if ($page > $maxPages) {
                throw new SourceRemoteException(sprintf('Prom API pagination exceeded the configured page limit for [%s].', $path));
            }

            $query = [
                'limit' => $limit,
            ];

            if ($lastId !== null) {
                $query['last_id'] = $lastId;
            }

            $payload = $this->transport->get($connection, $path, $query);
            $pageItems = $payload[$itemsKey] ?? null;

            if (! is_array($pageItems)) {
                throw new SourceInvalidPayloadException(sprintf('Prom API response for [%s] did not contain a [%s] array.', $path, $itemsKey));
            }

            $items = [...$items, ...$pageItems];
            $nextLastId = $this->nextLastId($pageItems);

            $pages[] = [
                'path' => $path,
                'page' => $page,
                'count' => count($pageItems),
                'last_id_used' => $query['last_id'] ?? null,
                'next_last_id' => $nextLastId,
            ];

            if (count($pageItems) < $limit || $nextLastId === null) {
                break;
            }

            $lastId = $nextLastId;
        }

        return [
            'items' => array_values($items),
            'pages' => $pages,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function nextLastId(array $items): ?int
    {
        $minId = Collection::make($items)
            ->pluck('id')
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->min();

        if ($minId === null || $minId <= 1) {
            return null;
        }

        return $minId - 1;
    }
}
