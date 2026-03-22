<?php

// ─── Appointment Model ────────────────────────────────────────────────────────
// Participant appointment scheduling across all PACE service types.
//
// Appointment types cover all care delivery modalities in a PACE program:
//   clinic_visit        — PCP/NP visit at the PACE center
//   therapy_pt/ot/st    — Physical, Occupational, Speech therapy
//   social_work         — Social work counseling/assessment
//   behavioral_health   — Behavioral/mental health visit
//   dietary_consult     — Dietitian consultation
//   home_visit          — Any discipline visiting participant at home
//   external_referral   — Community specialist referral
//   specialist          — Contracted specialist visit (at PACE or offsite)
//   lab / imaging       — Diagnostics
//   activities          — Day center group/individual activity
//   telehealth          — Video/phone visit (any discipline)
//   day_center_attendance — Standard day center attendance day (blocks slot)
//
// Status lifecycle:
//   scheduled → confirmed → completed
//   scheduled / confirmed → cancelled (requires cancellation_reason)
//   scheduled / confirmed → no_show
//
// Conflict detection:
//   ConflictDetectionService prevents overlapping appointments for the same
//   participant. Cancelled appointments are excluded from conflict checks.
//
// transport_request_id references transport.transport_requests (NO FK constraint —
// cross-app reference, transport tables are read-only in the EMR app).
// Populated via TransportBridgeService when transport is arranged.
//
// Soft deletes preserve historical records for audit trail.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_appointments';

    // ── Appointment types ─────────────────────────────────────────────────────
    public const APPOINTMENT_TYPES = [
        'clinic_visit',
        'therapy_pt',
        'therapy_ot',
        'therapy_st',
        'social_work',
        'behavioral_health',
        'dietary_consult',
        'home_visit',
        'external_referral',
        'specialist',
        'lab',
        'imaging',
        'activities',
        'telehealth',
        'day_center_attendance',
    ];

    // ── Status lifecycle ──────────────────────────────────────────────────────
    public const STATUSES = ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'];

    // ── Human-readable type labels for the frontend ────────────────────────────
    public const TYPE_LABELS = [
        'clinic_visit'          => 'Clinic Visit',
        'therapy_pt'            => 'Physical Therapy',
        'therapy_ot'            => 'Occupational Therapy',
        'therapy_st'            => 'Speech Therapy',
        'social_work'           => 'Social Work',
        'behavioral_health'     => 'Behavioral Health',
        'dietary_consult'       => 'Dietary Consult',
        'home_visit'            => 'Home Visit',
        'external_referral'     => 'External Referral',
        'specialist'            => 'Specialist',
        'lab'                   => 'Lab',
        'imaging'               => 'Imaging',
        'activities'            => 'Activities',
        'telehealth'            => 'Telehealth',
        'day_center_attendance' => 'Day Center Attendance',
    ];

    // ── Tailwind colors per appointment type (for calendar color-coding) ───────
    public const TYPE_COLORS = [
        'clinic_visit'          => 'blue',
        'therapy_pt'            => 'green',
        'therapy_ot'            => 'emerald',
        'therapy_st'            => 'teal',
        'social_work'           => 'purple',
        'behavioral_health'     => 'violet',
        'dietary_consult'       => 'orange',
        'home_visit'            => 'amber',
        'external_referral'     => 'rose',
        'specialist'            => 'pink',
        'lab'                   => 'slate',
        'imaging'               => 'gray',
        'activities'            => 'indigo',
        'telehealth'            => 'cyan',
        'day_center_attendance' => 'lime',
    ];

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'site_id',
        'appointment_type',
        'provider_user_id',
        'location_id',
        'scheduled_start',
        'scheduled_end',
        'status',
        'transport_required',
        'transport_request_id',
        'notes',
        'cancellation_reason',
        'created_by_user_id',
    ];

    protected $casts = [
        'scheduled_start'   => 'datetime',
        'scheduled_end'     => 'datetime',
        'transport_required' => 'boolean',
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

    /** The provider (PCP, NP, therapist, etc.) assigned to this appointment. */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    /** Physical location where this appointment occurs (nullable for telehealth etc.). */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Restrict to the current tenant (multi-tenancy boundary). */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Only appointments scheduled in the future (used for dashboard widgets). */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('scheduled_start', '>', now())
            ->whereNotIn('status', ['cancelled']);
    }

    /** Filter to a specific provider's appointments. */
    public function scopeForProvider(Builder $query, int $userId): Builder
    {
        return $query->where('provider_user_id', $userId);
    }

    /**
     * Exclude cancelled appointments (used in conflict detection).
     * Cancelled appointments no longer block the time slot.
     */
    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * An appointment is editable only while it is scheduled or confirmed.
     * Completed, cancelled, and no-show appointments are immutable.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['scheduled', 'confirmed']);
    }

    /**
     * Mark the appointment as completed.
     * Only valid for confirmed appointments; scheduled appointments may also be completed
     * when the system skips the explicit confirmation step.
     */
    public function complete(): void
    {
        $this->update(['status' => 'completed']);
    }

    /**
     * Cancel the appointment with a mandatory reason.
     * Cancellation_reason is required by the PACE scheduling policy — this is
     * enforced by StoreAppointmentRequest as well, but asserted here defensively.
     */
    public function cancel(string $reason): void
    {
        $this->update([
            'status'              => 'cancelled',
            'cancellation_reason' => $reason,
        ]);
    }

    /** Mark the appointment as a no-show (participant did not arrive). */
    public function noShow(): void
    {
        $this->update(['status' => 'no_show']);
    }

    /**
     * Human-readable label for the appointment type.
     * e.g. 'therapy_pt' → 'Physical Therapy'
     */
    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->appointment_type]
            ?? ucwords(str_replace('_', ' ', $this->appointment_type));
    }

    /**
     * Tailwind color name for calendar rendering.
     * Used by the React calendar to apply a consistent color per appointment type.
     */
    public function typeColor(): string
    {
        return self::TYPE_COLORS[$this->appointment_type] ?? 'gray';
    }

    /**
     * Duration in minutes.
     */
    public function durationMinutes(): int
    {
        return (int) $this->scheduled_start->diffInMinutes($this->scheduled_end);
    }
}
