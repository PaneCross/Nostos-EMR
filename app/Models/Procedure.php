<?php

// ─── Procedure Model ───────────────────────────────────────────────────────────
// Standalone procedure history for PACE participants (USCDI v3 Procedures).
// Distinct from encounter_log.procedure_code — this tracks the full procedure
// narrative with CPT/SNOMED coding for FHIR R4 Procedure resource mapping.
//
// source enum:
//   internal        — documented by PACE staff during a visit
//   external_report — received from hospital/specialist report
//   patient_reported — self-reported by participant or caregiver
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Procedure extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_procedures';

    public const SOURCES = ['internal', 'external_report', 'patient_reported'];

    protected $fillable = [
        'participant_id', 'tenant_id', 'performed_by_user_id',
        'procedure_name', 'cpt_code', 'snomed_code',
        'performed_date', 'facility', 'body_site', 'outcome', 'notes', 'source',
    ];

    protected $casts = [
        'performed_date' => 'date',
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

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'internal'         => 'PACE Staff',
            'external_report'  => 'External Report',
            'patient_reported' => 'Patient Reported',
            default            => ucfirst($this->source),
        };
    }

    public function sourceBadgeColor(): string
    {
        return match ($this->source) {
            'internal'         => 'text-blue-700 bg-blue-50 border-blue-200',
            'external_report'  => 'text-purple-700 bg-purple-50 border-purple-200',
            'patient_reported' => 'text-amber-700 bg-amber-50 border-amber-200',
            default            => 'text-gray-700 bg-gray-50 border-gray-200',
        };
    }
}
