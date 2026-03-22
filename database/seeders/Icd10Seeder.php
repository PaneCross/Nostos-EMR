<?php

// ─── Icd10Seeder ──────────────────────────────────────────────────────────────
// Seeds the emr_icd10_lookup table with ~200 ICD-10 codes most commonly
// encountered in PACE (Program of All-inclusive Care for the Elderly) programs.
// Chunked insert for performance. Safe to re-run — uses upsert on code.
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Icd10Seeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('  Seeding ICD-10 lookup codes (~200 PACE-relevant codes)...');

        $codes = $this->paceCodes();

        // Deduplicate by code (same code may appear in multiple category sections)
        $unique = collect($codes)->keyBy('code')->values();

        // Chunk inserts and upsert on code (idempotent re-runs)
        $unique->chunk(50)->each(function ($chunk) {
            DB::table('emr_icd10_lookup')->upsert(
                $chunk->toArray(),
                ['code'],           // unique key
                ['description', 'category']  // update on conflict
            );
        });

        $this->command->line('  ICD-10 codes seeded: <comment>' . $unique->count() . '</comment>');
    }

    // ─── ICD-10 codes organised by clinical category ──────────────────────────
    private function paceCodes(): array
    {
        $codes = [];

        // Helper closure to append rows
        $add = function (array $rows, string $category) use (&$codes) {
            foreach ($rows as [$code, $desc]) {
                $codes[] = ['code' => $code, 'description' => $desc, 'category' => $category];
            }
        };

        // ── Cardiovascular ────────────────────────────────────────────────────
        $add([
            ['I10',    'Essential (primary) hypertension'],
            ['I11.9',  'Hypertensive heart disease without heart failure'],
            ['I13.10', 'Hypertensive heart and chronic kidney disease without heart failure'],
            ['I20.9',  'Angina pectoris, unspecified'],
            ['I21.9',  'Acute myocardial infarction, unspecified'],
            ['I25.10', 'Atherosclerotic heart disease of native coronary artery without angina pectoris'],
            ['I48.0',  'Paroxysmal atrial fibrillation'],
            ['I48.11', 'Longstanding persistent atrial fibrillation'],
            ['I48.2',  'Chronic atrial fibrillation, unspecified'],
            ['I48.91', 'Unspecified atrial fibrillation'],
            ['I50.1',  'Left ventricular failure, unspecified'],
            ['I50.9',  'Heart failure, unspecified'],
            ['I50.30', 'Unspecified diastolic (congestive) heart failure'],
            ['I50.32', 'Chronic diastolic (congestive) heart failure'],
            ['I63.9',  'Cerebral infarction, unspecified'],
            ['I69.351','Hemiplegia and hemiparesis following cerebral infarction affecting right dominant side'],
            ['I69.354','Hemiplegia and hemiparesis following cerebral infarction affecting left non-dominant side'],
            ['I70.0',  'Atherosclerosis of aorta'],
            ['I70.201','Unspecified atherosclerosis of native arteries of extremities, right leg'],
            ['I73.9',  'Peripheral vascular disease, unspecified'],
            ['I87.2',  'Venous insufficiency (chronic) (peripheral)'],
        ], 'Cardiovascular');

        // ── Endocrine / Metabolic ─────────────────────────────────────────────
        $add([
            ['E11.9',  'Type 2 diabetes mellitus without complications'],
            ['E11.21', 'Type 2 diabetes mellitus with diabetic nephropathy'],
            ['E11.40', 'Type 2 diabetes mellitus with diabetic neuropathy, unspecified'],
            ['E11.51', 'Type 2 diabetes mellitus with diabetic macular edema, resolved following treatment'],
            ['E11.65', 'Type 2 diabetes mellitus with hyperglycemia'],
            ['E11.649','Type 2 diabetes mellitus with hypoglycemia without coma'],
            ['E03.9',  'Hypothyroidism, unspecified'],
            ['E04.2',  'Nontoxic multinodular goiter'],
            ['E05.00', 'Thyrotoxicosis with diffuse goiter without thyrotoxic crisis'],
            ['E66.01', 'Morbid (severe) obesity due to excess calories'],
            ['E66.09', 'Other obesity due to excess calories'],
            ['E78.00', 'Pure hypercholesterolemia, unspecified'],
            ['E78.5',  'Hyperlipidemia, unspecified'],
            ['E83.51', 'Hypocalcemia'],
            ['E87.1',  'Hypo-osmolality and hyponatremia'],
            ['E87.6',  'Hypokalemia'],
        ], 'Endocrine');

        // ── Neurological ──────────────────────────────────────────────────────
        $add([
            ['F01.50', 'Vascular dementia without behavioral disturbance'],
            ['F01.51', 'Vascular dementia with behavioral disturbance'],
            ['F02.80', 'Dementia in other diseases classified elsewhere without behavioral disturbance'],
            ['F02.81', 'Dementia in other diseases classified elsewhere with behavioral disturbance'],
            ['F03.90', 'Unspecified dementia without behavioral disturbance'],
            ['F03.91', 'Unspecified dementia with behavioral disturbance'],
            ['G20',    'Parkinson\'s disease'],
            ['G30.0',  'Alzheimer\'s disease with early onset'],
            ['G30.1',  'Alzheimer\'s disease with late onset'],
            ['G30.9',  'Alzheimer\'s disease, unspecified'],
            ['G35',    'Multiple sclerosis'],
            ['G40.909','Epilepsy, unspecified, not intractable, without status epilepticus'],
            ['G43.909','Migraine, unspecified, not intractable, without status migrainosus'],
            ['G45.9',  'Transient cerebral ischemic attack, unspecified'],
            ['G47.00', 'Insomnia, unspecified'],
            ['G47.33', 'Obstructive sleep apnea (adult) (pediatric)'],
            ['G89.29', 'Other chronic pain'],
            ['G89.4',  'Chronic pain syndrome'],
            ['R41.3',  'Other amnesia'],
        ], 'Neurological');

        // ── Psychiatric / Behavioral Health ───────────────────────────────────
        $add([
            ['F06.70', 'Mild neurocognitive disorder due to known physiological condition without behavioral disturbance'],
            ['F10.10', 'Alcohol abuse, uncomplicated'],
            ['F17.210','Nicotine dependence, cigarettes, uncomplicated'],
            ['F32.0',  'Major depressive disorder, single episode, mild'],
            ['F32.1',  'Major depressive disorder, single episode, moderate'],
            ['F32.9',  'Major depressive disorder, single episode, unspecified'],
            ['F33.0',  'Major depressive disorder, recurrent, mild'],
            ['F33.1',  'Major depressive disorder, recurrent, moderate'],
            ['F41.0',  'Panic disorder without agoraphobia'],
            ['F41.1',  'Generalized anxiety disorder'],
            ['F41.9',  'Anxiety disorder, unspecified'],
            ['F43.10', 'Post-traumatic stress disorder, unspecified'],
        ], 'Psychiatric');

        // ── Respiratory ───────────────────────────────────────────────────────
        $add([
            ['J06.9',  'Acute upper respiratory infection, unspecified'],
            ['J18.9',  'Pneumonia, unspecified organism'],
            ['J44.0',  'Chronic obstructive pulmonary disease with acute lower respiratory infection'],
            ['J44.1',  'Chronic obstructive pulmonary disease with (acute) exacerbation'],
            ['J44.9',  'Chronic obstructive pulmonary disease, unspecified'],
            ['J45.20', 'Mild intermittent asthma, uncomplicated'],
            ['J45.30', 'Mild persistent asthma, uncomplicated'],
            ['J45.40', 'Moderate persistent asthma, uncomplicated'],
            ['J45.50', 'Severe persistent asthma, uncomplicated'],
            ['J96.00', 'Acute respiratory failure, unspecified whether with hypoxia or hypercapnia'],
            ['J98.11', 'Atelectasis'],
        ], 'Respiratory');

        // ── Musculoskeletal ───────────────────────────────────────────────────
        $add([
            ['M06.9',  'Rheumatoid arthritis, unspecified'],
            ['M10.9',  'Gout, unspecified'],
            ['M16.0',  'Bilateral primary osteoarthritis of hip'],
            ['M16.11', 'Unilateral primary osteoarthritis, right hip'],
            ['M17.0',  'Bilateral primary osteoarthritis of knee'],
            ['M17.11', 'Unilateral primary osteoarthritis, right knee'],
            ['M19.041','Primary osteoarthritis, right hand'],
            ['M19.90', 'Unspecified osteoarthritis, unspecified site'],
            ['M41.9',  'Scoliosis, unspecified'],
            ['M47.816','Spondylosis without myelopathy or radiculopathy, lumbar region'],
            ['M47.817','Spondylosis without myelopathy or radiculopathy, lumbosacral region'],
            ['M48.06', 'Spinal stenosis, lumbar region'],
            ['M54.5',  'Low back pain'],
            ['M54.51', 'Vertebrogenic low back pain'],
            ['M79.3',  'Panniculitis, unspecified'],
            ['M80.00XA','Age-related osteoporosis with current pathological fracture, unspecified site, initial encounter'],
            ['M81.0',  'Age-related osteoporosis without current pathological fracture'],
            ['M86.9',  'Osteomyelitis, unspecified'],
        ], 'Musculoskeletal');

        // ── Renal / Urological ────────────────────────────────────────────────
        $add([
            ['N17.9',  'Acute kidney failure, unspecified'],
            ['N18.1',  'Chronic kidney disease, stage 1'],
            ['N18.2',  'Chronic kidney disease, stage 2 (mild)'],
            ['N18.3',  'Chronic kidney disease, stage 3 (moderate)'],
            ['N18.4',  'Chronic kidney disease, stage 4 (severe)'],
            ['N18.5',  'Chronic kidney disease, stage 5'],
            ['N18.6',  'End-stage renal disease'],
            ['N39.0',  'Urinary tract infection, site not specified'],
            ['N40.0',  'Benign prostatic hyperplasia without lower urinary tract symptoms'],
            ['N40.1',  'Benign prostatic hyperplasia with lower urinary tract symptoms'],
            ['N39.41', 'Urge incontinence'],
            ['N39.498','Other specified urinary incontinence'],
        ], 'Renal');

        // ── Gastrointestinal ──────────────────────────────────────────────────
        $add([
            ['K21.0',  'Gastro-esophageal reflux disease with esophagitis'],
            ['K21.9',  'Gastro-esophageal reflux disease without esophagitis'],
            ['K25.9',  'Gastric ulcer, unspecified as acute or chronic, without hemorrhage or perforation'],
            ['K57.30', 'Diverticulosis of large intestine without perforation or abscess without bleeding'],
            ['K57.32', 'Diverticulitis of large intestine without perforation or abscess without bleeding'],
            ['K59.00', 'Constipation, unspecified'],
            ['K92.1',  'Melena'],
            ['K74.60', 'Unspecified cirrhosis of liver'],
        ], 'Gastrointestinal');

        // ── Hematological / Oncological ───────────────────────────────────────
        $add([
            ['C18.9',  'Malignant neoplasm of colon, unspecified'],
            ['C34.11', 'Malignant neoplasm of upper lobe, right bronchus or lung'],
            ['C50.911','Malignant neoplasm of unspecified site of right female breast'],
            ['C61',    'Malignant neoplasm of prostate'],
            ['D50.9',  'Iron deficiency anemia, unspecified'],
            ['D51.0',  'Vitamin B12 deficiency anemia due to intrinsic factor deficiency'],
            ['D63.1',  'Anemia in chronic kidney disease'],
            ['D64.9',  'Anemia, unspecified'],
        ], 'Hematological');

        // ── Sensory ───────────────────────────────────────────────────────────
        $add([
            ['H25.11', 'Age-related nuclear cataract, right eye'],
            ['H25.12', 'Age-related nuclear cataract, left eye'],
            ['H25.13', 'Age-related nuclear cataract, bilateral'],
            ['H26.9',  'Unspecified cataract'],
            ['H35.30', 'Unspecified macular degeneration'],
            ['H35.31', 'Nonexudative age-related macular degeneration, bilateral'],
            ['H40.1110','Primary open-angle glaucoma, right eye, stage unspecified'],
            ['H54.0X33','Blindness right eye, category 3, blindness left eye, category 3'],
            ['H81.10', 'Benign paroxysmal vertigo, unspecified ear'],
            ['H83.3X1','Noise effects on right inner ear'],
            ['H91.10', 'Presbycusis, unspecified ear'],
            ['H91.20', 'Sudden idiopathic hearing loss, unspecified ear'],
        ], 'Sensory');

        // ── Skin / Integumentary ──────────────────────────────────────────────
        $add([
            ['L03.011','Cellulitis of right toe'],
            ['L89.000','Pressure ulcer of unspecified elbow, unstageable'],
            ['L89.310','Pressure ulcer of right buttock, unstageable'],
            ['L89.320','Pressure ulcer of left buttock, unstageable'],
            ['L89.510','Pressure ulcer of right ankle, unstageable'],
            ['L89.600','Pressure ulcer of right heel, unstageable'],
            ['L97.119','Non-pressure chronic ulcer of right thigh with unspecified severity'],
        ], 'Integumentary');

        // ── Falls / Injuries ──────────────────────────────────────────────────
        $add([
            ['R55',    'Syncope and collapse'],
            ['W19.XXXA','Unspecified fall, initial encounter'],
            ['W18.30XA','Fall on same level, unspecified, initial encounter'],
            ['W11.XXXA','Fall on and from ladder, initial encounter'],
            ['S72.001A','Fracture of unspecified part of neck of right femur, initial encounter for closed fracture'],
            ['S72.002A','Fracture of unspecified part of neck of left femur, initial encounter for closed fracture'],
            ['S22.9XXA','Fracture of unspecified parts of thorax, initial encounter for closed fracture'],
            ['Z87.39', 'Personal history of other musculoskeletal disorders'],
            ['Z91.81', 'History of falling'],
        ], 'Falls/Injuries');

        // ── Social / Functional ───────────────────────────────────────────────
        $add([
            ['Z59.0',  'Homelessness'],
            ['Z59.1',  'Inadequate housing'],
            ['Z60.0',  'Problems of adjustment to life-cycle transitions'],
            ['Z63.0',  'Problems in relationship with spouse or partner'],
            ['Z74.0',  'Reduced mobility'],
            ['Z74.01', 'Bed confinement status'],
            ['Z74.09', 'Other reduced mobility'],
            ['Z74.1',  'Need for assistance with personal care'],
            ['Z74.2',  'Need for assistance at home and no other household member able to render care'],
            ['Z74.3',  'Need for continuous supervision'],
            ['Z87.891','Personal history of other specified conditions'],
            ['Z96.641','Presence of right artificial hip joint'],
            ['Z96.642','Presence of left artificial hip joint'],
            ['Z96.651','Presence of right artificial knee joint'],
            ['Z96.652','Presence of left artificial knee joint'],
        ], 'Social/Functional');

        // ── Pain / Palliative ─────────────────────────────────────────────────
        $add([
            ['G89.21', 'Chronic pain due to trauma'],
            ['G89.22', 'Chronic post-thoracotomy pain'],
            ['G89.28', 'Other chronic postprocedural pain'],
            ['G89.3',  'Neoplasm related pain (acute) (chronic)'],
            ['M79.10', 'Myalgia, unspecified site'],
            ['M79.18', 'Myalgia, other site'],
            ['R52',    'Pain, unspecified'],
        ], 'Pain');

        // ── Preventive / Status ───────────────────────────────────────────────
        $add([
            ['Z00.00', 'Encounter for general adult medical examination without abnormal findings'],
            ['Z00.01', 'Encounter for general adult medical examination with abnormal findings'],
            ['Z79.01', 'Long-term (current) use of anticoagulants'],
            ['Z79.02', 'Long-term (current) use of antithrombotics/antiplatelets'],
            ['Z79.4',  'Long-term (current) use of insulin'],
            ['Z79.899','Other long-term (current) drug therapy'],
            ['Z87.39', 'Personal history of other musculoskeletal disorders'],
        ], 'Preventive');

        // ── Infectious Disease ────────────────────────────────────────────────
        $add([
            ['A41.9',  'Sepsis, unspecified organism'],
            ['A49.9',  'Bacterial infection, unspecified'],
            ['B02.9',  'Zoster without complications (shingles)'],
            ['B37.9',  'Candidiasis, unspecified'],
        ], 'Infectious');

        // ── Nutrition / Hydration ─────────────────────────────────────────────
        $add([
            ['E40',    'Kwashiorkor'],
            ['E43',    'Unspecified severe protein-calorie malnutrition'],
            ['E44.0',  'Moderate protein-calorie malnutrition'],
            ['E44.1',  'Mild protein-calorie malnutrition'],
            ['E46',    'Unspecified protein-calorie malnutrition'],
            ['E86.0',  'Dehydration'],
            ['E86.1',  'Hypovolemia'],
        ], 'Nutrition');

        // ── Swallowing / Dysphagia ────────────────────────────────────────────
        $add([
            ['R13.10', 'Dysphagia, unspecified'],
            ['R13.11', 'Dysphagia, oral phase'],
            ['R13.12', 'Dysphagia, oropharyngeal phase'],
            ['R13.13', 'Dysphagia, pharyngeal phase'],
            ['R13.14', 'Dysphagia, pharyngoesophageal phase'],
        ], 'Dysphagia');

        // ── Post-Acute / COVID ────────────────────────────────────────────────
        $add([
            ['U09.9',  'Post-COVID-19 condition, unspecified'],
            ['Z86.16', 'Personal history of COVID-19'],
        ], 'PostAcute');

        // ── Genitourinary ─────────────────────────────────────────────────────
        $add([
            ['N81.10', 'Cystocele, unspecified'],
            ['N95.1',  'Menopausal and female climacteric states'],
        ], 'Genitourinary');

        // ── Substance Use ─────────────────────────────────────────────────────
        $add([
            ['F11.20', 'Opioid dependence, uncomplicated'],
            ['F19.10', 'Other psychoactive substance abuse, uncomplicated'],
        ], 'SubstanceUse');

        return $codes;
    }
}
