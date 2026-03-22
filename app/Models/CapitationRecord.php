<?php

// ─── CapitationRecord ─────────────────────────────────────────────────────────
// Represents one monthly CMS capitation payment record for a participant.
//
// PACE billing model: CMS pays a fixed monthly capitation per participant based
// on their eligibility category (risk score). Stored with component breakdowns
// (Part A/B/D + Medicaid) for reconciliation against CMS remittance reports.
//
// Uniqueness: one record per participant per month_year (e.g. '2026-03').
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CapitationRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_capitation_records';

    protected $fillable = [
        'tenant_id',
        'participant_id',
        'month_year',
        'medicare_a_rate',
        'medicare_b_rate',
        'medicare_d_rate',
        'medicaid_rate',
        'total_capitation',
        'eligibility_category',
        'recorded_at',
        // Phase 9B: HCC risk adjustment fields
        'hcc_risk_score',
        'frailty_score',
        'county_fips_code',
        'adjustment_type',
        'medicare_ab_rate',
        'private_pay_rate',
        'rate_effective_date',
    ];

    protected $casts = [
        'medicare_a_rate'    => 'decimal:2',
        'medicare_b_rate'    => 'decimal:2',
        'medicare_d_rate'    => 'decimal:2',
        'medicaid_rate'      => 'decimal:2',
        'total_capitation'   => 'decimal:2',
        'recorded_at'        => 'datetime',
        // Phase 9B HCC fields
        'hcc_risk_score'     => 'decimal:4',
        'frailty_score'      => 'decimal:4',
        'medicare_ab_rate'   => 'decimal:2',
        'private_pay_rate'   => 'decimal:2',
        'rate_effective_date'=> 'date',
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

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Scope to a specific tenant. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Scope to a specific month_year string, e.g. '2026-03'. */
    public function scopeForMonth($query, string $monthYear)
    {
        return $query->where('month_year', $monthYear);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Human-readable month label, e.g. '2026-03' → 'March 2026'. */
    public function monthLabel(): string
    {
        [$year, $month] = explode('-', $this->month_year);
        return date('F Y', mktime(0, 0, 0, (int) $month, 1, (int) $year));
    }
}
