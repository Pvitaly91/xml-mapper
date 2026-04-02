<?php

namespace App\Contracts\Source;

use App\Models\SourceConnection;

interface PromApiClientInterface
{
    /**
     * @return array{groups_sample_count:int,products_sample_count:int}
     */
    public function checkConnection(SourceConnection $connection): array;

    /**
     * @return array{items:list<array<string,mixed>>,pages:list<array<string,mixed>>}
     */
    public function fetchAllGroups(SourceConnection $connection): array;

    /**
     * @return array{items:list<array<string,mixed>>,pages:list<array<string,mixed>>}
     */
    public function fetchAllProducts(SourceConnection $connection): array;
}
