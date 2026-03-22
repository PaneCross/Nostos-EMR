<?php

// ─── ParticipantSiteTransfer ───────────────────────────────────────────────────
// Represents a site-to-site participant transfer request within a PACE organization.
//
// Workflow: pending → approved → completed (via TransferCompletionJob)
//                   └→ cancelled (by requester or admin before approval)
//
// Phase 10A
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParticipantSiteTransfer extends Model
{
    use HasFactory;

    protected $table = 'emr_participant_site_transfers';

    protected $fillable = [
        'participant_id', 'tenant_id',
        'from_site_id', 'to_site_id',
        'transfer_reason', 'transfer_reason_notes',
        'requested_by_user_id', 'requested_at',
        'approved_by_user_id', 'approved_at',
        'effective_date', 'status', 'notification_sent',
    ];

    protected $casts = [
        'requested_at'      => 'datetime',
        'approved_at'       => 'datetime',
        'effective_date'    => 'date',
        'notification_sent' => 'boolean',
    ];

    // ── Constants ─────────────────────────────────────────────────────────────

    public const STATUSES = ['pending', 'approved', 'completed', 'cancelled'];

    public const TRANSFER_REASONS = [
        'participant_request',
        'relocation',
        'capacity',
        'program_closure',
        'other',
    ];

    public const TRANSFER_REASON_LABELS = [
        'participant_request' => 'Participant Request',
        'relocation'          => 'Participant Relocation',
        'capacity'            => 'Site Capacity',
        'program_closure'     => 'Program Closure',
        'other'               => 'Other',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function fromSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'from_site_id');
    }

    public function toSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'to_site_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeDueForCompletion($query)
    {
        return $query->where('status', 'approved')
                     ->where('effective_date', '<=', now()->toDateString());
    }

    // ── State helpers ─────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Returns whether a prior site staff member at $siteId retains read-only
     * access to the participant within the 90-day post-transfer window.
     */
    public function priorSiteHasReadAccess(int $siteId): bool
    {
        if (!$this->isCompleted()) {
            return false;
        }

        return $this->from_site_id === $siteId
            && $this->effective_date->greaterThanOrEqualTo(now()->subDays(90));
    }
}
