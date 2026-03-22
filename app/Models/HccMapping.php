<?php

// ─── HccMapping ───────────────────────────────────────────────────────────────
// Reference table mapping ICD-10-CM codes to CMS Hierarchical Condition Categories
// (HCCs) for risk adjustment scoring.
//
// CMS uses HCC risk scores to calculate PACE capitation rates. Each participant's
// documented ICD-10 diagnoses map to HCC categories via this table. The sum of
// mapped HCC RAF values (plus demographic adjustments) becomes the participant's
// RAF score, which multiplies the county base rate to produce their capitation.
//
// Under-documenting diagnoses = under-reported RAF = lower capitation next year.
// This table is seeded from the CMS published HCC mapping files (annual release).
// HccRiskScoringService uses this to identify HCC gaps between documented
// diagnoses (emr_problems) and submitted encounter data (emr_encounter_log).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HccMapping extends Model
{
    use HasFactory;

    protected $table = 'emr_hcc_mappings';

    protected $fillable = [
        'icd10_code',
        'hcc_category',
        'hcc_label',
        'raf_value',
        'effective_year',
    ];

    protected $casts = [
        'raf_value'      => 'decimal:4',
        'effective_year' => 'integer',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForYear($q, int $year)
    {
        return $q->where('effective_year', $year);
    }

    public function scopeForCode($q, string $code)
    {
        return $q->where('icd10_code', $code);
    }
}
