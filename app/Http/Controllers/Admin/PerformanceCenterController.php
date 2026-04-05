<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedProfile;
use App\Models\PerformanceRun;
use App\Services\Ops\PerformanceCenterService;
use App\Services\Ops\PerformanceReportService;
use App\Services\Ops\PerformanceWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class PerformanceCenterController extends AdminController
{
    public function index(Request $request, PerformanceCenterService $service): View
    {
        $shop = $this->adminShop($request);
        $feedProfile = $request->integer('feed_profile_id')
            ? $shop->feedProfiles()->find($request->integer('feed_profile_id'))
            : null;

        return view('admin.performance-center.index', [
            'shop' => $shop,
            'feedProfiles' => $shop->feedProfiles()->orderBy('name')->get(),
            'runs' => $service->runs($shop, $request->all()),
            'summary' => $service->summary($shop, $feedProfile),
            'selectedFeedProfile' => $feedProfile,
        ]);
    }

    public function show(Request $request, PerformanceRun $performanceRun): View
    {
        $shop = $this->adminShop($request);
        abort_unless((int) $performanceRun->shop_id === (int) $shop->id, 404);

        return view('admin.performance-center.show', [
            'shop' => $shop,
            'run' => $performanceRun->load(['shop', 'feedProfile', 'user', 'stageRuns']),
        ]);
    }

    public function bootstrap(Request $request, PerformanceWorkflowService $service): RedirectResponse
    {
        $validated = $request->validate([
            'products' => ['required', 'integer', 'min:1', 'max:100000'],
            'variants_per_product' => ['required', 'integer', 'min:1', 'max:20'],
            'fresh' => ['nullable', 'boolean'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $run = $service->runLoadBootstrap(
                (int) $validated['products'],
                (int) $validated['variants_per_product'],
                (bool) ($validated['fresh'] ?? false),
                $request->user(),
                $validated['label'] ?? null,
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($run->shop_id !== null) {
            $request->session()->put('admin_shop_id', $run->shop_id);
        }

        return redirect()
            ->route('admin.performance.show', $run)
            ->with($run->status === PerformanceRun::STATUS_FAILED ? 'error' : 'status', 'Scale bootstrap finished with status '.$run->status.'.');
    }

    public function benchmark(Request $request, FeedProfile $feedProfile, PerformanceWorkflowService $service): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);
        $validated = $request->validate([
            'stages' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);
        $stages = array_values(array_filter(array_map('trim', explode(',', (string) ($validated['stages'] ?? 'sync,normalize,build,reconciliation,report_generation')))));

        try {
            $run = $service->runBenchmark($feedProfile, $stages, $request->user(), $validated['label'] ?? null);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.performance.show', $run)
            ->with($run->status === PerformanceRun::STATUS_FAILED ? 'error' : 'status', 'Benchmark finished with status '.$run->status.'.');
    }

    public function report(Request $request, PerformanceRun $performanceRun, PerformanceReportService $service)
    {
        $shop = $this->adminShop($request);
        abort_unless((int) $performanceRun->shop_id === (int) $shop->id, 404);
        $report = $service->generate($performanceRun);

        return response()->download($report['absolute_path'], $report['filename']);
    }
}
