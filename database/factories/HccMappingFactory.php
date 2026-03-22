<?php

// ─── HccMappingFactory ────────────────────────────────────────────────────────
// Generates emr_hcc_mappings rows for tests and Phase 9B demo seeder.
//
// Uses a rotating list of real PACE-relevant ICD-10 → HCC mappings.
// In tests, use HccMapping::factory()->create(['icd10_code' => 'E11.9']) for
// deterministic mapping verification.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\HccMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

class HccMappingFactory extends Factory
{
    protected $model = HccMapping::class;

    /** Common PACE diagnoses with their CMS-HCC category and RAF values. */
    private const MAPPINGS = [
        ['icd10' => 'E11.9',  'cat' => 'HCC19',  'label' => 'Diabetes without Complications',       'raf' => 0.1180],
        ['icd10' => 'I50.9',  'cat' => 'HCC85',  'label' => 'Congestive Heart Failure',              'raf' => 0.3310],
        ['icd10' => 'J44.1',  'cat' => 'HCC111', 'label' => 'COPD',                                  'raf' => 0.3350],
        ['icd10' => 'N18.3',  'cat' => 'HCC136', 'label' => 'Chronic Kidney Disease Stage 3',        'raf' => 0.2890],
        ['icd10' => 'G30.9',  'cat' => 'HCC51',  'label' => 'Dementia with Behavioral Disturbance',  'raf' => 0.3460],
        ['icd10' => 'F32.9',  'cat' => 'HCC59',  'label' => 'Major Depressive Disorder',             'raf' => 0.2990],
        ['icd10' => 'I48.91', 'cat' => 'HCC96',  'label' => 'Atrial Fibrillation',                  'raf' => 0.2180],
        ['icd10' => 'I63.9',  'cat' => 'HCC100', 'label' => 'Ischemic Stroke',                       'raf' => 0.3100],
    ];

    public function definition(): array
    {
        $mapping = $this->faker->unique()->randomElement(self::MAPPINGS);

        return [
            'icd10_code'     => $mapping['icd10'],
            'hcc_category'   => $mapping['cat'],
            'hcc_label'      => $mapping['label'],
            'raf_value'      => $mapping['raf'],
            'effective_year' => 2025,
        ];
    }

    /** Create a mapping for a specific ICD-10 code. */
    public function forCode(string $code, string $hccCategory, string $label, float $raf): static
    {
        return $this->state(fn () => [
            'icd10_code'   => $code,
            'hcc_category' => $hccCategory,
            'hcc_label'    => $label,
            'raf_value'    => $raf,
        ]);
    }

    /** Create a mapping for a code with no HCC category (no revenue value). */
    public function unmapped(string $code): static
    {
        return $this->state(fn () => [
            'icd10_code'   => $code,
            'hcc_category' => null,
            'hcc_label'    => null,
            'raf_value'    => null,
        ]);
    }
}
