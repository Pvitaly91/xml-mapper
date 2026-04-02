<?php

namespace App\Contracts\Mappings;

use App\Models\AttributeMapping;
use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Models\SourceProduct;
use App\Models\SourceVariant;
use Illuminate\Support\Collection;

interface AttributeMappingServiceInterface
{
    /**
     * @return Collection<int, array{
     *     mapping:AttributeMapping,
     *     source_attribute_name:string,
     *     source_attribute_code:string,
     *     source_value:?string,
     *     kasta_attribute_name:string,
     *     kasta_attribute_code:string,
     *     kasta_attribute_id:int,
     *     mapped_value:?string,
     *     resolution:string,
     *     allows_custom_value:bool,
     *     default_value:?string,
     *     use_variant_value:bool,
     *     has_value_mapping:bool,
     *     used_value_mapping:bool,
     *     used_default:bool,
     *     used_custom_value:bool
     * }>
     */
    public function resolveMappingRows(
        FeedProfile $feedProfile,
        SourceProduct $product,
        SourceVariant $variant,
        ?KastaCategory $kastaCategory = null
    ): Collection;

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
