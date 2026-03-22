<?php

// ─── EhiExport Model ───────────────────────────────────────────────────────────
// Tracks Electronic Health Information (EHI) export requests per participant.
// Required by 21st Century Cures Act / ONC Information Blocking Rule.
//
// Lifecycle:
//   pending → ready (ZIP generated) → downloaded (or) expired (24h TTL)
//
// token: 64-char hex, single-use, time-limited download link.
// file_path: relative path under storage/app/ehi-exports/{participant_id}/
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EhiExport extends Model
{
    use HasFactory;

    protected $table = 'emr_ehi_exports';

    public const STATUSES = ['pending', 'ready', 'expired'];

    protected $fillable = [
        'participant_id', 'tenant_id', 'requested_by_user_id',
        'token', 'file_path', 'status', 'expires_at', 'downloaded_at',
    ];

    protected $casts = [
        'expires_at'    => 'datetime',
        'downloaded_at' => 'datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at->isPast() || $this->status === 'expired';
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready' && ! $this->isExpired();
    }
}
