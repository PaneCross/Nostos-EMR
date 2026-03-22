<?php

// ─── IntegrationLog Model ──────────────────────────────────────────────────────
// Records every inbound/outbound integration message for audit and retry.
//
// Key behaviors:
//   - Append-only: no updated_at, no SoftDeletes
//   - Status lifecycle: pending → processed | failed; failed → retried
//   - IT Admin can retry failed entries (increments retry_count)
//   - raw_payload stored as JSONB for query-ability
//
// Used by: Hl7AdtConnector, LabResultConnector, ItAdminController
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationLog extends Model
{
    use HasFactory;

    protected $table = 'emr_integration_log';

    // Append-only — no updated_at column
    public $timestamps = false;

    // ── Constants ─────────────────────────────────────────────────────────────

    public const CONNECTOR_TYPES = ['hl7_adt', 'lab_results', 'pharmacy_ncpdp', 'other'];
    public const DIRECTIONS       = ['inbound', 'outbound'];
    public const STATUSES         = ['pending', 'processed', 'failed', 'retried'];

    // ── Fillable / Casts ──────────────────────────────────────────────────────

    protected $fillable = [
        'tenant_id',
        'connector_type',
        'direction',
        'raw_payload',
        'processed_at',
        'status',
        'error_message',
        'retry_count',
    ];

    protected $casts = [
        'raw_payload'  => 'array',
        'processed_at' => 'datetime',
        'retry_count'  => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Methods ───────────────────────────────────────────────────────────────

    /** Mark as successfully processed. */
    public function markProcessed(): bool
    {
        return $this->forceFill([
            'status'       => 'processed',
            'processed_at' => now(),
        ])->save();
    }

    /** Mark as failed with error message. */
    public function markFailed(string $errorMessage): bool
    {
        return $this->forceFill([
            'status'        => 'failed',
            'error_message' => $errorMessage,
            'processed_at'  => now(),
        ])->save();
    }

    /** Increment retry counter and set status to retried. */
    public function markRetried(): bool
    {
        return $this->forceFill([
            'status'      => 'retried',
            'retry_count' => $this->retry_count + 1,
        ])->save();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Scope queries to a specific tenant. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Only failed entries (eligible for retry). */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /** Filter by connector type. */
    public function scopeForConnector($query, string $connectorType)
    {
        return $query->where('connector_type', $connectorType);
    }
}
