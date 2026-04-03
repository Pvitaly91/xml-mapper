<?php

use App\Http\Controllers\Admin\AttributeMappingController;
use App\Http\Controllers\Admin\AttributeMappingSuggestionController;
use App\Http\Controllers\Admin\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Admin\CategoryMappingAutomapController;
use App\Http\Controllers\Admin\CategoryMappingController;
use App\Http\Controllers\Admin\DictionaryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DictionaryImportController;
use App\Http\Controllers\Admin\FeedBuildController;
use App\Http\Controllers\Admin\FeedGenerationApprovalController;
use App\Http\Controllers\Admin\FeedGenerationCandidateController;
use App\Http\Controllers\Admin\FeedGenerationController;
use App\Http\Controllers\Admin\FeedGenerationPreviewLinkController;
use App\Http\Controllers\Admin\FeedItemController;
use App\Http\Controllers\Admin\FeedAcceptanceController;
use App\Http\Controllers\Admin\FeedFreezeController;
use App\Http\Controllers\Admin\MappingPresetController;
use App\Http\Controllers\Admin\ShopControlPanelController;
use App\Http\Controllers\Admin\ShopOnboardingController;
use App\Http\Controllers\Admin\FeedProfileController;
use App\Http\Controllers\Admin\FeedProfileStatusController;
use App\Http\Controllers\Admin\FeedPublishController;
use App\Http\Controllers\Admin\FeedQaBundleController;
use App\Http\Controllers\Admin\FeedReleaseCenterController;
use App\Http\Controllers\Admin\FeedReleaseReportController;
use App\Http\Controllers\Admin\FeedReviewNoteController;
use App\Http\Controllers\Admin\FeedRollbackController;
use App\Http\Controllers\Admin\FeedGenerationSignoffController;
use App\Http\Controllers\Admin\FeedSmokeCheckController;
use App\Http\Controllers\Admin\SourceConnectionController;
use App\Http\Controllers\Admin\SourceConnectionTestController;
use App\Http\Controllers\Admin\SourceSyncController;
use App\Http\Controllers\Admin\UnresolvedMappingsWorkbenchController;
use App\Http\Controllers\Admin\ValueMappingController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\FeedPreviewController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/feeds/{token}.xml', [FeedController::class, 'show'])->name('feeds.public');
Route::get('/feeds/previews/{preview_link}/{token}.xml', [FeedPreviewController::class, 'show'])
    ->middleware('signed')
    ->name('feeds.preview');

