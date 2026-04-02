<?php

// ─── BreakGlassEvent Model ─────────────────────────────────────────────────────
// HIPAA Emergency Access Override log. Records all instances where a user
// invoked emergency access to a participant's chart outside their normal RBAC scope.
//
// This table is append-only — no UPDATE or DELETE ever. Each row is a permanent
// immutable audit record. access_expires_at = access_granted_at + 4 hours.
//
// Supervisor acknowledgment confirms the event was reviewed post-hoc.
// Unacknowledged events >24 hours old are flagged in IT Admin dashboard widget.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreakGlassEvent extends Model
{
    use HasFactory;

    protected $table = 'emr_break_glass_events';

    // Append-only: no updated_at column
    const UPDATED_AT = null;

    /** Duration of emergency access in hours (HIPAA reasonable access window). */
    const ACCESS_DURATION_HOURS = 4;

    /** Maximum break-glass requests per user per 24 hours (abuse prevention). */
    const RATE_LIMIT_PER_DAY = 3;

    protected $fillable = [
        'user_id', 'tenant_id', 'participant_id',
        'justification',
        'access_granted_at', 'access_expires_at',
        'ip_address',
        'acknowledged_by_supervisor_user_id', 'acknowledged_at',
    ];

    protected $casts = [
        'access_granted_at'  => 'datetime',
        'access_expires_at'  => 'datetime',
        'acknowledged_at'    => 'datetime',
        'created_at'         => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_supervisor_user_id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        // Access is active if expiry is in the future
        return $query->where('access_expires_at', '>', now());
    }

    public function scopeUnacknowledged($query)
    {
        return $query->whereNull('acknowledged_at');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** Returns true if this break-glass access is currently valid (not yet expired). */
    public function isActive(): bool
    {
        return $this->access_expires_at->isFuture();
    }

    /** Returns true if a supervisor has acknowledged review of this event. */
    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /** API-safe array for IT Admin break-glass log. */
    public function toApiArray(): array
    {
        return [
            'id'              => $this->id,
            'user'            => $this->user
                ? ['id' => $this->user->id, 'name' => $this->user->first_name . ' ' . $this->user->last_name, 'department' => $this->user->department]
                : null,
            'participant'     => $this->participant
                ? ['id' => $this->participant->id, 'name' => $this->participant->first_name . ' ' . $this->participant->last_name, 'mrn' => $this->participant->mrn]
                : null,
            'justification'   => $this->justification,
            'access_granted_at'=> $this->access_granted_at?->toIso8601String(),
            'access_expires_at'=> $this->access_expires_at?->toIso8601String(),
            'is_active'       => $this->isActive(),
            'is_acknowledged' => $this->isAcknowledged(),
            'acknowledged_by' => $this->acknowledgedBy
                ? $this->acknowledgedBy->first_name . ' ' . $this->acknowledgedBy->last_name
                : null,
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'ip_address'      => $this->ip_address,
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
