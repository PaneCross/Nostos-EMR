<?php

// ─── PdeRecord ────────────────────────────────────────────────────────────────
// Prescription Drug Event (PDE) record for CMS Part D submission via MARx.
//
// One PDE per prescription dispensed to a PACE participant.
// PACE organizations sponsor a Part D plan; each dispensing event must be
// submitted to CMS as a PDE within the monthly close window.
//
// TrOOP (True Out-of-Pocket) is accumulated per participant per calendar year.
// When troop_amount reaches the catastrophic threshold ($7,400 for 2025),
// the revenue integrity dashboard flags the participant for CMS review.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdeRecord extends Model
{
    use HasFactory;

    protected $table = 'emr_pde_records';

    /** 2025 CMS Part D catastrophic threshold for TrOOP accumulation. */
    const TROOP_CATASTROPHIC_THRESHOLD = 7400.00;

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'medication_id',
        'drug_name',
        'ndc_code',
        'dispense_date',
        'days_supply',
        'quantity_dispensed',
        'ingredient_cost',
        'dispensing_fee',
        'patient_pay',
        'troop_amount',
        'pharmacy_npi',
        'prescriber_npi',
        'submission_status',
        'pde_id',
    ];

    protected $casts = [
        'dispense_date'      => 'date',
        'quantity_dispensed' => 'decimal:3',
        'ingredient_cost'    => 'decimal:2',
        'dispensing_fee'     => 'decimal:2',
        'patient_pay'        => 'decimal:2',
        'troop_amount'       => 'decimal:2',
        'days_supply'        => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant($q, int $id)
    {
        return $q->where('tenant_id', $id);
    }

    public function scopePending($q)
    {
        return $q->where('submission_status', 'pending');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Total cost of this dispensing event (ingredient cost + dispensing fee).
     */
    public function totalCost(): float
    {
        return (float) $this->ingredient_cost + (float) $this->dispensing_fee;
    }
}
