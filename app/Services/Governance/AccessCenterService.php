<?php

namespace App\Services\Governance;

use App\Models\AdminInvite;
use App\Models\AdminSession;
use App\Models\GovernanceAudit;
use App\Models\ApprovalRequest;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\SourceConnection;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use App\Services\Auth\AdminSessionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AccessCenterService
{
    public function __construct(
        private readonly AdminAccessService $accessService,
        private readonly MembershipService $membershipService,
        private readonly ComplianceReportService $complianceReportService,
        private readonly AdminSessionService $sessionService,
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
        $sessionSubject = $this->sessionSubject($actor, $filters);

        return [
            'currentShop' => $shop,
            'shops' => $shops,
            'memberships' => $shop ? $this->membershipService->membersForShop($shop, $filters, 15) : null,
            'invites' => $this->invites($actor, $shop, $filters),
            'approvals' => $approvals,
            'audits' => $audits,
            'authAudits' => $this->authAudits($actor, $shop, $filters),
            'sessions' => $sessionSubject ? $this->sessionService->listForUser($sessionSubject) : collect(),
            'sessionSubject' => $sessionSubject,
            'suspiciousIpCount' => $sessionSubject ? $this->sessionService->suspiciousIpCount($sessionSubject) : 0,
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

    public function inviteDetail(AdminInvite $invite): AdminInvite
    {
        return $invite->load(['membership.shop', 'user', 'requestedBy']);
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
    public function authAudit(User $actor, ?Shop $shop, array $filters = []): array
    {
        $filters['category'] = GovernanceAudit::CATEGORY_AUTH;

        if ($shop instanceof Shop) {
            $filters['shop_id'] = $shop->id;
        }

        return [
            'audits' => $this->complianceReportService->audits($filters, 25),
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
    private function invites(User $actor, ?Shop $shop, array $filters = []): LengthAwarePaginator
    {
        return AdminInvite::query()
            ->with(['membership.shop', 'user', 'requestedBy'])
            ->when($shop instanceof Shop && ! $this->accessService->isPlatformAdmin($actor), fn ($query) => $query->whereHas('membership', fn ($inner) => $inner->where('shop_id', $shop->id)))
            ->when($shop instanceof Shop && $this->accessService->isPlatformAdmin($actor) && filled($filters['shop_id'] ?? null), fn ($query) => $query->whereHas('membership', fn ($inner) => $inner->where('shop_id', (int) $filters['shop_id'])))
            ->when(filled($filters['invite_status'] ?? null), fn ($query) => $query->where('status', $filters['invite_status']))
            ->latest('id')
            ->paginate(10, ['*'], 'invites')
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
     * @param  array<string, mixed>  $filters
     */
    private function authAudits(User $actor, ?Shop $shop, array $filters = []): LengthAwarePaginator
    {
        $filters = array_merge($filters, [
            'category' => GovernanceAudit::CATEGORY_AUTH,
            'shop_id' => $shop?->id,
        ]);

        if ($shop === null && ! $this->accessService->isPlatformAdmin($actor)) {
            $filters['shop_id'] = $this->accessService->availableShops($actor)->first()?->id;
        }

        return $this->complianceReportService->audits($filters, 10);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function sessionSubject(User $actor, array $filters = []): ?User
    {
        if (filled($filters['session_user_id'] ?? null)) {
            $subject = User::query()->find((int) $filters['session_user_id']);

            if ($subject instanceof User && ($subject->id === $actor->id || $this->accessService->can($actor, 'access.manage'))) {
                return $subject;
            }
        }

        return $actor;
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
