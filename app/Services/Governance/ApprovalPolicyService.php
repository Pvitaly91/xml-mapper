<?php

namespace App\Services\Governance;

use App\Models\ApprovalRequest;
use App\Models\Shop;
use App\Services\Ops\EnvironmentContextService;
use RuntimeException;

class ApprovalPolicyService
{
    public const ACTION_RELEASE_FORCE_PUBLISH = 'release.force_publish';
    public const ACTION_RELEASE_ROLLBACK = 'release.rollback';
    public const ACTION_RELEASE_FREEZE = 'release.freeze_toggle';
    public const ACTION_PROMOTION_APPLY = 'promotion.apply';
    public const ACTION_PROMOTION_ROLLBACK = 'promotion.rollback';
    public const ACTION_SECRET_REBIND = 'source.secret_rebind';
    public const ACTION_SECRET_ROTATION = 'source.secret_rotation';
    public const ACTION_EMERGENCY_TUNING = 'launch.emergency_tuning';
    public const ACTION_LAUNCH_CLOSE_OVERRIDE = 'launch.close_override';
    public const ACTION_SILENCE_CRITICAL = 'ops.silence_critical';
    public const ACTION_PRUNE = 'ops.prune';

    public function __construct(
        private readonly EnvironmentContextService $environmentContextService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function rule(string $action, ?Shop $shop = null): array
    {
        $environment = $this->environmentContextService->summary();

        $definitions = [
            self::ACTION_RELEASE_FORCE_PUBLISH => [
                'classification' => ApprovalRequest::CLASS_HIGH_RISK,
                'permission' => 'release.manage',
                'platform_admin_only' => false,
                'approval_required' => $environment['is_production'],
                'four_eyes' => $environment['is_production'],
                'ttl_minutes' => 240,
            ],
            self::ACTION_RELEASE_ROLLBACK => [
                'classification' => ApprovalRequest::CLASS_HIGH_RISK,
                'permission' => 'release.manage',
                'platform_admin_only' => false,
                'approval_required' => $environment['is_production'],
                'four_eyes' => $environment['is_production'],
                'ttl_minutes' => 240,
            ],
            self::ACTION_RELEASE_FREEZE => [
                'classification' => ApprovalRequest::CLASS_SENSITIVE,
                'permission' => 'release.manage',
                'platform_admin_only' => false,
                'approval_required' => $environment['is_production'],
                'four_eyes' => false,
                'ttl_minutes' => 180,
            ],
            self::ACTION_PROMOTION_APPLY => [
                'classification' => ApprovalRequest::CLASS_HIGH_RISK,
                'permission' => 'promotion.manage',
                'platform_admin_only' => false,
                'approval_required' => $environment['is_production'],
                'four_eyes' => $environment['is_production'],
                'ttl_minutes' => 240,
            ],
            self::ACTION_PROMOTION_ROLLBACK => [
                'classification' => ApprovalRequest::CLASS_HIGH_RISK,
                'permission' => 'promotion.manage',
                'platform_admin_only' => false,
                'approval_required' => $environment['is_production'],
                'four_eyes' => $environment['is_production'],
                'ttl_minutes' => 240,
            ],
            self::ACTION_SECRET_REBIND => [
                'classification' => ApprovalRequest::CLASS_HIGH_RISK,
                'permission' => 'secrets.manage',
                'platform_admin_only' => false,
                'approval_required' => $environment['is_production'],
                'four_eyes' => $environment['is_production'],
                'ttl_minutes' => 120,
            ],
            self::ACTION_SECRET_ROTATION => [
                'classification' => ApprovalRequest::CLASS_HIGH_RISK,
                'permission' => 'secrets.manage',
                'platform_admin_only' => false,
                'approval_required' => $environment['is_production'],
                'four_eyes' => $environment['is_production'],
                'ttl_minutes' => 120,
            ],
            self::ACTION_EMERGENCY_TUNING => [
                'classification' => ApprovalRequest::CLASS_HIGH_RISK,
                'permission' => 'launch.manage',
                'platform_admin_only' => false,
                'approval_required' => $environment['is_production'],
                'four_eyes' => $environment['is_production'],
                'ttl_minutes' => 180,
            ],
            self::ACTION_LAUNCH_CLOSE_OVERRIDE => [
                'classification' => ApprovalRequest::CLASS_HIGH_RISK,
                'permission' => 'launch.manage',
                'platform_admin_only' => false,
                'approval_required' => true,
                'four_eyes' => true,
                'ttl_minutes' => 240,
            ],
            self::ACTION_SILENCE_CRITICAL => [
                'classification' => ApprovalRequest::CLASS_HIGH_RISK,
                'permission' => 'ops.manage',
                'platform_admin_only' => false,
                'approval_required' => $environment['is_production'],
                'four_eyes' => $environment['is_production'],
                'ttl_minutes' => 120,
            ],
            self::ACTION_PRUNE => [
                'classification' => ApprovalRequest::CLASS_HIGH_RISK,
                'permission' => 'ops.manage',
                'platform_admin_only' => true,
                'approval_required' => $environment['is_production'],
                'four_eyes' => $environment['is_production'],
                'ttl_minutes' => 120,
            ],
        ];

        if (! array_key_exists($action, $definitions)) {
            throw new RuntimeException('Unknown approval action ['.$action.'].');
        }

        return array_merge($definitions[$action], [
            'environment_class' => $environment['class'],
            'environment_label' => $environment['label'],
        ]);
    }
}
