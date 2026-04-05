<?php

namespace App\Services\Governance;

use App\Models\ApprovalRequest;
use App\Models\GovernanceAudit;
use App\Models\Shop;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class ComplianceReportService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function audits(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return GovernanceAudit::query()
            ->with(['shop', 'user', 'approvalRequest'])
            ->when(filled($filters['category'] ?? null), fn ($query) => $query->where('category', $filters['category']))
            ->when(filled($filters['shop_id'] ?? null), fn ($query) => $query->where('shop_id', (int) $filters['shop_id']))
            ->when(filled($filters['user_id'] ?? null), fn ($query) => $query->where('user_id', (int) $filters['user_id']))
            ->when(filled($filters['event_type'] ?? null), fn ($query) => $query->where('event_type', $filters['event_type']))
            ->when(filled($filters['severity'] ?? null), fn ($query) => $query->where('severity', $filters['severity']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->whereDate('occurred_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->whereDate('occurred_at', '<=', $filters['to']))
            ->latest('occurred_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function approvals(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return ApprovalRequest::query()
            ->with(['shop', 'requestedBy', 'approvedBy'])
            ->when(filled($filters['shop_id'] ?? null), fn ($query) => $query->where('shop_id', (int) $filters['shop_id']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['action'] ?? null), fn ($query) => $query->where('action', $filters['action']))
            ->when(filled($filters['user_id'] ?? null), fn ($query) => $query->where('requested_by_user_id', (int) $filters['user_id']))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function export(array $filters = []): array
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $directory = trim((string) config('feed_mediator.governance.reports_directory', 'feeds/runbooks/compliance'), '/');
        $timestamp = now()->format('YmdHis');
        $filename = 'compliance-report-'.$timestamp.'.json';
        $path = $directory.'/'.$filename;
        $absolutePath = $disk->path($path);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $handle = fopen($absolutePath, 'w+');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open compliance export file.');
        }

        fwrite($handle, "{\"generated_at\":".json_encode(now()->toIso8601String()).",\"filters\":".json_encode(Arr::only($filters, ['category', 'shop_id', 'user_id', 'status', 'action', 'event_type', 'severity', 'from', 'to']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).",\"audits\":[");
        $first = true;

        GovernanceAudit::query()
            ->when(filled($filters['category'] ?? null), fn ($query) => $query->where('category', $filters['category']))
            ->when(filled($filters['shop_id'] ?? null), fn ($query) => $query->where('shop_id', (int) $filters['shop_id']))
            ->when(filled($filters['user_id'] ?? null), fn ($query) => $query->where('user_id', (int) $filters['user_id']))
            ->when(filled($filters['event_type'] ?? null), fn ($query) => $query->where('event_type', $filters['event_type']))
            ->when(filled($filters['severity'] ?? null), fn ($query) => $query->where('severity', $filters['severity']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->whereDate('occurred_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->whereDate('occurred_at', '<=', $filters['to']))
            ->orderBy('id')
            ->chunkById((int) config('feed_mediator.performance.report_chunk_size', 500), function ($audits) use ($handle, &$first): void {
                foreach ($audits as $audit) {
                    fwrite($handle, ($first ? '' : ',').json_encode([
                        'id' => $audit->id,
                        'shop_id' => $audit->shop_id,
                        'user_id' => $audit->user_id,
                        'category' => $audit->category,
                        'event_type' => $audit->event_type,
                        'severity' => $audit->severity,
                        'summary' => $audit->summary,
                        'target_label' => $audit->target_label,
                        'correlation_id' => $audit->correlation_id,
                        'occurred_at' => $audit->occurred_at?->toIso8601String(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $first = false;
                }
            });

        fwrite($handle, "],\"approvals\":[");
        $first = true;

        ApprovalRequest::query()
            ->when(filled($filters['shop_id'] ?? null), fn ($query) => $query->where('shop_id', (int) $filters['shop_id']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['action'] ?? null), fn ($query) => $query->where('action', $filters['action']))
            ->when(filled($filters['user_id'] ?? null), fn ($query) => $query->where('requested_by_user_id', (int) $filters['user_id']))
            ->orderBy('id')
            ->chunkById((int) config('feed_mediator.performance.report_chunk_size', 500), function ($approvals) use ($handle, &$first): void {
                foreach ($approvals as $approval) {
                    fwrite($handle, ($first ? '' : ',').json_encode([
                        'id' => $approval->id,
                        'shop_id' => $approval->shop_id,
                        'action' => $approval->action,
                        'classification' => $approval->classification,
                        'status' => $approval->status,
                        'requested_by_user_id' => $approval->requested_by_user_id,
                        'approved_by_user_id' => $approval->approved_by_user_id,
                        'reason' => $approval->reason,
                        'target_label' => $approval->target_label,
                        'requested_at' => $approval->requested_at?->toIso8601String(),
                        'approved_at' => $approval->approved_at?->toIso8601String(),
                        'executed_at' => $approval->executed_at?->toIso8601String(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $first = false;
                }
            });

        fwrite($handle, ']}');
        fclose($handle);

        return [
            'disk' => config('feed_mediator.storage_disk'),
            'path' => $path,
            'absolute_path' => $absolutePath,
            'filename' => $filename,
            'payload' => [
                'generated_at' => now()->toIso8601String(),
                'filters' => Arr::only($filters, ['category', 'shop_id', 'user_id', 'status', 'action', 'event_type', 'severity', 'from', 'to']),
            ],
        ];
    }
}
