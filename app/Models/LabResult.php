<?php

// ─── LabResult ────────────────────────────────────────────────────────────────
// Structured laboratory result record for a PACE participant.
// May originate from HL7 ORU inbound messages or manual clinical entry.
//
// One LabResult has many LabResultComponent records (individual analytes).
// When sourced from HL7, integration_log_id links back to the raw message log.
//
// Clinical review workflow:
//   Abnormal/critical results → primary_care alert → clinician reviews
//   → marks reviewed (reviewed_by_user_id + reviewed_at set)
//   → alert resolved or acknowledged
//
// SoftDeletes: lab results are part of the clinical record (HIPAA).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabResult extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_lab_results';

    // ── Constants ─────────────────────────────────────────────────────────────

    public const SOURCES = ['hl7_inbound', 'manual_entry'];

    public const STATUSES = ['final', 'preliminary', 'corrected', 'cancelled'];

    public const STATUS_LABELS = [
        'final'       => 'Final',
        'preliminary' => 'Preliminary',
        'corrected'   => 'Corrected',
        'cancelled'   => 'Cancelled',
    ];

    // ── Fillable / Casts ──────────────────────────────────────────────────────

    protected $fillable = [
        'participant_id', 'tenant_id', 'integration_log_id',
        'test_name', 'test_code',
        'collected_at', 'resulted_at',
        'ordering_provider_name', 'performing_facility',
        'source', 'overall_status',
        'abnormal_flag',
        'reviewed_by_user_id', 'reviewed_at',
        'notes',
    ];

    protected $casts = [
        'collected_at'   => 'datetime',
        'resulted_at'    => 'datetime',
        'reviewed_at'    => 'datetime',
        'abnormal_flag'  => 'boolean',
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

    public function integrationLog(): BelongsTo
    {
        return $this->belongsTo(IntegrationLog::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(LabResultComponent::class)->orderBy('id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForParticipant($query, int $participantId)
    {
        return $query->where('participant_id', $participantId);
    }

    public function scopeAbnormal($query)
    {
        return $query->where('abnormal_flag', true);
    }

    public function scopeUnreviewed($query)
    {
        return $query->whereNull('reviewed_at');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    /** True if ANY component has a critical_low or critical_high abnormal flag. */
    public function hasCriticalComponent(): bool
    {
        return $this->components()
            ->whereIn('abnormal_flag', ['critical_low', 'critical_high'])
            ->exists();
    }

    /** Human-readable overall status label. */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->overall_status] ?? ucfirst($this->overall_status);
    }

    /** API-safe array for frontend list view. */
    public function toApiArray(): array
    {
        return [
            'id'                      => $this->id,
            'participant_id'          => $this->participant_id,
            'test_name'               => $this->test_name,
            'test_code'               => $this->test_code,
            'collected_at'            => $this->collected_at?->toIso8601String(),
            'resulted_at'             => $this->resulted_at?->toIso8601String(),
            'ordering_provider_name'  => $this->ordering_provider_name,
            'performing_facility'     => $this->performing_facility,
            'source'                  => $this->source,
            'overall_status'          => $this->overall_status,
            'status_label'            => $this->statusLabel(),
            'abnormal_flag'           => $this->abnormal_flag,
            'is_reviewed'             => $this->isReviewed(),
            'reviewed_by'             => $this->reviewedBy
                ? $this->reviewedBy->first_name . ' ' . $this->reviewedBy->last_name
                : null,
            'reviewed_at'             => $this->reviewed_at?->toIso8601String(),
            'notes'                   => $this->notes,
            'component_count'         => $this->components()->count(),
            'created_at'              => $this->created_at?->toIso8601String(),
        ];
    }

    /** API-safe array including component detail — used for Show endpoint. */
    public function toDetailArray(): array
    {
        $base = $this->toApiArray();
        $base['components'] = $this->components->map(fn (LabResultComponent $c) => $c->toApiArray())->values()->all();
        return $base;
    }
}
