<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Launch\MerchantLaunchBaselineRequest;
use App\Http\Requests\Admin\Launch\MerchantLaunchDefectRequest;
use App\Http\Requests\Admin\Launch\MerchantLaunchDefectUpdateRequest;
use App\Http\Requests\Admin\Launch\MerchantLaunchObservationRequest;
use App\Http\Requests\Admin\Launch\MerchantLaunchReasonRequest;
use App\Http\Requests\Admin\Launch\MerchantLaunchStartRequest;
use App\Http\Requests\Admin\Launch\MerchantLaunchTuningRequest;
use App\Models\MerchantLaunch;
use App\Models\MerchantLaunchDefect;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use App\Services\Launch\MerchantLaunchCenterService;
use App\Services\Launch\MerchantLaunchReportService;
use App\Services\Launch\MerchantLaunchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class MerchantLaunchController extends AdminController
{
    public function index(Request $request, MerchantLaunchCenterService $service): View
    {
        $shop = $this->adminShop($request);

        return view('admin.merchant-launches.index', [
            'shop' => $shop,
            'launches' => $service->list($shop),
            'feedProfiles' => $shop->feedProfiles()
                ->with(['sourceConnection', 'latestGeneration', 'publishedGeneration', 'currentMerchantLaunch'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(MerchantLaunchStartRequest $request, MerchantLaunchService $service): RedirectResponse
    {
        $shop = $this->adminShop($request);
        $feedProfile = $shop->feedProfiles()->findOrFail((int) $request->validated('feed_profile_id'));

        try {
            $launch = $service->start($feedProfile, [
                'pilot_run_id' => $request->validated('pilot_run_id'),
                'promotion_run_id' => $request->validated('promotion_run_id'),
                'note' => $request->validated('note'),
            ], $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.merchant-launches.show', $launch)
            ->with('status', 'Merchant launch record started.');
    }

    public function show(Request $request, MerchantLaunch $merchantLaunch, MerchantLaunchCenterService $service): View
    {
        $merchantLaunch->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $merchantLaunch->feedProfile);

        return view('admin.merchant-launches.show', $service->detail($merchantLaunch));
    }

    public function observe(
        MerchantLaunchObservationRequest $request,
        MerchantLaunch $merchantLaunch,
        MerchantLaunchService $service
    ): RedirectResponse {
        $merchantLaunch->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $merchantLaunch->feedProfile);

        try {
            $service->addObservation($merchantLaunch, $request->validated(), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Observation recorded.');
    }

    public function defect(
        MerchantLaunchDefectRequest $request,
        MerchantLaunch $merchantLaunch,
        MerchantLaunchService $service
    ): RedirectResponse {
        $merchantLaunch->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $merchantLaunch->feedProfile);

        try {
            $service->addDefect($merchantLaunch, $request->validated(), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Launch defect opened.');
    }

    public function updateDefect(
        MerchantLaunchDefectUpdateRequest $request,
        MerchantLaunch $merchantLaunch,
        MerchantLaunchDefect $defect,
        MerchantLaunchService $service
    ): RedirectResponse {
        $merchantLaunch->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $merchantLaunch->feedProfile);
        abort_unless($defect->merchant_launch_id === $merchantLaunch->id, 404);

        try {
            $service->updateDefect($defect, $request->validated(), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Launch defect updated.');
    }

    public function baseline(
        MerchantLaunchBaselineRequest $request,
        MerchantLaunch $merchantLaunch,
        MerchantLaunchService $service
    ): RedirectResponse {
        $merchantLaunch->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $merchantLaunch->feedProfile);

        try {
            $service->updateBaseline($merchantLaunch, $request->validated(), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Launch baseline updated.');
    }

    public function tuning(
        MerchantLaunchTuningRequest $request,
        MerchantLaunch $merchantLaunch,
        MerchantLaunchService $service,
        GovernedActionService $governedActionService
    ): RedirectResponse {
        $merchantLaunch->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $merchantLaunch->feedProfile);

        try {
            if (($request->validated('mode') ?? null) === 'emergency') {
                $result = $this->dispatchGovernedAction(
                    $request,
                    $governedActionService,
                    ApprovalPolicyService::ACTION_EMERGENCY_TUNING,
                    $merchantLaunch,
                    [
                        'merchant_launch_id' => $merchantLaunch->id,
                        'tuning_payload' => $request->validated(),
                    ],
                    [
                        'merchant_launch_id' => $merchantLaunch->id,
                        'type' => (string) $request->validated('type'),
                        'mode' => 'emergency',
                        'key' => $request->validated('key'),
                    ],
                    (string) $request->validated('reason'),
                    targetLabel: 'Launch #'.$merchantLaunch->id
                );

                if ($result->status !== 'executed') {
                    return $this->redirectWithGovernedResult(
                        $request,
                        $result,
                        null,
                        'Emergency tuning is waiting on re-authentication or approval.'
                    );
                }
            } else {
                $service->applyTuning($merchantLaunch, $request->validated(), $request->user());
            }
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Launch tuning applied.');
    }

    public function handover(
        MerchantLaunchReasonRequest $request,
        MerchantLaunch $merchantLaunch,
        MerchantLaunchService $service
    ): RedirectResponse {
        $merchantLaunch->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $merchantLaunch->feedProfile);

        try {
            $service->handover($merchantLaunch, (string) $request->validated('reason'), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Launch handed over.');
    }

    public function close(
        MerchantLaunchReasonRequest $request,
        MerchantLaunch $merchantLaunch,
        MerchantLaunchService $service,
        GovernedActionService $governedActionService
    ): RedirectResponse {
        $merchantLaunch->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $merchantLaunch->feedProfile);

        try {
            if ($request->boolean('override_blockers')) {
                $result = $this->dispatchGovernedAction(
                    $request,
                    $governedActionService,
                    ApprovalPolicyService::ACTION_LAUNCH_CLOSE_OVERRIDE,
                    $merchantLaunch,
                    [
                        'merchant_launch_id' => $merchantLaunch->id,
                        'reason' => (string) $request->validated('reason'),
                    ],
                    [
                        'merchant_launch_id' => $merchantLaunch->id,
                        'override_blockers' => true,
                    ],
                    (string) $request->validated('reason'),
                    targetLabel: 'Launch #'.$merchantLaunch->id
                );

                if ($result->status !== 'executed') {
                    return $this->redirectWithGovernedResult(
                        $request,
                        $result,
                        null,
                        'Launch close override is waiting on re-authentication or approval.'
                    );
                }
            } else {
                $service->close($merchantLaunch, (string) $request->validated('reason'), $request->user());
            }
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Launch closed.');
    }

    public function report(
        Request $request,
        MerchantLaunch $merchantLaunch,
        string $type,
        MerchantLaunchReportService $service
    ): BinaryFileResponse {
        $merchantLaunch->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $merchantLaunch->feedProfile);
        abort_unless(in_array($type, ['summary', 'observations', 'defects', 'closeout'], true), 404);
        $report = $service->generate($merchantLaunch, $type);

        return response()->download($report['absolute_path'], $report['filename']);
    }
}
