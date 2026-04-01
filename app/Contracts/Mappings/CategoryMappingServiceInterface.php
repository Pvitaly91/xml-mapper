<?php

namespace App\Contracts\Mappings;

use App\Models\CategoryMapping;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\SourceCategory;

interface CategoryMappingServiceInterface
{
    public function resolve(FeedProfile $feedProfile, SourceCategory|int|null $sourceCategory): ?CategoryMapping;

    public function getMappedCategory(FeedProfile $feedProfile, SourceCategory|int|null $sourceCategory): ?KastaCategory;
}
