<?php

// ─── StateMedicaidConfig ───────────────────────────────────────────────────────
// Per-tenant configuration for state Medicaid encounter submission.
//
// PACE participants are dually eligible (Medicare + Medicaid). Many states
// require separate 837 encounter submissions to the state Medicaid agency.
// Rules vary dramatically by state — submission format, timing, companion guide
// deviations, and clearinghouse requirements all differ.
//
// One config per tenant per state_code. Managed by IT Admin only.
// This table implements DEBT-038 (State Medicaid encounter submission support)
// as a configuration framework — the actual 837 submission pipeline builds on top.
//
// Phase 9C — Part B (State Medicaid Configuration Framework)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StateMedicaidConfig extends Model
{
    use HasFactory;

    protected $table = 'emr_state_medicaid_configs';

    // ── Constants ─────────────────────────────────────────────────────────────

    /** Valid 837 transaction types supported as state submission formats */
    const SUBMISSION_FORMATS = ['837P', '837I', 'custom'];

    const SUBMISSION_FORMAT_LABELS = [
        '837P'   => '837P Professional',
        '837I'   => '837I Institutional',
        'custom' => 'Custom / State Portal',
    ];

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id',
        'state_code',
        'state_name',
        'submission_format',
        'companion_guide_notes',
        'submission_endpoint',
        'clearinghouse_name',
        'days_to_submit',
        'effective_date',
        'contact_name',
        'contact_phone',
        'contact_email',
        'is_active',
    ];

    protected $casts = [
        'days_to_submit' => 'integer',
        'effective_date' => 'date',
        'is_active'      => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

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

    /** Return only active configurations */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
