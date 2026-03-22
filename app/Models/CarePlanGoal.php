<?php

// ─── CarePlanGoal Model ───────────────────────────────────────────────────────
// Domain-specific goals within a care plan.
// Each domain (medical, nursing, social, etc.) has one goal entry per plan version.
//
// Domains map directly to PACE disciplines. A complete care plan has one goal
// per domain (up to 12 domains).
//
// Status lifecycle: active → met | modified | discontinued
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarePlanGoal extends Model
{
    use HasFactory;

    protected $table = 'emr_care_plan_goals';

    public const DOMAINS = [
        'medical',
        'nursing',
        'social',
        'behavioral',
        'therapy_pt',
        'therapy_ot',
        'therapy_st',
        'dietary',
        'activities',
        'home_care',
        'transportation',
        'pharmacy',
    ];

    // Maps domain to human-readable label and corresponding department
    public const DOMAIN_LABELS = [
        'medical'        => ['label' => 'Medical',               'dept' => 'primary_care'],
        'nursing'        => ['label' => 'Nursing',               'dept' => 'primary_care'],
        'social'         => ['label' => 'Social Work',           'dept' => 'social_work'],
        'behavioral'     => ['label' => 'Behavioral Health',     'dept' => 'behavioral_health'],
        'therapy_pt'     => ['label' => 'Physical Therapy',      'dept' => 'therapies'],
        'therapy_ot'     => ['label' => 'Occupational Therapy',  'dept' => 'therapies'],
        'therapy_st'     => ['label' => 'Speech Therapy',        'dept' => 'therapies'],
        'dietary'        => ['label' => 'Dietary / Nutrition',   'dept' => 'dietary'],
        'activities'     => ['label' => 'Activities',            'dept' => 'activities'],
        'home_care'      => ['label' => 'Home Care',             'dept' => 'home_care'],
        'transportation' => ['label' => 'Transportation',        'dept' => 'transportation'],
        'pharmacy'       => ['label' => 'Pharmacy',              'dept' => 'pharmacy'],
    ];

    public const STATUSES = ['active', 'met', 'modified', 'discontinued'];

    protected $fillable = [
        'care_plan_id',
        'domain',
        'goal_description',
        'target_date',
        'measurable_outcomes',
        'interventions',
        'status',
        'authored_by_user_id',
        'last_updated_by_user_id',
    ];

    protected $casts = [
        'target_date' => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class, 'care_plan_id');
    }

    public function authoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by_user_id');
    }

    public function lastUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_updated_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** Human-readable domain label. */
    public function domainLabel(): string
    {
        return self::DOMAIN_LABELS[$this->domain]['label']
            ?? ucwords(str_replace('_', ' ', $this->domain));
    }

    /** Tailwind status badge classes. */
    public function statusClasses(): string
    {
        return match ($this->status) {
            'active'       => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            'met'          => 'bg-green-50 text-green-700 ring-green-600/20',
            'modified'     => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            'discontinued' => 'bg-gray-50 text-gray-700 ring-gray-600/20',
            default        => 'bg-gray-50 text-gray-700 ring-gray-600/20',
        };
    }
}
