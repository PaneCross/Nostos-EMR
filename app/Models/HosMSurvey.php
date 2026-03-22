<?php

// ─── HosMSurvey ───────────────────────────────────────────────────────────────
// PACE-specific Health Outcomes Survey for Medicare (HOS-M) annual record.
//
// CMS requires PACE organizations to administer the HOS-M survey annually to
// all enrolled participants. Results feed into HPMS submissions and influence
// the PACE frailty adjuster used in capitation rate calculation.
//
// Enforced uniqueness: one survey per participant per calendar year.
// Responses stored as JSONB to accommodate evolving CMS survey format.
//
// Response fields:
//   physical_health: 1-5 (1=excellent, 5=poor)
//   mental_health:   1-5
//   pain:            1-5 (1=none, 5=severe)
//   falls_past_year: 0|1
//   fall_injuries:   0|1
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HosMSurvey extends Model
{
    use HasFactory;

    protected $table = 'emr_hos_m_surveys';

    protected $fillable = [
        'participant_id',
        'tenant_id',
        'survey_year',
        'administered_by_user_id',
        'administered_at',
        'completed',
        'responses',
        'submitted_to_cms',
        'submitted_at',
    ];

    protected $casts = [
        'administered_at'  => 'datetime',
        'submitted_at'     => 'datetime',
        'completed'        => 'boolean',
        'submitted_to_cms' => 'boolean',
        'responses'        => 'array',
        'survey_year'      => 'integer',
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

    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administered_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForTenant($q, int $id)
    {
        return $q->where('tenant_id', $id);
    }

    public function scopeForYear($q, int $year)
    {
        return $q->where('survey_year', $year);
    }

    public function scopePendingSubmission($q)
    {
        return $q->where('completed', true)->where('submitted_to_cms', false);
    }
}
