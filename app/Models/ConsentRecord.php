<?php

// ─── ConsentRecord ─────────────────────────────────────────────────────────────
// Tracks participant consent and acknowledgment records.
//
// consent_type=npp_acknowledgment is the most critical:
//   - HIPAA 45 CFR §164.520 requires providers to make a good-faith effort
//     to obtain written acknowledgment of NPP receipt at first service delivery.
//   - NostosEMR auto-creates a pending npp_acknowledgment record when a
//     participant is enrolled (see EnrollmentService::handleEnrollment).
//   - QA dashboard flags enrolled participants with status='pending' on their
//     npp_acknowledgment record.
//
// status values:
//   pending          — created but not yet obtained (auto-created at enrollment)
//   acknowledged     — participant/rep signed or verbally confirmed
//   refused          — participant explicitly declined to sign
//   unable_to_consent — cognitive/physical incapacity documented
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentRecord extends Model
{
    use HasFactory;

    protected $table = 'emr_consent_records';

    // ── Constants ─────────────────────────────────────────────────────────────

    public const CONSENT_TYPES = [
        'npp_acknowledgment', 'hipaa_authorization',
        'treatment_consent', 'research_consent', 'photo_release', 'other',
    ];

    public const TYPE_LABELS = [
        'npp_acknowledgment'   => 'NPP Acknowledgment',
        'hipaa_authorization'  => 'HIPAA Authorization',
        'treatment_consent'    => 'Treatment Consent',
        'research_consent'     => 'Research Consent',
        'photo_release'        => 'Photo / Media Release',
        'other'                => 'Other',
    ];

    /** HIPAA §164.520: PACE must make good-faith effort to obtain NPP acknowledgment */
    public const NPP_TYPE = 'npp_acknowledgment';

    public const STATUSES = ['pending', 'acknowledged', 'refused', 'unable_to_consent'];

    public const STATUS_LABELS = [
        'pending'           => 'Pending',
        'acknowledged'      => 'Acknowledged',
        'refused'           => 'Refused',
        'unable_to_consent' => 'Unable to Consent',
    ];

    public const REPRESENTATIVE_TYPES = ['self', 'guardian', 'poa', 'healthcare_proxy'];

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'participant_id', 'tenant_id',
        'consent_type', 'document_title', 'document_version', 'document_path',
        'status', 'acknowledged_by', 'acknowledged_at', 'representative_type',
        'expiration_date', 'notes', 'created_by_user_id',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'expiration_date' => 'date',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForParticipant($query, int $participantId)
    {
        return $query->where('participant_id', $participantId);
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Pending (not yet obtained) NPP acknowledgments — QA compliance gap */
    public function scopePendingNpp($query)
    {
        return $query->where('consent_type', self::NPP_TYPE)
            ->where('status', 'pending');
    }

    // ── Business logic helpers ────────────────────────────────────────────────

    public function isAcknowledged(): bool
    {
        return $this->status === 'acknowledged';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->consent_type] ?? $this->consent_type;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function toApiArray(): array
    {
        return [
            'id'                  => $this->id,
            'consent_type'        => $this->consent_type,
            'type_label'          => $this->typeLabel(),
            'document_title'      => $this->document_title,
            'document_version'    => $this->document_version,
            'status'              => $this->status,
            'status_label'        => $this->statusLabel(),
            'acknowledged_by'     => $this->acknowledged_by,
            'acknowledged_at'     => $this->acknowledged_at?->toDateTimeString(),
            'representative_type' => $this->representative_type,
            'expiration_date'     => $this->expiration_date?->toDateString(),
            'notes'               => $this->notes,
            'created_by'          => $this->createdBy
                ? $this->createdBy->first_name . ' ' . $this->createdBy->last_name
                : null,
            'created_at'          => $this->created_at?->toDateTimeString(),
        ];
    }
}
