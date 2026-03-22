<?php

// ─── MedReconciliation Model ──────────────────────────────────────────────────
// Medication reconciliation record — documents a formal medication review
// performed when a participant's medication list is compared against an external
// source (discharge summary, pharmacy printout, etc.).
//
// CMS PACE regulations require medication reconciliation at care transitions:
//   - Enrollment (comprehensive initial reconciliation by primary_care)
//   - After hospitalization/ER visit (triggered by SDR workflow or care team)
//   - At each IDT meeting cycle (ongoing review of all medications)
//
// Workflow (5-step wizard, enforced by MedReconciliationService):
//   Step 1: Clinician selects prior_source and reconciliation_type
//   Step 2: Prior medications entered into prior_medications JSONB
//   Step 3: generateComparison() produces matched/prior_only/current_only diff
//   Step 4: applyDecisions() executes each action and fills reconciled_medications
//   Step 5: providerApproval() locks the record (status → 'approved')
//
// Status lifecycle:  in_progress → decisions_made → approved (terminal, immutable)
//
// prior_medications JSONB schema per entry:
//   {drug_name, dose, dose_unit, frequency, route, prescriber, notes}
//
// reconciled_medications JSONB schema per entry (set by applyDecisions):
//   {drug_name, medication_id, action: keep|discontinue|add|modify, notes}
//
// changes_made JSONB: audit trail of what was actually changed in emr_medications.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedReconciliation extends Model
{
    use HasFactory;

    protected $table = 'emr_med_reconciliations';

    // ── Constants ─────────────────────────────────────────────────────────────

    public const TYPES = ['enrollment', 'post_hospital', 'idt_review', 'routine'];

    /** Sources of the external prior medication list (Step 1 of wizard). */
    public const SOURCES = [
        'discharge_summary',
        'pharmacy_printout',
        'patient_reported',
        'transfer_records',
    ];

    public const SOURCE_LABELS = [
        'discharge_summary' => 'Discharge Summary',
        'pharmacy_printout' => 'Pharmacy Printout',
        'patient_reported'  => 'Patient/Family Reported',
        'transfer_records'  => 'Transfer Records',
    ];

    public const STATUSES = ['in_progress', 'decisions_made', 'approved'];

    /** Departments permitted to perform medication reconciliation. */
    public const PRESCRIBER_DEPARTMENTS = ['primary_care', 'pharmacy', 'it_admin'];

    /** Departments permitted to provide final provider approval. */
    public const APPROVER_DEPARTMENTS = ['primary_care', 'pharmacy', 'it_admin'];

    /** Valid per-medication decision actions applied in Step 4. */
    public const DECISION_ACTIONS = ['keep', 'discontinue', 'add', 'modify'];

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'reconciled_by_user_id',
        'reconciling_department',
        'reconciliation_type',
        'prior_source',
        'prior_medications',
        'reconciled_medications',
        'changes_made',
        'approved_by_user_id',
        'approved_at',
        'reconciled_at',
        'clinical_notes',
        'has_discrepancies',
        'status',
    ];

    protected $casts = [
        'prior_medications'      => 'array',
        'reconciled_medications' => 'array',
        'changes_made'           => 'array',
        'reconciled_at'          => 'datetime',
        'approved_at'            => 'datetime',
        'has_discrepancies'      => 'boolean',
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

    /** Clinician who performed the reconciliation. */
    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    /** Provider who gave final approval and locked the record. */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * True when the record has been provider-approved and is immutable.
     * Locked records cannot receive new prior meds, decisions, or re-approval.
     */
    public function isLocked(): bool
    {
        return $this->status === 'approved';
    }

    /** True if this reconciliation is still in the wizard flow. */
    public function isActive(): bool
    {
        return in_array($this->status, ['in_progress', 'decisions_made'], true);
    }

    /** Human-readable label for the prior_source field. */
    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->prior_source] ?? $this->prior_source ?? '—';
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('reconciliation_type', $type);
    }

    /** Reconciliations that have been provider-approved (terminal state). */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /** Reconciliations still in the wizard (not yet approved). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['in_progress', 'decisions_made']);
    }

    /** Reconciliations that found discrepancies — flagged for follow-up. */
    public function scopeWithDiscrepancies(Builder $query): Builder
    {
        return $query->where('has_discrepancies', true);
    }
}
