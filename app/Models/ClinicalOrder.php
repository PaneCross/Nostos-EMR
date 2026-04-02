<?php

// ─── ClinicalOrder ────────────────────────────────────────────────────────────
// W4-7: Lightweight CPOE (Computerized Provider Order Entry) model.
// 42 CFR §460.90 — all PACE services must be ordered and documented.
//
// Order lifecycle: pending → acknowledged → in_progress → resulted/completed
//                  (any non-terminal) → cancelled
//
// Auto-routing: DEPARTMENT_ROUTING maps order_type → target_department at
// creation time in ClinicalOrderController::store().
//
// Alert generation: stat orders create critical alerts, urgent→warning, routine→info
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class ClinicalOrder extends Model
{
    use HasFactory;

    protected $table = 'emr_clinical_orders';

    // ── Order types (42 CFR §460.90 PACE service categories) ─────────────────
    public const ORDER_TYPES = [
        'lab', 'imaging', 'consult', 'therapy_pt', 'therapy_ot',
        'therapy_st', 'therapy_speech', 'dme', 'medication_change',
        'home_health', 'hospice_referral', 'other',
    ];

    // Human-readable order type labels
    public const ORDER_TYPE_LABELS = [
        'lab'               => 'Laboratory',
        'imaging'           => 'Imaging / Radiology',
        'consult'           => 'Specialist Consult',
        'therapy_pt'        => 'Physical Therapy',
        'therapy_ot'        => 'Occupational Therapy',
        'therapy_st'        => 'Speech Therapy (ST)',
        'therapy_speech'    => 'Speech-Language Pathology',
        'dme'               => 'DME / Equipment',
        'medication_change' => 'Medication Change',
        'home_health'       => 'Home Health',
        'hospice_referral'  => 'Hospice Referral',
        'other'             => 'Other',
    ];

    public const PRIORITIES = ['routine', 'urgent', 'stat'];

    public const STATUSES = ['pending', 'acknowledged', 'in_progress', 'resulted', 'completed', 'cancelled'];

    // Terminal statuses — no further transitions allowed
    public const TERMINAL_STATUSES = ['completed', 'cancelled'];

    // ── Auto-routing: order_type → fulfilling department ─────────────────────
    // ClinicalOrderController::store() uses this map to set target_department.
    public const DEPARTMENT_ROUTING = [
        'lab'               => 'primary_care',
        'imaging'           => 'primary_care',
        'consult'           => 'primary_care',
        'therapy_pt'        => 'therapies',
        'therapy_ot'        => 'therapies',
        'therapy_st'        => 'therapies',
        'therapy_speech'    => 'therapies',
        'dme'               => 'home_care',
        'medication_change' => 'pharmacy',
        'home_health'       => 'home_care',
        'hospice_referral'  => 'social_work',
        'other'             => 'primary_care',
    ];

    protected $fillable = [
        'participant_id', 'tenant_id', 'site_id', 'ordered_by_user_id',
        'ordered_at', 'order_type', 'priority', 'status', 'instructions',
        'clinical_indication', 'target_department', 'target_facility', 'due_date',
        'acknowledged_by_user_id', 'acknowledged_at',
        'resulted_at', 'result_summary', 'result_document_id',
        'completed_at', 'cancellation_reason',
    ];

    protected $casts = [
        'ordered_at'       => 'datetime',
        'due_date'         => 'date:Y-m-d',
        'acknowledged_at'  => 'datetime',
        'resulted_at'      => 'datetime',
        'completed_at'     => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by_user_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function resultDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'result_document_id');
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    /** Returns true when no further status transitions are permitted. */
    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES);
    }

    /** Returns true when the order is awaiting fulfillment (not yet acted on). */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Returns true when a stat/urgent order is overdue:
     *   - stat: not completed within 4 hours of ordered_at
     *   - urgent: not completed within 24 hours of ordered_at
     *   - routine: not completed by due_date (if set)
     */
    public function isOverdue(): bool
    {
        if ($this->isTerminal()) return false;

        $now = Carbon::now();

        if ($this->priority === 'stat') {
            return $now->diffInHours($this->ordered_at, false) <= -4;
        }
        if ($this->priority === 'urgent') {
            return $now->diffInHours($this->ordered_at, false) <= -24;
        }
        // Routine: overdue only if due_date is set and has passed
        return $this->due_date !== null && $this->due_date->isPast();
    }

    /** Human-readable order type label. */
    public function orderTypeLabel(): string
    {
        return self::ORDER_TYPE_LABELS[$this->order_type] ?? ucfirst($this->order_type);
    }

    /**
     * Alert severity for this order based on priority.
     * Maps to Alert::SEVERITY constants: critical / warning / info.
     */
    public function alertSeverity(): string
    {
        return match ($this->priority) {
            'stat'    => 'critical',
            'urgent'  => 'warning',
            default   => 'info',
        };
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    /** Scope to a specific tenant. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Scope to active (non-terminal) orders. */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    /** Scope to orders targeting a specific department. */
    public function scopeForDepartment($query, string $dept)
    {
        return $query->where('target_department', $dept);
    }

    /** Scope to pending orders (awaiting acknowledgment). */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    // ── API serialization ─────────────────────────────────────────────────────

    /** Serializes order for API/Inertia responses. */
    public function toApiArray(): array
    {
        return [
            'id'                       => $this->id,
            'participant_id'           => $this->participant_id,
            'order_type'               => $this->order_type,
            'order_type_label'         => $this->orderTypeLabel(),
            'priority'                 => $this->priority,
            'status'                   => $this->status,
            'instructions'             => $this->instructions,
            'clinical_indication'      => $this->clinical_indication,
            'target_department'        => $this->target_department,
            'target_facility'          => $this->target_facility,
            'due_date'                 => $this->due_date?->format('Y-m-d'),
            'ordered_at'               => $this->ordered_at?->toIso8601String(),
            'ordered_by'               => $this->orderedBy
                ? ['id' => $this->orderedBy->id, 'name' => $this->orderedBy->first_name . ' ' . $this->orderedBy->last_name]
                : null,
            'acknowledged_at'          => $this->acknowledged_at?->toIso8601String(),
            'acknowledged_by'          => $this->acknowledgedBy
                ? ['id' => $this->acknowledgedBy->id, 'name' => $this->acknowledgedBy->first_name . ' ' . $this->acknowledgedBy->last_name]
                : null,
            'resulted_at'              => $this->resulted_at?->toIso8601String(),
            'result_summary'           => $this->result_summary,
            'completed_at'             => $this->completed_at?->toIso8601String(),
            'cancellation_reason'      => $this->cancellation_reason,
            'is_overdue'               => $this->isOverdue(),
            'created_at'               => $this->created_at?->toIso8601String(),
            'participant'              => $this->relationLoaded('participant') ? [
                'id'         => $this->participant->id,
                'first_name' => $this->participant->first_name,
                'last_name'  => $this->participant->last_name,
                'mrn'        => $this->participant->mrn,
            ] : null,
        ];
    }
}
