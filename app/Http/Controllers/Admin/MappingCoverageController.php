<?php

namespace App\Http\Controllers\Admin;

use App\Models\FeedProfile;
use App\Models\MappingBatch;
use App\Models\MappingTemplate;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Governance\GovernedActionService;
use App\Services\Mappings\Automation\MappingBatchService;
use App\Services\Mappings\Automation\MappingCoverageService;
use App\Services\Mappings\Automation\MappingTemplateLibraryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MappingCoverageController extends AdminController
{
    public function show(Request $request, FeedProfile $feedProfile, MappingCoverageService $coverageService): View
    {
        $this->ensureShopOwned($request, $feedProfile);

        return view('admin.mapping-coverage.show', [
            'feedProfile' => $feedProfile->loadMissing('shop', 'sourceConnection'),
            'coverage' => $coverageService->summarize($feedProfile, $request->only(['source_category_id', 'source_attribute_id', 'attribute_mapping_id', 'problem', 'search'])),
            'templates' => $this->templatesFor($feedProfile),
            'filters' => $request->only(['source_category_id', 'source_attribute_id', 'attribute_mapping_id', 'problem', 'search']),
        ]);
    }

    public function applySuggestions(
        Request $request,
        FeedProfile $feedProfile,
        MappingBatchService $batchService,
        GovernedActionService $governedActionService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        $validated = $request->validate([
            'type' => ['required', 'in:category,attribute,value'],
            'threshold' => ['nullable', 'numeric', 'min:0.5', 'max:0.99'],
            'strategy' => ['nullable', 'in:safe,overwrite_existing'],
            'dry_run' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'source_category_id' => ['nullable', 'integer'],
            'source_attribute_id' => ['nullable', 'integer'],
            'attribute_mapping_id' => ['nullable', 'integer'],
        ]);

        $batch = $batchService->planSuggestionBatch(
            $feedProfile,
            $validated['type'],
            (float) ($validated['threshold'] ?? (float) config('feed_mediator.mapping_automation.auto_apply_confidence', 0.9)),
            (string) ($validated['strategy'] ?? 'safe'),
            collect($validated)->only(['source_category_id', 'source_attribute_id', 'attribute_mapping_id'])->filter()->all(),
            $request->user(),
            (bool) ($validated['dry_run'] ?? false),
            $validated['reason'] ?? null,
            $validated['note'] ?? null,
        );

        if ($batch->status === MappingBatch::STATUS_DRY_RUN) {
            return back()->with('status', sprintf('Dry-run batch #%d prepared with %d planned entries.', $batch->id, (int) (($batch->summary ?? [])['planned'] ?? 0)));
        }

        if ($batch->risk_level !== 'standard') {
            $result = $this->dispatchGovernedAction(
                $request,
                $governedActionService,
                ApprovalPolicyService::ACTION_MAPPING_BULK_APPLY,
                $feedProfile,
                ['mapping_batch_id' => $batch->id],
                [
                    'batch_id' => $batch->id,
                    'mapping_type' => $batch->mapping_type,
                    'planned' => ($batch->summary ?? [])['planned'] ?? 0,
                    'risk_level' => $batch->risk_level,
                    'strategy' => $batch->strategy,
                ],
                $validated['reason'] ?? null,
                $validated['note'] ?? null,
                $feedProfile->name
            );

            if ($result->status === 'approval_required' && $result->approvalRequest) {
                $batch->update([
                    'approval_request_id' => $result->approvalRequest->id,
                    'status' => MappingBatch::STATUS_PENDING_APPROVAL,
                ]);
            }

            if ($result->status === 'executed') {
                return back()->with('status', sprintf('Mapping batch #%d executed.', $batch->id));
            }

            return $this->redirectWithGovernedResult(
                $request,
                $result,
                sprintf('Mapping batch #%d executed.', $batch->id),
                sprintf('Mapping batch #%d is waiting for approval.', $batch->id)
            );
        }

        $batchService->executeBatch($batch, $request->user());

        return back()->with('status', sprintf('Mapping batch #%d executed.', $batch->id));
    }

    public function rollbackBatch(
        Request $request,
        FeedProfile $feedProfile,
        MappingBatch $mappingBatch,
        MappingBatchService $batchService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);
        abort_unless($mappingBatch->feed_profile_id === $feedProfile->id, 404);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $batchService->rollback($mappingBatch, $request->user(), $validated['reason'] ?? null);

        return back()->with('status', sprintf('Mapping batch #%d rolled back.', $mappingBatch->id));
    }

    public function storeTemplate(
        Request $request,
        FeedProfile $feedProfile,
        MappingTemplateLibraryService $templateService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', 'in:global,shop,feed_profile'],
        ]);

        $template = $templateService->storeTemplate($feedProfile, $validated['name'], $validated['scope'], $request->user());

        return back()->with('status', sprintf('Mapping template [%s] stored as #%d.', $template->name, $template->id));
    }

    public function exportTemplate(Request $request, FeedProfile $feedProfile, MappingTemplateLibraryService $templateService)
    {
        $this->ensureShopOwned($request, $feedProfile);
        $payload = json_encode($templateService->exportPayload($feedProfile), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return response()->streamDownload(
            static fn () => print($payload),
            sprintf('feed-profile-%d-mapping-template.json', $feedProfile->id),
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public function applyTemplate(
        Request $request,
        FeedProfile $feedProfile,
        MappingTemplateLibraryService $templateService,
        GovernedActionService $governedActionService
    ): RedirectResponse {
        $this->ensureShopOwned($request, $feedProfile);

        $validated = $request->validate([
            'mapping_template_id' => ['required', 'integer', 'exists:mapping_templates,id'],
            'collision_strategy' => ['nullable', 'in:skip_existing,overwrite_existing,merge_if_safe'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $template = MappingTemplate::query()->findOrFail($validated['mapping_template_id']);
        $payload = (array) ($template->payload ?? []);
        $collisionStrategy = (string) ($validated['collision_strategy'] ?? 'skip_existing');

        if ((bool) ($validated['dry_run'] ?? false)) {
            $preview = $templateService->previewApply($feedProfile, $payload, $collisionStrategy);

            return back()->with('status', sprintf(
                'Template preview ready. Category create/update: %d/%d. Rules create/update: %d/%d.',
                $preview['mapping_plan']['summary']['category_mappings']['create'] ?? 0,
                $preview['mapping_plan']['summary']['category_mappings']['update'] ?? 0,
                $preview['rule_summary']['create'] ?? 0,
                $preview['rule_summary']['update'] ?? 0,
            ));
        }

        $needsGovernance = $template->scope === MappingTemplate::SCOPE_GLOBAL || $collisionStrategy === 'overwrite_existing';

        if ($needsGovernance) {
            $result = $this->dispatchGovernedAction(
                $request,
                $governedActionService,
                ApprovalPolicyService::ACTION_MAPPING_TEMPLATE_APPLY,
                $feedProfile,
                [
                    'feed_profile_id' => $feedProfile->id,
                    'template_payload' => $payload,
                    'collision_strategy' => $collisionStrategy,
                ],
                [
                    'template_id' => $template->id,
                    'template_name' => $template->name,
                    'scope' => $template->scope,
                    'collision_strategy' => $collisionStrategy,
                ],
                'Apply mapping template',
                null,
                $template->name
            );

            return $this->redirectWithGovernedResult(
                $request,
                $result,
                sprintf('Template [%s] applied.', $template->name),
                sprintf('Template [%s] is waiting for approval.', $template->name)
            );
        }

        $templateService->applyPayload($feedProfile, $payload, $collisionStrategy, $request->user());

        return back()->with('status', sprintf('Template [%s] applied.', $template->name));
    }

    private function templatesFor(FeedProfile $feedProfile)
    {
        return MappingTemplate::query()
            ->where('is_active', true)
            ->where(function ($query) use ($feedProfile): void {
                $query->where('feed_profile_id', $feedProfile->id)
                    ->orWhere(function ($inner) use ($feedProfile): void {
                        $inner->whereNull('feed_profile_id')
                            ->where('shop_id', $feedProfile->shop_id);
                    })
                    ->orWhere(function ($inner): void {
                        $inner->whereNull('feed_profile_id')
                            ->whereNull('shop_id');
                    });
            })
            ->orderBy('name')
            ->get();
    }
}
