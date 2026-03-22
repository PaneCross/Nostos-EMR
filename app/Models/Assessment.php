<?php

// ─── Assessment Model ─────────────────────────────────────────────────────────────
// Structured clinical assessments: PHQ-9, MMSE, Morse fall scale, ADL functional, etc.
// Responses are stored as a jsonb object keyed by the template field names.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assessment extends Model
{
    use HasFactory;

    protected $table = 'emr_assessments';

    // ── Valid assessment types ────────────────────────────────────────────────
    public const TYPES = [
        'initial_comprehensive', 'adl_functional', 'mmse_cognitive',
        'phq9_depression', 'gad7_anxiety', 'nutritional',
        'fall_risk_morse', 'pain_scale', 'annual_reassessment', 'custom',
    ];

    // ── Score ranges by type (max score) ─────────────────────────────────────
    public const SCORE_MAX = [
        'phq9_depression'  => 27,
        'gad7_anxiety'     => 21,
        'mmse_cognitive'   => 30,
        'fall_risk_morse'  => 125,
        'pain_scale'       => 10,
    ];

    protected $fillable = [
        'participant_id', 'tenant_id', 'authored_by_user_id', 'department',
        'assessment_type', 'responses', 'score',
        'completed_at', 'next_due_date', 'threshold_flags',
    ];

    protected $casts = [
        'responses'       => 'array',
        'threshold_flags' => 'array',
        'completed_at'    => 'datetime',
        'next_due_date'   => 'date',
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by_user_id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('next_due_date')
            ->where('next_due_date', '<', today());
    }

    public function scopeDueSoon($query, int $days = 14)
    {
        return $query->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [today(), today()->addDays($days)]);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where('assessment_type', $type);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    public function isOverdue(): bool
    {
        return $this->next_due_date && $this->next_due_date->isPast();
    }

    public function isDueSoon(int $days = 14): bool
    {
        if (! $this->next_due_date) {
            return false;
        }
        return ! $this->isOverdue() && $this->next_due_date->lte(today()->addDays($days));
    }

    /**
     * Human-readable score label including context (e.g., "14/27 — Moderate").
     */
    public function scoredLabel(): ?string
    {
        if ($this->score === null) {
            return null;
        }
        $max = self::SCORE_MAX[$this->assessment_type] ?? null;
        $base = $max ? "{$this->score}/{$max}" : (string) $this->score;

        $severity = match ($this->assessment_type) {
            'phq9_depression' => match (true) {
                $this->score <= 4  => 'Minimal',
                $this->score <= 9  => 'Mild',
                $this->score <= 14 => 'Moderate',
                $this->score <= 19 => 'Moderately Severe',
                default            => 'Severe',
            },
            'gad7_anxiety' => match (true) {
                $this->score <= 4  => 'Minimal',
                $this->score <= 9  => 'Mild',
                $this->score <= 14 => 'Moderate',
                default            => 'Severe',
            },
            'mmse_cognitive' => match (true) {
                $this->score >= 24 => 'Normal',
                $this->score >= 19 => 'Mild Impairment',
                $this->score >= 10 => 'Moderate Impairment',
                default            => 'Severe Impairment',
            },
            'fall_risk_morse' => match (true) {
                $this->score <= 24 => 'Low Risk',
                $this->score <= 44 => 'Medium Risk',
                default            => 'High Risk',
            },
            default => null,
        };

        return $severity ? "{$base} — {$severity}" : $base;
    }

    /**
     * Human-readable label for the assessment type.
     */
    public function typeLabel(): string
    {
        return match ($this->assessment_type) {
            'initial_comprehensive' => 'Initial Comprehensive',
            'adl_functional'        => 'ADL Functional',
            'mmse_cognitive'        => 'MMSE Cognitive',
            'phq9_depression'       => 'PHQ-9 Depression Screen',
            'gad7_anxiety'          => 'GAD-7 Anxiety Screen',
            'nutritional'           => 'Nutritional Assessment',
            'fall_risk_morse'       => 'Fall Risk (Morse Scale)',
            'pain_scale'            => 'Pain Scale',
            'annual_reassessment'   => 'Annual Reassessment',
            'custom'                => 'Custom Assessment',
            default                 => ucwords(str_replace('_', ' ', $this->assessment_type)),
        };
    }
}
