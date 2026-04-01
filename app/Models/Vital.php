<?php

// ─── Vital Model ─────────────────────────────────────────────────────────────────
// Append-only vitals record. No SoftDeletes — each row is an immutable snapshot
// of measured values at a point in time.
// isOutOfRange() returns which fields fall outside clinical normal ranges for PACE.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vital extends Model
{
    use HasFactory;

    protected $table = 'emr_vitals';

    // ── No updated_at (append-only) ───────────────────────────────────────────
    public const UPDATED_AT = null;

    protected $fillable = [
        'participant_id', 'tenant_id', 'recorded_by_user_id', 'recorded_at',
        'bp_systolic', 'bp_diastolic',
        'pulse', 'respiratory_rate', 'temperature_f', 'o2_saturation',
        'weight_lbs', 'height_in',
        'pain_score', 'blood_glucose', 'blood_glucose_timing',
        'position', 'notes',
    ];

    // ── Computed attributes ───────────────────────────────────────────────────

    // BMI is appended to every Vital response (null when height or weight absent)
    protected $appends = ['bmi'];

    protected $casts = [
        'recorded_at'   => 'datetime',
        'temperature_f' => 'decimal:1',
        'weight_lbs'    => 'decimal:1',
        'height_in'     => 'decimal:1',
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

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeRecentFirst($query)
    {
        return $query->orderByDesc('recorded_at');
    }

    public function scopeWithinDays($query, int $days)
    {
        return $query->where('recorded_at', '>=', now()->subDays($days));
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Compute BMI from weight_lbs and height_in.
     * Formula: weight_kg / height_m². Returns null when either field is absent.
     * QW-01 — surfaced on Vitals tab, Dietary dashboard, and Facesheet.
     */
    public function getBmiAttribute(): ?float
    {
        if (!$this->weight_lbs || !$this->height_in) {
            return null;
        }
        $weight_kg = $this->weight_lbs * 0.453592;
        $height_m  = $this->height_in * 0.0254;
        return round($weight_kg / ($height_m ** 2), 1);
    }

    /**
     * Returns an array of field names that fall outside clinical normal ranges.
     * Based on standard PACE/elderly population reference ranges.
     *
     * @return array<string, string>  ['field' => 'low'|'high']
     */
    public function isOutOfRange(): array
    {
        $flags = [];

        if ($this->bp_systolic !== null) {
            if ($this->bp_systolic < 90)  $flags['bp_systolic'] = 'low';
            if ($this->bp_systolic > 180) $flags['bp_systolic'] = 'high';
        }
        if ($this->bp_diastolic !== null) {
            if ($this->bp_diastolic < 60) $flags['bp_diastolic'] = 'low';
            if ($this->bp_diastolic > 110) $flags['bp_diastolic'] = 'high';
        }
        if ($this->pulse !== null) {
            if ($this->pulse < 50)  $flags['pulse'] = 'low';
            if ($this->pulse > 100) $flags['pulse'] = 'high';
        }
        if ($this->o2_saturation !== null && $this->o2_saturation < 92) {
            $flags['o2_saturation'] = 'low';
        }
        if ($this->temperature_f !== null) {
            if ($this->temperature_f < 96.8) $flags['temperature_f'] = 'low';
            if ($this->temperature_f > 100.4) $flags['temperature_f'] = 'high';
        }
        if ($this->pain_score !== null && $this->pain_score >= 7) {
            $flags['pain_score'] = 'high';
        }
        if ($this->blood_glucose !== null) {
            if ($this->blood_glucose < 70)  $flags['blood_glucose'] = 'low';
            if ($this->blood_glucose > 250) $flags['blood_glucose'] = 'high';
        }

        return $flags;
    }
}
