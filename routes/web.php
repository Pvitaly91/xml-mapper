<?php

use App\Http\Controllers\Admin\AttributeMappingController;
use App\Http\Controllers\Admin\AttributeMappingSuggestionController;
use App\Http\Controllers\Admin\AccessCenterController;
use App\Http\Controllers\Admin\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Admin\Auth\AdminSecurityController;
use App\Http\Controllers\Admin\CategoryMappingAutomapController;
use App\Http\Controllers\Admin\CategoryMappingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DictionaryController;
use App\Http\Controllers\Admin\DictionaryImportController;
use App\Http\Controllers\Admin\FeedAcceptanceController;
use App\Http\Controllers\Admin\FeedbackImportController;
use App\Http\Controllers\Admin\FeedbackWorkbenchController;
use App\Http\Controllers\Admin\FeedBuildController;
use App\Http\Controllers\Admin\FeedCutoverController;
use App\Http\Controllers\Admin\FeedFirstPullVerificationController;
use App\Http\Controllers\Admin\FeedFreezeController;
use App\Http\Controllers\Admin\FeedGenerationApprovalController;
use App\Http\Controllers\Admin\FeedGenerationCandidateController;
use App\Http\Controllers\Admin\FeedGenerationController;
use App\Http\Controllers\Admin\FeedGenerationPreviewLinkController;
use App\Http\Controllers\Admin\FeedGenerationSignoffController;
use App\Http\Controllers\Admin\FeedHypercareController;
use App\Http\Controllers\Admin\FeedHypercareReportController;
use App\Http\Controllers\Admin\FeedHypercareTimelineController;
use App\Http\Controllers\Admin\FeedItemController;
use App\Http\Controllers\Admin\FeedLaunchPackController;
use App\Http\Controllers\Admin\FeedOperationsController;
use App\Http\Controllers\Admin\FeedProfileController;
use App\Http\Controllers\Admin\FeedProfileStatusController;
use App\Http\Controllers\Admin\FeedPublishController;
use App\Http\Controllers\Admin\FeedQaBundleController;
use App\Http\Controllers\Admin\FeedReconciliationController;
use App\Http\Controllers\Admin\FeedPromotionController;
use App\Http\Controllers\Admin\FeedRehearsalController;
use App\Http\Controllers\Admin\FeedReleaseCenterController;
use App\Http\Controllers\Admin\FeedReleaseReportController;
use App\Http\Controllers\Admin\FeedRestoreDrillController;
use App\Http\Controllers\Admin\FeedReviewNoteController;
use App\Http\Controllers\Admin\FeedRollbackController;
use App\Http\Controllers\Admin\FeedRunbookController;
use App\Http\Controllers\Admin\FeedSmokeCheckController;
use App\Http\Controllers\Admin\MappingPresetController;
use App\Http\Controllers\Admin\MerchantLaunchController;
use App\Http\Controllers\Admin\NotificationCenterController;
use App\Http\Controllers\Admin\OpsMaintenanceController;
use App\Http\Controllers\Admin\OpsAlertController;
use App\Http\Controllers\Admin\OpsSilenceWindowController;
use App\Http\Controllers\Admin\PerformanceCenterController;
use App\Http\Controllers\Admin\PilotRunController;
use App\Http\Controllers\Admin\ShopControlPanelController;
use App\Http\Controllers\Admin\ShopOnboardingController;
use App\Http\Controllers\Admin\SourceConnectionController;
use App\Http\Controllers\Admin\SourceConnectionSecretRotationController;
use App\Http\Controllers\Admin\SourceConnectionTestController;
use App\Http\Controllers\Admin\SourceSyncController;
use App\Http\Controllers\Admin\UnresolvedMappingsWorkbenchController;
use App\Http\Controllers\Admin\ValueMappingController;
use App\Http\Controllers\E2e\E2eSupportController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\FeedPreviewController;
use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/feeds/{token}.xml', [FeedController::class, 'show'])->name('feeds.public');
Route::get('/feeds/previews/{preview_link}/{token}.xml', [FeedPreviewController::class, 'show'])
    ->middleware('signed')
    ->name('feeds.preview');

if (app()->environment(['local', 'testing', 'e2e'])) {
    Route::prefix('__e2e')->name('e2e.')->group(function (): void {
        Route::post('/security/expire-step-up', [E2eSupportController::class, 'expireStepUp'])
            ->middleware('auth')
            ->withoutMiddleware([ValidateCsrfToken::class])
            ->name('security.expire-step-up');
        Route::post('/mock-webhook/{outcome?}', [E2eSupportController::class, 'mockWebhook'])
            ->whereIn('outcome', ['success', 'fail'])
            ->withoutMiddleware([ValidateCsrfToken::class])
            ->name('mock-webhook');
    });
}