Route::prefix('admin')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('admin.login.store');
    });

    Route::middleware(['auth', 'can:access-admin'])->name('admin.')->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
        Route::get('/onboarding', [ShopOnboardingController::class, 'show'])->name('onboarding.show');
        Route::put('/onboarding/shop', [ShopOnboardingController::class, 'saveShop'])->name('onboarding.shop');
        Route::post('/onboarding/source-driver', [ShopOnboardingController::class, 'selectDriver'])->name('onboarding.source-driver');
        Route::post('/onboarding/default-feed-profile', [ShopOnboardingController::class, 'ensureFeedProfile'])->name('onboarding.feed-profile');
        Route::post('/onboarding/mappings', [ShopOnboardingController::class, 'applyMappings'])->name('onboarding.mappings');
        Route::post('/onboarding/candidate', [ShopOnboardingController::class, 'buildCandidate'])->name('onboarding.candidate');
        Route::post('/onboarding/bootstrap', [ShopOnboardingController::class, 'bootstrap'])->name('onboarding.bootstrap');
        Route::get('/shop/control-panel', [ShopControlPanelController::class, 'show'])->name('shop-control.show');

        Route::resource('source-connections', SourceConnectionController::class)->except(['destroy']);
        Route::post('/source-connections/{source_connection}/test', [SourceConnectionTestController::class, 'store'])->name('source-connections.test');
        Route::post('/source-connections/{source_connection}/sync', [SourceSyncController::class, 'store'])->name('source-connections.sync');

        Route::resource('feed-profiles', FeedProfileController::class)->except(['destroy']);
        Route::post('/feed-profiles/{feed_profile}/status', [FeedProfileStatusController::class, 'store'])->name('feed-profiles.status');
        Route::post('/feed-profiles/{feed_profile}/build', [FeedBuildController::class, 'store'])->name('feed-profiles.build');
        Route::post('/feed-profiles/{feed_profile}/publish', [FeedPublishController::class, 'store'])->name('feed-profiles.publish');
        Route::post('/feed-profiles/{feed_profile}/freeze', [FeedFreezeController::class, 'store'])->name('feed-profiles.freeze');
        Route::get('/feed-profiles/{feed_profile}/release-center', [FeedReleaseCenterController::class, 'show'])->name('feed-profiles.release-center');
        Route::get('/feed-profiles/{feed_profile}/acceptance', [FeedAcceptanceController::class, 'show'])->name('feed-profiles.acceptance.show');
        Route::post('/feed-profiles/{feed_profile}/rollback', [FeedRollbackController::class, 'store'])->name('feed-profiles.rollback');
        Route::get('/feed-profiles/{feed_profile}/reports/invalid-items', [FeedReleaseReportController::class, 'invalidItems'])->name('feed-profiles.reports.invalid-items');
        Route::get('/feed-profiles/{feed_profile}/generations/{feed_generation}', [FeedGenerationController::class, 'show'])->name('feed-profiles.generations.show');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/candidate', [FeedGenerationCandidateController::class, 'store'])->name('feed-profiles.generations.candidate');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/approve', [FeedGenerationApprovalController::class, 'store'])->name('feed-profiles.generations.approve');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/signoff', [FeedGenerationSignoffController::class, 'store'])->name('feed-profiles.generations.signoff');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/notes', [FeedReviewNoteController::class, 'store'])->name('feed-profiles.generations.notes');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/preview-links', [FeedGenerationPreviewLinkController::class, 'store'])->name('feed-profiles.generations.preview-links.store');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/preview-links/{feed_generation_preview_link}/revoke', [FeedGenerationPreviewLinkController::class, 'revoke'])->name('feed-profiles.generations.preview-links.revoke');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/preview-links/{feed_generation_preview_link}/smoke-check', [FeedGenerationPreviewLinkController::class, 'smokeCheck'])->name('feed-profiles.generations.preview-links.smoke-check');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/smoke-check', [FeedSmokeCheckController::class, 'store'])->name('feed-profiles.generations.smoke-check');
        Route::get('/feed-profiles/{feed_profile}/generations/{feed_generation}/qa-bundle', [FeedQaBundleController::class, 'show'])->name('feed-profiles.generations.qa-bundle');
        Route::get('/feed-profiles/{feed_profile}/generations/{feed_generation}/reports/diff', [FeedReleaseReportController::class, 'diff'])->name('feed-profiles.generations.reports.diff');
        Route::get('/feed-profiles/{feed_profile}/generations/{feed_generation}/reports/readiness', [FeedReleaseReportController::class, 'readiness'])->name('feed-profiles.generations.reports.readiness');
        Route::get('/feed-profiles/{feed_profile}/workbench', [UnresolvedMappingsWorkbenchController::class, 'index'])->name('feed-profiles.workbench.index');
        Route::post('/feed-profiles/{feed_profile}/workbench/suggestions', [UnresolvedMappingsWorkbenchController::class, 'applySuggestions'])->name('feed-profiles.workbench.suggestions');
        Route::post('/feed-profiles/{feed_profile}/workbench/value-suggestions', [UnresolvedMappingsWorkbenchController::class, 'applyValueSuggestions'])->name('feed-profiles.workbench.value-suggestions');
        Route::post('/feed-profiles/{feed_profile}/workbench/bulk/confirm', [UnresolvedMappingsWorkbenchController::class, 'confirmBulk'])->name('feed-profiles.workbench.bulk-confirm');
        Route::post('/feed-profiles/{feed_profile}/workbench/bulk/execute', [UnresolvedMappingsWorkbenchController::class, 'executeBulk'])->name('feed-profiles.workbench.bulk-execute');
        Route::get('/feed-profiles/{feed_profile}/mapping-presets/import', [MappingPresetController::class, 'importForm'])->name('feed-profiles.mapping-presets.import');
        Route::get('/feed-profiles/{feed_profile}/mapping-presets/export', [MappingPresetController::class, 'export'])->name('feed-profiles.mapping-presets.export');
        Route::post('/feed-profiles/{feed_profile}/mapping-presets/preview', [MappingPresetController::class, 'preview'])->name('feed-profiles.mapping-presets.preview');
        Route::post('/feed-profiles/{feed_profile}/mapping-presets/import', [MappingPresetController::class, 'store'])->name('feed-profiles.mapping-presets.store');

        Route::get('/dictionaries', [DictionaryController::class, 'index'])->name('dictionaries.index');
        Route::post('/dictionaries/import', [DictionaryController::class, 'import'])->name('dictionaries.import');
        Route::get('/dictionaries/categories', [DictionaryController::class, 'categories'])->name('dictionaries.categories');
        Route::get('/dictionaries/attributes', [DictionaryController::class, 'attributes'])->name('dictionaries.attributes');
        Route::get('/dictionaries/values', [DictionaryController::class, 'values'])->name('dictionaries.values');
        Route::get('/dictionaries/size-grids', [DictionaryController::class, 'sizeGrids'])->name('dictionaries.size-grids');
        Route::get('/dictionaries/imports', [DictionaryImportController::class, 'index'])->name('dictionary-imports.index');
        Route::post('/dictionaries/imports', [DictionaryImportController::class, 'store'])->name('dictionary-imports.store');
        Route::post('/dictionaries/imports/reimport', [DictionaryImportController::class, 'reimport'])->name('dictionary-imports.reimport');
        Route::get('/dictionaries/imports/{dictionary_import}', [DictionaryImportController::class, 'show'])->name('dictionary-imports.show');

        Route::get('/feed-profiles/{feed_profile}/category-mappings', [CategoryMappingController::class, 'index'])->name('feed-profiles.category-mappings.index');
        Route::post('/feed-profiles/{feed_profile}/category-mappings', [CategoryMappingController::class, 'store'])->name('feed-profiles.category-mappings.store');
        Route::put('/feed-profiles/{feed_profile}/category-mappings/{category_mapping}', [CategoryMappingController::class, 'update'])->name('feed-profiles.category-mappings.update');
        Route::delete('/feed-profiles/{feed_profile}/category-mappings/{category_mapping}', [CategoryMappingController::class, 'destroy'])->name('feed-profiles.category-mappings.destroy');
        Route::post('/feed-profiles/{feed_profile}/category-mappings/{category_mapping}/deactivate', [CategoryMappingController::class, 'deactivate'])->name('feed-profiles.category-mappings.deactivate');
        Route::post('/feed-profiles/{feed_profile}/category-mappings/automap', [CategoryMappingAutomapController::class, 'store'])->name('feed-profiles.category-mappings.automap');

        Route::get('/feed-profiles/{feed_profile}/attribute-mappings', [AttributeMappingController::class, 'index'])->name('feed-profiles.attribute-mappings.index');
        Route::post('/feed-profiles/{feed_profile}/attribute-mappings', [AttributeMappingController::class, 'store'])->name('feed-profiles.attribute-mappings.store');
        Route::put('/feed-profiles/{feed_profile}/attribute-mappings/{attribute_mapping}', [AttributeMappingController::class, 'update'])->name('feed-profiles.attribute-mappings.update');
        Route::delete('/feed-profiles/{feed_profile}/attribute-mappings/{attribute_mapping}', [AttributeMappingController::class, 'destroy'])->name('feed-profiles.attribute-mappings.destroy');
        Route::post('/feed-profiles/{feed_profile}/attribute-mappings/suggestions', [AttributeMappingSuggestionController::class, 'store'])->name('feed-profiles.attribute-mappings.suggestions');

        Route::get('/feed-profiles/{feed_profile}/value-mappings', [ValueMappingController::class, 'index'])->name('feed-profiles.value-mappings.index');
        Route::post('/feed-profiles/{feed_profile}/attribute-mappings/{attribute_mapping}/value-mappings', [ValueMappingController::class, 'store'])->name('feed-profiles.value-mappings.store');
        Route::put('/feed-profiles/{feed_profile}/attribute-mappings/{attribute_mapping}/value-mappings/{value_mapping}', [ValueMappingController::class, 'update'])->name('feed-profiles.value-mappings.update');
        Route::delete('/feed-profiles/{feed_profile}/attribute-mappings/{attribute_mapping}/value-mappings/{value_mapping}', [ValueMappingController::class, 'destroy'])->name('feed-profiles.value-mappings.destroy');
        Route::post('/feed-profiles/{feed_profile}/attribute-mappings/{attribute_mapping}/value-mappings/suggestions', [ValueMappingController::class, 'approveSuggestions'])->name('feed-profiles.value-mappings.suggestions');

        Route::get('/feed-profiles/{feed_profile}/feed-items', [FeedItemController::class, 'index'])->name('feed-profiles.feed-items.index');
        Route::post('/feed-profiles/{feed_profile}/feed-items/bulk', [FeedItemController::class, 'bulkUpdate'])->name('feed-profiles.feed-items.bulk');
        Route::get('/feed-profiles/{feed_profile}/feed-items/{feed_item}', [FeedItemController::class, 'show'])->name('feed-profiles.feed-items.show');
        Route::put('/feed-profiles/{feed_profile}/feed-items/{feed_item}/override', [FeedItemController::class, 'override'])->name('feed-profiles.feed-items.override');
    });
});
