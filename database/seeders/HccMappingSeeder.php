<?php

// ─── HccMappingSeeder ─────────────────────────────────────────────────────────
// Seeds emr_hcc_mappings with ICD-10-CM to CMS-HCC category mappings.
//
// Source: CMS-HCC Risk Adjustment Model (V28, effective 2024+).
// These mappings are used by HccRiskScoringService to calculate RAF scores
// and identify coding gaps that represent lost capitation revenue.
//
// PACE-specific: PACE populations skew toward high HCC categories (chronic conditions
// with multiple comorbidities). The mappings here cover the most common PACE diagnoses.
//
// RAF values are illustrative — production deployments should use the exact CMS
// published risk factors from the annual rate announcement (CMS-HCC V28 tables).
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HccMappingSeeder extends Seeder
{
    // Effective year for all mappings in this seeder (CMS V28 model year)
    private const EFFECTIVE_YEAR = 2025;

    public function run(): void
    {
        $mappings = $this->getMappings();

        foreach ($mappings as $mapping) {
            DB::table('emr_hcc_mappings')->updateOrInsert(
                [
                    'icd10_code'     => $mapping['icd10_code'],
                    'effective_year' => $mapping['effective_year'],
                ],
                $mapping
            );
        }

        $this->command->info('HCC Mappings seeded: ' . count($mappings) . ' ICD-10→HCC entries.');
    }

    /**
     * Returns all ICD-10→HCC mappings for the CMS-HCC V28 model.
     * Fields: icd10_code, hcc_category, hcc_label, raf_value, effective_year.
     * hcc_category NULL means the code maps to no HCC (still valid, just not risk-adjusting).
     */
    private function getMappings(): array
    {
        $year = self::EFFECTIVE_YEAR;

        return [
            // ── Diabetes ──────────────────────────────────────────────────────
            // HCC 18: Diabetes with Chronic Complications (RAF ~0.318)
            ['icd10_code' => 'E1140', 'hcc_category' => '18', 'hcc_label' => 'Diabetes with Chronic Complications', 'raf_value' => 0.3180, 'effective_year' => $year],
            ['icd10_code' => 'E1141', 'hcc_category' => '18', 'hcc_label' => 'Diabetes with Chronic Complications', 'raf_value' => 0.3180, 'effective_year' => $year],
            ['icd10_code' => 'E1151', 'hcc_category' => '18', 'hcc_label' => 'Diabetes with Chronic Complications', 'raf_value' => 0.3180, 'effective_year' => $year],
            ['icd10_code' => 'E1165', 'hcc_category' => '18', 'hcc_label' => 'Diabetes with Chronic Complications', 'raf_value' => 0.3180, 'effective_year' => $year],
            ['icd10_code' => 'E1169', 'hcc_category' => '18', 'hcc_label' => 'Diabetes with Chronic Complications', 'raf_value' => 0.3180, 'effective_year' => $year],

            // HCC 19: Diabetes without Complication (RAF ~0.105)
            ['icd10_code' => 'E119',  'hcc_category' => '19', 'hcc_label' => 'Diabetes without Complication', 'raf_value' => 0.1050, 'effective_year' => $year],
            ['icd10_code' => 'E109',  'hcc_category' => '19', 'hcc_label' => 'Diabetes without Complication', 'raf_value' => 0.1050, 'effective_year' => $year],

            // ── Congestive Heart Failure ───────────────────────────────────────
            // HCC 85: Congestive Heart Failure (RAF ~0.331)
            ['icd10_code' => 'I50.9', 'hcc_category' => '85', 'hcc_label' => 'Congestive Heart Failure', 'raf_value' => 0.3310, 'effective_year' => $year],
            ['icd10_code' => 'I5030', 'hcc_category' => '85', 'hcc_label' => 'Congestive Heart Failure', 'raf_value' => 0.3310, 'effective_year' => $year],
            ['icd10_code' => 'I5031', 'hcc_category' => '85', 'hcc_label' => 'Congestive Heart Failure', 'raf_value' => 0.3310, 'effective_year' => $year],
            ['icd10_code' => 'I5032', 'hcc_category' => '85', 'hcc_label' => 'Congestive Heart Failure', 'raf_value' => 0.3310, 'effective_year' => $year],
            ['icd10_code' => 'I5040', 'hcc_category' => '85', 'hcc_label' => 'Congestive Heart Failure', 'raf_value' => 0.3310, 'effective_year' => $year],
            ['icd10_code' => 'I5041', 'hcc_category' => '85', 'hcc_label' => 'Congestive Heart Failure', 'raf_value' => 0.3310, 'effective_year' => $year],

            // ── Chronic Kidney Disease ─────────────────────────────────────────
            // HCC 136: CKD Stage 3-4 (RAF ~0.180)
            ['icd10_code' => 'N183',  'hcc_category' => '136', 'hcc_label' => 'Chronic Kidney Disease, Stage 3-4', 'raf_value' => 0.1800, 'effective_year' => $year],
            ['icd10_code' => 'N184',  'hcc_category' => '136', 'hcc_label' => 'Chronic Kidney Disease, Stage 3-4', 'raf_value' => 0.1800, 'effective_year' => $year],

            // HCC 137: CKD Stage 5 or ESRD (RAF ~0.289)
            ['icd10_code' => 'N185',  'hcc_category' => '137', 'hcc_label' => 'Chronic Kidney Disease, Stage 5', 'raf_value' => 0.2890, 'effective_year' => $year],
            ['icd10_code' => 'N186',  'hcc_category' => '137', 'hcc_label' => 'End Stage Renal Disease', 'raf_value' => 0.2890, 'effective_year' => $year],

            // ── COPD / Asthma ──────────────────────────────────────────────────
            // HCC 111: COPD (RAF ~0.352)
            ['icd10_code' => 'J449',  'hcc_category' => '111', 'hcc_label' => 'COPD', 'raf_value' => 0.3520, 'effective_year' => $year],
            ['icd10_code' => 'J441',  'hcc_category' => '111', 'hcc_label' => 'COPD with Acute Exacerbation', 'raf_value' => 0.3520, 'effective_year' => $year],

            // ── Stroke / Cerebrovascular Disease ──────────────────────────────
            // HCC 100: Stroke (RAF ~0.261)
            ['icd10_code' => 'I6359', 'hcc_category' => '100', 'hcc_label' => 'Ischemic or Unspecified Stroke', 'raf_value' => 0.2610, 'effective_year' => $year],
            ['icd10_code' => 'I63.9', 'hcc_category' => '100', 'hcc_label' => 'Ischemic or Unspecified Stroke', 'raf_value' => 0.2610, 'effective_year' => $year],

            // HCC 103: Hemiplegia/Hemiparesis (RAF ~0.571)
            ['icd10_code' => 'G8190', 'hcc_category' => '103', 'hcc_label' => 'Hemiplegia/Hemiparesis', 'raf_value' => 0.5710, 'effective_year' => $year],

            // ── Dementia / Alzheimer's ────────────────────────────────────────
            // HCC 52: Dementia with Complications (RAF ~0.346)
            ['icd10_code' => 'F0150', 'hcc_category' => '52', 'hcc_label' => 'Alzheimer\'s Disease', 'raf_value' => 0.3460, 'effective_year' => $year],
            ['icd10_code' => 'F0151', 'hcc_category' => '52', 'hcc_label' => 'Alzheimer\'s with Behavioral Disturbance', 'raf_value' => 0.3460, 'effective_year' => $year],
            ['icd10_code' => 'G309',  'hcc_category' => '52', 'hcc_label' => 'Alzheimer\'s Disease, Unspecified', 'raf_value' => 0.3460, 'effective_year' => $year],
            ['icd10_code' => 'F03.90','hcc_category' => '52', 'hcc_label' => 'Unspecified Dementia', 'raf_value' => 0.3460, 'effective_year' => $year],

            // ── Cancer ────────────────────────────────────────────────────────
            // HCC 12: Breast/Prostate/Colorectal Cancer (RAF ~0.148)
            ['icd10_code' => 'C50919','hcc_category' => '12', 'hcc_label' => 'Breast Cancer', 'raf_value' => 0.1480, 'effective_year' => $year],
            ['icd10_code' => 'C61',   'hcc_category' => '12', 'hcc_label' => 'Prostate Cancer', 'raf_value' => 0.1480, 'effective_year' => $year],
            ['icd10_code' => 'C189',  'hcc_category' => '12', 'hcc_label' => 'Colon Cancer', 'raf_value' => 0.1480, 'effective_year' => $year],

            // HCC 10: Lymphoma/Leukemia (RAF ~0.688)
            ['icd10_code' => 'C919',  'hcc_category' => '10', 'hcc_label' => 'Lymphocytic Leukemia', 'raf_value' => 0.6880, 'effective_year' => $year],

            // ── Peripheral Artery Disease / Vascular ──────────────────────────
            // HCC 108: Vascular Disease with Complications (RAF ~0.421)
            ['icd10_code' => 'I7000', 'hcc_category' => '108', 'hcc_label' => 'Atherosclerosis of Native Arteries', 'raf_value' => 0.4210, 'effective_year' => $year],
            ['icd10_code' => 'I7001', 'hcc_category' => '108', 'hcc_label' => 'Atherosclerosis of Aorta', 'raf_value' => 0.4210, 'effective_year' => $year],

            // ── Mental Health ──────────────────────────────────────────────────
            // HCC 59: Major Depressive Disorder (RAF ~0.309)
            ['icd10_code' => 'F329',  'hcc_category' => '59', 'hcc_label' => 'Major Depressive Disorder', 'raf_value' => 0.3090, 'effective_year' => $year],
            ['icd10_code' => 'F3290', 'hcc_category' => '59', 'hcc_label' => 'Major Depressive Disorder, Unspecified', 'raf_value' => 0.3090, 'effective_year' => $year],

            // HCC 57: Schizophrenia (RAF ~0.379)
            ['icd10_code' => 'F209',  'hcc_category' => '57', 'hcc_label' => 'Schizophrenia', 'raf_value' => 0.3790, 'effective_year' => $year],

            // ── Pressure Ulcers ────────────────────────────────────────────────
            // HCC 161: Stage 3+ Pressure Ulcers (RAF ~1.012)
            ['icd10_code' => 'L8930', 'hcc_category' => '161', 'hcc_label' => 'Pressure Ulcer, Stage 3', 'raf_value' => 1.0120, 'effective_year' => $year],
            ['icd10_code' => 'L8940', 'hcc_category' => '161', 'hcc_label' => 'Pressure Ulcer, Stage 4', 'raf_value' => 1.0120, 'effective_year' => $year],

            // ── Atrial Fibrillation ────────────────────────────────────────────
            // HCC 96: Specified Heart Arrhythmias (RAF ~0.178)
            ['icd10_code' => 'I4891', 'hcc_category' => '96', 'hcc_label' => 'Atrial Fibrillation', 'raf_value' => 0.1780, 'effective_year' => $year],
            ['icd10_code' => 'I480',  'hcc_category' => '96', 'hcc_label' => 'Paroxysmal Atrial Fibrillation', 'raf_value' => 0.1780, 'effective_year' => $year],
            ['icd10_code' => 'I4811', 'hcc_category' => '96', 'hcc_label' => 'Longstanding Persistent Atrial Fibrillation', 'raf_value' => 0.1780, 'effective_year' => $year],

            // ── Rheumatoid Arthritis ───────────────────────────────────────────
            // HCC 40: Rheumatoid Arthritis (RAF ~0.373)
            ['icd10_code' => 'M0610', 'hcc_category' => '40', 'hcc_label' => 'Rheumatoid Arthritis', 'raf_value' => 0.3730, 'effective_year' => $year],

            // ── Parkinson's Disease ────────────────────────────────────────────
            // HCC 73: Parkinson's (RAF ~0.537)
            ['icd10_code' => 'G20',   'hcc_category' => '73', 'hcc_label' => 'Parkinson\'s Disease', 'raf_value' => 0.5370, 'effective_year' => $year],

            // ── HIV/AIDS ───────────────────────────────────────────────────────
            // HCC 1: HIV/AIDS (RAF ~0.295)
            ['icd10_code' => 'B20',   'hcc_category' => '1', 'hcc_label' => 'HIV/AIDS', 'raf_value' => 0.2950, 'effective_year' => $year],
        ];
    }
}
