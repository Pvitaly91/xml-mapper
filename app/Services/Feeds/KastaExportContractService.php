<?php

namespace App\Services\Feeds;

use App\Models\FeedProfile;
use App\Models\KastaCategory;
use App\Support\Canonicalizer;

class KastaExportContractService
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(FeedProfile $feedProfile, ?KastaCategory $category): array
    {
        $path = $category?->full_path ?: $category?->name;
        $pathKey = Canonicalizer::normalizeKey((string) $path);
        $department = Canonicalizer::normalizeKey((string) data_get($category?->metadata ?? [], 'department', 'general'));
        $department = $department === 'undefined' ? 'general' : $department;

        $contract = [
            'profile_key' => 'default',
            'category_id' => $category?->id,
            'category_external_id' => $category?->external_id,
            'category_name' => $path,
            'department' => $department,
            'required_fields' => ['category', 'vendor', 'vendor_code', 'title', 'description', 'images'],
            'optional_fields' => ['attributes', 'color', 'size', 'size_grid_code'],
            'minimum_images' => max(1, $feedProfile->minimumPictures()),
            'requires_color' => false,
            'requires_size' => false,
            'requires_size_grid' => false,
            'size_grid_code_hint' => null,
            'title_rules' => [
                'prepend_vendor' => true,
                'append_color' => false,
                'append_size' => false,
                'max_length' => 160,
            ],
            'description_rules' => [
                'allow_fallback' => true,
                'max_length' => 2000,
            ],
            'category_defaults' => [],
        ];

        if (
            $department === 'apparel'
            || str_contains($pathKey, 't_shirts')
            || str_contains($pathKey, 'dresses')
            || str_contains($pathKey, 'apparel')
        ) {
            $contract = $this->merge($contract, [
                'profile_key' => 'apparel',
                'required_fields' => ['color', 'size'],
                'requires_color' => true,
                'requires_size' => true,
                'title_rules' => [
                    'append_color' => true,
                    'append_size' => true,
                ],
            ]);
        }

        if (
            $department === 'footwear'
            || str_contains($pathKey, 'shoes')
            || str_contains($pathKey, 'sneakers')
        ) {
            $contract = $this->merge($contract, [
                'profile_key' => 'footwear',
                'required_fields' => ['color', 'size', 'size_grid_code'],
                'minimum_images' => max(3, (int) $contract['minimum_images']),
                'requires_color' => true,
                'requires_size' => true,
                'requires_size_grid' => true,
                'size_grid_code_hint' => 'adult-eu-shoes',
                'title_rules' => [
                    'append_color' => true,
                    'append_size' => true,
                ],
            ]);
        }

        return $this->merge($contract, $this->profileOverrides($feedProfile, $category, $department, $pathKey));
    }

    /**
     * @return array<string, mixed>
     */
    private function profileOverrides(
        FeedProfile $feedProfile,
        ?KastaCategory $category,
        string $department,
        string $pathKey
    ): array {
        $profiles = (array) ($feedProfile->exportSettings()['contract_profiles'] ?? []);
        $candidates = array_filter([
            'default',
            $department,
            $pathKey,
            $category?->external_id,
        ]);

        $overrides = [];

        foreach ($candidates as $candidate) {
            if (! array_key_exists($candidate, $profiles)) {
                continue;
            }

            $overrides = $this->merge($overrides, (array) $profiles[$candidate]);
        }

        return $overrides;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function merge(array $base, array $override): array
    {
        $merged = array_replace_recursive($base, $override);

        foreach (['required_fields', 'optional_fields'] as $key) {
            if (array_key_exists($key, $base) || array_key_exists($key, $override)) {
                $merged[$key] = array_values(array_unique(array_filter(array_merge(
                    (array) ($base[$key] ?? []),
                    (array) ($override[$key] ?? [])
                ))));
            }
        }

        return $merged;
    }
}
