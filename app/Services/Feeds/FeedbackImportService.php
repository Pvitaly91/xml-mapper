<?php

namespace App\Services\Feeds;

use App\Models\FeedbackImport;
use App\Models\FeedbackRecord;
use App\Models\FeedGeneration;
use App\Models\FeedItem;
use App\Models\FeedProfile;
use App\Models\SourceVariant;
use App\Models\User;
use App\Support\Canonicalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FeedbackImportService
{
    public function __construct(
        private readonly FeedReleaseAuditService $auditService,
        private readonly FeedCutoverService $cutoverService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        FeedProfile $feedProfile,
        string $format,
        string $content,
        ?FeedGeneration $generation = null
    ): array {
        return $this->plan($feedProfile, $format, $content, $generation);
    }

    /**
     * @return array<string, mixed>
     */
    public function importUploadedFile(
        FeedProfile $feedProfile,
        string $format,
        UploadedFile $file,
        bool $dryRun = false,
        ?User $user = null,
        ?FeedGeneration $generation = null
    ): array {
        $content = file_get_contents($file->getRealPath());

        if ($content === false) {
            throw new RuntimeException('Unable to read feedback file.');
        }

        $plan = $this->plan($feedProfile, $format, $content, $generation);

        if ($dryRun) {
            return [
                'dry_run' => true,
                'summary' => $plan['summary'],
                'rows' => $plan['rows'],
            ];
        }

        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.feedback_directory'), '/')
            .'/shop-'.$feedProfile->shop_id
            .'/feed-'.$feedProfile->id
            .'/'.now()->format('YmdHis').'-'.$file->hashName();

        $disk->put($relativePath, $content);

        $import = FeedbackImport::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation?->id ?? $feedProfile->published_generation_id,
            'user_id' => $user?->id,
            'format' => $format,
            'status' => FeedbackImport::STATUS_IMPORTED,
            'original_filename' => $file->getClientOriginalName(),
            'source_path' => $relativePath,
            'checksum' => hash('sha256', $content),
            'matched_total' => $plan['summary']['matched'],
            'unmatched_total' => $plan['summary']['unmatched'],
            'accepted_total' => $plan['summary']['accepted'],
            'rejected_total' => $plan['summary']['rejected'],
            'warnings_total' => $plan['summary']['warnings'],
            'meta' => [
                'rows_total' => count($plan['rows']),
            ],
            'imported_at' => now(),
        ]);

        foreach ($plan['rows'] as $row) {
            FeedbackRecord::create([
                'shop_id' => $feedProfile->shop_id,
                'feed_profile_id' => $feedProfile->id,
                'feedback_import_id' => $import->id,
                'feed_generation_id' => $row['matched_generation_id'],
                'feed_item_id' => $row['matched_feed_item_id'],
                'source_product_id' => $row['matched_source_product_id'],
                'source_variant_id' => $row['matched_source_variant_id'],
                'status' => $row['status'],
                'resolution_status' => FeedbackRecord::RESOLUTION_OPEN,
                'external_item_reference' => $row['external_item_reference'],
                'offer_id' => $row['offer_id'],
                'vendor_code' => $row['vendor_code'],
                'article' => $row['article'],
                'rejection_reason_code' => $row['rejection_reason_code'],
                'rejection_reason_message' => $row['rejection_reason_message'],
                'raw_payload' => $row['raw_payload'],
                'imported_at' => now(),
            ]);
        }

        $this->auditService->record(
            $feedProfile,
            $generation ?? $feedProfile->publishedGeneration,
            'feedback_imported',
            $user,
            $file->getClientOriginalName(),
            [
                'feedback_import_id' => $import->id,
                'format' => $format,
                'summary' => $plan['summary'],
            ]
        );

        $this->cutoverService->syncState($feedProfile->fresh(), $generation?->fresh(), $user, 'Feedback imported');

        return [
            'dry_run' => false,
            'import' => $import->fresh(),
            'summary' => $plan['summary'],
            'rows' => $plan['rows'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(FeedProfile $feedProfile, string $format, string $content, ?FeedGeneration $generation = null): array
    {
        $rows = collect($this->parse($format, $content))
            ->map(fn (array $row) => $this->normalizeRow($row))
            ->map(fn (array $row) => $this->matchRow($feedProfile, $row, $generation))
            ->values()
            ->all();

        return [
            'summary' => [
                'matched' => collect($rows)->where('matched', true)->count(),
                'unmatched' => collect($rows)->where('matched', false)->count(),
                'accepted' => collect($rows)->where('status', FeedbackRecord::STATUS_ACCEPTED)->count(),
                'rejected' => collect($rows)->where('status', FeedbackRecord::STATUS_REJECTED)->count(),
                'warnings' => collect($rows)->where('status', FeedbackRecord::STATUS_WARNING)->count(),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $format, string $content): array
    {
        return match (mb_strtolower($format)) {
            'json' => $this->parseJson($content),
            default => $this->parseCsv($content),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsv(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));

        if (! is_array($lines) || $lines === []) {
            return [];
        }

        $header = array_map([$this, 'stripBom'], str_getcsv((string) array_shift($lines)));
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            $combined = array_combine($header, array_pad($values, count($header), null));

            if (is_array($combined)) {
                $rows[] = $combined;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseJson(string $content): array
    {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $rows = $decoded['items'] ?? $decoded;

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $status = Canonicalizer::normalizeKey((string) ($row['status'] ?? $row['result'] ?? 'unknown'));

        return [
            'status' => match ($status) {
                'accepted', 'accept', 'ok', 'approved' => FeedbackRecord::STATUS_ACCEPTED,
                'rejected', 'reject', 'error', 'declined' => FeedbackRecord::STATUS_REJECTED,
                'warning', 'warn' => FeedbackRecord::STATUS_WARNING,
                default => FeedbackRecord::STATUS_UNKNOWN,
            },
            'external_item_reference' => $row['external_item_reference'] ?? $row['externalItemReference'] ?? null,
            'offer_id' => $row['offer_id'] ?? $row['offerId'] ?? $row['offer'] ?? null,
            'vendor_code' => $row['vendor_code'] ?? $row['vendorCode'] ?? $row['vendorcode'] ?? null,
            'article' => $row['article'] ?? null,
            'rejection_reason_code' => $row['rejection_reason_code'] ?? $row['reason_code'] ?? $row['code'] ?? null,
            'rejection_reason_message' => $row['rejection_reason_message'] ?? $row['reason_message'] ?? $row['message'] ?? null,
            'raw_payload' => $row,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function matchRow(FeedProfile $feedProfile, array $row, ?FeedGeneration $generation = null): array
    {
        $candidateReferences = collect([
            $row['offer_id'],
            $row['external_item_reference'],
            $row['vendor_code'],
            $row['article'],
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

        if ($candidateReferences === []) {
            return array_merge($row, [
                'matched' => false,
                'matched_feed_item_id' => null,
                'matched_source_variant_id' => null,
                'matched_source_product_id' => null,
                'matched_generation_id' => $generation?->id ?? $feedProfile->published_generation_id,
            ]);
        }

        $variant = SourceVariant::query()
            ->with('product')
            ->where('source_connection_id', $feedProfile->source_connection_id)
            ->where(function ($query) use ($candidateReferences): void {
                foreach ($candidateReferences as $reference) {
                    $query->orWhere('stable_offer_id', $reference)
                        ->orWhere('external_offer_id', $reference)
                        ->orWhere('external_sku', $reference)
                        ->orWhereHas('product', fn ($builder) => $builder->where('article', $reference));
                }
            })
            ->first();

        $feedItem = $variant?->feedItems()
            ->where('feed_profile_id', $feedProfile->id)
            ->when($generation !== null, fn ($query) => $query->where('last_built_generation_id', $generation->id))
            ->latest('id')
            ->first();

        if (! $feedItem instanceof FeedItem && $variant instanceof SourceVariant) {
            $feedItem = $variant->feedItems()
                ->where('feed_profile_id', $feedProfile->id)
                ->latest('id')
                ->first();
        }

        return array_merge($row, [
            'matched' => $variant instanceof SourceVariant && $feedItem instanceof FeedItem,
            'matched_feed_item_id' => $feedItem?->id,
            'matched_source_variant_id' => $variant?->id,
            'matched_source_product_id' => $variant?->source_product_id,
            'matched_generation_id' => $generation?->id ?? $feedProfile->published_generation_id ?? $feedItem?->last_built_generation_id,
        ]);
    }

    private function stripBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }
}
