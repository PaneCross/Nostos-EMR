<?php

// ─── WoundRecord Model ─────────────────────────────────────────────────────────
// Tracks wound and pressure injury records for PACE participants.
// High wound prevalence in frail elderly population; supports CMS quality metrics
// for new pressure injuries and nursing plan-of-care documentation.
//
// Stage 3/4/unstageable/DTI pressure injuries trigger critical alerts to
// primary_care + qa_compliance (CMS quality metric).
//
// SoftDeletes only — wound records are part of the clinical record (HIPAA).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WoundRecord extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_wound_records';

    // ── Enum constants ─────────────────────────────────────────────────────────

    public const WOUND_TYPES = [
        'pressure_injury', 'diabetic_foot_ulcer', 'venous_ulcer',
        'arterial_ulcer', 'surgical_wound', 'traumatic_wound',
        'moisture_associated', 'other',
    ];

    public const WOUND_TYPE_LABELS = [
        'pressure_injury'    => 'Pressure Injury',
        'diabetic_foot_ulcer'=> 'Diabetic Foot Ulcer',
        'venous_ulcer'       => 'Venous Ulcer',
        'arterial_ulcer'     => 'Arterial Ulcer',
        'surgical_wound'     => 'Surgical Wound',
        'traumatic_wound'    => 'Traumatic Wound',
        'moisture_associated'=> 'Moisture-Associated',
        'other'              => 'Other',
    ];

    /** Pressure injury stages that trigger CMS quality metric alerts (stage 3+). */
    public const CRITICAL_STAGES = ['stage_3', 'stage_4', 'unstageable', 'deep_tissue_injury'];

    public const PRESSURE_STAGES = [
        'stage_1', 'stage_2', 'stage_3', 'stage_4', 'unstageable', 'deep_tissue_injury',
    ];

    public const STAGE_LABELS = [
        'stage_1'           => 'Stage 1',
        'stage_2'           => 'Stage 2',
        'stage_3'           => 'Stage 3',
        'stage_4'           => 'Stage 4',
        'unstageable'       => 'Unstageable',
        'deep_tissue_injury'=> 'Deep Tissue Injury',
    ];

    public const STATUSES = ['open', 'healing', 'healed', 'deteriorating', 'stable'];

    public const GOALS = ['healing', 'maintenance', 'palliative'];

    // ── Fillable / Casts ──────────────────────────────────────────────────────

    protected $fillable = [
        'participant_id', 'tenant_id', 'site_id',
        'wound_type', 'location', 'pressure_injury_stage',
        'length_cm', 'width_cm', 'depth_cm',
        'wound_bed', 'exudate_amount', 'exudate_type', 'periwound_skin',
        'odor', 'pain_score',
        'treatment_description', 'dressing_type', 'dressing_change_frequency',
        'goal', 'status',
        'first_identified_date', 'healed_date',
        'documented_by_user_id', 'photo_taken', 'notes',
    ];

    protected $casts = [
        'first_identified_date' => 'date',
        'healed_date'           => 'date',
        'odor'                  => 'boolean',
        'photo_taken'           => 'boolean',
        'pain_score'            => 'integer',
        'length_cm'             => 'decimal:1',
        'width_cm'              => 'decimal:1',
        'depth_cm'              => 'decimal:1',
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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function documentedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'documented_by_user_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(WoundAssessment::class, 'wound_record_id')->orderByDesc('assessed_at');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForParticipant($query, int $participantId)
    {
        return $query->where('participant_id', $participantId);
    }

    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', ['healed']);
    }

    public function scopeCriticalStage($query)
    {
        // CMS quality metric: stage 3/4 and unstageable pressure injuries
        return $query->where('wound_type', 'pressure_injury')
            ->whereIn('pressure_injury_stage', self::CRITICAL_STAGES);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** Returns true for stage 3, 4, unstageable, or DTI pressure injuries. */
    public function isCriticalStage(): bool
    {
        return $this->wound_type === 'pressure_injury'
            && in_array($this->pressure_injury_stage, self::CRITICAL_STAGES, true);
    }

    public function isOpen(): bool
    {
        return $this->status !== 'healed';
    }

    /** Days since the wound was first identified. */
    public function daysOpen(): int
    {
        $end = $this->healed_date ?? now()->toDateString();

        return (int) \Carbon\Carbon::parse($this->first_identified_date)->diffInDays($end);
    }

    /** Human-readable wound type label. */
    public function woundTypeLabel(): string
    {
        return self::WOUND_TYPE_LABELS[$this->wound_type] ?? ucwords(str_replace('_', ' ', $this->wound_type));
    }

    /** Human-readable stage label for pressure injuries. */
    public function stageLabel(): ?string
    {
        if (! $this->pressure_injury_stage) {
            return null;
        }

        return self::STAGE_LABELS[$this->pressure_injury_stage] ?? $this->pressure_injury_stage;
    }

    /** API-safe array for frontend display. */
    public function toApiArray(): array
    {
        return [
            'id'                      => $this->id,
            'participant_id'          => $this->participant_id,
            'wound_type'              => $this->wound_type,
            'wound_type_label'        => $this->woundTypeLabel(),
            'location'                => $this->location,
            'pressure_injury_stage'   => $this->pressure_injury_stage,
            'stage_label'             => $this->stageLabel(),
            'is_critical_stage'       => $this->isCriticalStage(),
            'length_cm'               => $this->length_cm,
            'width_cm'                => $this->width_cm,
            'depth_cm'                => $this->depth_cm,
            'wound_bed'               => $this->wound_bed,
            'exudate_amount'          => $this->exudate_amount,
            'exudate_type'            => $this->exudate_type,
            'periwound_skin'          => $this->periwound_skin,
            'odor'                    => $this->odor,
            'pain_score'              => $this->pain_score,
            'treatment_description'   => $this->treatment_description,
            'dressing_type'           => $this->dressing_type,
            'dressing_change_frequency'=> $this->dressing_change_frequency,
            'goal'                    => $this->goal,
            'status'                  => $this->status,
            'first_identified_date'   => $this->first_identified_date?->toDateString(),
            'healed_date'             => $this->healed_date?->toDateString(),
            'days_open'               => $this->daysOpen(),
            'photo_taken'             => $this->photo_taken,
            'notes'                   => $this->notes,
            'documented_by'           => $this->documentedBy
                ? $this->documentedBy->first_name . ' ' . $this->documentedBy->last_name
                : null,
            'last_assessment_at'      => $this->assessments->first()?->assessed_at?->toIso8601String(),
            'assessment_count'        => $this->assessments->count(),
            'created_at'              => $this->created_at?->toIso8601String(),
        ];
    }
}
