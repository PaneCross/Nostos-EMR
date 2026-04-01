<?php

// ─── DisenrollmentRecord Model ─────────────────────────────────────────────────
// Tracks the transition plan and CMS/SMA notification workflow when a PACE
// participant disenrolls (42 CFR §460.116).
//
// Created automatically by EnrollmentService::disenroll() whenever a participant
// is disenrolled. One record per disenrollment event (a re-enrolled participant
// would get a new record on subsequent disenrollment).
//
// CMS notification requirement:
//   cms_notification_required=true → enrollment coordinator must mark cms_notified_at
//   QaDashboardController checks for overdue (effective_date > 7 days ago, no notified_at)
//
// Transition plan lifecycle:
//   pending → in_progress → completed
//   not_required: for deceased participants (no transition plan needed per CMS guidance)
//   transition_plan_due_date = effective_date + 30 days (set in EnrollmentService)
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class DisenrollmentRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_disenrollment_records';

    // Transition plan status lifecycle
    public const PLAN_PENDING      = 'pending';
    public const PLAN_IN_PROGRESS  = 'in_progress';
    public const PLAN_COMPLETED    = 'completed';
    public const PLAN_NOT_REQUIRED = 'not_required';

    // How many days after effective_date before CMS notification is considered overdue
    public const CMS_NOTIFICATION_OVERDUE_DAYS = 7;

    // How many days from effective_date the transition plan is due (42 CFR §460.116)
    public const TRANSITION_PLAN_DUE_DAYS = 30;

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'created_by_user_id',
        'reason',
        'effective_date',
        'notes',
        'transition_plan_status',
        'transition_plan_text',
        'transition_plan_due_date',
        'transition_plan_completed_date',
        'transition_plan_completed_by_user_id',
        'cms_notification_required',
        'cms_notified_at',
        'cms_notified_by_user_id',
        'cms_notification_notes',
        'providers_notified',
        'providers_notified_at',
    ];

    protected $casts = [
        'effective_date'                    => 'date',
        'transition_plan_due_date'          => 'date',
        'transition_plan_completed_date'    => 'date',
        'cms_notification_required'         => 'boolean',
        'cms_notified_at'                   => 'datetime',
        'providers_notified'                => 'boolean',
        'providers_notified_at'             => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function transitionPlanCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transition_plan_completed_by_user_id');
    }

    public function cmsNotifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cms_notified_by_user_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────────────────

    /** Restrict to a single tenant — always apply before returning records. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Records where CMS notification is required but has not yet been sent,
     * and the effective_date is older than CMS_NOTIFICATION_OVERDUE_DAYS.
     * Used by QaDashboardController to surface the "Pending CMS Disenrollment
     * Notifications" KPI card.
     */
    public function scopePendingCmsNotification($query)
    {
        return $query
            ->where('cms_notification_required', true)
            ->whereNull('cms_notified_at')
            ->whereDate('effective_date', '<', now()->subDays(self::CMS_NOTIFICATION_OVERDUE_DAYS)->toDateString());
    }

    // ─── Business Logic ────────────────────────────────────────────────────────

    /**
     * True when CMS notification is required but not yet recorded,
     * and the effective_date grace period has elapsed.
     * Used by DisenrollmentController to show amber warning in UI.
     */
    public function cmsNotificationPending(): bool
    {
        return $this->cms_notification_required
            && is_null($this->cms_notified_at)
            && $this->effective_date->isPast()
            && $this->effective_date->diffInDays(now()) > self::CMS_NOTIFICATION_OVERDUE_DAYS;
    }

    /**
     * True when the transition plan is due but not yet completed.
     * transition_plan_due_date is nullable (not_required records may omit it).
     */
    public function transitionPlanOverdue(): bool
    {
        if ($this->transition_plan_status === self::PLAN_COMPLETED
            || $this->transition_plan_status === self::PLAN_NOT_REQUIRED) {
            return false;
        }

        return $this->transition_plan_due_date !== null
            && $this->transition_plan_due_date->isPast();
    }

    /** Transition plan status label for display. */
    public function planStatusLabel(): string
    {
        return match ($this->transition_plan_status) {
            self::PLAN_PENDING      => 'Pending',
            self::PLAN_IN_PROGRESS  => 'In Progress',
            self::PLAN_COMPLETED    => 'Completed',
            self::PLAN_NOT_REQUIRED => 'Not Required',
            default                 => ucfirst($this->transition_plan_status),
        };
    }
}
