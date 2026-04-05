<?php

namespace App\Services\Ops;

use App\Models\FeedProfile;
use App\Models\PerformanceRun;
use App\Models\User;
use Throwable;

class PerformanceWorkflowService
{
    public function __construct(
        private readonly ScaleCatalogBootstrapService $scaleCatalogBootstrapService,
        private readonly PerformanceBenchmarkService $benchmarkService,
        private readonly PerformanceRunService $performanceRunService,
    ) {}

    public function runLoadBootstrap(
        int $products,
        int $variantsPerProduct,
        bool $fresh = false,
        ?User $user = null,
        ?string $label = null,
    ): PerformanceRun {
        $run = $this->performanceRunService->start(
            PerformanceRun::TYPE_LOAD_BOOTSTRAP,
            null,
            null,
            $user,
            [
                'products' => $products,
                'variants' => $products * $variantsPerProduct,
                'images' => $products * $variantsPerProduct * 2,
            ],
            ['load_bootstrap'],
            $label,
            'Scale bootstrap workflow.',
        );

        try {
            $stage = $this->performanceRunService->measureStage($run, 'load_bootstrap', function () use ($products, $variantsPerProduct, $fresh, $user, $label): array {
                $result = $this->scaleCatalogBootstrapService->bootstrap($products, $variantsPerProduct, $fresh, $user, $label);

                return [
                    'processed_products' => (int) data_get($result, 'dataset.products', 0),
                    'processed_variants' => (int) data_get($result, 'dataset.variants', 0),
                    'processed_rows' => (int) data_get($result, 'dataset.variants', 0),
                    'meta' => [
                        'shop_id' => data_get($result, 'shop.id'),
                        'feed_profile_id' => data_get($result, 'feed_profile.id'),
                        'source_import_id' => data_get($result, 'source_import.id'),
                        'generation_id' => data_get($result, 'generation.id'),
                        'fixture' => data_get($result, 'fixture'),
                    ],
                ];
            });

            $run->forceFill([
                'shop_id' => (int) data_get($stage, 'result.meta.shop_id') ?: $run->shop_id,
                'feed_profile_id' => (int) data_get($stage, 'result.meta.feed_profile_id') ?: $run->feed_profile_id,
            ])->save();

            return $this->performanceRunService->finish($run, [
                'stage' => $stage['stage']->stage,
                'dataset_products' => $products,
                'dataset_variants' => $products * $variantsPerProduct,
            ]);
        } catch (Throwable $exception) {
            return $this->performanceRunService->fail($run, $exception);
        }
    }

    /**
     * @param  list<string>  $stages
     */
    public function runBenchmark(FeedProfile $feedProfile, array $stages, ?User $user = null, ?string $label = null): PerformanceRun
    {
        return $this->benchmarkService->run($feedProfile, $stages, $user, $label);
    }
}
