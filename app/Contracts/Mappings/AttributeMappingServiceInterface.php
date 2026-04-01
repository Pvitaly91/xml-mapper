<?php

namespace App\Contracts\Mappings;

use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\SourceProduct;
use App\Models\SourceVariant;

interface AttributeMappingServiceInterface
{
    /**
     * @return array<string, string>
     */
    public function resolveMappedAttributes(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        ?KastaCategory $kastaCategory = null
    ): array;

    /**
     * @return list<string>
     */
    public function missingRequiredMappings(
        FeedProfile $feedProfile,
        SourceProduct $product,
        ?KastaCategory $kastaCategory = null
    ): array;
}
