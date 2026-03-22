<?php

// ─── Authorization ────────────────────────────────────────────────────────────
// Represents a service authorization for a participant.
//
// Authorizations define the permitted scope (service type, units, date range)
// for a PACE participant's care. The Finance team uses these to validate
// encounter billing and to flag upcoming expirations for renewal action.
//
// Status lifecycle: active → expired (auto by date) | cancelled (manual)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Authorization extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_authorizations';

    public const STATUSES = ['active', 'expired', 'cancelled'];

    /** Types of services that commonly require authorization in PACE. */
    public const SERVICE_TYPES = [
        'home_care'         => 'Home Health Care',
        'specialist'        => 'Specialist Services',
        'dme'               => 'Durable Medical Equipment',
        'therapy'           => 'Therapy Services',
        'behavioral_health' => 'Behavioral Health Services',
        'transportation'    => 'Non-Emergency Transportation',
        'pharmacy'          => 'Specialty Pharmacy',
        'other'             => 'Other',
    ];

    protected $fillable = [
        'tenant_id',
        'participant_id',
        'service_type',
        'authorized_units',
        'authorized_start',
        'authorized_end',
        'status',
        'notes',
    ];

    protected $casts = [
        'authorized_start' => 'date',
        'authorized_end'   => 'date',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Active authorizations expiring within $days days. */
    public function scopeExpiringWithin($query, int $days = 30)
    {
        return $query->where('status', 'active')
            ->where('authorized_end', '>=', now()->toDateString())
            ->where('authorized_end', '<=', now()->addDays($days)->toDateString());
    }

    /** Active authorizations only. */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** True if authorization is currently valid (active and within date range). */
    public function isValid(): bool
    {
        return $this->status === 'active'
            && $this->authorized_start->lte(now())
            && $this->authorized_end->gte(now());
    }

    /** Days until expiration (negative if already expired). */
    public function daysUntilExpiry(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->authorized_end, false);
    }
}
