<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Shops\BootstrapShopForPilotAction;
use App\Actions\Admin\Shops\UpsertShopAction;
use App\Http\Requests\Admin\Onboarding\SelectSourceDriverRequest;
use App\Http\Requests\Admin\Shops\ShopProfileRequest;
use App\Services\Shops\ShopOnboardingService;
use App\Services\Shops\ShopOnboardingStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ShopOnboardingController extends AdminController
{
    public function show(Request $request, ShopOnboardingService $service): View
    {
        $summary = $service->summarize($request->user()->loadMissing('shop'));

        return view('admin.onboarding.show', [
            'summary' => $summary,
            'shop' => $summary['shop'],
            'sourceConnection' => $summary['source_connection'],
            'feedProfile' => $summary['feed_profile'],
            'latestGeneration' => $summary['latest_generation'],
        ]);
    }

    public function saveShop(ShopProfileRequest $request, UpsertShopAction $action): RedirectResponse
    {
        $action->handle($request->user(), $request->validated());

        return redirect()
            ->route('admin.onboarding.show')
            ->with('status', 'Shop profile saved.');
    }

    public function selectDriver(SelectSourceDriverRequest $request, ShopOnboardingStateService $stateService): RedirectResponse
    {
        $stateService->markStepCompleted($request->user(), ShopOnboardingService::STEP_SOURCE_DRIVER, [
            'selected_driver' => $request->validated('driver'),
        ]);

        return redirect()
            ->route('admin.onboarding.show')
            ->with('status', 'Source driver selected.');
    }

    public function ensureFeedProfile(Request $request, BootstrapShopForPilotAction $action): RedirectResponse
    {
        try {
            $profile = $action->ensureDefaultFeedProfile($request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Default feed profile is ready: '.$profile->name.'.');
    }

    public function applyMappings(Request $request, BootstrapShopForPilotAction $action): RedirectResponse
    {
        try {
            $summary = $action->applyInitialMappings($request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'status',
            sprintf(
                'Initial mapping bootstrap finished: %d category mappings created, %d attribute mappings created, %d value mappings created.',
                $summary['category_mappings_created'],
                $summary['attribute_mappings_created'],
                $summary['value_mappings_created'],
            )
        );
    }

    public function buildCandidate(Request $request, BootstrapShopForPilotAction $action): RedirectResponse
    {
        try {
            $generation = $action->buildReleaseCandidate($request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Release candidate #'.$generation->id.' is ready.');
    }

    public function bootstrap(Request $request, BootstrapShopForPilotAction $action): RedirectResponse
    {
        try {
            $summary = $action->bootstrap($request->user(), $request->boolean('run_sync'), $request->boolean('build_candidate', true));
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'status',
            'Bootstrap finished for feed profile #'.$summary['feed_profile_id'].'.'
        );
    }
}
