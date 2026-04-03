<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PilotRun extends Model
{
    use HasFactory;

    public const STATE_PLANNED = 'planned';

    public const STATE_STAGING_REHEARSAL_PENDING = 'staging_rehearsal_pending';

    public const STATE_STAGING_REHEARSAL_PASSED = 'staging_rehearsal_passed';

    public const STATE_PROMOTION_PENDING = 'promotion_pending';

    public const STATE_PROMOTION_APPLIED = 'promotion_applied';

    public const STATE_SECRET_REBIND_PENDING = 'secret_rebind_pending';

    public const STATE_SOURCE_VERIFIED = 'source_verified';

    public const STATE_INITIAL_SYNC_COMPLETED = 'initial_sync_completed';

    public const STATE_CANDIDATE_BUILT = 'candidate_built';

    public const STATE_QA_READY = 'qa_ready';

    public const STATE_SIGNOFF_COMPLETED = 'signoff_completed';

    public const STATE_PUBLISH_PENDING = 'publish_pending';

    public const STATE_PUBLISHED = 'published';

    public const STATE_FIRST_PULL_VERIFIED = 'first_pull_verified';

    public const STATE_FEEDBACK_REVIEW_ACTIVE = 'feedback_review_active';

    public const STATE_HYPERCARE_ACTIVE = 'hypercare_active';

    public const STATE_COMPLETED = 'completed';

    public const STATE_BLOCKED = 'blocked';

    public const STATE_FAILED = 'failed';

    public const STATE_ABORTED = 'aborted';

    public const STEP_STAGING_REHEARSAL = 'staging_rehearsal';

    public const STEP_PROMOTION = 'promotion';

    public const STEP_SOURCE_VERIFICATION = 'source_verification';

    public const STEP_SYNC = 'sync';

    public const STEP_CANDIDATE_BUILD = 'candidate_build';

    public const STEP_QA = 'qa';

    public const STEP_SIGNOFF = 'signoff';

    public const STEP_PUBLISH = 'publish';

    public const STEP_RELEASE_VERIFICATION = 'release_verification';

    public const STEP_FEEDBACK = 'feedback';

    public const STEP_HYPERCARE = 'hypercare';

    public const STEP_CLOSEOUT = 'closeout';

    protected $fillable = [
        'shop_id',
        'feed_profile_id',
        'source_connection_id',
        'source_snapshot_id',
        'candidate_generation_id',
        'published_generation_id',
        'initiated_by_user_id',
        'owner_user_id',
        'environment_class',
        'environment_label',
        'state',
        'current_step',
        'blocking_reason',
        'note',
        'summary',
        'meta',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function feedProfile(): BelongsTo
    {
        return $this->belongsTo(FeedProfile::class);
    }

    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }

    public function sourceSnapshot(): BelongsTo
    {
        return $this->belongsTo(PromotionSnapshot::class, 'source_snapshot_id');
    }

    public function candidateGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class, 'candidate_generation_id');
    }

    public function publishedGeneration(): BelongsTo
    {
        return $this->belongsTo(FeedGeneration::class, 'published_generation_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PilotRunEvent::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, self::terminalStates(), true);
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [
            self::STATE_PLANNED,
            self::STATE_STAGING_REHEARSAL_PENDING,
            self::STATE_STAGING_REHEARSAL_PASSED,
            self::STATE_PROMOTION_PENDING,
            self::STATE_PROMOTION_APPLIED,
            self::STATE_SECRET_REBIND_PENDING,
            self::STATE_SOURCE_VERIFIED,
            self::STATE_INITIAL_SYNC_COMPLETED,
            self::STATE_CANDIDATE_BUILT,
            self::STATE_QA_READY,
            self::STATE_SIGNOFF_COMPLETED,
            self::STATE_PUBLISH_PENDING,
            self::STATE_PUBLISHED,
            self::STATE_FIRST_PULL_VERIFIED,
            self::STATE_FEEDBACK_REVIEW_ACTIVE,
            self::STATE_HYPERCARE_ACTIVE,
            self::STATE_COMPLETED,
            self::STATE_BLOCKED,
            self::STATE_FAILED,
            self::STATE_ABORTED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminalStates(): array
    {
        return [
            self::STATE_COMPLETED,
            self::STATE_FAILED,
            self::STATE_ABORTED,
        ];
    }
}