Route::prefix('admin')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:admin-login')->name('admin.login.store');
        Route::get('/invites/{token}', [AdminSecurityController::class, 'showInvite'])->name('admin.invites.show');
        Route::post('/invites/{token}', [AdminSecurityController::class, 'acceptInvite'])->name('admin.invites.accept');
    });

    Route::middleware(['auth', 'admin.security'])->name('admin.')->group(function (): void {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
        Route::get('/security/password-reset', [AdminSecurityController::class, 'editPasswordReset'])->name('auth.password-reset.edit');
        Route::put('/security/password-reset', [AdminSecurityController::class, 'updatePassword'])->name('auth.password-reset.update');
        Route::get('/security/mfa/setup', [AdminSecurityController::class, 'showMfaSetup'])->name('auth.mfa.setup');
        Route::post('/security/mfa/setup', [AdminSecurityController::class, 'enableMfa'])->name('auth.mfa.enable');
        Route::get('/security/mfa/challenge', [AdminSecurityController::class, 'showMfaChallenge'])->name('auth.mfa.challenge.create');
        Route::post('/security/mfa/challenge', [AdminSecurityController::class, 'verifyMfaChallenge'])->name('auth.mfa.challenge.store');
        Route::get('/security/reauth/password', [AdminSecurityController::class, 'showPasswordReauth'])->name('auth.reauth.password.create');
        Route::post('/security/reauth/password', [AdminSecurityController::class, 'verifyPasswordReauth'])->name('auth.reauth.password.store');
        Route::get('/security/reauth/mfa', [AdminSecurityController::class, 'showMfaReauth'])->name('auth.reauth.mfa.create');
        Route::post('/security/reauth/mfa', [AdminSecurityController::class, 'verifyMfaReauth'])->name('auth.reauth.mfa.store');
        Route::post('/security/break-glass', [AdminSecurityController::class, 'startBreakGlass'])->name('auth.break-glass.start');
        Route::post('/security/break-glass/end', [AdminSecurityController::class, 'endBreakGlass'])->name('auth.break-glass.end');
    });

    Route::middleware(['auth', 'admin.security', 'can:access-admin', 'admin.shop.context', 'admin.permission'])->name('admin.')->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/access', [AccessCenterController::class, 'index'])->name('access.index');
        Route::post('/access/switch-shop', [AccessCenterController::class, 'switchShop'])->name('access.switch-shop');
        Route::post('/access/memberships', [AccessCenterController::class, 'storeMembership'])->name('access.memberships.store');
        Route::put('/access/memberships/{shop_membership}', [AccessCenterController::class, 'updateMembership'])->name('access.memberships.update');
        Route::post('/access/memberships/{shop_membership}/revoke', [AccessCenterController::class, 'revokeMembership'])->name('access.memberships.revoke');
        Route::post('/access/invites', [AccessCenterController::class, 'storeInvite'])->name('access.invites.store');
        Route::get('/access/invites/{admin_invite}', [AccessCenterController::class, 'showInvite'])->name('access.invites.show');
        Route::post('/access/invites/{admin_invite}/resend', [AccessCenterController::class, 'resendInvite'])->name('access.invites.resend');
        Route::post('/access/invites/{admin_invite}/revoke', [AccessCenterController::class, 'revokeInvite'])->name('access.invites.revoke');
        Route::post('/access/users/{user}/suspend', [AccessCenterController::class, 'suspendUser'])->name('access.users.suspend');
        Route::post('/access/users/{user}/reactivate', [AccessCenterController::class, 'reactivateUser'])->name('access.users.reactivate');
        Route::post('/access/users/{user}/force-password-reset', [AccessCenterController::class, 'forcePasswordReset'])->name('access.users.force-password-reset');
        Route::post('/access/users/{user}/reset-mfa', [AccessCenterController::class, 'resetMfa'])->name('access.users.reset-mfa');
        Route::get('/access/sessions', [AccessCenterController::class, 'sessions'])->name('access.sessions');
        Route::post('/access/sessions/{admin_session}/revoke', [AccessCenterController::class, 'revokeSession'])->name('access.sessions.revoke');
        Route::post('/access/users/{user}/sessions/revoke', [AccessCenterController::class, 'revokeUserSessions'])->name('access.users.sessions.revoke');
        Route::get('/access/auth-audit', [AccessCenterController::class, 'authAudit'])->name('access.auth-audit');
        Route::get('/access/approvals/{approval_request}', [AccessCenterController::class, 'showApproval'])->name('access.approvals.show');
        Route::post('/access/approvals/{approval_request}/approve', [AccessCenterController::class, 'approve'])->name('access.approvals.approve');
        Route::post('/access/approvals/{approval_request}/reject', [AccessCenterController::class, 'reject'])->name('access.approvals.reject');
        Route::get('/access/compliance', [AccessCenterController::class, 'compliance'])->name('access.compliance');
        Route::get('/access/compliance/export', [AccessCenterController::class, 'exportCompliance'])->name('access.compliance.export');
        Route::post('/ops/preflight', [OpsMaintenanceController::class, 'preflight'])->middleware('throttle:admin-sensitive')->name('ops.preflight');
        Route::post('/ops/backup-db', [OpsMaintenanceController::class, 'backupDb'])->middleware('throttle:admin-sensitive')->name('ops.backup-db');
        Route::post('/ops/backup-files', [OpsMaintenanceController::class, 'backupFiles'])->middleware('throttle:admin-sensitive')->name('ops.backup-files');
        Route::post('/ops/prune', [OpsMaintenanceController::class, 'prune'])->middleware('throttle:admin-sensitive')->name('ops.prune');
        Route::get('/onboarding', [ShopOnboardingController::class, 'show'])->name('onboarding.show');
        Route::put('/onboarding/shop', [ShopOnboardingController::class, 'saveShop'])->name('onboarding.shop');
        Route::post('/onboarding/source-driver', [ShopOnboardingController::class, 'selectDriver'])->name('onboarding.source-driver');
        Route::post('/onboarding/default-feed-profile', [ShopOnboardingController::class, 'ensureFeedProfile'])->name('onboarding.feed-profile');
        Route::post('/onboarding/mappings', [ShopOnboardingController::class, 'applyMappings'])->name('onboarding.mappings');
        Route::post('/onboarding/candidate', [ShopOnboardingController::class, 'buildCandidate'])->name('onboarding.candidate');
        Route::post('/onboarding/bootstrap', [ShopOnboardingController::class, 'bootstrap'])->name('onboarding.bootstrap');
        Route::get('/shop/control-panel', [ShopControlPanelController::class, 'show'])->name('shop-control.show');
        Route::get('/pilot-runs', [PilotRunController::class, 'index'])->name('pilot-runs.index');
        Route::post('/pilot-runs', [PilotRunController::class, 'store'])->name('pilot-runs.store');
        Route::get('/pilot-runs/{pilot_run}', [PilotRunController::class, 'show'])->name('pilot-runs.show');
        Route::post('/pilot-runs/{pilot_run}/next', [PilotRunController::class, 'next'])->middleware('throttle:admin-sensitive')->name('pilot-runs.next');
        Route::post('/pilot-runs/{pilot_run}/resume', [PilotRunController::class, 'resume'])->middleware('throttle:admin-sensitive')->name('pilot-runs.resume');
        Route::post('/pilot-runs/{pilot_run}/abort', [PilotRunController::class, 'abort'])->middleware('throttle:admin-sensitive')->name('pilot-runs.abort');
        Route::post('/pilot-runs/{pilot_run}/events', [PilotRunController::class, 'event'])->name('pilot-runs.events.store');
        Route::get('/pilot-runs/{pilot_run}/evidence', [PilotRunController::class, 'evidence'])->name('pilot-runs.evidence');
        Route::get('/pilot-runs/{pilot_run}/reports/{type}', [PilotRunController::class, 'report'])->name('pilot-runs.reports.show');
        Route::get('/merchant-launches', [MerchantLaunchController::class, 'index'])->name('merchant-launches.index');
        Route::post('/merchant-launches', [MerchantLaunchController::class, 'store'])->name('merchant-launches.store');
        Route::get('/merchant-launches/{merchant_launch}', [MerchantLaunchController::class, 'show'])->name('merchant-launches.show');
        Route::post('/merchant-launches/{merchant_launch}/observations', [MerchantLaunchController::class, 'observe'])->name('merchant-launches.observations.store');
        Route::post('/merchant-launches/{merchant_launch}/defects', [MerchantLaunchController::class, 'defect'])->name('merchant-launches.defects.store');
        Route::put('/merchant-launches/{merchant_launch}/defects/{defect}', [MerchantLaunchController::class, 'updateDefect'])->name('merchant-launches.defects.update');
        Route::put('/merchant-launches/{merchant_launch}/baseline', [MerchantLaunchController::class, 'baseline'])->name('merchant-launches.baseline.update');
        Route::post('/merchant-launches/{merchant_launch}/tuning', [MerchantLaunchController::class, 'tuning'])->name('merchant-launches.tuning.store');
        Route::post('/merchant-launches/{merchant_launch}/handover', [MerchantLaunchController::class, 'handover'])->middleware('throttle:admin-sensitive')->name('merchant-launches.handover');
        Route::post('/merchant-launches/{merchant_launch}/close', [MerchantLaunchController::class, 'close'])->middleware('throttle:admin-sensitive')->name('merchant-launches.close');
        Route::get('/merchant-launches/{merchant_launch}/reports/{type}', [MerchantLaunchController::class, 'report'])->name('merchant-launches.reports.show');
        Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/routes', [NotificationCenterController::class, 'storeRoute'])->name('notifications.routes.store');
        Route::put('/notifications/routes/{ops_notification_route}', [NotificationCenterController::class, 'updateRoute'])->name('notifications.routes.update');
        Route::post('/notifications/routes/{ops_notification_route}/test', [NotificationCenterController::class, 'testRoute'])->name('notifications.routes.test');
        Route::post('/notifications/routes/{ops_notification_route}/mute', [NotificationCenterController::class, 'mute'])->name('notifications.routes.mute');
        Route::post('/notifications/test', [NotificationCenterController::class, 'testChannel'])->name('notifications.test');
        Route::get('/notifications/deliveries/{ops_notification_delivery}', [NotificationCenterController::class, 'show'])->name('notifications.deliveries.show');
        Route::post('/notifications/deliveries/{ops_notification_delivery}/retry', [NotificationCenterController::class, 'retry'])->name('notifications.deliveries.retry');
        Route::get('/performance', [PerformanceCenterController::class, 'index'])->name('performance.index');
        Route::post('/performance/bootstrap', [PerformanceCenterController::class, 'bootstrap'])->middleware('throttle:admin-sensitive')->name('performance.bootstrap');
        Route::get('/performance/runs/{performance_run}', [PerformanceCenterController::class, 'show'])->name('performance.show');
        Route::get('/performance/runs/{performance_run}/report', [PerformanceCenterController::class, 'report'])->name('performance.report');

        Route::resource('source-connections', SourceConnectionController::class)->except(['destroy']);
        Route::post('/source-connections/{source_connection}/test', [SourceConnectionTestController::class, 'store'])->middleware('throttle:admin-sensitive')->name('source-connections.test');
        Route::post('/source-connections/{source_connection}/sync', [SourceSyncController::class, 'store'])->middleware('throttle:admin-sensitive')->name('source-connections.sync');
        Route::post('/source-connections/{source_connection}/rotation', [SourceConnectionSecretRotationController::class, 'store'])->middleware('throttle:admin-sensitive')->name('source-connections.rotation');

        Route::resource('feed-profiles', FeedProfileController::class)->except(['destroy']);
        Route::post('/feed-profiles/{feed_profile}/status', [FeedProfileStatusController::class, 'store'])->name('feed-profiles.status');
        Route::post('/feed-profiles/{feed_profile}/build', [FeedBuildController::class, 'store'])->name('feed-profiles.build');
        Route::post('/feed-profiles/{feed_profile}/publish', [FeedPublishController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.publish');
        Route::post('/feed-profiles/{feed_profile}/freeze', [FeedFreezeController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.freeze');
        Route::get('/feed-profiles/{feed_profile}/release-center', [FeedReleaseCenterController::class, 'show'])->name('feed-profiles.release-center');
        Route::get('/feed-profiles/{feed_profile}/promotion', [FeedPromotionController::class, 'show'])->name('feed-profiles.promotion.show');
        Route::post('/feed-profiles/{feed_profile}/promotion/snapshot', [FeedPromotionController::class, 'snapshot'])->middleware('throttle:admin-sensitive')->name('feed-profiles.promotion.snapshot');
        Route::post('/feed-profiles/{feed_profile}/promotion/import', [FeedPromotionController::class, 'import'])->middleware('throttle:admin-sensitive')->name('feed-profiles.promotion.import');
        Route::post('/feed-profiles/{feed_profile}/promotion/compare', [FeedPromotionController::class, 'compare'])->middleware('throttle:admin-sensitive')->name('feed-profiles.promotion.compare');
        Route::post('/feed-profiles/{feed_profile}/promotion/dry-run', [FeedPromotionController::class, 'dryRun'])->middleware('throttle:admin-sensitive')->name('feed-profiles.promotion.dry-run');
        Route::post('/feed-profiles/{feed_profile}/promotion/apply', [FeedPromotionController::class, 'apply'])->middleware('throttle:admin-sensitive')->name('feed-profiles.promotion.apply');
        Route::post('/feed-profiles/{feed_profile}/promotion/runs/{promotion_run}/rollback', [FeedPromotionController::class, 'rollback'])->middleware('throttle:admin-sensitive')->name('feed-profiles.promotion.runs.rollback');
        Route::get('/feed-profiles/{feed_profile}/promotion/runs/{promotion_run}', [FeedPromotionController::class, 'run'])->name('feed-profiles.promotion.runs.show');
        Route::get('/feed-profiles/{feed_profile}/promotion/runs/{promotion_run}/download', [FeedPromotionController::class, 'downloadRun'])->name('feed-profiles.promotion.runs.download');
        Route::get('/feed-profiles/{feed_profile}/promotion/snapshots/{promotion_snapshot}/download', [FeedPromotionController::class, 'downloadSnapshot'])->name('feed-profiles.promotion.snapshots.download');
        Route::get('/feed-profiles/{feed_profile}/hypercare', [FeedHypercareController::class, 'show'])->name('feed-profiles.hypercare.show');
        Route::post('/feed-profiles/{feed_profile}/hypercare/start', [FeedHypercareController::class, 'start'])->middleware('throttle:admin-sensitive')->name('feed-profiles.hypercare.start');
        Route::post('/feed-profiles/{feed_profile}/hypercare/extend', [FeedHypercareController::class, 'extend'])->middleware('throttle:admin-sensitive')->name('feed-profiles.hypercare.extend');
        Route::post('/feed-profiles/{feed_profile}/hypercare/close', [FeedHypercareController::class, 'close'])->middleware('throttle:admin-sensitive')->name('feed-profiles.hypercare.close');
        Route::post('/feed-profiles/{feed_profile}/hypercare/abort', [FeedHypercareController::class, 'abort'])->middleware('throttle:admin-sensitive')->name('feed-profiles.hypercare.abort');
        Route::post('/feed-profiles/{feed_profile}/hypercare/notes', [FeedHypercareController::class, 'note'])->name('feed-profiles.hypercare.note');
        Route::get('/feed-profiles/{feed_profile}/hypercare/timeline', [FeedHypercareTimelineController::class, 'show'])->name('feed-profiles.hypercare.timeline.show');
        Route::get('/feed-profiles/{feed_profile}/hypercare/timeline/download', [FeedHypercareTimelineController::class, 'download'])->name('feed-profiles.hypercare.timeline.download');
        Route::get('/feed-profiles/{feed_profile}/hypercare/digest', [FeedHypercareReportController::class, 'digest'])->name('feed-profiles.hypercare.digest');
        Route::get('/feed-profiles/{feed_profile}/hypercare/handoff', [FeedHypercareReportController::class, 'handoff'])->name('feed-profiles.hypercare.handoff');
        Route::get('/feed-profiles/{feed_profile}/acceptance', [FeedAcceptanceController::class, 'show'])->name('feed-profiles.acceptance.show');
        Route::get('/feed-profiles/{feed_profile}/rehearsal', [FeedRehearsalController::class, 'show'])->name('feed-profiles.rehearsal.show');
        Route::post('/feed-profiles/{feed_profile}/rehearsal', [FeedRehearsalController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.rehearsal.store');
        Route::post('/feed-profiles/{feed_profile}/cutover', [FeedCutoverController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.cutover');
        Route::post('/feed-profiles/{feed_profile}/rollback', [FeedRollbackController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.rollback');
        Route::get('/feed-profiles/{feed_profile}/operations', [FeedOperationsController::class, 'show'])->name('feed-profiles.operations.show');
        Route::post('/feed-profiles/{feed_profile}/restore-drill', [FeedRestoreDrillController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.restore-drill.store');
        Route::get('/feed-profiles/{feed_profile}/restore-drill/{ops_run}', [FeedRestoreDrillController::class, 'show'])->name('feed-profiles.restore-drill.show');
        Route::post('/feed-profiles/{feed_profile}/benchmark', [OpsMaintenanceController::class, 'benchmark'])->middleware('throttle:admin-sensitive')->name('feed-profiles.benchmark');
        Route::post('/feed-profiles/{feed_profile}/performance/benchmark', [PerformanceCenterController::class, 'benchmark'])->middleware('throttle:admin-sensitive')->name('feed-profiles.performance.benchmark');
        Route::get('/feed-profiles/{feed_profile}/reconciliation', [FeedReconciliationController::class, 'show'])->name('feed-profiles.reconciliation.show');
        Route::get('/feed-profiles/{feed_profile}/reports/reconciliation', [FeedReconciliationController::class, 'download'])->name('feed-profiles.reports.reconciliation');
        Route::get('/feed-profiles/{feed_profile}/runbook', [FeedRunbookController::class, 'show'])->name('feed-profiles.runbook.show');
        Route::get('/feed-profiles/{feed_profile}/launch-pack', [FeedLaunchPackController::class, 'show'])->name('feed-profiles.launch-pack.show');
        Route::get('/feed-profiles/{feed_profile}/reports/invalid-items', [FeedReleaseReportController::class, 'invalidItems'])->name('feed-profiles.reports.invalid-items');
        Route::get('/feed-profiles/{feed_profile}/feedback/import', [FeedbackImportController::class, 'create'])->name('feed-profiles.feedback.create');
        Route::post('/feed-profiles/{feed_profile}/feedback/preview', [FeedbackImportController::class, 'preview'])->name('feed-profiles.feedback.preview');
        Route::post('/feed-profiles/{feed_profile}/feedback/import', [FeedbackImportController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.feedback.store');
        Route::get('/feed-profiles/{feed_profile}/feedback-workbench', [FeedbackWorkbenchController::class, 'index'])->name('feed-profiles.feedback-workbench.index');
        Route::put('/feed-profiles/{feed_profile}/feedback-records/{feedback_record}', [FeedbackWorkbenchController::class, 'update'])->name('feed-profiles.feedback-records.update');
        Route::post('/feed-profiles/{feed_profile}/alerts/{ops_alert}/acknowledge', [OpsAlertController::class, 'acknowledge'])->middleware('throttle:admin-sensitive')->name('feed-profiles.alerts.acknowledge');
        Route::post('/feed-profiles/{feed_profile}/alerts/{ops_alert}/resolve', [OpsAlertController::class, 'resolve'])->middleware('throttle:admin-sensitive')->name('feed-profiles.alerts.resolve');
        Route::post('/feed-profiles/{feed_profile}/alerts/{ops_alert}/false-positive', [OpsAlertController::class, 'falsePositive'])->middleware('throttle:admin-sensitive')->name('feed-profiles.alerts.false-positive');
        Route::post('/feed-profiles/{feed_profile}/silence', [OpsSilenceWindowController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.silence.store');
        Route::post('/feed-profiles/{feed_profile}/silence/clear', [OpsSilenceWindowController::class, 'clear'])->middleware('throttle:admin-sensitive')->name('feed-profiles.silence.clear');
        Route::get('/feed-profiles/{feed_profile}/generations/{feed_generation}', [FeedGenerationController::class, 'show'])->name('feed-profiles.generations.show');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/candidate', [FeedGenerationCandidateController::class, 'store'])->name('feed-profiles.generations.candidate');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/approve', [FeedGenerationApprovalController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.generations.approve');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/signoff', [FeedGenerationSignoffController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.generations.signoff');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/notes', [FeedReviewNoteController::class, 'store'])->name('feed-profiles.generations.notes');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/preview-links', [FeedGenerationPreviewLinkController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.generations.preview-links.store');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/preview-links/{feed_generation_preview_link}/revoke', [FeedGenerationPreviewLinkController::class, 'revoke'])->middleware('throttle:admin-sensitive')->name('feed-profiles.generations.preview-links.revoke');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/preview-links/{feed_generation_preview_link}/smoke-check', [FeedGenerationPreviewLinkController::class, 'smokeCheck'])->middleware('throttle:admin-sensitive')->name('feed-profiles.generations.preview-links.smoke-check');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/smoke-check', [FeedSmokeCheckController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.generations.smoke-check');
        Route::post('/feed-profiles/{feed_profile}/generations/{feed_generation}/first-pull-verify', [FeedFirstPullVerificationController::class, 'store'])->middleware('throttle:admin-sensitive')->name('feed-profiles.generations.first-pull-verify');
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
