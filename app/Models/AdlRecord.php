<?php

// ─── AdlRecord Model ──────────────────────────────────────────────────────────
// Append-only ADL observation. No SoftDeletes — historical records must be preserved.
// threshold_breached is set by AdlThresholdService on insert and never changed.
//
// LEVELS is ordered from best (independent) to worst (total_dependent).
// This ordering is used by AdlThresholdService::checkBreach() to compare levels.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdlRecord extends Model
{
    use HasFactory;

    protected $table = 'emr_adl_records';

    // ── No updated_at (append-only) ───────────────────────────────────────────
    public const UPDATED_AT = null;

    // ── ADL categories ────────────────────────────────────────────────────────
    public const CATEGORIES = [
        'bathing', 'dressing', 'grooming', 'toileting', 'transferring',
        'ambulation', 'eating', 'continence', 'medication_management', 'communication',
    ];

    // ── Independence levels ordered best → worst ──────────────────────────────
    // Index position is used for comparison: higher index = more dependent = worse
    public const LEVELS = [
        'independent',      // index 0 — best
        'supervision',      // index 1
        'limited_assist',   // index 2
        'extensive_assist', // index 3
        'total_dependent',  // index 4 — worst
    ];

    protected $fillable = [
        'participant_id', 'tenant_id', 'recorded_by_user_id', 'recorded_at',
        'adl_category', 'independence_level',
        'assistive_device_used', 'notes',
        'threshold_breached',
    ];

    protected $casts = [
        'recorded_at'       => 'datetime',
        'threshold_breached' => 'boolean',
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

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Returns the numeric index of this record's independence_level in LEVELS.
     * Higher index = more dependent. Used by AdlThresholdService for comparison.
     */
    public function levelIndex(): int
    {
        return (int) array_search($this->independence_level, self::LEVELS, true);
    }

    /** Human-readable label for the independence level. */
    public function levelLabel(): string
    {
        return match ($this->independence_level) {
            'independent'      => 'Independent',
            'supervision'      => 'Supervision',
            'limited_assist'   => 'Limited Assist',
            'extensive_assist' => 'Extensive Assist',
            'total_dependent'  => 'Total Dependent',
            default            => ucwords(str_replace('_', ' ', $this->independence_level)),
        };
    }

    /** Tailwind color class for the current level (green → red scale). */
    public function levelColor(): string
    {
        return match ($this->independence_level) {
            'independent'      => 'text-green-700 bg-green-50',
            'supervision'      => 'text-lime-700 bg-lime-50',
            'limited_assist'   => 'text-yellow-700 bg-yellow-50',
            'extensive_assist' => 'text-orange-700 bg-orange-50',
            'total_dependent'  => 'text-red-700 bg-red-50',
            default            => 'text-gray-700 bg-gray-50',
        };
    }
}
