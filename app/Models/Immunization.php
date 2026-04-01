<?php

// ─── Immunization Model ────────────────────────────────────────────────────────
// Tracks vaccine administrations and refusals for PACE participants.
// Supports HPMS quality reporting (flu/pneumo rates) and FHIR R4 Immunization.
//
// CVX codes: CDC standard vaccine codes used in FHIR Immunization resources.
// refused=true: amber badge in UI; refusal_reason captured for compliance record.
// next_dose_due: highlighted in UI when overdue (date < today).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Immunization extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_immunizations';

    public const VACCINE_TYPES = [
        'influenza', 'pneumococcal_ppsv23', 'pneumococcal_pcv15',
        'pneumococcal_pcv20', 'covid_19', 'tdap', 'shingles',
        'hepatitis_b', 'other',
    ];

    /** CDC CVX codes for FHIR mapping. */
    public const CVX_CODES = [
        'influenza'            => '141',
        'pneumococcal_ppsv23'  => '33',
        'pneumococcal_pcv15'   => '215',
        'pneumococcal_pcv20'   => '216',
        'covid_19'             => '212',
        'tdap'                 => '115',
        'shingles'             => '187',
        'hepatitis_b'          => '08',
        'other'                => null,
    ];

    protected $fillable = [
        'participant_id', 'tenant_id',
        'vaccine_type', 'vaccine_name', 'cvx_code',
        'administered_date', 'administered_by_user_id', 'administered_at_location',
        'lot_number', 'manufacturer', 'dose_number',
        'next_dose_due', 'refused', 'refusal_reason',
        // W4-4 QW-11: VIS documentation (42 USC 300aa-26 + CMS PACE guidelines)
        'vis_given', 'vis_publication_date',
    ];

    protected $casts = [
        'administered_date'   => 'date',
        'next_dose_due'       => 'date',
        'refused'             => 'boolean',
        'dose_number'         => 'integer',
        'vis_given'           => 'boolean',
        'vis_publication_date' => 'date:Y-m-d',
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

    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by_user_id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('next_dose_due')
            ->where('next_dose_due', '<', now()->toDateString())
            ->where('refused', false);
    }

    public function scopeForVaccineType($query, string $type)
    {
        return $query->where('vaccine_type', $type);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** Human-readable vaccine type label. */
    public function vaccineTypeLabel(): string
    {
        return match ($this->vaccine_type) {
            'influenza'           => 'Influenza (Flu)',
            'pneumococcal_ppsv23' => 'Pneumococcal PPSV23',
            'pneumococcal_pcv15'  => 'Pneumococcal PCV15',
            'pneumococcal_pcv20'  => 'Pneumococcal PCV20',
            'covid_19'            => 'COVID-19',
            'tdap'                => 'Tdap',
            'shingles'            => 'Shingles (Zoster)',
            'hepatitis_b'         => 'Hepatitis B',
            'other'               => $this->vaccine_name,
            default               => ucfirst($this->vaccine_type),
        };
    }

    /** Whether next dose is overdue. */
    public function isOverdue(): bool
    {
        return $this->next_dose_due !== null
            && $this->next_dose_due->isPast()
            && ! $this->refused;
    }

    /** Resolve CVX code: explicit override takes precedence over type default. */
    public function resolvedCvxCode(): ?string
    {
        return $this->cvx_code ?? self::CVX_CODES[$this->vaccine_type] ?? null;
    }
}
