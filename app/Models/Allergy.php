<?php

// ─── Allergy Model ─────────────────────────────────────────────────────────────
// Drug/food/environmental allergies and dietary restrictions.
// Life-threatening severity triggers a persistent red banner on the participant profile.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Allergy extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'emr_allergies';

    // ── Severity constants ────────────────────────────────────────────────────
    public const LIFE_THREATENING = 'life_threatening';

    public const ALLERGY_TYPES = [
        'drug', 'food', 'environmental', 'dietary_restriction', 'latex', 'contrast',
    ];

    public const SEVERITIES = [
        'mild', 'moderate', 'severe', 'life_threatening', 'intolerance',
    ];

    protected $fillable = [
        'participant_id', 'tenant_id',
        'allergy_type', 'allergen_name', 'reaction_description',
        'severity',
        'onset_date', 'is_active',
        'verified_by_user_id', 'verified_at',
        'notes',
    ];

    protected $casts = [
        'onset_date'  => 'date',
        'is_active'   => 'boolean',
        'verified_at' => 'datetime',
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

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    // ── Query Scopes ──────────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLifeThreateningActive($query)
    {
        return $query->where('is_active', true)
            ->where('severity', self::LIFE_THREATENING);
    }

    // ── Business Logic ────────────────────────────────────────────────────────

    public function isLifeThreatening(): bool
    {
        return $this->severity === self::LIFE_THREATENING;
    }

    /** CSS color classes for the severity badge. */
    public function severityColor(): string
    {
        return match ($this->severity) {
            'life_threatening' => 'text-red-700 bg-red-50 border-red-300',
            'severe'           => 'text-orange-700 bg-orange-50 border-orange-200',
            'moderate'         => 'text-amber-700 bg-amber-50 border-amber-200',
            'mild'             => 'text-yellow-700 bg-yellow-50 border-yellow-200',
            'intolerance'      => 'text-blue-700 bg-blue-50 border-blue-200',
            default            => 'text-gray-700 bg-gray-50 border-gray-200',
        };
    }

    /** Human-readable label for the allergy type. */
    public function typeLabel(): string
    {
        return match ($this->allergy_type) {
            'drug'                 => 'Drug',
            'food'                 => 'Food',
            'environmental'        => 'Environmental',
            'dietary_restriction'  => 'Dietary Restriction',
            'latex'                => 'Latex',
            'contrast'             => 'Contrast / Dye',
            default                => ucfirst(str_replace('_', ' ', $this->allergy_type)),
        };
    }
}
