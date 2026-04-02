<?php

namespace App\Providers;

use App\Contracts\Feeds\FeedBuildServiceInterface;
use App\Contracts\Feeds\FeedPublishServiceInterface;
use App\Contracts\Dictionaries\KastaDictionaryImportServiceInterface;
use App\Contracts\Mappings\AttributeMappingServiceInterface;
use App\Contracts\Mappings\CategoryMappingServiceInterface;
use App\Contracts\Mappings\ValueMappingServiceInterface;
use App\Contracts\Source\ProductNormalizerInterface;
use App\Contracts\Source\PromYmlParserInterface;
use App\Contracts\Source\SourceImportServiceInterface;
use App\Contracts\Validation\ValidationServiceInterface;
use App\Models\User;
use App\Services\Feeds\FeedBuildService;
use App\Services\Feeds\FeedPublishService;
use App\Services\Dictionaries\KastaDictionaryImportService;
use App\Services\Mappings\AttributeMappingService;
use App\Services\Mappings\CategoryMappingService;
use App\Services\Mappings\ValueMappingService;
use App\Services\Source\ProductNormalizer;
use App\Services\Source\PromYmlParser;
use App\Services\Source\SourceImportService;
use App\Services\Ops\HeartbeatService;
use App\Services\Validation\ValidationService;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SourceImportServiceInterface::class, SourceImportService::class);
        $this->app->bind(PromYmlParserInterface::class, PromYmlParser::class);
        $this->app->bind(ProductNormalizerInterface::class, ProductNormalizer::class);
        $this->app->bind(CategoryMappingServiceInterface::class, CategoryMappingService::class);
        $this->app->bind(AttributeMappingServiceInterface::class, AttributeMappingService::class);
        $this->app->bind(ValueMappingServiceInterface::class, ValueMappingService::class);
        $this->app->bind(ValidationServiceInterface::class, ValidationService::class);
        $this->app->bind(FeedBuildServiceInterface::class, FeedBuildService::class);
        $this->app->bind(FeedPublishServiceInterface::class, FeedPublishService::class);
        $this->app->bind(KastaDictionaryImportServiceInterface::class, KastaDictionaryImportService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('access-admin', fn (User $user): bool => $user->isAdmin());

        Authenticate::redirectUsing(static fn () => route('login'));
        RedirectIfAuthenticated::redirectUsing(static fn () => route('admin.dashboard'));

        Queue::looping(static function (): void {
            app(HeartbeatService::class)->recordWorkerHeartbeat();
        });
    }
}
