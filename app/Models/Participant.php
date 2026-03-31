<?php

// ─── Participant Model ─────────────────────────────────────────────────────────
// The central record for every PACE participant (patient) in the system.
//
// Multi-tenancy: every participant belongs to a tenant (PACE organization) and a
// site (physical PACE center). All queries must be scoped by tenant_id — use the
// forTenant() scope or the CheckDepartmentAccess middleware, never query globally.
//
// MRN: auto-generated on creation by MrnService using the site's mrn_prefix +
// a zero-padded sequence (e.g., "SRP-000042"). Once set it is never changed.
//
// Enrollment lifecycle:
//   pending → enrolled → (deceased | disenrolled | transferred)
//   Only 'enrolled' participants appear in the active census.
//   Soft deletes preserve the record for audit purposes; use is_active=false
//   to hide a participant from views without deleting the row.
//
// Clinical data (Phase 3+) is stored in child models (ClinicalNote, Vital, etc.)
// and loaded lazily via their own endpoints. Only small datasets (allergies,
// problems) are pre-loaded in ParticipantController::show() to keep page payload small.
//
// NF eligibility: nursing_facility_eligible + nf_certification_date track CMS
// nursing-home level-of-care certification (required for PACE enrollment).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use App\Services\MrnService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Participant extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_participants';

    protected $fillable = [
        'tenant_id', 'site_id', 'mrn',
        'first_name', 'last_name', 'preferred_name', 'dob', 'gender', 'pronouns',
        'ssn_last_four', 'medicare_id', 'medicaid_id', 'pace_contract_id', 'h_number',
        'primary_language', 'interpreter_needed', 'interpreter_language',
        'enrollment_status', 'enrollment_date', 'disenrollment_date', 'disenrollment_reason',
        'nursing_facility_eligible', 'nf_certification_date',
        'photo_path', 'is_active', 'created_by_user_id',
        // Phase 11B: advance directive structured fields
        'advance_directive_status', 'advance_directive_type',
        'advance_directive_reviewed_at', 'advance_directive_reviewed_by_user_id',
    ];

    protected $casts = [
        'dob'                           => 'date',
        'enrollment_date'               => 'date',
        'disenrollment_date'            => 'date',
        'nf_certification_date'         => 'date',
        'interpreter_needed'            => 'boolean',
        'nursing_facility_eligible'     => 'boolean',
        'is_active'                     => 'boolean',
        'advance_directive_reviewed_at' => 'date',
    ];

    // ─── Auto-generate MRN on creation ────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Participant $participant) {
            if (empty($participant->mrn)) {
                $site = Site::findOrFail($participant->site_id);
                $participant->mrn = app(MrnService::class)->generate($site);
            }
        });
    }

    // ─── Relationships ─────────────────────────────────────────────────────────

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ParticipantAddress::class, 'participant_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ParticipantContact::class, 'participant_id');
    }

    public function flags(): HasMany
    {
        return $this->hasMany(ParticipantFlag::class, 'participant_id');
    }

    public function activeFlags(): HasMany
    {
        return $this->hasMany(ParticipantFlag::class, 'participant_id')
            ->where('is_active', true)
            ->whereNull('deleted_at');
    }

    public function insuranceCoverages(): HasMany
    {
        return $this->hasMany(InsuranceCoverage::class, 'participant_id');
    }

    // ─── Phase 3: Clinical relationships ──────────────────────────────────────

    public function clinicalNotes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class, 'participant_id');
    }

    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class, 'participant_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'participant_id');
    }

    public function problems(): HasMany
    {
        return $this->hasMany(Problem::class, 'participant_id');
    }

    public function allergies(): HasMany
    {
        return $this->hasMany(Allergy::class, 'participant_id');
    }

    public function adlRecords(): HasMany
    {
        return $this->hasMany(AdlRecord::class, 'participant_id');
    }

    public function adlThresholds(): HasMany
    {
        return $this->hasMany(AdlThreshold::class, 'participant_id');
    }

    /** Active allergies with life-threatening severity — drives the red profile banner. */
    public function activeLifeThreateningAllergies(): HasMany
    {
        return $this->hasMany(Allergy::class, 'participant_id')
            ->where('is_active', true)
            ->where('severity', Allergy::LIFE_THREATENING);
    }

    // ─── Phase 5A: Scheduling relationships ───────────────────────────────────

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'participant_id');
    }

    // ─── Phase 5C: Medications relationships ──────────────────────────────────

    public function medications(): HasMany
    {
        return $this->hasMany(Medication::class, 'participant_id');
    }

    public function emarRecords(): HasMany
    {
        return $this->hasMany(EmarRecord::class, 'participant_id');
    }

    public function medReconciliations(): HasMany
    {
        return $this->hasMany(MedReconciliation::class, 'participant_id');
    }

    public function drugInteractionAlerts(): HasMany
    {
        return $this->hasMany(DrugInteractionAlert::class, 'participant_id');
    }

    public function immunizations(): HasMany
    {
        return $this->hasMany(Immunization::class, 'participant_id');
    }

    public function socialDeterminants(): HasMany
    {
        return $this->hasMany(SocialDeterminant::class, 'participant_id');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(Procedure::class, 'participant_id');
    }

    public function ehiExports(): HasMany
    {
        return $this->hasMany(EhiExport::class, 'participant_id');
    }

    /** Unacknowledged drug interaction alerts — shown in header/banner. */
    public function unacknowledgedInteractionAlerts(): HasMany
    {
        return $this->hasMany(DrugInteractionAlert::class, 'participant_id')
            ->where('is_acknowledged', false);
    }

    // ─── Phase 10A / W3-6: Site transfer relationships ────────────────────────

    public function siteTransfers(): HasMany
    {
        return $this->hasMany(ParticipantSiteTransfer::class, 'participant_id');
    }

    /**
     * Returns true if this participant has ever completed a site transfer.
     * Used to decide whether site-source labels should appear on clinical data.
     */
    public function hasMultipleSites(): bool
    {
        return $this->siteTransfers()->where('status', 'completed')->exists();
    }

    // ─── Computed helpers ──────────────────────────────────────────────────────

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function displayName(): string
    {
        $name = $this->fullName();
        if ($this->preferred_name) {
            $name .= " \"{$this->preferred_name}\"";
        }
        return $name;
    }

    public function age(): int
    {
        return $this->dob->age;
    }

    public function isDeceased(): bool
    {
        return $this->enrollment_status === 'deceased';
    }

    /** Whether participant has a DNR specifically (not POLST/living will). */
    public function hasDnr(): bool
    {
        return $this->advance_directive_status === 'has_directive'
            && $this->advance_directive_type === 'dnr';
    }

    /** Advance directive status label for display. */
    public function advanceDirectiveLabel(): ?string
    {
        return match ($this->advance_directive_status) {
            'has_directive'               => match ($this->advance_directive_type) {
                'dnr'            => 'DNR on File',
                'polst'          => 'POLST on File',
                'living_will'    => 'Living Will on File',
                'healthcare_proxy'=> 'Healthcare Proxy on File',
                'combined'       => 'Full Directive on File',
                default          => 'Directive on File',
            },
            'declined_directive'          => 'Declined Directive',
            'incapacitated_no_directive'  => 'Incapacitated — No Directive',
            'unknown'                     => 'Directive Status Unknown',
            default                       => null,
        };
    }

    public function isEnrolled(): bool
    {
        return $this->enrollment_status === 'enrolled';
    }

    // ─── Scopes ────────────────────────────────────────────────────────────────

    /** Restrict results to the given tenant. Always apply before returning participant lists. */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Full-text name/MRN search across the participant directory.
     * Searches first_name, last_name, MRN, and concatenated full name.
     * Uses PostgreSQL case-insensitive ILIKE — not portable to MySQL.
     * The CONCAT clause catches "John Doe" style searches where neither token
     * matches a single column individually.
     */
    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('first_name', 'ilike', "%{$term}%")
              ->orWhere('last_name', 'ilike', "%{$term}%")
              ->orWhere('mrn', 'ilike', "%{$term}%")
              ->orWhereRaw("CONCAT(first_name, ' ', last_name) ILIKE ?", ["%{$term}%"]);
        });
    }

    /**
     * Exact date-of-birth lookup used by front desk to locate participants
     * when a name search returns multiple results.
     * Accepts ISO date string (Y-m-d).
     */
    public function scopeSearchByDob($query, string $dob)
    {
        return $query->whereDate('dob', $dob);
    }
}
