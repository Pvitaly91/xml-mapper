<?php

namespace App\Services\Pilot;

use App\Models\PilotRun;

class PilotRunStateMachine
{
    /**
     * @return array<string, string>
     */
    public function stateLabels(): array
    {
        return [
            PilotRun::STATE_PLANNED => 'Planned',
            PilotRun::STATE_STAGING_REHEARSAL_PENDING => 'Staging rehearsal pending',
            PilotRun::STATE_STAGING_REHEARSAL_PASSED => 'Staging rehearsal passed',
            PilotRun::STATE_PROMOTION_PENDING => 'Promotion pending',
            PilotRun::STATE_PROMOTION_APPLIED => 'Promotion applied',
            PilotRun::STATE_SECRET_REBIND_PENDING => 'Secret rebind pending',
            PilotRun::STATE_SOURCE_VERIFIED => 'Source verified',
            PilotRun::STATE_INITIAL_SYNC_COMPLETED => 'Initial sync completed',
            PilotRun::STATE_CANDIDATE_BUILT => 'Candidate built',
            PilotRun::STATE_QA_READY => 'QA ready',
            PilotRun::STATE_SIGNOFF_COMPLETED => 'Sign-off completed',
            PilotRun::STATE_PUBLISH_PENDING => 'Publish pending',
            PilotRun::STATE_PUBLISHED => 'Published',
            PilotRun::STATE_FIRST_PULL_VERIFIED => 'First-pull verified',
            PilotRun::STATE_FEEDBACK_REVIEW_ACTIVE => 'Feedback review active',
            PilotRun::STATE_HYPERCARE_ACTIVE => 'Hypercare active',
            PilotRun::STATE_COMPLETED => 'Completed',
            PilotRun::STATE_BLOCKED => 'Blocked',
            PilotRun::STATE_FAILED => 'Failed',
            PilotRun::STATE_ABORTED => 'Aborted',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function stepLabels(): array
    {
        return [
            PilotRun::STEP_STAGING_REHEARSAL => 'Run staging rehearsal',
            PilotRun::STEP_PROMOTION => 'Run promotion dry-run and apply',
            PilotRun::STEP_SOURCE_VERIFICATION => 'Verify rebound source access',
            PilotRun::STEP_SYNC => 'Run initial source sync',
            PilotRun::STEP_CANDIDATE_BUILD => 'Build candidate generation',
            PilotRun::STEP_QA => 'Prepare QA bundle and preview',
            PilotRun::STEP_SIGNOFF => 'Complete sign-off and approval',
            PilotRun::STEP_PUBLISH => 'Publish candidate',
            PilotRun::STEP_RELEASE_VERIFICATION => 'Verify smoke and first-pull',
            PilotRun::STEP_FEEDBACK => 'Import and review feedback',
            PilotRun::STEP_HYPERCARE => 'Enter hypercare monitoring',
            PilotRun::STEP_CLOSEOUT => 'Close hypercare and complete pilot',
        ];
    }

    /**
     * @return array{step:string,label:string}|null
     */
    public function nextStepForState(string $state): ?array
    {
        $step = match ($state) {
            PilotRun::STATE_PLANNED,
            PilotRun::STATE_STAGING_REHEARSAL_PENDING => PilotRun::STEP_STAGING_REHEARSAL,
            PilotRun::STATE_STAGING_REHEARSAL_PASSED,
            PilotRun::STATE_PROMOTION_PENDING => PilotRun::STEP_PROMOTION,
            PilotRun::STATE_PROMOTION_APPLIED,
            PilotRun::STATE_SECRET_REBIND_PENDING => PilotRun::STEP_SOURCE_VERIFICATION,
            PilotRun::STATE_BLOCKED => null,
            PilotRun::STATE_SOURCE_VERIFIED => PilotRun::STEP_SYNC,
            PilotRun::STATE_INITIAL_SYNC_COMPLETED => PilotRun::STEP_CANDIDATE_BUILD,
            PilotRun::STATE_CANDIDATE_BUILT => PilotRun::STEP_QA,
            PilotRun::STATE_QA_READY => PilotRun::STEP_SIGNOFF,
            PilotRun::STATE_SIGNOFF_COMPLETED,
            PilotRun::STATE_PUBLISH_PENDING => PilotRun::STEP_PUBLISH,
            PilotRun::STATE_PUBLISHED => PilotRun::STEP_RELEASE_VERIFICATION,
            PilotRun::STATE_FIRST_PULL_VERIFIED => PilotRun::STEP_FEEDBACK,
            PilotRun::STATE_FEEDBACK_REVIEW_ACTIVE => PilotRun::STEP_HYPERCARE,
            PilotRun::STATE_HYPERCARE_ACTIVE => PilotRun::STEP_CLOSEOUT,
            default => null,
        };

        if ($step === null) {
            return null;
        }

        return [
            'step' => $step,
            'label' => $this->stepLabels()[$step],
        ];
    }

    public function defaultStepForState(string $state): ?string
    {
        return $this->nextStepForState($state)['step'] ?? null;
    }

    public function labelForState(string $state): string
    {
        return $this->stateLabels()[$state] ?? $state;
    }

    public function labelForStep(?string $step): ?string
    {
        return $step === null ? null : ($this->stepLabels()[$step] ?? $step);
    }
}
