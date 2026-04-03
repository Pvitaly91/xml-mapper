<?php

namespace App\Services\Feeds;

use App\Models\FeedGeneration;
use App\Models\FeedGenerationPreviewLink;
use App\Models\FeedGenerationSmokeCheck;
use App\Models\FeedProfile;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use RuntimeException;

class FeedSmokeCheckService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Kernel $kernel,
        private readonly FeedReleaseAuditService $auditService,
        private readonly PilotNotificationService $notificationService,
        private readonly FeedPreviewLinkService $previewLinkService,
    ) {
    }

    public function run(
        FeedProfile $feedProfile,
        FeedGeneration $generation,
        string $trigger = FeedGenerationSmokeCheck::TRIGGER_AUTOMATIC,
        ?User $user = null,
        ?string $reason = null
    ): FeedGenerationSmokeCheck {
        $url = route('feeds.public', $feedProfile->public_token);

        return $this->runForUrl($feedProfile, $generation, $url, $trigger, $user, $reason, [
            'target' => 'published',
        ]);
    }

    public function runPreview(
        FeedGenerationPreviewLink $previewLink,
        string $trigger = FeedGenerationSmokeCheck::TRIGGER_MANUAL,
        ?User $user = null,
        ?string $reason = null
    ): FeedGenerationSmokeCheck {
        if (! $previewLink->isActive()) {
            throw new RuntimeException('Preview link is expired or revoked.');
        }

        $url = $this->previewLinkService->urlFor($previewLink);
        $smokeCheck = $this->runForUrl(
            $previewLink->feedProfile,
            $previewLink->feedGeneration,
            $url,
            $trigger,
            $user,
            $reason,
            [
                'target' => 'preview',
                'preview_link_id' => $previewLink->id,
            ]
        );

        $previewLink->forceFill([
            'last_smoke_check_status' => $smokeCheck->status,
            'last_smoke_check_at' => $smokeCheck->checked_at,
        ])->save();

        return $smokeCheck;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function runForUrl(
        FeedProfile $feedProfile,
        FeedGeneration $generation,
        string $url,
        string $trigger,
        ?User $user,
        ?string $reason,
        array $meta = []
    ): FeedGenerationSmokeCheck {
        $response = $this->fetch($url);
        $errors = [];
        $warnings = [];
        $body = $response['body'];
        $contentType = $response['content_type'];
        $httpStatus = $response['status'];
        $responseChecksum = $body !== '' ? hash('sha256', $body) : null;
        $offersTotal = null;
        $categoriesTotal = null;

        if ($httpStatus !== 200) {
            $errors[] = sprintf('Expected HTTP 200, got %d.', $httpStatus);
        }

        if ($body === '') {
            $errors[] = 'Published feed body is empty.';
        }

        if (! str_contains(mb_strtolower($contentType ?? ''), 'xml')) {
            $errors[] = 'Published feed Content-Type is not XML.';
        }

        if ($body !== '') {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);

            if ($xml === false) {
                $errors[] = 'Published feed XML is not well-formed.';
            } else {
                $offersTotal = count($xml->shop->offers->offer ?? []);
                $categoriesTotal = count($xml->shop->categories->category ?? []);

                if ($offersTotal <= 0) {
                    $errors[] = 'Published feed contains no offers.';
                }

                if ($categoriesTotal <= 0) {
                    $errors[] = 'Published feed contains no categories.';
                }
            }

            libxml_clear_errors();
            libxml_use_internal_errors(false);
        }

        $expectedOffers = (int) ($generation->meta['summary']['ready'] ?? $generation->valid_items_total);

        if ($offersTotal !== null && $expectedOffers > 0 && $offersTotal !== $expectedOffers) {
            $errors[] = sprintf('Published offers count %d does not match expected %d.', $offersTotal, $expectedOffers);
        }

        if ($generation->checksum !== null && $responseChecksum !== $generation->checksum) {
            $errors[] = 'Published checksum does not match the generation checksum.';
        }

        if ($response['latency_ms'] > (int) config('feed_mediator.smoke_checks.latency_warning_ms')) {
            $warnings[] = sprintf('Published feed latency %d ms exceeds the warning threshold.', $response['latency_ms']);
        }

        $status = $errors !== []
            ? FeedGenerationSmokeCheck::STATUS_FAILED
            : ($warnings !== [] ? FeedGenerationSmokeCheck::STATUS_WARNING : FeedGenerationSmokeCheck::STATUS_OK);

        $smokeCheck = FeedGenerationSmokeCheck::create([
            'shop_id' => $feedProfile->shop_id,
            'feed_profile_id' => $feedProfile->id,
            'feed_generation_id' => $generation->id,
            'user_id' => $user?->id,
            'trigger_source' => $trigger,
            'status' => $status,
            'http_status' => $httpStatus,
            'content_type' => $contentType,
            'latency_ms' => $response['latency_ms'],
            'offers_total' => $offersTotal,
            'categories_total' => $categoriesTotal,
            'response_size_bytes' => strlen($body),
            'response_checksum' => $responseChecksum,
            'expected_checksum' => $generation->checksum,
            'warnings' => $warnings,
            'errors' => $errors,
            'checked_at' => now(),
            'meta' => array_merge([
                'url' => $url,
                'reason' => $reason,
            ], $meta),
        ]);

        $generation->forceFill([
            'last_smoke_check_status' => $status,
            'last_smoke_check_at' => $smokeCheck->checked_at,
            'meta' => array_merge($generation->meta ?? [], [
                'smoke_check' => [
                    'id' => $smokeCheck->id,
                    'status' => $status,
                    'checked_at' => $smokeCheck->checked_at?->toIso8601String(),
                    'latency_ms' => $smokeCheck->latency_ms,
                    'offers_total' => $smokeCheck->offers_total,
                    'categories_total' => $smokeCheck->categories_total,
                    'response_checksum' => $smokeCheck->response_checksum,
                    'errors' => $errors,
                    'warnings' => $warnings,
                ],
            ]),
        ])->save();

        if (in_array($trigger, [FeedGenerationSmokeCheck::TRIGGER_MANUAL, FeedGenerationSmokeCheck::TRIGGER_COMMAND], true)) {
            $this->auditService->record(
                $feedProfile,
                $generation,
                ($meta['target'] ?? 'published') === 'preview' ? 'preview_smoke_check_rerun' : 'smoke_check_rerun',
                $user,
                $reason,
                [
                    'status' => $status,
                    'smoke_check_id' => $smokeCheck->id,
                    'trigger_source' => $trigger,
                    'target' => $meta['target'] ?? 'published',
                ]
            );
        }

        if ($status === FeedGenerationSmokeCheck::STATUS_FAILED) {
            $this->notificationService->notifyFeedProfileAdmins(
                $feedProfile,
                'feed.smoke_check_failed',
                'Published feed smoke check failed',
                'The published feed URL failed post-publish smoke checks.',
                [
                    'generation_id' => $generation->id,
                    'smoke_check_id' => $smokeCheck->id,
                    'errors' => $errors,
                    'target' => $meta['target'] ?? 'published',
                ],
                'error',
                $generation
            );
        }

        return $smokeCheck;
    }

    /**
     * @return array{status:int,content_type:?string,body:string,latency_ms:int}
     */
    private function fetch(string $url): array
    {
        $appUrl = parse_url((string) config('app.url'));
        $parsedUrl = parse_url($url);
        $startedAt = microtime(true);

        $isInternal = ($parsedUrl['host'] ?? null) === ($appUrl['host'] ?? null)
            && ($parsedUrl['scheme'] ?? 'http') === ($appUrl['scheme'] ?? 'http')
            && (($parsedUrl['port'] ?? null) === ($appUrl['port'] ?? null));

        if ($isInternal) {
            $path = ($parsedUrl['path'] ?? '/').(isset($parsedUrl['query']) ? '?'.$parsedUrl['query'] : '');
            $request = Request::create($path, 'GET', [], [], [], [
                'HTTP_HOST' => $parsedUrl['host'] ?? 'localhost',
                'SERVER_PORT' => $parsedUrl['port'] ?? (($parsedUrl['scheme'] ?? 'http') === 'https' ? 443 : 80),
                'HTTPS' => (($parsedUrl['scheme'] ?? 'http') === 'https') ? 'on' : 'off',
            ]);
            $response = $this->kernel->handle($request);
            $body = (string) $response->getContent();

            $result = [
                'status' => $response->getStatusCode(),
                'content_type' => $response->headers->get('Content-Type'),
                'body' => $body,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];

            $this->kernel->terminate($request, $response);

            return $result;
        }

        $response = $this->http
            ->timeout((int) config('feed_mediator.smoke_checks.timeout_seconds'))
            ->get($url);

        return [
            'status' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'body' => $response->body(),
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }
}
