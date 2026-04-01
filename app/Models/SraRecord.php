<?php

// ─── SraRecord ────────────────────────────────────────────────────────────────
// Tracks Security Risk Analysis (SRA) cycles per HIPAA 45 CFR §164.308(a)(1).
//
// CMS HIPAA Security Rule requires an annual SRA update. Each row represents
// one SRA cycle: a point-in-time assessment of risks to ePHI confidentiality,
// integrity, and availability. next_sra_due drives the overdue warning in the
// compliance posture widget.
//
// Status lifecycle: in_progress → completed (terminal for that cycle)
//   completed SRAs do not reopen; a new row is created for each annual cycle.
//   needs_update is used when a completed SRA requires amendment.
//
// The most recent completed SRA with next_sra_due in the past triggers
// the SRA overdue flag in QaDashboardController's compliance_posture block.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SraRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_sra_records';

    // ── Constants ─────────────────────────────────────────────────────────────

    /** Overall risk level of the environment as assessed by the SRA */
    public const RISK_LEVELS = ['low', 'moderate', 'high', 'critical'];

    public const RISK_LEVEL_LABELS = [
        'low'      => 'Low',
        'moderate' => 'Moderate',
        'high'     => 'High',
        'critical' => 'Critical',
    ];

    /** SRA workflow status */
    public const STATUSES = ['in_progress', 'completed', 'needs_update'];

    public const STATUS_LABELS = [
        'in_progress'  => 'In Progress',
        'completed'    => 'Completed',
        'needs_update' => 'Needs Update',
    ];

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id', 'sra_date', 'conducted_by', 'scope_description',
        'risk_level', 'findings_summary', 'next_sra_due', 'status',
        'reviewed_by_user_id',
    ];

    protected $casts = [
        'sra_date'     => 'date',
        'next_sra_due' => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Staff member who reviewed/approved the completed SRA (nullable) */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Scope to a specific PACE organization */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Completed SRAs only — used to find the most recent completed cycle */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    // ── Business logic helpers ────────────────────────────────────────────────

    /**
     * Returns true if next_sra_due is set and in the past.
     * This indicates the annual SRA renewal is overdue per HIPAA §164.308(a)(1).
     * Used by QaDashboardController compliance_posture block.
     */
    public function isOverdue(): bool
    {
        return $this->next_sra_due !== null
            && $this->next_sra_due->isPast();
    }

    /** Human-readable risk level label */
    public function riskLevelLabel(): string
    {
        return self::RISK_LEVEL_LABELS[$this->risk_level] ?? $this->risk_level;
    }

    /** Human-readable status label */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Serialize for API/Inertia responses */
    public function toApiArray(): array
    {
        return [
            'id'                => $this->id,
            'sra_date'          => $this->sra_date?->toDateString(),
            'conducted_by'      => $this->conducted_by,
            'scope_description' => $this->scope_description,
            'risk_level'        => $this->risk_level,
            'risk_level_label'  => $this->riskLevelLabel(),
            'findings_summary'  => $this->findings_summary,
            'next_sra_due'      => $this->next_sra_due?->toDateString(),
            'is_overdue'        => $this->isOverdue(),
            'status'            => $this->status,
            'status_label'      => $this->statusLabel(),
            'reviewed_by'       => $this->reviewedBy
                ? $this->reviewedBy->first_name . ' ' . $this->reviewedBy->last_name
                : null,
            'created_at'        => $this->created_at?->toDateString(),
        ];
    }
}
