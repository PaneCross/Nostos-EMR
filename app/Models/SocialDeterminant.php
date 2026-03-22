<?php

// ─── SocialDeterminant Model ───────────────────────────────────────────────────
// SDOH screening record per participant (USCDI v3 Social Determinants of Health).
// Each row is a point-in-time assessment by a care team member.
// Maps to FHIR Observation resources using LOINC SDOH category codes.
//
// LOINC panel: 88122-7 (PRAPARE Social Determinants of Health screening)
// Individual LOINC codes used in FHIR Observation mapping (SdohObservationMapper).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialDeterminant extends Model
{
    use HasFactory;

    protected $table = 'emr_social_determinants';

    public const HOUSING_VALUES      = ['stable', 'at_risk', 'unstable', 'homeless', 'unknown'];
    public const FOOD_VALUES         = ['secure', 'at_risk', 'insecure', 'unknown'];
    public const TRANSPORT_VALUES    = ['adequate', 'limited', 'none', 'unknown'];
    public const ISOLATION_VALUES    = ['low', 'moderate', 'high', 'unknown'];
    public const STRAIN_VALUES       = ['none', 'mild', 'moderate', 'severe', 'unknown'];

    /** LOINC codes for FHIR Observation mapping. */
    public const LOINC_CODES = [
        'housing_stability'    => '71802-3',
        'food_security'        => '88122-7',
        'transportation_access'=> '93030-5',
        'social_isolation_risk'=> '93029-7',
        'caregiver_strain'     => '93038-8',
        'financial_strain'     => '68517-2',
    ];

    protected $fillable = [
        'participant_id', 'tenant_id', 'assessed_by_user_id', 'assessed_at',
        'housing_stability', 'food_security', 'transportation_access',
        'social_isolation_risk', 'caregiver_strain', 'financial_strain',
        'safety_concerns', 'notes',
    ];

    protected $casts = [
        'assessed_at' => 'datetime',
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

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_user_id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeLatestPerParticipant($query)
    {
        return $query->orderBy('assessed_at', 'desc');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** Returns true if any domain indicates elevated risk. */
    public function hasElevatedRisk(): bool
    {
        return in_array($this->housing_stability, ['at_risk', 'unstable', 'homeless'])
            || in_array($this->food_security, ['at_risk', 'insecure'])
            || $this->transportation_access === 'none'
            || $this->social_isolation_risk === 'high'
            || in_array($this->caregiver_strain, ['moderate', 'severe'])
            || in_array($this->financial_strain, ['moderate', 'severe'])
            || ! empty($this->safety_concerns);
    }

    /** Risk level badge color for UI. */
    public function riskColor(): string
    {
        return $this->hasElevatedRisk()
            ? 'text-red-700 bg-red-50 border-red-200'
            : 'text-green-700 bg-green-50 border-green-200';
    }

    /** Human-readable label for a domain value. */
    public static function valueLabel(string $value): string
    {
        return match ($value) {
            'at_risk'                  => 'At Risk',
            'unstable'                 => 'Unstable',
            'insecure'                 => 'Insecure',
            'incapacitated_no_directive' => 'Incapacitated (No Directive)',
            default                    => ucfirst(str_replace('_', ' ', $value)),
        };
    }
}
