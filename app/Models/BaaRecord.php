<?php

// ─── BaaRecord ─────────────────────────────────────────────────────────────────
// Tracks Business Associate Agreements per HIPAA 45 CFR §164.308(b)(1).
//
// Every vendor that creates, receives, maintains, or transmits ePHI on behalf
// of the PACE organization must have a signed BAA. This model tracks the BAA
// lifecycle from pending → active → expiring/expired so IT Admin can monitor
// compliance without relying on manual spreadsheets.
//
// Expiration warning: isExpiringSoon() returns true within EXPIRING_SOON_DAYS (60)
// days of baa_expiration_date. This drives the amber badges in the Security UI
// and the compliance posture widget on the QA dashboard.
//
// NOTE: status is manually maintained by IT Admin. isExpired() / isExpiringSoon()
// are runtime computed from the date field — use these for real-time checks,
// not the status column which may be stale if not manually updated.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BaaRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_baa_records';

    // ── Constants ─────────────────────────────────────────────────────────────

    /** Vendor categories — drive the UI filter chips and badge colors */
    public const VENDOR_TYPES = [
        'cloud_provider', 'clearinghouse', 'lab', 'pharmacy',
        'ehr', 'telehealth', 'it_services', 'other',
    ];

    public const VENDOR_TYPE_LABELS = [
        'cloud_provider' => 'Cloud Provider',
        'clearinghouse'  => 'Clearinghouse',
        'lab'            => 'Laboratory',
        'pharmacy'       => 'Pharmacy',
        'ehr'            => 'EHR / Health IT',
        'telehealth'     => 'Telehealth',
        'it_services'    => 'IT Services',
        'other'          => 'Other',
    ];

    /** BAA status lifecycle — manually maintained by IT Admin */
    public const STATUSES = ['active', 'expiring_soon', 'expired', 'pending', 'terminated'];

    public const STATUS_LABELS = [
        'active'        => 'Active',
        'expiring_soon' => 'Expiring Soon',
        'expired'       => 'Expired',
        'pending'       => 'Pending Signature',
        'terminated'    => 'Terminated',
    ];

    /**
     * BAAs with expiration within this many days trigger amber warnings.
     * CMS survey practice: 60-day renewal lead time is standard for healthcare BAAs.
     */
    public const EXPIRING_SOON_DAYS = 60;

    // ── Fillable ──────────────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id', 'vendor_name', 'vendor_type', 'phi_accessed',
        'baa_signed_date', 'baa_expiration_date', 'status',
        'contact_name', 'contact_email', 'contact_phone', 'notes',
    ];

    protected $casts = [
        'baa_signed_date'     => 'date',
        'baa_expiration_date' => 'date',
        'phi_accessed'        => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Scope to a specific PACE organization */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * BAAs expiring within EXPIRING_SOON_DAYS and not yet terminated.
     * Used by compliance posture widget and SecurityComplianceController.
     */
    public function scopeExpiringSoon($query)
    {
        return $query
            ->whereNotNull('baa_expiration_date')
            ->where('baa_expiration_date', '>=', now()->toDateString())
            ->where('baa_expiration_date', '<=', now()->addDays(self::EXPIRING_SOON_DAYS)->toDateString())
            ->where('status', '!=', 'terminated');
    }

    /**
     * BAAs that are past their expiration date.
     * Used by compliance posture widget to count active risk items.
     */
    public function scopeExpired($query)
    {
        return $query
            ->whereNotNull('baa_expiration_date')
            ->where('baa_expiration_date', '<', now()->toDateString())
            ->where('status', '!=', 'terminated');
    }

    // ── Business logic helpers ────────────────────────────────────────────────

    /**
     * Returns true if the BAA expiration date is in the past.
     * Runtime check — use this instead of checking status='expired'
     * since the status field may not be manually updated promptly.
     */
    public function isExpired(): bool
    {
        if ($this->status === 'terminated') {
            return false; // terminated is a distinct state, not expired
        }
        return $this->baa_expiration_date !== null
            && $this->baa_expiration_date->isPast();
    }

    /**
     * Returns true if the BAA expires within EXPIRING_SOON_DAYS days.
     * Triggers amber badges. False if already expired or terminated.
     */
    public function isExpiringSoon(): bool
    {
        if ($this->isExpired() || $this->status === 'terminated') {
            return false;
        }
        if ($this->baa_expiration_date === null) {
            return false;
        }
        // Carbon 3: call diffInDays FROM now() TO the expiration date so the result
        // is a positive "days remaining" value. Calling from $expiration->diffInDays(now())
        // can return a negative sign for future dates, causing false positives.
        return $this->baa_expiration_date->isFuture()
            && now()->diffInDays($this->baa_expiration_date) <= self::EXPIRING_SOON_DAYS;
    }

    /** Human-readable status label */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Human-readable vendor type label */
    public function vendorTypeLabel(): string
    {
        return self::VENDOR_TYPE_LABELS[$this->vendor_type] ?? $this->vendor_type;
    }

    /**
     * Serialize for API/Inertia responses.
     * Computed is_expired and is_expiring_soon are always fresh
     * (date-based, not from stale status column).
     */
    public function toApiArray(): array
    {
        return [
            'id'                  => $this->id,
            'vendor_name'         => $this->vendor_name,
            'vendor_type'         => $this->vendor_type,
            'vendor_type_label'   => $this->vendorTypeLabel(),
            'phi_accessed'        => $this->phi_accessed,
            'baa_signed_date'     => $this->baa_signed_date?->toDateString(),
            'baa_expiration_date' => $this->baa_expiration_date?->toDateString(),
            'status'              => $this->status,
            'status_label'        => $this->statusLabel(),
            'is_expired'          => $this->isExpired(),
            'is_expiring_soon'    => $this->isExpiringSoon(),
            'contact_name'        => $this->contact_name,
            'contact_email'       => $this->contact_email,
            'contact_phone'       => $this->contact_phone,
            'notes'               => $this->notes,
            'created_at'          => $this->created_at?->toDateString(),
        ];
    }
}
