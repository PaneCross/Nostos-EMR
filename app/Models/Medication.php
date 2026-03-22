<?php

// ─── Medication Model ──────────────────────────────────────────────────────────
// Active medication list entry for a PACE participant.
//
// Each row is one ordered medication. The eMAR (EmarRecord) generates daily
// dose events from active Medication rows via MedicationScheduleService.
//
// PRN medications (is_prn = true) are NOT pre-scheduled in the eMAR.
// PRN doses are recorded on-demand via MedicationController::recordPrnDose().
//
// Controlled substance workflow:
//   is_controlled = true → EmarRecord requires witness_user_id for Schedule II/III.
//   The controller enforces this at administration time.
//
// DrugInteractionService::checkInteractions() is called on create/update to
// detect interactions with the participant's other active medications.
//
// SoftDeletes: medications can be "removed" from UI without destroying eMAR history.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Medication extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_medications';

    // ── Valid enum values ──────────────────────────────────────────────────────

    public const DOSE_UNITS = ['mg', 'mcg', 'ml', 'units', 'tab', 'cap', 'patch', 'drop'];

    public const ROUTES = [
        'oral', 'IV', 'IM', 'subcut', 'topical', 'inhaled',
        'sublingual', 'rectal', 'nasal', 'optic', 'otic',
    ];

    public const FREQUENCIES = [
        'daily', 'BID', 'TID', 'QID', 'Q4H', 'Q6H', 'Q8H', 'Q12H',
        'PRN', 'weekly', 'monthly', 'once',
    ];

    public const STATUSES = ['active', 'discontinued', 'on_hold', 'prn'];

    /** DEA schedules requiring witness co-signature in eMAR (Schedule II/III). */
    public const WITNESS_REQUIRED_SCHEDULES = ['II', 'III'];

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'drug_name',
        'rxnorm_code',
        'dose',
        'dose_unit',
        'route',
        'frequency',
        'is_prn',
        'prn_indication',
        'prescribing_provider_user_id',
        'prescribed_date',
        'start_date',
        'end_date',
        'discontinued_reason',
        'status',
        'is_controlled',
        'controlled_schedule',
        'refills_remaining',
        'last_filled_date',
        'pharmacy_notes',
    ];

    protected $casts = [
        'dose'           => 'decimal:3',
        'is_prn'         => 'boolean',
        'is_controlled'  => 'boolean',
        'prescribed_date'=> 'date',
        'start_date'     => 'date',
        'end_date'       => 'date',
        'last_filled_date'=> 'date',
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

    /** The clinician/provider who prescribed this medication. */
    public function prescribingProvider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescribing_provider_user_id');
    }

    /** eMAR records generated for this medication. */
    public function emarRecords(): HasMany
    {
        return $this->hasMany(EmarRecord::class);
    }

    /** Drug interaction alerts involving this medication (as either drug). */
    public function interactionAlerts(): HasMany
    {
        return $this->hasMany(DrugInteractionAlert::class, 'medication_id_1')
            ->orWhere('medication_id_2', $this->id);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Restrict to a single tenant. */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Only medications that are currently active or PRN (schedulable or on-demand). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'prn']);
    }

    /** Medications that should appear in daily MAR generation (active, non-PRN). */
    public function scopeSchedulable(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('is_prn', false);
    }

    /** Controlled substances requiring witness co-signature for Schedule II/III. */
    public function scopeRequiresWitness(Builder $query): Builder
    {
        return $query->where('is_controlled', true)
            ->whereIn('controlled_schedule', self::WITNESS_REQUIRED_SCHEDULES);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** True if this medication should be pre-scheduled in the daily eMAR. */
    public function isSchedulable(): bool
    {
        return $this->status === 'active' && !$this->is_prn;
    }

    /** True if a co-signature (witness) is required when administering via eMAR. */
    public function requiresWitness(): bool
    {
        return $this->is_controlled
            && in_array($this->controlled_schedule, self::WITNESS_REQUIRED_SCHEDULES);
    }

    /** Human-readable dose string, e.g., "10 mg oral daily". */
    public function doseLabel(): string
    {
        $parts = array_filter([
            $this->dose,
            $this->dose_unit,
            $this->route,
            $this->frequency,
        ]);
        return implode(' ', $parts);
    }

    /** Discontinue this medication, recording the reason. */
    public function discontinue(string $reason): void
    {
        $this->update([
            'status'               => 'discontinued',
            'discontinued_reason'  => $reason,
            'end_date'             => now()->toDateString(),
        ]);
    }
}
