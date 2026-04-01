<?php

// ─── SignificantChangeEvent Model ─────────────────────────────────────────────
// Tracks significant changes in participant health status that require IDT
// reassessment within 30 days per 42 CFR §460.104(b).
//
// Events are created by:
//   - ProcessHl7AdtJob::handleA01() on hospital admission (trigger_type='hospitalization')
//   - IncidentService::createIncident() for falls with injuries (trigger_type='fall_with_injury')
//   - QA/IDT staff manually via POST /significant-change-events
//
// idt_review_due_date = trigger_date + IDT_REVIEW_DUE_DAYS (30)
//
// Lifecycle: pending → completed (IDT reviewed) or waived (clinical judgment)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class SignificantChangeEvent extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_significant_change_events';

    // ── Constants ─────────────────────────────────────────────────────────────

    /** 42 CFR §460.104(b): IDT must reassess within 30 days of significant change. */
    public const IDT_REVIEW_DUE_DAYS = 30;

    /** Valid trigger types. */
    public const TRIGGER_TYPES = [
        'hospitalization',
        'fall_with_injury',
        'functional_decline',
        'other',
    ];

    /** Human-readable labels for trigger types. */
    public const TRIGGER_TYPE_LABELS = [
        'hospitalization'    => 'Hospitalization',
        'fall_with_injury'   => 'Fall with Injury',
        'functional_decline' => 'Functional Decline',
        'other'              => 'Other Significant Change',
    ];

    /** Valid source systems. */
    public const TRIGGER_SOURCES = ['manual', 'adt_connector', 'incident_service'];

    /** Valid status values. */
    public const STATUSES = ['pending', 'completed', 'waived'];

    // ── Fillable + Casts ──────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id',
        'participant_id',
        'trigger_type',
        'trigger_date',
        'trigger_source',
        'source_incident_id',
        'source_integration_log_id',
        'idt_review_due_date',
        'status',
        'review_completed_at',
        'review_completed_by_user_id',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'trigger_date'        => 'date',
        'idt_review_due_date' => 'date',
        'review_completed_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function sourceIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'source_incident_id');
    }

    public function reviewCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_completed_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** True when this event is still awaiting IDT review. */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** True when idt_review_due_date has passed and status is still pending. */
    public function isOverdue(): bool
    {
        return $this->isPending() && $this->idt_review_due_date->isPast();
    }

    /**
     * Number of days until the IDT review is due.
     * Negative value means the review is already overdue.
     */
    public function daysUntilDue(): int
    {
        // Carbon 3: call from now() to future date for positive "days remaining"
        $days = (int) now()->diffInDays($this->idt_review_due_date, false);
        return $days;
    }

    /** Human-readable label for the trigger type. */
    public function triggerTypeLabel(): string
    {
        return self::TRIGGER_TYPE_LABELS[$this->trigger_type] ?? $this->trigger_type;
    }

    /** Human-readable label for the status. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'   => 'Pending IDT Review',
            'completed' => 'IDT Review Completed',
            'waived'    => 'Waived',
            default     => ucfirst($this->status),
        };
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Filter by tenant. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Only pending (not yet reviewed) events. */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /** Events that are past their due date and still pending. */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'pending')
            ->where('idt_review_due_date', '<', now()->toDateString());
    }

    /** Events due within the next N days (for proactive alerts). */
    public function scopeDueSoon($query, int $days = 7)
    {
        return $query->where('status', 'pending')
            ->whereBetween('idt_review_due_date', [
                now()->toDateString(),
                now()->addDays($days)->toDateString(),
            ]);
    }
}
