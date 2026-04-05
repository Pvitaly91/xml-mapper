<?php

namespace App\Services\Demo;

use App\Actions\Admin\Shops\BootstrapShopForPilotAction;
use App\Models\AdminInvite;
use App\Models\FeedProfile;
use App\Models\Shop;
use App\Models\ShopMembership;
use App\Models\SourceConnection;
use App\Models\User;
use App\Services\Auth\AdminInvitationService;
use App\Services\Auth\AdminMfaService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class E2eDemoBootstrapService
{
    private const MAIN_SHOP_SLUG = 'e2e-main-shop';
    private const SECONDARY_SHOP_SLUG = 'e2e-secondary-shop';
    private const PLATFORM_ADMIN_EMAIL = 'platform-admin@e2e.test';
    private const REVIEWER_EMAIL = 'reviewer@e2e.test';
    private const OPERATOR_EMAIL = 'operator@e2e.test';
    private const INVITED_SHOP_ADMIN_EMAIL = 'invited-shop-admin@e2e.test';
    private const PLATFORM_ADMIN_PASSWORD = 'PlatformAdminPass123!';
    private const REVIEWER_PASSWORD = 'ReviewerPass123!';
    private const OPERATOR_PASSWORD = 'OperatorPass123!';
    private const SOURCE_CODE = 'e2e-prom-yml';

    public function __construct(
        private readonly BootstrapShopForPilotAction $bootstrapAction,
        private readonly AdminInvitationService $invitationService,
        private readonly AdminMfaService $mfaService,
    ) {}

    /**
     * @return array{manifest_path:string,summary_path:string,manifest:array<string,mixed>,summary:array<string,mixed>}
     */
    public function bootstrap(bool $fresh = false): array
    {
        $this->ensureAllowedEnvironment();

        if ($fresh) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        $this->prepareSupportFiles();

        $mainShop = $this->upsertShop([
            'name' => 'E2E Demo Shop',
            'slug' => self::MAIN_SHOP_SLUG,
        ]);
        $secondaryShop = $this->upsertShop([
            'name' => 'E2E Secondary Shop',
            'slug' => self::SECONDARY_SHOP_SLUG,
        ]);

        $platformAdmin = $this->upsertAdminUser(
            self::PLATFORM_ADMIN_EMAIL,
            'E2E Platform Admin',
            self::PLATFORM_ADMIN_PASSWORD,
            null,
            User::STATE_ACTIVE
        );
        $this->grantMembership($platformAdmin, null, ShopMembership::ROLE_PLATFORM_ADMIN);

        $reviewer = $this->upsertAdminUser(
            self::REVIEWER_EMAIL,
            'E2E Reviewer',
            self::REVIEWER_PASSWORD,
            $mainShop,
            User::STATE_ACTIVE
        );
        $this->grantMembership($reviewer, $mainShop, ShopMembership::ROLE_REVIEWER);
        $this->grantMembership($reviewer, $secondaryShop, ShopMembership::ROLE_REVIEWER);

        $operator = $this->upsertAdminUser(
            self::OPERATOR_EMAIL,
            'E2E Operator',
            self::OPERATOR_PASSWORD,
            $mainShop,
            User::STATE_ACTIVE
        );
        $this->grantMembership($operator, $mainShop, ShopMembership::ROLE_OPERATOR);

        $sourceConnection = SourceConnection::query()->updateOrCreate(
            [
                'shop_id' => $mainShop->id,
                'code' => self::SOURCE_CODE,
            ],
            [
                'name' => 'E2E Prom YML',
                'driver' => SourceConnection::DRIVER_PROM_YML,
                'status' => SourceConnection::STATUS_ACTIVE,
                'source_url' => base_path('tests/Fixtures/prom_sample.yml'),
                'credentials' => [
                    'login' => 'demo-merchant-login',
                    'password' => 'demo-merchant-password',
                ],
                'sync_interval_minutes' => 60,
                'last_connection_check_status' => SourceConnection::CHECK_STATUS_OK,
                'last_connection_check_message' => 'Fixture-backed connection is ready for E2E verification.',
                'last_connection_check_at' => now(),
                'last_sync_status' => SourceConnection::CHECK_STATUS_OK,
                'last_sync_message' => 'Fixture sync prepared.',
                'last_synced_at' => now(),
                'next_sync_at' => now()->addHour(),
            ]
        );

        $bootstrapSummary = $this->bootstrapAction->bootstrap($operator->fresh(), true, true);
        $feedProfile = FeedProfile::query()
            ->where('shop_id', $mainShop->id)
            ->where('source_connection_id', $sourceConnection->id)
            ->latest('id')
            ->first();

        if (! $feedProfile instanceof FeedProfile) {
            throw new RuntimeException('E2E feed profile bootstrap did not create a feed profile.');
        }

        $invite = $this->issueShopAdminInvite($platformAdmin->fresh(), $mainShop);
        $platformAdminMfa = $this->ensureMfaEnabled($platformAdmin->fresh());
        $reviewerMfa = $this->ensureMfaEnabled($reviewer->fresh());

        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'app_url' => config('app.url'),
            'environment' => [
                'app_env' => app()->environment(),
                'governance_env' => config('feed_mediator.environment.class'),
                'label' => config('feed_mediator.environment.label'),
            ],
            'shops' => [
                'main' => [
                    'id' => $mainShop->id,
                    'name' => $mainShop->name,
                    'slug' => $mainShop->slug,
                ],
                'secondary' => [
                    'id' => $secondaryShop->id,
                    'name' => $secondaryShop->name,
                    'slug' => $secondaryShop->slug,
                ],
            ],
            'users' => [
                'platform_admin' => [
                    'email' => $platformAdmin->email,
                    'password' => self::PLATFORM_ADMIN_PASSWORD,
                    'totp_secret' => $platformAdminMfa['secret'],
                ],
                'reviewer' => [
                    'email' => $reviewer->email,
                    'password' => self::REVIEWER_PASSWORD,
                    'totp_secret' => $reviewerMfa['secret'],
                ],
                'operator' => [
                    'email' => $operator->email,
                    'password' => self::OPERATOR_PASSWORD,
                ],
                'invited_shop_admin' => [
                    'email' => self::INVITED_SHOP_ADMIN_EMAIL,
                    'accept_url' => route('admin.invites.show', ['token' => $invite->token_ciphertext]),
                ],
            ],
            'entities' => [
                'source_connection' => [
                    'id' => $sourceConnection->id,
                    'name' => $sourceConnection->name,
                    'code' => $sourceConnection->code,
                    'driver' => $sourceConnection->driver,
                ],
                'feed_profile' => [
                    'id' => $feedProfile->id,
                    'name' => $feedProfile->name,
                    'code' => $feedProfile->code,
                ],
                'latest_generation_id' => $feedProfile->latestGeneration?->id,
                'published_generation_id' => $feedProfile->publishedGeneration?->id,
            ],
            'paths' => [
                'login' => route('login'),
                'dashboard' => route('admin.dashboard'),
                'access_center' => route('admin.access.index'),
                'sessions' => route('admin.access.sessions'),
                'notifications' => route('admin.notifications.index'),
                'onboarding' => route('admin.onboarding.show'),
                'source_connection_show' => route('admin.source-connections.show', $sourceConnection),
                'source_connection_edit' => route('admin.source-connections.edit', $sourceConnection),
                'feed_profile_show' => route('admin.feed-profiles.show', $feedProfile),
                'release_center' => route('admin.feed-profiles.release-center', $feedProfile),
                'acceptance' => route('admin.feed-profiles.acceptance.show', $feedProfile),
                'launch_center' => route('admin.merchant-launches.index'),
                'pilot_center' => route('admin.pilot-runs.index'),
            ],
            'fixtures' => [
                'prom_yml_path' => base_path('tests/Fixtures/prom_sample.yml'),
                'pilot_manifest_path' => base_path('database/samples/pilot/manifest.json'),
                'feedback_csv_path' => base_path('database/samples/pilot/feedback/feedback-sample.csv'),
                'feedback_json_path' => base_path('database/samples/pilot/feedback/feedback-sample.json'),
                'mock_webhook_success' => rtrim((string) config('app.url'), '/').'/__e2e/mock-webhook/success',
                'mock_webhook_fail' => rtrim((string) config('app.url'), '/').'/__e2e/mock-webhook/fail',
            ],
        ];

        $summary = [
            'generated_at' => $manifest['generated_at'],
            'manifest_path' => $this->manifestPath(),
            'shops' => $manifest['shops'],
            'users' => [
                'platform_admin' => ['email' => $platformAdmin->email],
                'reviewer' => ['email' => $reviewer->email],
                'operator' => ['email' => $operator->email],
                'invited_shop_admin' => ['email' => self::INVITED_SHOP_ADMIN_EMAIL],
            ],
            'entities' => $manifest['entities'],
            'paths' => $manifest['paths'],
            'bootstrap' => $bootstrapSummary,
        ];

        File::put($this->manifestPath(), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        File::put($this->summaryPath(), json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'manifest_path' => $this->manifestPath(),
            'summary_path' => $this->summaryPath(),
            'manifest' => $manifest,
            'summary' => $summary,
        ];
    }

    public function manifestPath(): string
    {
        return storage_path('app/e2e/demo-manifest.json');
    }

    public function summaryPath(): string
    {
        return storage_path('app/e2e/demo-summary.json');
    }

    private function ensureAllowedEnvironment(): void
    {
        if (! app()->environment(['local', 'testing', 'e2e'])) {
            throw new RuntimeException('demo:bootstrap-e2e is only allowed in local, e2e, or testing environments.');
        }
    }

    private function prepareSupportFiles(): void
    {
        File::ensureDirectoryExists(dirname($this->manifestPath()));
        File::put(storage_path('app/e2e/mock-webhook.jsonl'), '');
    }

    /**
     * @param  array{name:string,slug:string}  $payload
     */
    private function upsertShop(array $payload): Shop
    {
        return Shop::query()->updateOrCreate(
            ['slug' => $payload['slug']],
            [
                'name' => $payload['name'],
                'currency' => 'UAH',
                'locale' => 'uk',
                'timezone' => 'Europe/Kiev',
                'is_active' => true,
            ]
        );
    }

    private function upsertAdminUser(
        string $email,
        string $name,
        string $password,
        ?Shop $shop,
        string $accountState,
    ): User {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'shop_id' => $shop?->id,
                'name' => $name,
                'password' => Hash::make($password),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'account_state' => $accountState,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'password_reset_required_at' => null,
            ]
        );
    }

    private function grantMembership(User $user, ?Shop $shop, string $role, string $status = ShopMembership::STATUS_ACTIVE): ShopMembership
    {
        return ShopMembership::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'shop_id' => $shop?->id,
            ],
            [
                'role' => $role,
                'status' => $status,
                'updated_by_user_id' => $user->id,
            ]
        );
    }

    private function issueShopAdminInvite(User $actor, Shop $shop): AdminInvite
    {
        $payload = [
            'email' => self::INVITED_SHOP_ADMIN_EMAIL,
            'name' => 'Invited Shop Admin',
            'role' => ShopMembership::ROLE_SHOP_ADMIN,
            'shop_id' => $shop->id,
            'note' => 'E2E security onboarding invite.',
            'allow_existing' => true,
        ];
        $existing = User::query()->where('email', self::INVITED_SHOP_ADMIN_EMAIL)->first();

        if ($existing instanceof User) {
            $existing->forceFill([
                'shop_id' => $shop->id,
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'account_state' => User::STATE_INVITED,
                'password' => Hash::make(Str::random(48)),
                'password_reset_required_at' => null,
                'mfa_secret' => null,
                'mfa_pending_secret' => null,
                'mfa_recovery_codes' => null,
                'mfa_enabled_at' => null,
                'mfa_last_verified_at' => null,
                'invite_accepted_at' => null,
            ])->save();
        }

        $issued = $this->invitationService->createInvite($payload, $actor);

        return $issued['invite'];
    }

    /**
     * @return array{secret:string}
     */
    private function ensureMfaEnabled(User $user): array
    {
        $this->mfaService->reset($user);
        $setup = $this->mfaService->beginEnrollment($user->fresh());
        $this->mfaService->confirmEnrollment(
            $user->fresh(),
            $this->mfaService->currentCode($setup['secret'])
        );

        return ['secret' => $setup['secret']];
    }
}
