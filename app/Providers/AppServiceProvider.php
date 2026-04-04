<?php

namespace App\Providers;

use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Contracts\Mappings\AttributeMappingServiceInterface;
use App\Contracts\Mappings\CategoryMappingServiceInterface;
use App\Contracts\Mappings\ValueMappingServiceInterface;
use App\Contracts\Source\ProductNormalizerInterface;
use App\Contracts\Source\PromApiClientInterface;
use App\Contracts\Source\PromYmlParserInterface;
use App\Contracts\Source\SourceConnectionTestServiceInterface;
use App\Contracts\Source\SourceImportServiceInterface;
use App\Contracts\Source\SourceSyncWorkflowServiceInterface;
use App\Contracts\Validation\ValidationServiceInterface;
use App\Models\User;
use App\Services\Access\AdminAccessService;
use App\Services\Admin\CurrentAdminShopResolver;
use App\Services\Governance\GovernedActionRegistry;
use App\Services\Governance\Handlers\CriticalSilenceWindowActionHandler;
use App\Services\Governance\Handlers\EmergencyTuningActionHandler;
use App\Services\Governance\Handlers\ForcePublishActionHandler;
use App\Services\Governance\Handlers\FreezeToggleActionHandler;
use App\Services\Governance\Handlers\LaunchCloseOverrideActionHandler;
use App\Services\Governance\Handlers\PromotionApplyActionHandler;
use App\Services\Governance\Handlers\PromotionRollbackActionHandler;
use App\Services\Governance\Handlers\PruneActionHandler;
use App\Services\Governance\Handlers\RollbackActionHandler;
use App\Services\Governance\Handlers\SecretRebindActionHandler;
use App\Services\Governance\Handlers\SecretRotationActionHandler;
use App\Services\Governance\ApprovalPolicyService;
use App\Services\Dictionaries\KastaDictionaryImportService;
use App\Services\Feeds\FeedBuildService;
use App\Services\Feeds\FeedPublishService;
use App\Services\Mappings\AttributeMappingService;
use App\Services\Mappings\CategoryMappingService;
use App\Services\Mappings\ValueMappingService;
use App\Services\Ops\CorrelationContext;
use App\Services\Ops\EnvironmentContextService;
use App\Services\Ops\HeartbeatService;
use App\Services\Source\Drivers\PromApiSourceDriver;
use App\Services\Source\Drivers\PromYmlSourceDriver;
use App\Services\Source\ProductNormalizer;
use App\Services\Source\PromApiClient;
use App\Services\Source\PromYmlParser;
use App\Services\Source\SourceConnectionTestService;
use App\Services\Source\SourceDriverRegistry;
use App\Services\Source\SourceImportService;
use App\Services\Source\SourceSyncWorkflowService;
use App\Services\Validation\ValidationService;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CorrelationContext::class);
        $this->app->singleton(GovernedActionRegistry::class, function ($app): GovernedActionRegistry {
            return new GovernedActionRegistry([
                ApprovalPolicyService::ACTION_RELEASE_FORCE_PUBLISH => $app->make(ForcePublishActionHandler::class),
                ApprovalPolicyService::ACTION_RELEASE_ROLLBACK => $app->make(RollbackActionHandler::class),
                ApprovalPolicyService::ACTION_RELEASE_FREEZE => $app->make(FreezeToggleActionHandler::class),
                ApprovalPolicyService::ACTION_PROMOTION_APPLY => $app->make(PromotionApplyActionHandler::class),
                ApprovalPolicyService::ACTION_PROMOTION_ROLLBACK => $app->make(PromotionRollbackActionHandler::class),
                ApprovalPolicyService::ACTION_SECRET_REBIND => $app->make(SecretRebindActionHandler::class),
                ApprovalPolicyService::ACTION_SECRET_ROTATION => $app->make(SecretRotationActionHandler::class),
                ApprovalPolicyService::ACTION_EMERGENCY_TUNING => $app->make(EmergencyTuningActionHandler::class),
                ApprovalPolicyService::ACTION_LAUNCH_CLOSE_OVERRIDE => $app->make(LaunchCloseOverrideActionHandler::class),
                ApprovalPolicyService::ACTION_SILENCE_CRITICAL => $app->make(CriticalSilenceWindowActionHandler::class),
                ApprovalPolicyService::ACTION_PRUNE => $app->make(PruneActionHandler::class),
            ]);
        });
        $this->app->bind(SourceImportServiceInterface::class, SourceImportService::class);
        $this->app->bind(PromYmlParserInterface::class, PromYmlParser::class);
        $this->app->bind(PromApiClientInterface::class, PromApiClient::class);
        $this->app->bind(SourceConnectionTestServiceInterface::class, SourceConnectionTestService::class);
        $this->app->bind(SourceSyncWorkflowServiceInterface::class, SourceSyncWorkflowService::class);
        $this->app->bind(ProductNormalizerInterface::class, ProductNormalizer::class);
        $this->app->bind(CategoryMappingServiceInterface::class, CategoryMappingService::class);
        $this->app->bind(AttributeMappingServiceInterface::class, AttributeMappingService::class);
        $this->app->bind(ValueMappingServiceInterface::class, ValueMappingService::class);
        $this->app->bind(ValidationServiceInterface::class, ValidationService::class);
        $this->app->bind(FeedBuildServiceInterface::class, FeedBuildService::class);
        $this->app->bind(FeedPublishServiceInterface::class, FeedPublishService::class);
        $this->app->bind(KastaDictionaryImportServiceInterface::class, KastaDictionaryImportService::class);

        $this->app->singleton(SourceDriverRegistry::class, function ($app): SourceDriverRegistry {
            return new SourceDriverRegistry([
                $app->make(PromYmlSourceDriver::class),
                $app->make(PromApiSourceDriver::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-admin', fn (User $user): bool => app(AdminAccessService::class)->canAccessAdmin($user));

        Authenticate::redirectUsing(static fn () => route('login'));
        RedirectIfAuthenticated::redirectUsing(static fn () => route('admin.dashboard'));

        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute((int) config('feed_mediator.security.rate_limits.admin_login_per_minute', 5))
                ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('admin-sensitive', function (Request $request): Limit {
            return Limit::perMinute((int) config('feed_mediator.security.rate_limits.admin_sensitive_per_minute', 20))
                ->by((string) ($request->user()?->id ?: $request->ip()));
        });

        Queue::looping(static function (): void {
            app(HeartbeatService::class)->recordWorkerHeartbeat();
        });

        View::composer('*', static function ($view): void {
            $view->with('appEnvironment', app(EnvironmentContextService::class)->summary());
        });

        View::composer('layouts.admin', static function ($view): void {
            $request = request();
            $user = $request?->user();

            if (! $user instanceof User) {
                return;
            }

            $accessService = app(AdminAccessService::class);
            $shopResolver = app(CurrentAdminShopResolver::class);
            $currentShop = $request->attributes->get('admin_shop');
            $currentRole = $accessService->roleFor($user, $currentShop);

            if (! $currentShop) {
                $currentShop = $shopResolver->resolve($request);
                $currentRole = $accessService->roleFor($user, $currentShop);
            }

            $nav = collect([
                ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'active' => 'admin.dashboard', 'visible' => $accessService->can($user, 'dashboard.view', $currentShop)],
                ['label' => 'Onboarding', 'route' => 'admin.onboarding.show', 'active' => 'admin.onboarding.*', 'visible' => $accessService->can($user, 'onboarding.manage', $currentShop)],
                ['label' => 'Go-Live Control', 'route' => 'admin.shop-control.show', 'active' => 'admin.shop-control.*', 'visible' => $currentShop && $accessService->can($user, 'launch.view', $currentShop)],
                ['label' => 'Pilot Center', 'route' => 'admin.pilot-runs.index', 'active' => 'admin.pilot-runs.*', 'visible' => $currentShop && $accessService->can($user, 'pilot.view', $currentShop)],
                ['label' => 'Launch Center', 'route' => 'admin.merchant-launches.index', 'active' => 'admin.merchant-launches.*', 'visible' => $currentShop && $accessService->can($user, 'launch.view', $currentShop)],
                ['label' => 'Notification Center', 'route' => 'admin.notifications.index', 'active' => 'admin.notifications.*', 'visible' => $currentShop && $accessService->can($user, 'notifications.view', $currentShop)],
                ['label' => 'Source Connections', 'route' => 'admin.source-connections.index', 'active' => 'admin.source-connections.*', 'visible' => $currentShop && $accessService->can($user, 'source.view', $currentShop)],
                ['label' => 'Feed Profiles', 'route' => 'admin.feed-profiles.index', 'active' => 'admin.feed-profiles.*', 'visible' => $currentShop && $accessService->can($user, 'feed_profiles.view', $currentShop)],
                ['label' => 'Kasta Dictionaries', 'route' => 'admin.dictionaries.index', 'active' => 'admin.dictionaries.*|admin.dictionary-imports.*', 'visible' => $accessService->can($user, 'dictionaries.view', $currentShop) || $accessService->can($user, 'dictionaries.manage', $currentShop)],
                ['label' => 'Access Center', 'route' => 'admin.access.index', 'active' => 'admin.access.*', 'visible' => $accessService->can($user, 'access.view', $currentShop) || $accessService->can($user, 'compliance.view', $currentShop) || $accessService->can($user, 'approvals.review', $currentShop)],
            ])->filter(fn (array $item) => $item['visible'])->values()->all();

            $view->with('adminLayout', [
                'currentShop' => $currentShop,
                'availableShops' => $accessService->availableShops($user),
                'currentRoleLabel' => $currentRole ? $accessService->labelForRole($currentRole) : null,
                'security' => [
                    'account_state' => $user->account_state,
                    'mfa_status' => $user->mfaStatus(),
                    'break_glass_expires_at' => session('admin_auth.break_glass.expires_at'),
                ],
                'nav' => $nav,
            ]);
        });
    }
}
