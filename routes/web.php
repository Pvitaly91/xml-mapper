<?php

use App\Http\Controllers\Admin\FeedBuildController;
use App\Http\Controllers\Admin\SourceSyncController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/feeds/{token}.xml', [FeedController::class, 'show'])->name('feeds.public');

Route::prefix('admin')->group(function (): void {
    Route::post('/sources/{id}/sync', [SourceSyncController::class, 'store'])->name('admin.sources.sync');
    Route::post('/feeds/{id}/build', [FeedBuildController::class, 'store'])->name('admin.feeds.build');
});
