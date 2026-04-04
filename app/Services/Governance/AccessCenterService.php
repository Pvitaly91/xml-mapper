<?php

namespace App\Services\Governance;

use App\Models\ApprovalRequest;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\SourceConnection;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AccessCenterService
{
    public function __construct(
        private readonly AdminAccessService $accessService,
        private readonly MembershipService $membershipService,
        private readonly ComplianceReportService $complianceReportService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(User $actor, ?Shop $currentShop, array $filters = []): array
    {
        $shops = $this->accessService->availableShops($actor);
        $shop = $currentShop ?: $shops->first();
        $approvals = $this->approvalQueue($actor, $shop, $filters);
        $audits = $this->recentAudits($actor, $shop, $filters);

        return [
            'currentShop' => $shop,
            'shops' => $shops,
            'memberships' => $shop ? $this->membershipService->membersForShop($shop, $filters, 15) : null,
            'approvals' => $approvals,
            'audits' => $audits,
            'roles' => ShopMembership::roles(),
            'statuses' => ShopMembership::statuses(),
            'secretState' => $shop ? $this->secretState($shop) : collect(),
            'users' => User::query()->orderBy('name')->limit(100)->get(['id', 'name', 'email']),
        ];
    }

    public function approvalDetail(ApprovalRequest $approvalRequest): ApprovalRequest
    {
        return $approvalRequest->load(['shop', 'requestedBy', 'approvedBy', 'target', 'audits.user']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function compliance(User $actor, ?Shop $shop, array $filters = []): array
    {
        if ($shop instanceof Shop) {
            $filters['shop_id'] = $shop->id;
        }

        return [
            'audits' => $this->complianceReportService->audits($filters, 25),
            'approvals' => $this->complianceReportService->approvals($filters, 25),
            'shops' => $this->accessService->availableShops($actor),
            'users' => User::query()->orderBy('name')->limit(100)->get(['id', 'name', 'email']),
            'filters' => $filters,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function approvalQueue(User $actor, ?Shop $shop, array $filters = []): LengthAwarePaginator
    {
        return ApprovalRequest::query()
            ->with(['shop', 'requestedBy', 'approvedBy'])
            ->when($shop instanceof Shop && ! $this->accessService->isPlatformAdmin($actor), fn ($query) => $query->where('shop_id', $shop->id))
            ->when($shop instanceof Shop && $this->accessService->isPlatformAdmin($actor) && filled($filters['shop_id'] ?? null), fn ($query) => $query->where('shop_id', (int) $filters['shop_id']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->latest('id')
            ->paginate(15, ['*'], 'approvals')
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function recentAudits(User $actor, ?Shop $shop, array $filters = []): LengthAwarePaginator
    {
        $filters = array_merge($filters, [
            'shop_id' => $shop?->id,
        ]);

        if ($shop === null && ! $this->accessService->isPlatformAdmin($actor)) {
            $filters['shop_id'] = $this->accessService->availableShops($actor)->first()?->id;
        }

        return $this->complianceReportService->audits($filters, 15);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function secretState(Shop $shop): Collection
    {
        return SourceConnection::query()
            ->where('shop_id', $shop->id)
            ->orderBy('name')
            ->get()
            ->map(fn (SourceConnection $connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'driver' => $connection->driver,
                'masked_secret' => $connection->usesPromApi() ? ($connection->maskedApiToken() ?: 'not configured') : 'credentials configured',
                'secret_state' => $connection->promotionSecretState(),
                'rebind_required' => $connection->promotionSecretRebindRequired(),
                'last_validated_at' => $connection->last_connection_check_at,
            ]);
    }
}
