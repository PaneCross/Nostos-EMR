<?php

// ─── EdiBatch ─────────────────────────────────────────────────────────────────
// Represents one X12 5010A1 EDI batch file submitted (or being prepared for
// submission) to CMS Encounter Data System (EDS) via CSSC Operations.
//
// Each batch aggregates one or more EncounterLog records into a single EDI file.
// batch_type:
//   edr = Encounter Data Records (external services with existing claim)
//   crr = Chart Review Records (PACE center services, no external claim)
//   pde = Prescription Drug Event batch (Part D PDE submissions)
//
// File content is stored in file_content — the full X12 text.
// Downloads go through EdiBatchController::download() (never direct URL).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EdiBatch extends Model
{
    use HasFactory;

    protected $table = 'emr_edi_batches';

    /** Human-readable labels for batch type codes. */
    const BATCH_TYPES = [
        'edr' => 'Encounter Data Record (EDR)',
        'crr' => 'Chart Review Record (CRR)',
        'pde' => 'Part D PDE',
    ];

    /** Valid status values for the EDI submission lifecycle. */
    const STATUSES = ['draft', 'submitted', 'acknowledged', 'partially_accepted', 'rejected'];

    /** Submission methods supported. */
    const SUBMISSION_METHODS = ['direct', 'clearinghouse'];

    protected $fillable = [
        'tenant_id',
        'batch_type',
        'file_name',
        'file_content',
        'record_count',
        'total_charge_amount',
        'status',
        'submitted_at',
        'submission_method',
        'clearinghouse_reference',
        'cms_response_code',
        'created_by_user_id',
    ];

    protected $casts = [
        'total_charge_amount' => 'decimal:2',
        'submitted_at'        => 'datetime',
        'record_count'        => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function encounters(): HasMany
    {
        return $this->hasMany(EncounterLog::class, 'edi_batch_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant($q, int $id)
    {
        return $q->where('tenant_id', $id);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Whether this batch can still be modified (only draft batches are editable).
     */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Human-readable status label.
     */
    public function statusLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->status));
    }
}
