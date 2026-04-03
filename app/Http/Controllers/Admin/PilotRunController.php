<?php

namespace App\Http\Controllers\Admin;

use App\Models\PilotRun;
use App\Models\PilotRunEvent;
use App\Services\Pilot\PilotCenterService;
use App\Services\Pilot\PilotEvidencePackService;
use App\Services\Pilot\PilotExecutionService;
use App\Services\Pilot\PilotReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PilotRunController extends AdminController
{
    public function index(Request $request, PilotCenterService $service): View
    {
        $shop = $this->adminShop($request);

        return view('admin.pilot-runs.index', [
            'shop' => $shop,
            'runs' => $service->list($shop),
            'feedProfiles' => $shop->feedProfiles()
                ->with(['sourceConnection', 'latestGeneration', 'publishedGeneration'])
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, PilotExecutionService $service): RedirectResponse
    {
        $shop = $this->adminShop($request);
        $validated = $request->validate([
            'feed_profile_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $feedProfile = $shop->feedProfiles()->findOrFail((int) $validated['feed_profile_id']);
        $run = $service->plan($feedProfile, [
            'note' => $validated['note'] ?? null,
        ], $request->user());

        return redirect()
            ->route('admin.pilot-runs.show', $run)
            ->with('status', 'Pilot run planned.');
    }

    public function show(Request $request, PilotRun $pilotRun, PilotCenterService $service): View
    {
        $pilotRun->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $pilotRun->feedProfile);
        $detail = $service->detail($pilotRun);

        return view('admin.pilot-runs.show', [
            'pilotRun' => $detail['run'],
            'history' => $detail['history'],
            'readiness' => $detail['readiness'],
            'blocker' => $detail['blocker'],
            'nextStep' => $detail['next_step'],
            'reportTypes' => [
                'summary' => 'Summary report',
                'blockers' => 'Blocker report',
                'execution-log' => 'Execution log',
                'readiness' => 'Readiness report',
            ],
        ]);
    }

    public function next(Request $request, PilotRun $pilotRun, PilotExecutionService $service): RedirectResponse
    {
        $pilotRun->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $pilotRun->feedProfile);

        try {
            $result = $service->executeNextStep($pilotRun, $this->options($request), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.pilot-runs.show', $result['run'])
            ->with('status', $result['progressed'] ? 'Pilot step executed.' : 'Pilot run is waiting for manual action.');
    }

    public function resume(Request $request, PilotRun $pilotRun, PilotExecutionService $service): RedirectResponse
    {
        $pilotRun->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $pilotRun->feedProfile);

        try {
            $run = $service->resume($pilotRun, $this->options($request), $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.pilot-runs.show', $run)
            ->with('status', 'Pilot run resumed.');
    }

    public function abort(Request $request, PilotRun $pilotRun, PilotExecutionService $service): RedirectResponse
    {
        $pilotRun->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $pilotRun->feedProfile);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $run = $service->abort($pilotRun, (string) $validated['reason'], $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.pilot-runs.show', $run)
            ->with('status', 'Pilot run aborted.');
    }

    public function event(Request $request, PilotRun $pilotRun, PilotExecutionService $service): RedirectResponse
    {
        $pilotRun->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $pilotRun->feedProfile);
        $validated = $request->validate([
            'event_type' => ['required', Rule::in([
                PilotRunEvent::TYPE_NOTE,
                PilotRunEvent::TYPE_INCIDENT,
                PilotRunEvent::TYPE_OVERRIDE,
            ])],
            'message' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $service->addEvent($pilotRun, (string) $validated['event_type'], (string) $validated['message'], $request->user());
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Pilot event recorded.');
    }

    public function evidence(
        Request $request,
        PilotRun $pilotRun,
        PilotEvidencePackService $service
    ): BinaryFileResponse {
        $pilotRun->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $pilotRun->feedProfile);
        $bundle = $service->generate($pilotRun);

        return response()->download($bundle['absolute_path'], $bundle['filename']);
    }

    public function report(
        Request $request,
        PilotRun $pilotRun,
        string $type,
        PilotReportService $service
    ): BinaryFileResponse {
        $pilotRun->loadMissing('feedProfile');
        $this->ensureShopOwned($request, $pilotRun->feedProfile);
        abort_unless(in_array($type, ['summary', 'blockers', 'execution-log', 'readiness'], true), 404);
        $report = $service->generate($pilotRun, $type);

        return response()->download($report['absolute_path'], $report['filename']);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Request $request): array
    {
        return [
            'with_sync' => $request->boolean('with_sync'),
            'with_build' => $request->boolean('with_build'),
            'with_publish' => $request->boolean('with_publish'),
            'with_feedback_fixtures' => $request->boolean('with_feedback_fixtures'),
        ];
    }
}
