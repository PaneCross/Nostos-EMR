<?php

// ─── WoundAssessment Model ─────────────────────────────────────────────────────
// Periodic re-measurement of an existing wound record.
// Append-only (no updated_at, no SoftDeletes per clinical documentation standard).
//
// status_change drives WoundService alerts:
//   deteriorated → warning alert to primary_care
//   healed       → wound_record.status updated to 'healed' + healed_date set
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WoundAssessment extends Model
{
    use HasFactory;

    protected $table = 'emr_wound_assessments';

    // Append-only: no updated_at column
    const UPDATED_AT = null;

    protected $fillable = [
        'wound_record_id', 'assessed_by_user_id', 'assessed_at',
        'length_cm', 'width_cm', 'depth_cm',
        'wound_bed', 'exudate_amount', 'exudate_type', 'periwound_skin',
        'odor', 'pain_score',
        'treatment_description', 'status_change', 'notes',
    ];

    protected $casts = [
        'assessed_at' => 'datetime',
        'odor'        => 'boolean',
        'pain_score'  => 'integer',
        'length_cm'   => 'decimal:1',
        'width_cm'    => 'decimal:1',
        'depth_cm'    => 'decimal:1',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function woundRecord(): BelongsTo
    {
        return $this->belongsTo(WoundRecord::class, 'wound_record_id');
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_user_id');
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    /** API-safe array for timeline display. */
    public function toApiArray(): array
    {
        return [
            'id'                   => $this->id,
            'wound_record_id'      => $this->wound_record_id,
            'assessed_at'          => $this->assessed_at?->toIso8601String(),
            'length_cm'            => $this->length_cm,
            'width_cm'             => $this->width_cm,
            'depth_cm'             => $this->depth_cm,
            'wound_bed'            => $this->wound_bed,
            'exudate_amount'       => $this->exudate_amount,
            'exudate_type'         => $this->exudate_type,
            'periwound_skin'       => $this->periwound_skin,
            'odor'                 => $this->odor,
            'pain_score'           => $this->pain_score,
            'treatment_description'=> $this->treatment_description,
            'status_change'        => $this->status_change,
            'notes'                => $this->notes,
            'assessed_by'          => $this->assessedBy
                ? $this->assessedBy->first_name . ' ' . $this->assessedBy->last_name
                : null,
        ];
    }
}
