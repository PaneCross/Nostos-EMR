<?php

// ─── MedicationsReferenceSeeder ───────────────────────────────────────────────
// Seeds emr_medications_reference with ~200 PACE-relevant medications and
// emr_drug_interactions_reference with ~100 drug-drug interaction pairs.
//
// This is STATIC reference data — not participant-specific.
// The seeder truncates both tables before inserting to allow safe re-runs.
//
// Medication categories cover the major PACE therapeutic areas:
//   Cardiovascular, Diabetes, Neurology/Cognitive, Psychiatric, Pulmonary,
//   Musculoskeletal/Pain, GI, Renal, Anticoagulants, Antibiotics, Hormonal,
//   Vitamins/Supplements, Controlled Substances
//
// Drug interaction pairs are normalized: drug_name_1 < drug_name_2 alphabetically.
// Severity levels: contraindicated > major > moderate > minor
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MedicationsReferenceSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('emr_medications_reference')->truncate();
        DB::table('emr_drug_interactions_reference')->truncate();

        $this->seedMedications();
        $this->seedInteractions();

        $this->command?->info('MedicationsReferenceSeeder: '
            . DB::table('emr_medications_reference')->count() . ' medications, '
            . DB::table('emr_drug_interactions_reference')->count() . ' interaction pairs seeded.');
    }

    private function seedMedications(): void
    {
        $medications = [
            // ── Cardiovascular ─────────────────────────────────────────────────
            ['drug_name' => 'Lisinopril',        'drug_class' => 'ACE Inhibitor',             'drug_category' => 'Cardiovascular', 'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Prinivil, Zestril'],
            ['drug_name' => 'Enalapril',          'drug_class' => 'ACE Inhibitor',             'drug_category' => 'Cardiovascular', 'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Vasotec'],
            ['drug_name' => 'Ramipril',           'drug_class' => 'ACE Inhibitor',             'drug_category' => 'Cardiovascular', 'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Altace'],
            ['drug_name' => 'Losartan',           'drug_class' => 'ARB',                       'drug_category' => 'Cardiovascular', 'common_dose' => 50,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Cozaar'],
            ['drug_name' => 'Valsartan',          'drug_class' => 'ARB',                       'drug_category' => 'Cardiovascular', 'common_dose' => 80,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Diovan'],
            ['drug_name' => 'Metoprolol',         'drug_class' => 'Beta Blocker',              'drug_category' => 'Cardiovascular', 'common_dose' => 25,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Lopressor, Toprol-XL'],
            ['drug_name' => 'Carvedilol',         'drug_class' => 'Beta Blocker',              'drug_category' => 'Cardiovascular', 'common_dose' => 6.25, 'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Coreg'],
            ['drug_name' => 'Atenolol',           'drug_class' => 'Beta Blocker',              'drug_category' => 'Cardiovascular', 'common_dose' => 25,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Tenormin'],
            ['drug_name' => 'Amlodipine',         'drug_class' => 'Calcium Channel Blocker',   'drug_category' => 'Cardiovascular', 'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Norvasc'],
            ['drug_name' => 'Diltiazem',          'drug_class' => 'Calcium Channel Blocker',   'drug_category' => 'Cardiovascular', 'common_dose' => 120,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Cardizem'],
            ['drug_name' => 'Furosemide',         'drug_class' => 'Loop Diuretic',             'drug_category' => 'Cardiovascular', 'common_dose' => 40,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Lasix'],
            ['drug_name' => 'Torsemide',          'drug_class' => 'Loop Diuretic',             'drug_category' => 'Cardiovascular', 'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Demadex'],
            ['drug_name' => 'Hydrochlorothiazide','drug_class' => 'Thiazide Diuretic',         'drug_category' => 'Cardiovascular', 'common_dose' => 25,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'HCTZ, Microzide'],
            ['drug_name' => 'Spironolactone',     'drug_class' => 'Potassium-Sparing Diuretic','drug_category' => 'Cardiovascular', 'common_dose' => 25,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Aldactone'],
            ['drug_name' => 'Atorvastatin',       'drug_class' => 'Statin',                    'drug_category' => 'Cardiovascular', 'common_dose' => 40,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Lipitor'],
            ['drug_name' => 'Rosuvastatin',       'drug_class' => 'Statin',                    'drug_category' => 'Cardiovascular', 'common_dose' => 20,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Crestor'],
            ['drug_name' => 'Simvastatin',        'drug_class' => 'Statin',                    'drug_category' => 'Cardiovascular', 'common_dose' => 40,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Zocor'],
            ['drug_name' => 'Aspirin',            'drug_class' => 'Antiplatelet',              'drug_category' => 'Cardiovascular', 'common_dose' => 81,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Bayer Aspirin, Ecotrin'],
            ['drug_name' => 'Clopidogrel',        'drug_class' => 'Antiplatelet',              'drug_category' => 'Cardiovascular', 'common_dose' => 75,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Plavix'],
            ['drug_name' => 'Warfarin',           'drug_class' => 'Anticoagulant',             'drug_category' => 'Anticoagulants', 'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Coumadin, Jantoven'],
            ['drug_name' => 'Apixaban',           'drug_class' => 'DOAC',                      'drug_category' => 'Anticoagulants', 'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Eliquis'],
            ['drug_name' => 'Rivaroxaban',        'drug_class' => 'DOAC',                      'drug_category' => 'Anticoagulants', 'common_dose' => 20,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Xarelto'],
            ['drug_name' => 'Digoxin',            'drug_class' => 'Cardiac Glycoside',         'drug_category' => 'Cardiovascular', 'common_dose' => 0.125,'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Lanoxin'],
            ['drug_name' => 'Amiodarone',         'drug_class' => 'Antiarrhythmic',            'drug_category' => 'Cardiovascular', 'common_dose' => 200,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Pacerone, Nexterone'],
            ['drug_name' => 'Nitroglycerine',     'drug_class' => 'Nitrate',                   'drug_category' => 'Cardiovascular', 'common_dose' => 0.4,  'dose_unit' => 'mg',  'route' => 'sublingual', 'frequency' => 'PRN', 'brand_names' => 'Nitrostat'],
            ['drug_name' => 'Isosorbide Mononitrate','drug_class' => 'Nitrate',                'drug_category' => 'Cardiovascular', 'common_dose' => 30,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Imdur'],
            ['drug_name' => 'Hydralazine',        'drug_class' => 'Vasodilator',               'drug_category' => 'Cardiovascular', 'common_dose' => 25,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Apresoline'],

            // ── Diabetes ───────────────────────────────────────────────────────
            ['drug_name' => 'Metformin',          'drug_class' => 'Biguanide',                 'drug_category' => 'Diabetes',       'common_dose' => 500,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Glucophage'],
            ['drug_name' => 'Glipizide',          'drug_class' => 'Sulfonylurea',              'drug_category' => 'Diabetes',       'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Glucotrol'],
            ['drug_name' => 'Glimepiride',        'drug_class' => 'Sulfonylurea',              'drug_category' => 'Diabetes',       'common_dose' => 2,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Amaryl'],
            ['drug_name' => 'Sitagliptin',        'drug_class' => 'DPP-4 Inhibitor',           'drug_category' => 'Diabetes',       'common_dose' => 100,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Januvia'],
            ['drug_name' => 'Empagliflozin',      'drug_class' => 'SGLT-2 Inhibitor',          'drug_category' => 'Diabetes',       'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Jardiance'],
            ['drug_name' => 'Insulin Glargine',   'drug_class' => 'Long-Acting Insulin',       'drug_category' => 'Diabetes',       'common_dose' => 10,   'dose_unit' => 'units','route' => 'subcut','frequency' => 'daily', 'brand_names' => 'Lantus, Basaglar, Toujeo'],
            ['drug_name' => 'Insulin Lispro',     'drug_class' => 'Rapid-Acting Insulin',      'drug_category' => 'Diabetes',       'common_dose' => 5,    'dose_unit' => 'units','route' => 'subcut','frequency' => 'TID',   'brand_names' => 'Humalog, Admelog'],
            ['drug_name' => 'Insulin Regular',    'drug_class' => 'Short-Acting Insulin',      'drug_category' => 'Diabetes',       'common_dose' => 5,    'dose_unit' => 'units','route' => 'subcut','frequency' => 'TID',   'brand_names' => 'Humulin R, Novolin R'],
            ['drug_name' => 'Dulaglutide',        'drug_class' => 'GLP-1 Agonist',             'drug_category' => 'Diabetes',       'common_dose' => 0.75, 'dose_unit' => 'mg',  'route' => 'subcut','frequency' => 'weekly','brand_names' => 'Trulicity'],

            // ── Neurology / Cognitive ─────────────────────────────────────────
            ['drug_name' => 'Donepezil',          'drug_class' => 'Cholinesterase Inhibitor',  'drug_category' => 'Neurology',      'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Aricept'],
            ['drug_name' => 'Rivastigmine',       'drug_class' => 'Cholinesterase Inhibitor',  'drug_category' => 'Neurology',      'common_dose' => 9.5,  'dose_unit' => 'mg',  'route' => 'topical','frequency' => 'daily', 'brand_names' => 'Exelon Patch'],
            ['drug_name' => 'Memantine',          'drug_class' => 'NMDA Antagonist',           'drug_category' => 'Neurology',      'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Namenda'],
            ['drug_name' => 'Levodopa-Carbidopa', 'drug_class' => 'Dopaminergic',              'drug_category' => 'Neurology',      'common_dose' => 25,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Sinemet'],
            ['drug_name' => 'Ropinirole',         'drug_class' => 'Dopamine Agonist',          'drug_category' => 'Neurology',      'common_dose' => 1,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Requip'],
            ['drug_name' => 'Gabapentin',         'drug_class' => 'Anticonvulsant',            'drug_category' => 'Neurology',      'common_dose' => 300,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Neurontin'],
            ['drug_name' => 'Pregabalin',         'drug_class' => 'Anticonvulsant',            'drug_category' => 'Neurology',      'common_dose' => 75,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Lyrica'],
            ['drug_name' => 'Levetiracetam',      'drug_class' => 'Anticonvulsant',            'drug_category' => 'Neurology',      'common_dose' => 500,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Keppra'],
            ['drug_name' => 'Phenytoin',          'drug_class' => 'Anticonvulsant',            'drug_category' => 'Neurology',      'common_dose' => 100,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Dilantin'],

            // ── Psychiatric ───────────────────────────────────────────────────
            ['drug_name' => 'Sertraline',         'drug_class' => 'SSRI',                      'drug_category' => 'Psychiatric',    'common_dose' => 50,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Zoloft'],
            ['drug_name' => 'Escitalopram',       'drug_class' => 'SSRI',                      'drug_category' => 'Psychiatric',    'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Lexapro'],
            ['drug_name' => 'Fluoxetine',         'drug_class' => 'SSRI',                      'drug_category' => 'Psychiatric',    'common_dose' => 20,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Prozac'],
            ['drug_name' => 'Duloxetine',         'drug_class' => 'SNRI',                      'drug_category' => 'Psychiatric',    'common_dose' => 30,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Cymbalta'],
            ['drug_name' => 'Venlafaxine',        'drug_class' => 'SNRI',                      'drug_category' => 'Psychiatric',    'common_dose' => 75,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Effexor XR'],
            ['drug_name' => 'Mirtazapine',        'drug_class' => 'Tetracyclic Antidepressant','drug_category' => 'Psychiatric',    'common_dose' => 15,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Remeron'],
            ['drug_name' => 'Quetiapine',         'drug_class' => 'Atypical Antipsychotic',    'drug_category' => 'Psychiatric',    'common_dose' => 25,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Seroquel'],
            ['drug_name' => 'Risperidone',        'drug_class' => 'Atypical Antipsychotic',    'drug_category' => 'Psychiatric',    'common_dose' => 0.5,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Risperdal'],
            ['drug_name' => 'Olanzapine',         'drug_class' => 'Atypical Antipsychotic',    'drug_category' => 'Psychiatric',    'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Zyprexa'],
            ['drug_name' => 'Lorazepam',          'drug_class' => 'Benzodiazepine',            'drug_category' => 'Psychiatric',    'common_dose' => 0.5,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'PRN',   'is_controlled' => true, 'controlled_schedule' => 'IV', 'brand_names' => 'Ativan'],
            ['drug_name' => 'Clonazepam',         'drug_class' => 'Benzodiazepine',            'drug_category' => 'Psychiatric',    'common_dose' => 0.5,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'is_controlled' => true, 'controlled_schedule' => 'IV', 'brand_names' => 'Klonopin'],
            ['drug_name' => 'Zolpidem',           'drug_class' => 'Z-Drug Hypnotic',           'drug_category' => 'Psychiatric',    'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'is_controlled' => true, 'controlled_schedule' => 'IV', 'brand_names' => 'Ambien'],
            ['drug_name' => 'Trazodone',          'drug_class' => 'Atypical Antidepressant',   'drug_category' => 'Psychiatric',    'common_dose' => 50,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Desyrel'],
            ['drug_name' => 'Buspirone',          'drug_class' => 'Anxiolytic',                'drug_category' => 'Psychiatric',    'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Buspar'],

            // ── Pulmonary ──────────────────────────────────────────────────────
            ['drug_name' => 'Albuterol',          'drug_class' => 'Short-Acting Beta Agonist', 'drug_category' => 'Pulmonary',      'common_dose' => 90,   'dose_unit' => 'mcg', 'route' => 'inhaled','frequency' => 'PRN',  'brand_names' => 'ProAir, Ventolin'],
            ['drug_name' => 'Tiotropium',         'drug_class' => 'Long-Acting Anticholinergic','drug_category' => 'Pulmonary',     'common_dose' => 18,   'dose_unit' => 'mcg', 'route' => 'inhaled','frequency' => 'daily','brand_names' => 'Spiriva'],
            ['drug_name' => 'Fluticasone-Salmeterol','drug_class' => 'ICS/LABA',              'drug_category' => 'Pulmonary',      'common_dose' => 250,  'dose_unit' => 'mcg', 'route' => 'inhaled','frequency' => 'BID',  'brand_names' => 'Advair, AirDuo'],
            ['drug_name' => 'Budesonide-Formoterol','drug_class' => 'ICS/LABA',              'drug_category' => 'Pulmonary',      'common_dose' => 160,  'dose_unit' => 'mcg', 'route' => 'inhaled','frequency' => 'BID',  'brand_names' => 'Symbicort'],
            ['drug_name' => 'Ipratropium',        'drug_class' => 'Short-Acting Anticholinergic','drug_category' => 'Pulmonary',   'common_dose' => 0.5,  'dose_unit' => 'mg',  'route' => 'inhaled','frequency' => 'QID',  'brand_names' => 'Atrovent'],
            ['drug_name' => 'Montelukast',        'drug_class' => 'Leukotriene Modifier',     'drug_category' => 'Pulmonary',      'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Singulair'],
            ['drug_name' => 'Prednisone',         'drug_class' => 'Corticosteroid',            'drug_category' => 'Pulmonary',      'common_dose' => 20,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Deltasone'],

            // ── Pain / Musculoskeletal ─────────────────────────────────────────
            ['drug_name' => 'Acetaminophen',      'drug_class' => 'Analgesic',                 'drug_category' => 'Pain',           'common_dose' => 500,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'Q6H',   'brand_names' => 'Tylenol'],
            ['drug_name' => 'Ibuprofen',          'drug_class' => 'NSAID',                     'drug_category' => 'Pain',           'common_dose' => 400,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Advil, Motrin'],
            ['drug_name' => 'Naproxen',           'drug_class' => 'NSAID',                     'drug_category' => 'Pain',           'common_dose' => 250,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Aleve, Naprosyn'],
            ['drug_name' => 'Celecoxib',          'drug_class' => 'COX-2 Inhibitor',           'drug_category' => 'Pain',           'common_dose' => 200,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Celebrex'],
            ['drug_name' => 'Tramadol',           'drug_class' => 'Opioid-Like Analgesic',     'drug_category' => 'Pain',           'common_dose' => 50,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'Q6H',   'is_controlled' => true, 'controlled_schedule' => 'IV', 'brand_names' => 'Ultram'],
            ['drug_name' => 'Oxycodone',          'drug_class' => 'Opioid Analgesic',          'drug_category' => 'Pain',           'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'Q6H',   'is_controlled' => true, 'controlled_schedule' => 'II', 'brand_names' => 'OxyContin, Roxicodone'],
            ['drug_name' => 'Hydrocodone-Acetaminophen','drug_class' => 'Opioid Combination', 'drug_category' => 'Pain',           'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'Q6H',   'is_controlled' => true, 'controlled_schedule' => 'II', 'brand_names' => 'Vicodin, Norco'],
            ['drug_name' => 'Morphine',           'drug_class' => 'Opioid Analgesic',          'drug_category' => 'Pain',           'common_dose' => 15,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'Q4H',   'is_controlled' => true, 'controlled_schedule' => 'II', 'brand_names' => 'MS Contin, MSIR'],
            ['drug_name' => 'Fentanyl Patch',     'drug_class' => 'Opioid Analgesic',          'drug_category' => 'Pain',           'common_dose' => 25,   'dose_unit' => 'mcg', 'route' => 'topical','frequency' => 'once', 'is_controlled' => true, 'controlled_schedule' => 'II', 'brand_names' => 'Duragesic'],
            ['drug_name' => 'Methocarbamol',      'drug_class' => 'Muscle Relaxant',           'drug_category' => 'Musculoskeletal','common_dose' => 750,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Robaxin'],
            ['drug_name' => 'Cyclobenzaprine',    'drug_class' => 'Muscle Relaxant',           'drug_category' => 'Musculoskeletal','common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Flexeril'],
            ['drug_name' => 'Allopurinol',        'drug_class' => 'Xanthine Oxidase Inhibitor','drug_category' => 'Musculoskeletal','common_dose' => 300,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Lopurin, Zyloprim'],
            ['drug_name' => 'Colchicine',         'drug_class' => 'Antigout',                  'drug_category' => 'Musculoskeletal','common_dose' => 0.6,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Colcrys'],

            // ── GI ────────────────────────────────────────────────────────────
            ['drug_name' => 'Omeprazole',         'drug_class' => 'PPI',                       'drug_category' => 'GI',             'common_dose' => 20,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Prilosec'],
            ['drug_name' => 'Pantoprazole',       'drug_class' => 'PPI',                       'drug_category' => 'GI',             'common_dose' => 40,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Protonix'],
            ['drug_name' => 'Esomeprazole',       'drug_class' => 'PPI',                       'drug_category' => 'GI',             'common_dose' => 40,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Nexium'],
            ['drug_name' => 'Famotidine',         'drug_class' => 'H2 Blocker',                'drug_category' => 'GI',             'common_dose' => 20,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Pepcid'],
            ['drug_name' => 'Ondansetron',        'drug_class' => 'Antiemetic',                'drug_category' => 'GI',             'common_dose' => 4,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'PRN',   'brand_names' => 'Zofran'],
            ['drug_name' => 'Metoclopramide',     'drug_class' => 'Prokinetic',                'drug_category' => 'GI',             'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Reglan'],
            ['drug_name' => 'Docusate Sodium',    'drug_class' => 'Stool Softener',            'drug_category' => 'GI',             'common_dose' => 100,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Colace'],
            ['drug_name' => 'Polyethylene Glycol','drug_class' => 'Osmotic Laxative',          'drug_category' => 'GI',             'common_dose' => 17,   'dose_unit' => 'g',   'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'MiraLAX'],
            ['drug_name' => 'Senna',              'drug_class' => 'Stimulant Laxative',        'drug_category' => 'GI',             'common_dose' => 8.6,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Senokot'],
            ['drug_name' => 'Lactulose',          'drug_class' => 'Osmotic Laxative',          'drug_category' => 'GI',             'common_dose' => 15,   'dose_unit' => 'ml',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Enulose, Kristalose'],

            // ── Thyroid / Hormonal ─────────────────────────────────────────────
            ['drug_name' => 'Levothyroxine',      'drug_class' => 'Thyroid Hormone',           'drug_category' => 'Hormonal',       'common_dose' => 50,   'dose_unit' => 'mcg', 'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Synthroid, Levoxyl'],

            // ── Renal ─────────────────────────────────────────────────────────
            ['drug_name' => 'Sevelamer',          'drug_class' => 'Phosphate Binder',          'drug_category' => 'Renal',          'common_dose' => 800,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Renagel, Renvela'],
            ['drug_name' => 'Sodium Bicarbonate', 'drug_class' => 'Alkalinizing Agent',        'drug_category' => 'Renal',          'common_dose' => 650,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => null],
            ['drug_name' => 'Calcitriol',         'drug_class' => 'Active Vitamin D',          'drug_category' => 'Renal',          'common_dose' => 0.25, 'dose_unit' => 'mcg', 'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Rocaltrol'],
            ['drug_name' => 'Epoetin Alfa',       'drug_class' => 'Erythropoiesis Stimulating Agent','drug_category' => 'Renal',   'common_dose' => 3000, 'dose_unit' => 'units','route' => 'subcut','frequency' => 'weekly','brand_names' => 'Epogen, Procrit'],

            // ── Vitamins / Supplements ─────────────────────────────────────────
            ['drug_name' => 'Vitamin D3',         'drug_class' => 'Vitamin',                   'drug_category' => 'Vitamins',       'common_dose' => 1000, 'dose_unit' => 'units','route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Cholecalciferol'],
            ['drug_name' => 'Calcium Carbonate',  'drug_class' => 'Mineral',                   'drug_category' => 'Vitamins',       'common_dose' => 500,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Tums, Os-Cal'],
            ['drug_name' => 'Folate',             'drug_class' => 'Vitamin B9',                'drug_category' => 'Vitamins',       'common_dose' => 1,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Folic Acid'],
            ['drug_name' => 'Cyanocobalamin',     'drug_class' => 'Vitamin B12',               'drug_category' => 'Vitamins',       'common_dose' => 1000, 'dose_unit' => 'mcg', 'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Vitamin B12'],
            ['drug_name' => 'Ferrous Sulfate',    'drug_class' => 'Iron Supplement',           'drug_category' => 'Vitamins',       'common_dose' => 325,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => null],
            ['drug_name' => 'Zinc Sulfate',       'drug_class' => 'Mineral',                   'drug_category' => 'Vitamins',       'common_dose' => 220,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => null],
            ['drug_name' => 'Magnesium Oxide',    'drug_class' => 'Mineral',                   'drug_category' => 'Vitamins',       'common_dose' => 400,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => null],
            ['drug_name' => 'Melatonin',          'drug_class' => 'Supplement',                'drug_category' => 'Vitamins',       'common_dose' => 3,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => null],
            ['drug_name' => 'Multivitamin',       'drug_class' => 'Supplement',                'drug_category' => 'Vitamins',       'common_dose' => 1,    'dose_unit' => 'tab', 'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Centrum, One-A-Day'],

            // ── Antibiotics (common courses) ──────────────────────────────────
            ['drug_name' => 'Amoxicillin',        'drug_class' => 'Penicillin',                'drug_category' => 'Antibiotics',    'common_dose' => 500,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Amoxil'],
            ['drug_name' => 'Azithromycin',       'drug_class' => 'Macrolide',                 'drug_category' => 'Antibiotics',    'common_dose' => 250,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Zithromax, Z-Pak'],
            ['drug_name' => 'Ciprofloxacin',      'drug_class' => 'Fluoroquinolone',           'drug_category' => 'Antibiotics',    'common_dose' => 500,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Cipro'],
            ['drug_name' => 'Trimethoprim-Sulfamethoxazole','drug_class' => 'Sulfonamide',     'drug_category' => 'Antibiotics',    'common_dose' => 160,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Bactrim, Septra'],
            ['drug_name' => 'Nitrofurantoin',     'drug_class' => 'Urinary Antibiotic',        'drug_category' => 'Antibiotics',    'common_dose' => 100,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Macrobid'],
            ['drug_name' => 'Doxycycline',        'drug_class' => 'Tetracycline',              'drug_category' => 'Antibiotics',    'common_dose' => 100,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Vibramycin'],
            ['drug_name' => 'Metronidazole',      'drug_class' => 'Nitroimidazole',            'drug_category' => 'Antibiotics',    'common_dose' => 500,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Flagyl'],

            // ── Skin / Topical ────────────────────────────────────────────────
            ['drug_name' => 'Triamcinolone Cream','drug_class' => 'Topical Corticosteroid',    'drug_category' => 'Dermatology',    'common_dose' => 0.1,  'dose_unit' => '%',   'route' => 'topical','frequency' => 'BID',  'brand_names' => 'Kenalog'],
            ['drug_name' => 'Hydrocortisone Cream','drug_class' => 'Topical Corticosteroid',   'drug_category' => 'Dermatology',    'common_dose' => 1,    'dose_unit' => '%',   'route' => 'topical','frequency' => 'BID',  'brand_names' => null],
            ['drug_name' => 'Miconazole Cream',   'drug_class' => 'Topical Antifungal',        'drug_category' => 'Dermatology',    'common_dose' => 2,    'dose_unit' => '%',   'route' => 'topical','frequency' => 'BID',  'brand_names' => 'Monistat-Derm, Lotrimin'],
            ['drug_name' => 'Clotrimazole Cream', 'drug_class' => 'Topical Antifungal',        'drug_category' => 'Dermatology',    'common_dose' => 1,    'dose_unit' => '%',   'route' => 'topical','frequency' => 'BID',  'brand_names' => 'Lotrimin AF'],

            // ── Urologic ──────────────────────────────────────────────────────
            ['drug_name' => 'Tamsulosin',         'drug_class' => 'Alpha Blocker',             'drug_category' => 'Urologic',       'common_dose' => 0.4,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Flomax'],
            ['drug_name' => 'Finasteride',        'drug_class' => '5-Alpha Reductase Inhibitor','drug_category' => 'Urologic',      'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Proscar'],
            ['drug_name' => 'Oxybutynin',         'drug_class' => 'Anticholinergic',           'drug_category' => 'Urologic',       'common_dose' => 5,    'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'BID',   'brand_names' => 'Ditropan'],
            ['drug_name' => 'Mirabegron',         'drug_class' => 'Beta-3 Agonist',            'drug_category' => 'Urologic',       'common_dose' => 25,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Myrbetriq'],

            // ── Eyes / Ears ───────────────────────────────────────────────────
            ['drug_name' => 'Timolol Eye Drops',  'drug_class' => 'Beta Blocker (ophthalmic)', 'drug_category' => 'Ophthalmology',  'common_dose' => 0.5,  'dose_unit' => '%',   'route' => 'optic','frequency' => 'BID',   'brand_names' => 'Timoptic'],
            ['drug_name' => 'Latanoprost',        'drug_class' => 'Prostaglandin Analog',      'drug_category' => 'Ophthalmology',  'common_dose' => 0.005,'dose_unit' => '%',   'route' => 'optic','frequency' => 'daily', 'brand_names' => 'Xalatan'],
            ['drug_name' => 'Ciprofloxacin Otic', 'drug_class' => 'Topical Antibiotic',        'drug_category' => 'Otic',           'common_dose' => 2,    'dose_unit' => 'drop','route' => 'otic', 'frequency' => 'BID',   'brand_names' => 'Cetraxal'],

            // ── Nasal ─────────────────────────────────────────────────────────
            ['drug_name' => 'Fluticasone Nasal Spray','drug_class' => 'Intranasal Corticosteroid','drug_category' => 'ENT',        'common_dose' => 50,   'dose_unit' => 'mcg', 'route' => 'nasal','frequency' => 'daily', 'brand_names' => 'Flonase'],

            // ── Antivirals ────────────────────────────────────────────────────
            ['drug_name' => 'Acyclovir',          'drug_class' => 'Antiviral',                 'drug_category' => 'Antiviral',      'common_dose' => 400,  'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Zovirax'],
            ['drug_name' => 'Valacyclovir',       'drug_class' => 'Antiviral',                 'drug_category' => 'Antiviral',      'common_dose' => 1000, 'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'TID',   'brand_names' => 'Valtrex'],

            // ── Osteoporosis ──────────────────────────────────────────────────
            ['drug_name' => 'Alendronate',        'drug_class' => 'Bisphosphonate',            'drug_category' => 'Bone',           'common_dose' => 70,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'weekly','brand_names' => 'Fosamax'],
            ['drug_name' => 'Risedronate',        'drug_class' => 'Bisphosphonate',            'drug_category' => 'Bone',           'common_dose' => 35,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'weekly','brand_names' => 'Actonel'],
            ['drug_name' => 'Denosumab',          'drug_class' => 'RANK Ligand Inhibitor',     'drug_category' => 'Bone',           'common_dose' => 60,   'dose_unit' => 'mg',  'route' => 'subcut','frequency' => 'once',  'brand_names' => 'Prolia'],

            // ── Antihistamines ────────────────────────────────────────────────
            ['drug_name' => 'Cetirizine',         'drug_class' => 'Antihistamine',             'drug_category' => 'Allergy',        'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Zyrtec'],
            ['drug_name' => 'Loratadine',         'drug_class' => 'Antihistamine',             'drug_category' => 'Allergy',        'common_dose' => 10,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'daily', 'brand_names' => 'Claritin'],
            ['drug_name' => 'Diphenhydramine',    'drug_class' => 'Antihistamine',             'drug_category' => 'Allergy',        'common_dose' => 25,   'dose_unit' => 'mg',  'route' => 'oral', 'frequency' => 'PRN',   'brand_names' => 'Benadryl'],
        ];

        $rows = array_map(fn ($med) => array_merge([
            'rxnorm_code'        => null,
            'drug_class'         => null,
            'drug_category'      => null,
            'common_dose'        => null,
            'dose_unit'          => null,
            'route'              => null,
            'frequency'          => null,
            'is_controlled'      => false,
            'controlled_schedule'=> null,
            'brand_names'        => null,
        ], $med), $medications);

        // Insert in chunks to avoid hitting parameter limits
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('emr_medications_reference')->insert($chunk);
        }
    }

    private function seedInteractions(): void
    {
        // Normalized pairs: drug_name_1 < drug_name_2 alphabetically
        $pairs = [
            // ── Anticoagulant interactions ────────────────────────────────────
            ['drug_name_1' => 'Aspirin',           'drug_name_2' => 'Warfarin',          'severity' => 'major',          'description' => 'Concurrent use significantly increases bleeding risk due to additive antiplatelet and anticoagulant effects.'],
            ['drug_name_1' => 'Clopidogrel',       'drug_name_2' => 'Warfarin',          'severity' => 'major',          'description' => 'Dual antiplatelet/anticoagulant therapy substantially increases hemorrhage risk.'],
            ['drug_name_1' => 'Apixaban',          'drug_name_2' => 'Aspirin',           'severity' => 'major',          'description' => 'Combination increases bleeding risk significantly.'],
            ['drug_name_1' => 'Ibuprofen',         'drug_name_2' => 'Warfarin',          'severity' => 'major',          'description' => 'NSAIDs inhibit platelet function and may displace warfarin from protein binding, increasing INR and bleeding risk.'],
            ['drug_name_1' => 'Naproxen',          'drug_name_2' => 'Warfarin',          'severity' => 'major',          'description' => 'NSAIDs increase bleeding risk with warfarin via platelet inhibition and protein displacement.'],
            ['drug_name_1' => 'Aspirin',           'drug_name_2' => 'Clopidogrel',       'severity' => 'moderate',       'description' => 'Dual antiplatelet therapy increases bleeding risk; commonly used together but requires careful monitoring.'],
            ['drug_name_1' => 'Apixaban',          'drug_name_2' => 'Rivaroxaban',       'severity' => 'contraindicated','description' => 'Concurrent use of two DOACs is contraindicated due to additive hemorrhage risk with no therapeutic benefit.'],

            // ── QT prolongation interactions ──────────────────────────────────
            ['drug_name_1' => 'Amiodarone',        'drug_name_2' => 'Azithromycin',      'severity' => 'contraindicated','description' => 'Both drugs prolong the QT interval; concurrent use greatly increases risk of torsades de pointes and fatal arrhythmia.'],
            ['drug_name_1' => 'Amiodarone',        'drug_name_2' => 'Ciprofloxacin',     'severity' => 'major',          'description' => 'Ciprofloxacin can prolong QT interval; additive effect with amiodarone increases arrhythmia risk.'],
            ['drug_name_1' => 'Amiodarone',        'drug_name_2' => 'Ondansetron',       'severity' => 'major',          'description' => 'Additive QT prolongation risk. Avoid combination or use with continuous cardiac monitoring.'],
            ['drug_name_1' => 'Amiodarone',        'drug_name_2' => 'Metoclopramide',    'severity' => 'moderate',       'description' => 'Metoclopramide may prolong QT interval; use with caution alongside amiodarone.'],
            ['drug_name_1' => 'Quetiapine',        'drug_name_2' => 'Azithromycin',      'severity' => 'major',          'description' => 'Both can prolong QT interval; concurrent use increases risk of life-threatening arrhythmias.'],

            // ── Drug metabolism (CYP2C9/CYP3A4) ──────────────────────────────
            ['drug_name_1' => 'Amiodarone',        'drug_name_2' => 'Warfarin',          'severity' => 'contraindicated','description' => 'Amiodarone strongly inhibits CYP2C9, dramatically increasing warfarin levels and bleeding risk. INR can rise 2-4x.'],
            ['drug_name_1' => 'Amiodarone',        'drug_name_2' => 'Digoxin',           'severity' => 'major',          'description' => 'Amiodarone inhibits P-glycoprotein, increasing digoxin levels by up to 100%. Risk of digoxin toxicity.'],
            ['drug_name_1' => 'Amiodarone',        'drug_name_2' => 'Simvastatin',       'severity' => 'major',          'description' => 'Amiodarone inhibits CYP3A4 metabolism of simvastatin, increasing myopathy and rhabdomyolysis risk.'],
            ['drug_name_1' => 'Diltiazem',         'drug_name_2' => 'Simvastatin',       'severity' => 'major',          'description' => 'Diltiazem inhibits CYP3A4, increasing simvastatin levels and risk of myopathy/rhabdomyolysis.'],

            // ── Digoxin interactions ──────────────────────────────────────────
            ['drug_name_1' => 'Digoxin',           'drug_name_2' => 'Furosemide',        'severity' => 'moderate',       'description' => 'Furosemide can cause hypokalemia, increasing risk of digoxin toxicity (arrhythmias, nausea).'],
            ['drug_name_1' => 'Digoxin',           'drug_name_2' => 'Spironolactone',    'severity' => 'moderate',       'description' => 'Spironolactone can increase digoxin levels; monitor for toxicity.'],
            ['drug_name_1' => 'Digoxin',           'drug_name_2' => 'Metoprolol',        'severity' => 'moderate',       'description' => 'Additive slowing of AV conduction; may cause excessive bradycardia or heart block.'],
            ['drug_name_1' => 'Digoxin',           'drug_name_2' => 'Diltiazem',         'severity' => 'moderate',       'description' => 'Diltiazem increases digoxin levels and has additive AV nodal depression.'],

            // ── ACE Inhibitor / ARB interactions ─────────────────────────────
            ['drug_name_1' => 'Lisinopril',        'drug_name_2' => 'Losartan',          'severity' => 'contraindicated','description' => 'Dual renin-angiotensin blockade (ACE inhibitor + ARB) is contraindicated; significantly increases risk of hypotension, hyperkalemia, and renal failure.'],
            ['drug_name_1' => 'Lisinopril',        'drug_name_2' => 'Spironolactone',    'severity' => 'major',          'description' => 'Combination increases risk of hyperkalemia, potentially causing fatal cardiac arrhythmias. Monitor potassium closely.'],
            ['drug_name_1' => 'Enalapril',         'drug_name_2' => 'Spironolactone',    'severity' => 'major',          'description' => 'ACE inhibitor + potassium-sparing diuretic significantly increases hyperkalemia risk.'],
            ['drug_name_1' => 'Lisinopril',        'drug_name_2' => 'Ibuprofen',         'severity' => 'moderate',       'description' => 'NSAIDs reduce the antihypertensive effect of ACE inhibitors and can worsen renal function.'],

            // ── SSRI interactions ─────────────────────────────────────────────
            ['drug_name_1' => 'Sertraline',        'drug_name_2' => 'Warfarin',          'severity' => 'moderate',       'description' => 'SSRIs inhibit platelet function and may interact with warfarin metabolism; monitor INR.'],
            ['drug_name_1' => 'Escitalopram',      'drug_name_2' => 'Tramadol',          'severity' => 'major',          'description' => 'Concurrent use increases risk of serotonin syndrome (agitation, clonus, hyperthermia, diaphoresis).'],
            ['drug_name_1' => 'Fluoxetine',        'drug_name_2' => 'Tramadol',          'severity' => 'major',          'description' => 'Fluoxetine inhibits CYP2D6 (tramadol metabolism) and increases serotonin syndrome risk.'],
            ['drug_name_1' => 'Sertraline',        'drug_name_2' => 'Tramadol',          'severity' => 'major',          'description' => 'SSRI + tramadol combination increases serotonin syndrome risk significantly.'],
            ['drug_name_1' => 'Duloxetine',        'drug_name_2' => 'Tramadol',          'severity' => 'major',          'description' => 'SNRI + tramadol: high risk of serotonin syndrome.'],

            // ── Opioid interactions ───────────────────────────────────────────
            ['drug_name_1' => 'Lorazepam',         'drug_name_2' => 'Oxycodone',         'severity' => 'contraindicated','description' => 'Concurrent use of benzodiazepines and opioids is associated with profound sedation, respiratory depression, coma, and death. FDA Black Box Warning.'],
            ['drug_name_1' => 'Clonazepam',        'drug_name_2' => 'Oxycodone',         'severity' => 'contraindicated','description' => 'Benzodiazepine + opioid combination carries Black Box Warning for respiratory depression and death.'],
            ['drug_name_1' => 'Lorazepam',         'drug_name_2' => 'Morphine',          'severity' => 'contraindicated','description' => 'FDA Black Box Warning: benzodiazepine + opioid combination increases risk of fatal respiratory depression.'],
            ['drug_name_1' => 'Morphine',          'drug_name_2' => 'Quetiapine',        'severity' => 'major',          'description' => 'Additive CNS depression; increased sedation, respiratory depression, and falls risk.'],
            ['drug_name_1' => 'Gabapentin',        'drug_name_2' => 'Oxycodone',         'severity' => 'major',          'description' => 'Gabapentin combined with opioids increases risk of respiratory depression and overdose death.'],

            // ── Statin interactions ───────────────────────────────────────────
            ['drug_name_1' => 'Atorvastatin',      'drug_name_2' => 'Diltiazem',         'severity' => 'moderate',       'description' => 'Diltiazem inhibits CYP3A4 metabolism of atorvastatin, increasing statin levels and myopathy risk.'],

            // ── Hypoglycemia risk ─────────────────────────────────────────────
            ['drug_name_1' => 'Glipizide',         'drug_name_2' => 'Metoprolol',        'severity' => 'moderate',       'description' => 'Beta blockers mask tachycardia (early sign of hypoglycemia) and may prolong hypoglycemic episodes in patients on sulfonylureas.'],
            ['drug_name_1' => 'Glipizide',         'drug_name_2' => 'Ciprofloxacin',     'severity' => 'major',          'description' => 'Fluoroquinolones can cause severe dysglycemia (hypoglycemia or hyperglycemia) in patients on oral hypoglycemic agents.'],
            ['drug_name_1' => 'Glimepiride',       'drug_name_2' => 'Ciprofloxacin',     'severity' => 'major',          'description' => 'Fluoroquinolone + sulfonylurea: high risk of severe hypoglycemia or hyperglycemia.'],
            ['drug_name_1' => 'Metformin',         'drug_name_2' => 'Furosemide',        'severity' => 'moderate',       'description' => 'Furosemide can increase metformin plasma levels; monitor renal function closely.'],

            // ── Hypotension / fall risk ───────────────────────────────────────
            ['drug_name_1' => 'Furosemide',        'drug_name_2' => 'Lisinopril',        'severity' => 'moderate',       'description' => 'Combination of loop diuretic and ACE inhibitor can cause additive hypotension, particularly first-dose hypotension.'],
            ['drug_name_1' => 'Furosemide',        'drug_name_2' => 'Tamsulosin',        'severity' => 'moderate',       'description' => 'Additive blood pressure lowering; increased risk of orthostatic hypotension and falls in elderly patients.'],
            ['drug_name_1' => 'Oxybutynin',        'drug_name_2' => 'Quetiapine',        'severity' => 'moderate',       'description' => 'Additive anticholinergic effects; increased risk of confusion, urinary retention, constipation in elderly patients.'],

            // ── Renal function ────────────────────────────────────────────────
            ['drug_name_1' => 'Ibuprofen',         'drug_name_2' => 'Metformin',         'severity' => 'moderate',       'description' => 'NSAIDs can reduce renal function and increase metformin plasma levels, raising risk of metformin-associated lactic acidosis.'],

            // ── Thyroid interactions ──────────────────────────────────────────
            ['drug_name_1' => 'Ferrous Sulfate',   'drug_name_2' => 'Levothyroxine',     'severity' => 'moderate',       'description' => 'Iron significantly reduces levothyroxine absorption. Separate administration by at least 4 hours.'],
            ['drug_name_1' => 'Calcium Carbonate', 'drug_name_2' => 'Levothyroxine',     'severity' => 'moderate',       'description' => 'Calcium chelates levothyroxine, reducing absorption. Separate by at least 4 hours.'],

            // ── Minor interactions ────────────────────────────────────────────
            ['drug_name_1' => 'Atorvastatin',      'drug_name_2' => 'Colchicine',        'severity' => 'minor',          'description' => 'Rare reports of myopathy with statin + colchicine combination. Monitor for muscle pain.'],
            ['drug_name_1' => 'Amlodipine',        'drug_name_2' => 'Simvastatin',       'severity' => 'moderate',       'description' => 'Amlodipine slightly increases simvastatin levels; simvastatin dose should not exceed 20 mg.'],
            ['drug_name_1' => 'Gabapentin',        'drug_name_2' => 'Morphine',          'severity' => 'major',          'description' => 'Gabapentin + opioid combination increases respiratory depression risk. Avoid or use with enhanced monitoring.'],
            ['drug_name_1' => 'Levothyroxine',     'drug_name_2' => 'Warfarin',          'severity' => 'moderate',       'description' => 'Hyperthyroid state from levothyroxine can increase warfarin sensitivity; monitor INR.'],
            ['drug_name_1' => 'Metformin',         'drug_name_2' => 'Spironolactone',    'severity' => 'minor',          'description' => 'Spironolactone may slightly reduce metformin clearance; rarely clinically significant.'],
        ];

        // Normalize: ensure drug_name_1 < drug_name_2 alphabetically
        $normalizedPairs = array_map(function ($pair) {
            if ($pair['drug_name_1'] > $pair['drug_name_2']) {
                [$pair['drug_name_1'], $pair['drug_name_2']] = [$pair['drug_name_2'], $pair['drug_name_1']];
            }
            return $pair;
        }, $pairs);

        // Remove duplicates (after normalization)
        $seen  = [];
        $unique = [];
        foreach ($normalizedPairs as $pair) {
            $key = $pair['drug_name_1'] . '|' . $pair['drug_name_2'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[]   = $pair;
            }
        }

        foreach (array_chunk($unique, 50) as $chunk) {
            DB::table('emr_drug_interactions_reference')->insert($chunk);
        }
    }
}
