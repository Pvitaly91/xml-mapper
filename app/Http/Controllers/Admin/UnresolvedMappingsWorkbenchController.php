<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Shops\BootstrapShopForPilotAction;
use App\Actions\Admin\Workbench\ExecuteWorkbenchBulkAction;
use App\Http\Requests\Admin\Workbench\WorkbenchBulkActionRequest;
use App\Models\FeedProfile;
use App\Services\Shops\UnresolvedMappingsWorkbenchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class UnresolvedMappingsWorkbenchController extends AdminController
{
    public function index(Request $request, FeedProfile $feedProfile, UnresolvedMappingsWorkbenchService $service): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.workbench.index', [
            'feedProfile' => $feedProfile,
            'workbench' => $service->summarize($feedProfile, $request->only(['problem', 'search'])),
            'filters' => $request->only(['problem', 'search']),
        ]);
    }

    public function applySuggestions(Request $request, FeedProfile $feedProfile, BootstrapShopForPilotAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $summary = $action->applyInitialMappings($request->user(), $feedProfile);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'status',
            sprintf(
                'Suggestions applied: %d category mappings, %d attribute mappings, %d value mappings.',
                $summary['category_mappings_created'],
                $summary['attribute_mappings_created'],
                $summary['value_mappings_created'],
            )
        );
    }

    public function applyValueSuggestions(Request $request, FeedProfile $feedProfile, BootstrapShopForPilotAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $summary = $action->applyValueSuggestions($request->user(), $feedProfile);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', sprintf('Exact-match value suggestions applied: %d value mappings.', $summary['created']));
    }

    public function confirmBulk(
        WorkbenchBulkActionRequest $request,
        FeedProfile $feedProfile,
        ExecuteWorkbenchBulkAction $action,
        UnresolvedMappingsWorkbenchService $service
    ): View {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.workbench.confirm', [
            'feedProfile' => $feedProfile,
            'preview' => $action->preview(
                $feedProfile,
                $request->validated('operation'),
                $request->validated('feed_item_ids') ?? [],
                $request->validated('reason')
            ),
            'workbench' => $service->summarize($feedProfile, ['problem' => $request->input('problem')]),
        ]);
    }

    public function executeBulk(
        WorkbenchBulkActionRequest $request,
        FeedProfile $feedProfile,
        ExecuteWorkbenchBulkAction $action
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        try {
            $result = $action->handle(
                $feedProfile,
                $request->validated('operation'),
                $request->validated('feed_item_ids') ?? [],
                $request->validated('reason'),
                $request->user()
            );
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.feed-profiles.workbench.index', $feedProfile)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.feed-profiles.workbench.index', $feedProfile)
            ->with('status', $result['message']);
    }
}
