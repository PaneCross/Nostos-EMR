<?php

// ─── Problem Model ─────────────────────────────────────────────────────────────
// Participant problem list entry (active diagnoses, chronic conditions).
// ICD-10 code and description stored inline so records survive lookup table updates.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Problem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_problems';

    public const STATUSES = ['active', 'resolved', 'chronic', 'ruled_out'];

    protected $fillable = [
        'participant_id', 'tenant_id',
        'icd10_code', 'icd10_description',
        'onset_date', 'resolved_date',
        'status',
        'added_by_user_id', 'last_reviewed_by_user_id', 'last_reviewed_at',
        'is_primary_diagnosis',
        'notes',
    ];

    protected $casts = [
        'onset_date'          => 'date',
        'resolved_date'       => 'date',
        'last_reviewed_at'    => 'datetime',
        'is_primary_diagnosis' => 'boolean',
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

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function lastReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by_user_id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'chronic']);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    public function statusLabel(): string
    {
        return match ($this->status) {
            'active'    => 'Active',
            'resolved'  => 'Resolved',
            'chronic'   => 'Chronic',
            'ruled_out' => 'Ruled Out',
            default     => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'active'    => 'text-red-700 bg-red-50 border-red-200',
            'resolved'  => 'text-green-700 bg-green-50 border-green-200',
            'chronic'   => 'text-amber-700 bg-amber-50 border-amber-200',
            'ruled_out' => 'text-gray-500 bg-gray-50 border-gray-200',
            default     => 'text-gray-700 bg-gray-50 border-gray-200',
        };
    }
}
