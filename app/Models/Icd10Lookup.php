<?php

// ─── Icd10Lookup Model ────────────────────────────────────────────────────────
// Static ICD-10 code reference table. Seeded by Icd10Seeder with ~200 PACE-relevant
// codes. Used for typeahead search in the problem list — no HasFactory needed.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Icd10Lookup extends Model
{
    protected $table = 'emr_icd10_lookup';

    // ── Append-only reference data — no updated_at ────────────────────────────
    public $timestamps = false;

    protected $fillable = ['code', 'description', 'category'];

    // ── Query Scope ───────────────────────────────────────────────────────────

    /**
     * Case-insensitive search across code and description.
     * Uses PostgreSQL ILIKE for partial matching.
     */
    public function scopeSearch($query, string $term)
    {
        $like = '%' . $term . '%';

        return $query->where(function ($q) use ($like, $term) {
            // Exact code match ranks first via UNION — handled in controller
            $q->where('code', 'ilike', $like)
              ->orWhere('description', 'ilike', $like);
        });
    }
}
