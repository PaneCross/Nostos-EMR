<?php

// ─── ParticipantRiskScore ──────────────────────────────────────────────────────
// Stores annual CMS-HCC risk adjustment data per participant per payment year.
//
// One record per participant per payment_year. Can be imported from CMS
// remittance data (score_source='cms_import') or calculated locally from
// emr_problems ICD-10 codes via RiskAdjustmentService (score_source='calculated').
//
// The risk_score (RAF) × frailty_score × county base rate = capitation payment.
// Tracking this allows the organization to detect CMS underpayments.
//
// Phase 9C — Part A (Risk Adjustment Tracking)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParticipantRiskScore extends Model
{
    use HasFactory;

    protected $table = 'emr_participant_risk_scores';

    // ── Constants ─────────────────────────────────────────────────────────────

    /** Valid values for score_source column */
    const SCORE_SOURCES = ['cms_import', 'calculated', 'manual'];

    const SCORE_SOURCE_LABELS = [
        'cms_import'  => 'CMS Import',
        'calculated'  => 'Locally Calculated',
        'manual'      => 'Manual Entry',
    ];

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'payment_year',
        'risk_score',
        'frailty_score',
        'hcc_categories',
        'diagnoses_submitted',
        'diagnoses_accepted',
        'score_source',
        'effective_date',
        'imported_at',
    ];

    protected $casts = [
        'payment_year'        => 'integer',
        'risk_score'          => 'decimal:4',
        'frailty_score'       => 'decimal:4',
        'hcc_categories'      => 'array',
        'diagnoses_submitted' => 'integer',
        'diagnoses_accepted'  => 'integer',
        'effective_date'      => 'date',
        'imported_at'         => 'datetime',
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

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Filter by tenant — required on all EMR queries (multi-tenant isolation) */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Filter by payment year */
    public function scopeForYear($query, int $year)
    {
        return $query->where('payment_year', $year);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Diagnosis acceptance rate (0.0–1.0).
     * Returns null when diagnoses_submitted is 0 to avoid division by zero.
     */
    public function acceptanceRate(): ?float
    {
        if ($this->diagnoses_submitted === 0) {
            return null;
        }
        return round($this->diagnoses_accepted / $this->diagnoses_submitted, 4);
    }

    /**
     * Whether this record was imported from CMS (as opposed to locally calculated).
     */
    public function isFromCms(): bool
    {
        return $this->score_source === 'cms_import';
    }
}
