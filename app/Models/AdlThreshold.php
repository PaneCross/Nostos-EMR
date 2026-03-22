<?php

// ─── AdlThreshold Model ───────────────────────────────────────────────────────
// One row per participant per ADL category (enforced by UNIQUE constraint).
// When a new AdlRecord's independence_level is worse than threshold_level,
// AdlThresholdService sets threshold_breached = true on the record.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdlThreshold extends Model
{
    use HasFactory;

    protected $table = 'emr_adl_thresholds';

    // ── No updated_at (use set_at instead) ────────────────────────────────────
    public $timestamps = false;

    protected $fillable = [
        'participant_id', 'adl_category', 'threshold_level',
        'set_by_user_id', 'set_at',
    ];

    protected $casts = [
        'set_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /**
     * Returns the LEVELS index for this threshold's level.
     * Used by AdlThresholdService to compare against a new AdlRecord.
     */
    public function levelIndex(): int
    {
        return (int) array_search($this->threshold_level, AdlRecord::LEVELS, true);
    }

    /**
     * Load all thresholds for a participant, keyed by adl_category.
     * Returns an empty collection if no thresholds are set.
     *
     * @return \Illuminate\Support\Collection<string, AdlThreshold>
     */
    public static function forParticipant(int $participantId): \Illuminate\Support\Collection
    {
        return static::where('participant_id', $participantId)
            ->get()
            ->keyBy('adl_category');
    }
}
