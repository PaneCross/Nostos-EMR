<?php

// ─── Incident Model ────────────────────────────────────────────────────────────
// Represents an adverse event or safety incident for a PACE participant.
//
// CMS Rule: RCA (Root Cause Analysis) is mandatory for high-severity incident
// types (falls, medication errors, elopements, hospitalizations, ER visits,
// abuse/neglect). The rca_required flag is auto-set by IncidentService on create.
// Incidents with rca_required=true CANNOT be closed until rca_completed=true.
//
// Status lifecycle:
//   open → under_review → rca_in_progress → closed
//   (Any non-closed status can also move directly to closed if no RCA needed)
//
// cms_reportable is set by QA admin and triggers an HPMS reporting task.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Incident extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_incidents';

    // ── Constants ─────────────────────────────────────────────────────────────

    /** All valid incident_type values (W4-6 adds 'unexpected_death'). */
    public const TYPES = [
        'fall',
        'medication_error',
        'elopement',
        'injury',
        'behavioral',
        'hospitalization',
        'er_visit',
        'infection',
        'abuse_neglect',
        'complaint',
        'unexpected_death',
        'other',
    ];

    /** Human-readable labels for incident types. */
    public const TYPE_LABELS = [
        'fall'              => 'Fall',
        'medication_error'  => 'Medication Error',
        'elopement'         => 'Elopement',
        'injury'            => 'Injury (Other)',
        'behavioral'        => 'Behavioral Incident',
        'hospitalization'   => 'Hospitalization',
        'er_visit'          => 'Emergency Room Visit',
        'infection'         => 'Infection',
        'abuse_neglect'     => 'Abuse / Neglect',
        'complaint'         => 'Grievance / Complaint',
        'unexpected_death'  => 'Unexpected Death',
        'other'             => 'Other',
    ];

    /**
     * Incident types that CMS/PACE regulations require a Root Cause Analysis for.
     * Any incident of these types must have rca_required=true.
     * Source: CMS PACE regulations 42 CFR 460.136.
     */
    public const RCA_REQUIRED_TYPES = [
        'fall',
        'medication_error',
        'elopement',
        'hospitalization',
        'er_visit',
        'abuse_neglect',
        'unexpected_death',
    ];

    /**
     * Incident types that require CMS and SMA notification within 72 hours.
     * W4-6: IncidentService auto-sets cms_notification_required=true for these.
     * Source: 42 CFR §460.136.
     */
    public const CMS_NOTIFICATION_TYPES = [
        'abuse_neglect',
        'hospitalization',
        'er_visit',
        'unexpected_death',
    ];

    /** Hours after occurred_at within which CMS/SMA must be notified. */
    public const CMS_NOTIFICATION_DEADLINE_HOURS = 72;

    /** All valid workflow status values. */
    public const STATUSES = ['open', 'under_review', 'rca_in_progress', 'closed'];

    /** Human-readable labels for statuses. */
    public const STATUS_LABELS = [
        'open'           => 'Open',
        'under_review'   => 'Under Review',
        'rca_in_progress'=> 'RCA In Progress',
        'closed'         => 'Closed',
    ];

    // ── Fillable + Casts ──────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id',
        'participant_id',
        'incident_type',
        'occurred_at',
        'location_of_incident',
        'reported_by_user_id',
        'reported_at',
        'description',
        'immediate_actions_taken',
        'injuries_sustained',
        'injury_description',
        'witnesses',
        'rca_required',
        'rca_completed',
        'rca_text',
        'rca_completed_by_user_id',
        'cms_reportable',
        'cms_reported_at',
        // W4-6 CMS/SMA notification tracking (auto-set by IncidentService — never from UI)
        'cms_notification_required',
        'cms_notification_sent_at',
        'sma_notification_sent_at',
        'notification_notes',
        'regulatory_deadline',
        'status',
    ];

    protected $casts = [
        'occurred_at'                => 'datetime',
        'reported_at'                => 'datetime',
        'cms_reported_at'            => 'datetime',
        'cms_notification_sent_at'   => 'datetime',
        'sma_notification_sent_at'   => 'datetime',
        'regulatory_deadline'        => 'datetime',
        'injuries_sustained'         => 'boolean',
        'rca_required'               => 'boolean',
        'rca_completed'              => 'boolean',
        'cms_reportable'             => 'boolean',
        'cms_notification_required'  => 'boolean',
        'witnesses'                  => 'array',
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

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function rcaCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rca_completed_by_user_id');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** True if this incident type legally requires a root cause analysis. */
    public function requiresRca(): bool
    {
        return in_array($this->incident_type, self::RCA_REQUIRED_TYPES, true);
    }

    /** True when the incident is in a terminal state (no further status changes). */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /** True when all blocking conditions for closure are met. */
    public function canClose(): bool
    {
        if ($this->isClosed()) {
            return false;
        }
        // RCA-required incidents must complete RCA before closing
        if ($this->rca_required && ! $this->rca_completed) {
            return false;
        }
        return true;
    }

    /** Human-readable label for the current status. */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Human-readable label for the incident type. */
    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->incident_type] ?? $this->incident_type;
    }

    /** True when this incident requires CMS/SMA notification per 42 CFR §460.136. */
    public function requiresCmsNotification(): bool
    {
        return in_array($this->incident_type, self::CMS_NOTIFICATION_TYPES, true);
    }

    /**
     * True when CMS notification is required but overdue (past regulatory_deadline
     * and neither cms_notification_sent_at nor sma_notification_sent_at is set).
     */
    public function isCmsNotificationOverdue(): bool
    {
        if (! $this->cms_notification_required) {
            return false;
        }
        if ($this->cms_notification_sent_at !== null) {
            return false;
        }
        return $this->regulatory_deadline !== null && $this->regulatory_deadline->isPast();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Filter by tenant. */
    public function scopeForTenant(\Illuminate\Database\Eloquent\Builder $query, int $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Only open (non-closed) incidents. */
    public function scopeOpen(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', '!=', 'closed');
    }

    /** Incidents with RCA required but not yet completed. */
    public function scopeRcaPending(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('rca_required', true)->where('rca_completed', false);
    }

    /** Hospitalizations and ER visits (used for monthly KPI). */
    public function scopeHospitalizations(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn('incident_type', ['hospitalization', 'er_visit']);
    }

    /**
     * Incidents with cms_notification_required=true that are past their regulatory
     * deadline but have not yet had cms_notification_sent_at recorded.
     * Used by QA dashboard KPI and IncidentNotificationOverdueJob.
     */
    public function scopeCmsNotificationOverdue(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('cms_notification_required', true)
            ->whereNull('cms_notification_sent_at')
            ->where('regulatory_deadline', '<', now());
    }
}
