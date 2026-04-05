<?php

namespace App\Services\Access;

class AdminRoutePermissionResolver
{
    public function resolve(?string $routeName, string $method): ?string
    {
        if (! is_string($routeName) || ! str_starts_with($routeName, 'admin.')) {
            return null;
        }

        if ($routeName === 'admin.dashboard' || $routeName === 'admin.logout') {
            return 'dashboard.view';
        }

        if (str_starts_with($routeName, 'admin.access.')) {
            return match (true) {
                str_starts_with($routeName, 'admin.access.compliance') => 'compliance.view',
                str_starts_with($routeName, 'admin.access.approvals.') => 'approvals.review',
                $routeName === 'admin.access.switch-shop' => 'dashboard.view',
                default => in_array($method, ['GET', 'HEAD'], true) ? 'access.view' : 'access.manage',
            };
        }

        if (str_starts_with($routeName, 'admin.onboarding.')) {
            return 'onboarding.manage';
        }

        if ($routeName === 'admin.shop-control.show') {
            return 'launch.view';
        }

        if (str_starts_with($routeName, 'admin.pilot-runs.')) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'pilot.view' : 'pilot.manage';
        }

        if (str_starts_with($routeName, 'admin.merchant-launches.')) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'launch.view' : 'launch.manage';
        }

        if (str_starts_with($routeName, 'admin.notifications.')) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'notifications.view' : 'notifications.manage';
        }

        if (str_starts_with($routeName, 'admin.performance.')) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'ops.view' : 'ops.manage';
        }

        if ($routeName === 'admin.ops.preflight' || $routeName === 'admin.ops.backup-db' || $routeName === 'admin.ops.backup-files' || $routeName === 'admin.ops.prune') {
            return 'maintenance.manage';
        }

        if (str_starts_with($routeName, 'admin.source-connections.rotation')) {
            return 'secrets.manage';
        }

        if (str_starts_with($routeName, 'admin.source-connections.')) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'source.view' : 'source.manage';
        }

        if (str_starts_with($routeName, 'admin.dictionaries.') || str_starts_with($routeName, 'admin.dictionary-imports.')) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'dictionaries.view' : 'dictionaries.manage';
        }

        if (
            str_starts_with($routeName, 'admin.feed-profiles.category-mappings.')
            || str_starts_with($routeName, 'admin.feed-profiles.attribute-mappings.')
            || str_starts_with($routeName, 'admin.feed-profiles.value-mappings.')
            || str_starts_with($routeName, 'admin.feed-profiles.workbench.')
            || str_starts_with($routeName, 'admin.feed-profiles.mapping-presets.')
        ) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'mappings.view' : 'mappings.manage';
        }

        if (str_starts_with($routeName, 'admin.feed-profiles.feed-items.')) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'feed_items.view' : 'feed_items.manage';
        }

        if (str_starts_with($routeName, 'admin.feed-profiles.promotion.')) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'promotion.view' : 'promotion.manage';
        }

        if (
            str_starts_with($routeName, 'admin.feed-profiles.hypercare.')
            || str_starts_with($routeName, 'admin.feed-profiles.feedback.')
            || str_starts_with($routeName, 'admin.feed-profiles.feedback-workbench.')
            || str_starts_with($routeName, 'admin.feed-profiles.alerts.')
            || str_starts_with($routeName, 'admin.feed-profiles.silence.')
        ) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'hypercare.view' : 'hypercare.manage';
        }

        if (
            $routeName === 'admin.feed-profiles.operations.show'
            || str_starts_with($routeName, 'admin.feed-profiles.restore-drill.')
            || $routeName === 'admin.feed-profiles.benchmark'
            || str_starts_with($routeName, 'admin.feed-profiles.performance.')
        ) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'ops.view' : 'ops.manage';
        }

        if (
            $routeName === 'admin.feed-profiles.release-center'
            || $routeName === 'admin.feed-profiles.acceptance.show'
            || $routeName === 'admin.feed-profiles.reconciliation.show'
            || $routeName === 'admin.feed-profiles.reports.reconciliation'
            || $routeName === 'admin.feed-profiles.runbook.show'
            || $routeName === 'admin.feed-profiles.launch-pack.show'
            || str_starts_with($routeName, 'admin.feed-profiles.generations.')
            || $routeName === 'admin.feed-profiles.publish'
            || $routeName === 'admin.feed-profiles.freeze'
            || $routeName === 'admin.feed-profiles.rollback'
            || $routeName === 'admin.feed-profiles.cutover'
        ) {
            if ($routeName === 'admin.feed-profiles.generations.approve' || $routeName === 'admin.feed-profiles.generations.signoff') {
                return 'release.review';
            }

            return in_array($method, ['GET', 'HEAD'], true) ? 'release.view' : 'release.manage';
        }

        if (str_starts_with($routeName, 'admin.feed-profiles.')) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'feed_profiles.view' : 'feed_profiles.manage';
        }

        return 'dashboard.view';
    }
}
