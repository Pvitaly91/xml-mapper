<?php

use App\Http\Controllers\Admin\AttributeMappingController;
use App\Http\Controllers\Admin\AttributeMappingSuggestionController;
use App\Http\Controllers\Admin\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Admin\CategoryMappingAutomapController;
use App\Http\Controllers\Admin\CategoryMappingController;
use App\Http\Controllers\Admin\DictionaryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeedBuildController;
use App\Http\Controllers\Admin\FeedItemController;
use App\Http\Controllers\Admin\FeedProfileController;
use App\Http\Controllers\Admin\FeedProfileStatusController;
use App\Http\Controllers\Admin\FeedPublishController;
use App\Http\Controllers\Admin\SourceConnectionController;
use App\Http\Controllers\Admin\SourceSyncController;
use App\Http\Controllers\Admin\ValueMappingController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/feeds/{token}.xml', [FeedController::class, 'show'])->name('feeds.public');

Route::prefix('admin')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('admin.login.store');
    });

    Route::middleware(['auth', 'can:access-admin'])->name('admin.')->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

        Route::resource('source-connections', SourceConnectionController::class)->except(['destroy']);
        Route::post('/source-connections/{source_connection}/sync', [SourceSyncController::class, 'store'])->name('source-connections.sync');

        Route::resource('feed-profiles', FeedProfileController::class)->except(['destroy']);
        Route::post('/feed-profiles/{feed_profile}/status', [FeedProfileStatusController::class, 'store'])->name('feed-profiles.status');
        Route::post('/feed-profiles/{feed_profile}/build', [FeedBuildController::class, 'store'])->name('feed-profiles.build');
        Route::post('/feed-profiles/{feed_profile}/publish', [FeedPublishController::class, 'store'])->name('feed-profiles.publish');

        Route::get('/dictionaries', [DictionaryController::class, 'index'])->name('dictionaries.index');
        Route::post('/dictionaries/import', [DictionaryController::class, 'import'])->name('dictionaries.import');
        Route::get('/dictionaries/categories', [DictionaryController::class, 'categories'])->name('dictionaries.categories');
        Route::get('/dictionaries/attributes', [DictionaryController::class, 'attributes'])->name('dictionaries.attributes');
        Route::get('/dictionaries/values', [DictionaryController::class, 'values'])->name('dictionaries.values');
        Route::get('/dictionaries/size-grids', [DictionaryController::class, 'sizeGrids'])->name('dictionaries.size-grids');

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
