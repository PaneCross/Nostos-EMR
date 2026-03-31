<?php

// ─── Grievance ─────────────────────────────────────────────────────────────────
// Represents a participant, family, or staff grievance per 42 CFR §460.120–§460.121.
//
// CMS timelines (enforced by GrievanceOverdueJob and GrievanceService::checkOverdue):
//   - Standard: resolve within 30 days
//   - Urgent (threat to health/safety): resolve within 72 hours
//
// Status lifecycle:
//   open → under_review → resolved
//   open → under_review → escalated (serious/unresolved)
//   any  → withdrawn (participant withdraws complaint)
//
// priority=urgent triggers an immediate critical alert to qa_compliance + it_admin
// via GrievanceService::open(). CMS surveys routinely request grievance logs.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Grievance extends Model
{
    use SoftDeletes, HasFactory;

    protected $table = 'emr_grievances';

    // ── Constants ─────────────────────────────────────────────────────────────

    /** Who filed the grievance — may not be a system user (e.g. anonymous, family) */
    public const FILED_BY_TYPES = [
        'participant', 'family_member', 'caregiver',
        'legal_representative', 'staff', 'anonymous',
    ];

    /** Grievance categories per 42 CFR §460.120 */
    public const CATEGORIES = [
        'quality_of_care', 'access_to_services', 'staff_conduct',
        'billing', 'discrimination', 'privacy', 'transportation', 'other',
    ];

    public const CATEGORY_LABELS = [
        'quality_of_care'    => 'Quality of Care',
        'access_to_services' => 'Access to Services',
        'staff_conduct'      => 'Staff Conduct',
        'billing'            => 'Billing',
        'discrimination'     => 'Discrimination',
        'privacy'            => 'Privacy',
        'transportation'     => 'Transportation',
        'other'              => 'Other',
    ];

    /** Status lifecycle — see class docblock */
    public const STATUSES = ['open', 'under_review', 'resolved', 'escalated', 'withdrawn'];

    public const STATUS_LABELS = [
        'open'         => 'Open',
        'under_review' => 'Under Review',
        'resolved'     => 'Resolved',
        'escalated'    => 'Escalated',
        'withdrawn'    => 'Withdrawn',
    ];

    /** Urgent = potential threat to health/safety. 72h resolution clock. */
    public const PRIORITIES = ['standard', 'urgent'];

    /** How participant was notified of resolution (CMS §460.120(d)) */
    public const NOTIFICATION_METHODS = ['verbal', 'written', 'phone', 'mail'];

    /** Standard grievances: resolve within 30 days (CMS expectation) */
    public const STANDARD_RESOLUTION_DAYS = 30;

    /** Urgent grievances: resolve within 72 hours (CMS expectation) */
    public const URGENT_RESOLUTION_HOURS = 72;

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'participant_id', 'tenant_id', 'site_id',
        'filed_by_name', 'filed_by_type', 'filed_at', 'received_by_user_id',
        'category', 'description',
        'status', 'priority', 'assigned_to_user_id',
        'investigation_notes', 'resolution_text', 'resolution_date', 'escalation_reason',
        'participant_notified_at', 'notification_method',
        'cms_reportable', 'cms_reported_at',
    ];

    protected $casts = [
        'filed_at'                => 'datetime',
        'resolution_date'         => 'date',
        'participant_notified_at' => 'datetime',
        'cms_reported_at'         => 'datetime',
        'cms_reportable'          => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Staff member who received/logged the grievance */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /** Staff member assigned to investigate */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Scope to a specific tenant */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Active (non-terminal) grievances */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'under_review', 'escalated']);
    }

    /** Urgent priority only */
    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }

    /**
     * Urgent grievances unresolved beyond 72 hours.
     * Used by GrievanceOverdueJob and GrievanceService::checkOverdue().
     */
    public function scopeUrgentOverdue($query)
    {
        return $query->where('priority', 'urgent')
            ->whereIn('status', ['open', 'under_review'])
            ->where('filed_at', '<', now()->subHours(self::URGENT_RESOLUTION_HOURS));
    }

    /**
     * Standard grievances unresolved beyond 30 days.
     * Used by GrievanceOverdueJob and GrievanceService::checkOverdue().
     */
    public function scopeStandardOverdue($query)
    {
        return $query->where('priority', 'standard')
            ->whereIn('status', ['open', 'under_review'])
            ->where('filed_at', '<', now()->subDays(self::STANDARD_RESOLUTION_DAYS));
    }

    // ── Business logic helpers ────────────────────────────────────────────────

    /** Returns true if the grievance has been closed (resolved or withdrawn) */
    public function isClosed(): bool
    {
        return in_array($this->status, ['resolved', 'withdrawn'], true);
    }

    /** Returns true if this is an urgent grievance past the 72-hour window */
    public function isUrgentOverdue(): bool
    {
        return $this->priority === 'urgent'
            && in_array($this->status, ['open', 'under_review'], true)
            && $this->filed_at->lt(now()->subHours(self::URGENT_RESOLUTION_HOURS));
    }

    /** Returns true if this is a standard grievance past the 30-day window */
    public function isStandardOverdue(): bool
    {
        return $this->priority === 'standard'
            && in_array($this->status, ['open', 'under_review'], true)
            && $this->filed_at->lt(now()->subDays(self::STANDARD_RESOLUTION_DAYS));
    }

    /** Human-readable status label */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Human-readable category label */
    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }

    /**
     * Serialize for API responses (list view).
     * Keeps PHI to a minimum — full details on show endpoint.
     */
    public function toApiArray(): array
    {
        return [
            'id'                       => $this->id,
            'participant_id'           => $this->participant_id,
            'participant_name'         => $this->participant
                ? $this->participant->first_name . ' ' . $this->participant->last_name
                : null,
            'participant_mrn'          => $this->participant?->mrn,
            'filed_by_name'            => $this->filed_by_name,
            'filed_by_type'            => $this->filed_by_type,
            'filed_at'                 => $this->filed_at?->toDateTimeString(),
            'category'                 => $this->category,
            'category_label'           => $this->categoryLabel(),
            'status'                   => $this->status,
            'status_label'             => $this->statusLabel(),
            'priority'                 => $this->priority,
            'assigned_to'              => $this->assignedTo
                ? $this->assignedTo->first_name . ' ' . $this->assignedTo->last_name
                : null,
            'cms_reportable'           => $this->cms_reportable,
            'participant_notified_at'  => $this->participant_notified_at?->toDateTimeString(),
            'resolution_date'          => $this->resolution_date?->toDateString(),
            'is_urgent_overdue'        => $this->isUrgentOverdue(),
            'is_standard_overdue'      => $this->isStandardOverdue(),
            'created_at'               => $this->created_at?->toDateTimeString(),
        ];
    }
}
