<?php

namespace App\Services\Pilot;

use App\Models\PilotRun;
use App\Models\PilotRunEvent;
use App\Services\Feeds\FeedbackSlaService;
use App\Services\Feeds\FeedStabilityService;
use App\Services\Ops\SecretsRotationService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class PilotEvidencePackService
{
    public function __construct(
        private readonly PilotReadinessScoreService $readinessScoreService,
        private readonly FeedbackSlaService $feedbackSlaService,
        private readonly FeedStabilityService $feedStabilityService,
        private readonly SecretsRotationService $secretsRotationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(PilotRun $pilotRun): array
    {
        $pilotRun->loadMissing([
            'feedProfile.shop',
            'feedProfile.sourceConnection.latestImport',
            'feedProfile.currentHypercareWindow',
            'feedProfile.hypercareWindows',
            'feedProfile.feedbackImports',
            'feedProfile.firstPullVerifications',
            'feedProfile.opsAlerts',
            'sourceConnection.latestImport',
            'sourceSnapshot',
            'candidateGeneration',
            'publishedGeneration',
            'initiatedBy',
            'owner',
            'events.user',
        ]);

        $feedProfile = $pilotRun->feedProfile;
        $sourceConnection = $feedProfile->sourceConnection;
        $currentHypercare = $feedProfile->currentHypercareWindow ?: $feedProfile->hypercareWindows()->latest('id')->first();
        $feedback = $this->feedbackSlaService->summarize($feedProfile, $currentHypercare);
        $stability = $this->feedStabilityService->evaluate($feedProfile, $currentHypercare);
        $score = $this->readinessScoreService->score($feedProfile, $pilotRun);
        $latestSmoke = $pilotRun->publishedGeneration?->smokeChecks()->latest('checked_at')->first();
        $latestFirstPull = $feedProfile->firstPullVerifications()
            ->when($pilotRun->published_generation_id !== null, fn ($query) => $query->where('feed_generation_id', $pilotRun->published_generation_id))
            ->latest('verified_at')
            ->first();
        $events = $pilotRun->events()->with('user')->orderBy('occurred_at')->get();
        $operatorEvents = $events->whereIn('event_type', [
            PilotRunEvent::TYPE_NOTE,
            PilotRunEvent::TYPE_INCIDENT,
            PilotRunEvent::TYPE_OVERRIDE,
        ])->values();
        $incidents = $feedProfile->opsAlerts()
            ->when($pilotRun->started_at !== null, fn ($query) => $query->where('created_at', '>=', $pilotRun->started_at))
            ->when($pilotRun->finished_at !== null, fn ($query) => $query->where('created_at', '<=', $pilotRun->finished_at))
            ->latest('id')
            ->get();

        return [
            'execution_summary' => [
                'pilot_run_id' => $pilotRun->id,
                'state' => $pilotRun->state,
                'current_step' => $pilotRun->current_step,
                'started_at' => $pilotRun->started_at?->toIso8601String(),
                'finished_at' => $pilotRun->finished_at?->toIso8601String(),
                'blocking_reason' => $pilotRun->blocking_reason,
                'note' => $pilotRun->note,
                'shop' => [
                    'id' => $feedProfile->shop_id,
                    'name' => $feedProfile->shop?->name,
                ],
                'feed_profile' => [
                    'id' => $feedProfile->id,
                    'name' => $feedProfile->name,
                    'code' => $feedProfile->code,
                ],
                'owner' => $pilotRun->owner?->email,
                'initiated_by' => $pilotRun->initiatedBy?->email,
                'environment' => [
                    'class' => $pilotRun->environment_class,
                    'label' => $pilotRun->environment_label,
                ],
            ],
            'state_history' => $events->map(fn (PilotRunEvent $event) => [
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'event_type' => $event->event_type,
                'status' => $event->status,
                'step' => $event->step,
                'from_state' => $event->from_state,
                'to_state' => $event->to_state,
                'title' => $event->title,
                'message' => $event->message,
                'user' => $event->user?->email,
            ])->all(),
            'rehearsal_summary' => (array) data_get($pilotRun->summary, 'sections.rehearsal', []),
            'promotion_summary' => (array) data_get($pilotRun->summary, 'sections.promotion', []),
            'secret_rebind_status' => [
                'promotion_meta' => $sourceConnection?->promotionMeta(),
                'rotation_summary' => $sourceConnection ? $this->secretsRotationService->summarize($sourceConnection) : null,
                'source_connection_health' => $sourceConnection?->last_connection_check_status,
            ],
            'source_verification_result' => (array) data_get($pilotRun->summary, 'sections.source_verification', []),
            'candidate_generation_summary' => array_merge(
                (array) data_get($pilotRun->summary, 'sections.candidate_generation', []),
                [
                    'generation_id' => $pilotRun->candidateGeneration?->id,
                    'file_path' => $pilotRun->candidateGeneration?->file_path,
                    'checksum' => $pilotRun->candidateGeneration?->checksum,
                ]
            ),
            'preview_qa_signoff_summary' => [
                'qa' => (array) data_get($pilotRun->summary, 'sections.qa', []),
                'signoff' => (array) data_get($pilotRun->summary, 'sections.signoff', []),
            ],
            'publish_summary' => (array) data_get($pilotRun->summary, 'sections.publish', []),
            'smoke_check_result' => $latestSmoke?->toArray(),
            'first_pull_verification_result' => $latestFirstPull?->toArray(),
            'feedback_import_summary' => (array) data_get($pilotRun->summary, 'sections.feedback', []),
            'remediation_summary' => array_merge(
                (array) data_get($pilotRun->summary, 'sections.remediation', []),
                ['feedback_sla' => $feedback]
            ),
            'hypercare_summary' => [
                'current' => $currentHypercare?->toArray(),
                'stability' => $stability,
                'closeout_report' => data_get($pilotRun->summary, 'sections.hypercare.closeout_report'),
            ],
            'relevant_links' => [
                'pilot_center' => route('admin.pilot-runs.show', $pilotRun),
                'release_center' => route('admin.feed-profiles.release-center', $feedProfile),
                'promotion_center' => route('admin.feed-profiles.promotion.show', $feedProfile),
                'feedback_workbench' => route('admin.feed-profiles.feedback-workbench.index', $feedProfile),
                'hypercare' => route('admin.feed-profiles.hypercare.show', $feedProfile),
                'public_feed' => $pilotRun->publishedGeneration ? route('feeds.public', $feedProfile->public_token) : null,
                'preview_url' => data_get($pilotRun->summary, 'sections.qa.preview_url'),
                'candidate_checksum' => $pilotRun->candidateGeneration?->checksum,
                'published_checksum' => $pilotRun->publishedGeneration?->checksum,
                'source_snapshot_checksum' => $pilotRun->sourceSnapshot?->checksum,
            ],
            'operator_notes' => $operatorEvents->map(fn (PilotRunEvent $event) => [
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'type' => $event->event_type,
                'message' => $event->message,
                'user' => $event->user?->email,
            ])->all(),
            'incident_summary' => $incidents->map(fn ($alert) => [
                'id' => $alert->id,
                'severity' => $alert->severity,
                'state' => $alert->state,
                'title' => $alert->title,
                'message' => $alert->message,
                'created_at' => $alert->created_at?->toIso8601String(),
            ])->all(),
            'readiness' => $score,
        ];
    }

    /**
     * @return array{path:string,absolute_path:string,filename:string,payload:array<string,mixed>}
     */
    public function generate(PilotRun $pilotRun): array
    {
        $payload = $this->buildPayload($pilotRun->fresh());
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.pilot.evidence_directory', 'feeds/runbooks/pilot/evidence'), '/')
            .'/pilot-run-'.$pilotRun->id.'-evidence.zip';
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $zip = new ZipArchive;

        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create pilot evidence ZIP archive.');
        }

        $zip->addFromString('summary.json', json_encode($payload['execution_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('state-history.json', json_encode($payload['state_history'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('readiness.json', json_encode($payload['readiness'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('rehearsal-summary.json', json_encode($payload['rehearsal_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('promotion-summary.json', json_encode($payload['promotion_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('secret-rebind.json', json_encode($payload['secret_rebind_status'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('source-verification.json', json_encode($payload['source_verification_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('candidate-generation.json', json_encode($payload['candidate_generation_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('preview-qa-signoff.json', json_encode($payload['preview_qa_signoff_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('publish-summary.json', json_encode($payload['publish_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('smoke-check.json', json_encode($payload['smoke_check_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('first-pull.json', json_encode($payload['first_pull_verification_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('feedback-summary.json', json_encode($payload['feedback_import_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('remediation-summary.json', json_encode($payload['remediation_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('hypercare-summary.json', json_encode($payload['hypercare_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('links-and-checksums.json', json_encode($payload['relevant_links'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('operator-notes.json', json_encode($payload['operator_notes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('incident-summary.json', json_encode($payload['incident_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $zip->addFromString('index.html', $this->renderHtml($payload));

        $candidatePath = $pilotRun->candidateGeneration?->file_path;

        if ($candidatePath && $disk->exists($candidatePath)) {
            $zip->addFile($disk->path($candidatePath), 'candidate.xml');
        }

        $zip->close();

        return [
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => 'pilot-run-'.$pilotRun->id.'-evidence.zip',
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderHtml(array $payload): string
    {
        $historyRows = collect($payload['state_history'] ?? [])
            ->map(fn (array $row): string => sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                e($row['occurred_at'] ?? 'n/a'),
                e($row['event_type'] ?? 'n/a'),
                e($row['status'] ?? 'n/a'),
                e($row['title'] ?? $row['message'] ?? 'n/a'),
            ))
            ->implode('');

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Pilot Evidence</title>'
            .'<style>body{font-family:Segoe UI,sans-serif;background:#f6f6f2;color:#1f2933;padding:24px}section{background:#fff;border:1px solid #d6dce3;border-radius:16px;padding:18px;margin-bottom:18px}table{width:100%;border-collapse:collapse}td,th{padding:8px;border-bottom:1px solid #e7ecf0;text-align:left}h1,h2{margin-top:0}.badge{display:inline-block;padding:4px 8px;background:#eaf3f5;border-radius:999px}</style></head><body>'
            .'<h1>Pilot Evidence Pack</h1>'
            .'<section><h2>Execution Summary</h2><pre>'.e(json_encode($payload['execution_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)).'</pre></section>'
            .'<section><h2>Readiness</h2><pre>'.e(json_encode($payload['readiness'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)).'</pre></section>'
            .'<section><h2>State History</h2><table><thead><tr><th>When</th><th>Type</th><th>Status</th><th>Title</th></tr></thead><tbody>'.$historyRows.'</tbody></table></section>'
            .'<section><h2>Publish / Verification</h2><pre>'.e(json_encode([
                'publish' => $payload['publish_summary'],
                'smoke' => $payload['smoke_check_result'],
                'first_pull' => $payload['first_pull_verification_result'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)).'</pre></section>'
            .'<section><h2>Feedback / Hypercare</h2><pre>'.e(json_encode([
                'feedback' => $payload['feedback_import_summary'],
                'remediation' => $payload['remediation_summary'],
                'hypercare' => $payload['hypercare_summary'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)).'</pre></section>'
            .'</body></html>';
    }
}
