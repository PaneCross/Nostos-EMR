<?php

// ─── EmarRecord Model ─────────────────────────────────────────────────────────
// Electronic Medication Administration Record — one record per scheduled dose.
// APPEND-ONLY (no SoftDeletes, no updated_at). Clinical audit trail.
//
// Row lifecycle:
//   MedicationScheduleService pre-creates rows nightly with status='scheduled'.
//   A nurse opens the eMAR grid and updates each row to given/refused/held/etc.
//   LateMarDetectionJob (every 30 min) marks rows past their window as 'late'.
//
// PRN doses are created on-demand (status starts as 'given', not 'scheduled').
//
// Controlled substance administrations (medication.requiresWitness() = true)
// require witness_user_id before MedicationController accepts the record.
//
// Status meanings:
//   scheduled     — Pre-generated, awaiting administration
//   given         — Successfully administered; administered_at + user filled
//   refused       — Participant declined; reason_not_given required
//   held          — Held per MD order; reason_not_given required
//   not_available — Drug not in stock; reason_not_given required
//   late          — Scheduled window passed without administration
//   missed        — Nurse explicitly marked as missed
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmarRecord extends Model
{
    use HasFactory;

    protected $table = 'emr_emar_records';

    // Append-only: updated_at doesn't exist on this table
    public const UPDATED_AT = null;

    public const STATUSES = [
        'scheduled',
        'given',
        'refused',
        'held',
        'not_available',
        'late',
        'missed',
    ];

    /** Statuses indicating the dose was NOT administered (for MAR adherence reporting). */
    public const NON_ADMINISTRATION_STATUSES = ['refused', 'held', 'not_available', 'missed'];

    protected $fillable = [
        'participant_id',
        'medication_id',
        'tenant_id',
        'scheduled_time',
        'administered_at',
        'administered_by_user_id',
        'status',
        'dose_given',
        'route_given',
        'reason_not_given',
        'witness_user_id',
        'notes',
    ];

    protected $casts = [
        'scheduled_time'  => 'datetime',
        'administered_at' => 'datetime',
        'created_at'      => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The nurse/clinician who administered the medication. */
    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by_user_id');
    }

    /** Co-signing witness (required for DEA Schedule II/III controlled substances). */
    public function witness(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witness_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Records scheduled for a specific calendar date (eMAR grid day view). */
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('scheduled_time', $date);
    }

    /** Records that were pre-scheduled but the window has passed without administration. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_time', '<', now());
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** True if the dose has not yet been given and is past its scheduled window. */
    public function isOverdue(): bool
    {
        return $this->status === 'scheduled'
            && $this->scheduled_time->isPast();
    }

    /** True if a nurse still needs to chart this dose (not yet acted on). */
    public function needsCharting(): bool
    {
        return $this->status === 'scheduled';
    }

    /** True if the dose was actually administered (for adherence calculations). */
    public function wasGiven(): bool
    {
        return $this->status === 'given';
    }
}
