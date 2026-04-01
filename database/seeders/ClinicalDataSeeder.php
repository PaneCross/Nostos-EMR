<?php

// ─── ClinicalDataSeeder ───────────────────────────────────────────────────────
// Seeds Phase 3 clinical data for demo participants: clinical notes, vitals,
// assessments, problem lists, allergies, ADL records, and ADL thresholds.
//
// Per enrolled participant:
//   3–5 clinical notes (mix of signed + draft)
//   10–20 vitals spread over 90 days
//   1–2 assessments
//   2–4 problems (primary diagnosis + chronic conditions)
//   0–3 allergies/dietary restrictions
//   15–20 ADL records spread over 90 days
//   ADL thresholds for all 10 categories
//
// Only seeds enrolled participants to keep demo data meaningful.
// Safe to re-run on an empty clinical tables set (does not guard against dupes).
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\AdlRecord;
use App\Models\AdlThreshold;
use App\Models\Allergy;
use App\Models\Assessment;
use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ClinicalDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();

        // Use primary_care admin as default author for clinical records
        $pcUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')
            ->where('role', 'admin')
            ->first();

        $swUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'social_work')
            ->first();

        $authorId = $pcUser?->id ?? 1;

        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->get();

        $this->command->info("  Seeding clinical data for {$participants->count()} enrolled participants...");

        $noteCount       = 0;
        $vitalCount      = 0;
        $assessmentCount = 0;
        $problemCount    = 0;
        $allergyCount    = 0;
        $adlCount        = 0;

        foreach ($participants as $participant) {
            // ── Clinical Notes ────────────────────────────────────────────────
            $notes = rand(3, 5);
            for ($i = 0; $i < $notes; $i++) {
                $isSoap   = $i === 0;
                $isSigned = $i < ($notes - 1);  // last note stays draft
                $visitDt  = Carbon::now()->subDays(rand(0, 85));

                $data = [
                    'participant_id'      => $participant->id,
                    'tenant_id'           => $tenant->id,
                    'site_id'             => $participant->site_id,
                    'note_type'           => $isSoap ? 'soap' : $this->randomNoteType(),
                    'authored_by_user_id' => $authorId,
                    'department'          => 'primary_care',
                    'status'              => $isSigned
                        ? ClinicalNote::STATUS_SIGNED
                        : ClinicalNote::STATUS_DRAFT,
                    'visit_type'          => 'in_center',
                    'visit_date'          => $visitDt->format('Y-m-d'),
                    'visit_time'          => '09:00:00',
                    'signed_at'           => $isSigned ? $visitDt : null,
                    'signed_by_user_id'   => $isSigned ? $authorId : null,
                    'is_late_entry'       => false,
                ];

                if ($isSoap) {
                    $data += [
                        'subjective' => 'Participant reports feeling well. Denies chest pain or shortness of breath.',
                        'objective'  => 'Alert and oriented x3. BP within target range. No acute distress.',
                        'assessment' => 'Stable chronic conditions. Hypertension controlled on current regimen.',
                        'plan'       => 'Continue current medications. Follow-up in 30 days. Labs ordered.',
                        'content'    => null,
                    ];
                } else {
                    $data += [
                        'content' => ['notes' => 'Participant participated in group activity. Good engagement noted.'],
                    ];
                }

                ClinicalNote::create($data);
                $noteCount++;
            }

            // ── Vitals ────────────────────────────────────────────────────────
            $vitalEntries = rand(10, 20);
            for ($i = 0; $i < $vitalEntries; $i++) {
                $recordedAt = Carbon::now()->subDays(rand(0, 90))
                    ->setTime(rand(8, 16), rand(0, 59));

                // ~65% of vitals include a glucose reading (common in PACE/diabetic population)
                // Valid timing values per emr_vitals_blood_glucose_timing_check constraint
                $timings        = ['fasting', 'pre_meal', 'post_meal_2h', 'random'];
                $glucoseTiming  = $timings[array_rand($timings)];
                $glucoseRanges  = [
                    'fasting'      => [80, 130],
                    'pre_meal'     => [85, 145],
                    'post_meal_2h' => [120, 210],
                    'random'       => [75, 195],
                ];
                [$gMin, $gMax] = $glucoseRanges[$glucoseTiming];
                $hasGlucose = (rand(1, 100) <= 65);

                Vital::create([
                    'participant_id'       => $participant->id,
                    'tenant_id'            => $tenant->id,
                    'recorded_by_user_id'  => $authorId,
                    'recorded_at'          => $recordedAt,
                    'bp_systolic'          => rand(110, 165),
                    'bp_diastolic'         => rand(65, 100),
                    'pulse'                => rand(58, 98),
                    'temperature_f'        => round(97.5 + lcg_value() * 1.5, 1),
                    'respiratory_rate'     => rand(14, 20),
                    'o2_saturation'        => rand(92, 99),
                    'weight_lbs'           => round(140 + lcg_value() * 80, 1),
                    'height_in'            => rand(60, 70),
                    'pain_score'           => rand(0, 5),
                    'blood_glucose'        => $hasGlucose ? rand($gMin, $gMax) : null,
                    'blood_glucose_timing' => $hasGlucose ? $glucoseTiming : null,
                    'notes'                => null,
                ]);
                $vitalCount++;
            }

            // ── Assessments ───────────────────────────────────────────────────
            $assessmentTypes = ['phq9_depression', 'fall_risk_morse'];
            foreach (array_slice($assessmentTypes, 0, rand(1, 2)) as $type) {
                $completedAt = Carbon::now()->subDays(rand(30, 180));
                Assessment::create([
                    'participant_id'      => $participant->id,
                    'tenant_id'           => $tenant->id,
                    'authored_by_user_id' => $authorId,
                    'department'          => 'primary_care',
                    'assessment_type'     => $type,
                    'responses'           => $this->assessmentResponses($type),
                    'score'               => $this->assessmentScore($type),
                    'completed_at'        => $completedAt,
                    'next_due_date'       => $completedAt->copy()->addYear(),
                    'threshold_flags'     => null,
                ]);
                $assessmentCount++;
            }

            // ── Problems ──────────────────────────────────────────────────────
            $problemList = $this->randomProblems(rand(2, 4));
            foreach ($problemList as $idx => $problem) {
                Problem::create([
                    'participant_id'      => $participant->id,
                    'tenant_id'           => $tenant->id,
                    'added_by_user_id'    => $authorId,
                    'icd10_code'          => $problem['code'],
                    'icd10_description'   => $problem['desc'],
                    'status'              => $idx === 0 ? 'active' : 'chronic',
                    'onset_date'          => Carbon::now()->subYears(rand(1, 8))->format('Y-m-d'),
                    'resolved_date'       => null,
                    'is_primary_diagnosis' => $idx === 0,
                    'notes'               => null,
                ]);
                $problemCount++;
            }

            // ── Allergies (0–3, some may have none) ───────────────────────────
            $allergyCount_ = rand(0, 3);
            for ($i = 0; $i < $allergyCount_; $i++) {
                [$type, $allergen, $reaction, $severity] = $this->randomAllergy($i);
                Allergy::create([
                    'participant_id'       => $participant->id,
                    'tenant_id'            => $tenant->id,
                    'allergy_type'         => $type,
                    'allergen_name'        => $allergen,
                    'reaction_description' => $reaction,
                    'severity'             => $severity,
                    'onset_date'           => null,
                    'is_active'            => true,
                    'verified_by_user_id'  => $authorId,
                    'verified_at'          => now()->subDays(rand(1, 60)),
                    'notes'                => null,
                ]);
                $allergyCount++;
            }

            // ── ADL Records ───────────────────────────────────────────────────
            $adlEntries = rand(15, 20);
            for ($i = 0; $i < $adlEntries; $i++) {
                $category = AdlRecord::CATEGORIES[$i % count(AdlRecord::CATEGORIES)];
                // Lean toward middle levels for a realistic population
                $levelWeights = ['independent' => 15, 'supervision' => 35, 'limited_assist' => 35, 'extensive_assist' => 12, 'total_dependent' => 3];
                $level = $this->weightedRandom($levelWeights);

                AdlRecord::create([
                    'participant_id'       => $participant->id,
                    'tenant_id'            => $tenant->id,
                    'recorded_by_user_id'  => $authorId,
                    'recorded_at'          => Carbon::now()->subDays(rand(0, 89)),
                    'adl_category'         => $category,
                    'independence_level'   => $level,
                    'assistive_device_used'=> $level === 'independent' ? 'None' : 'Walker',
                    'notes'                => null,
                    'threshold_breached'   => false,
                ]);
                $adlCount++;
            }

            // ── ADL Thresholds (one per category) ─────────────────────────────
            foreach (AdlRecord::CATEGORIES as $category) {
                AdlThreshold::updateOrCreate(
                    ['participant_id' => $participant->id, 'adl_category' => $category],
                    [
                        'threshold_level' => 'extensive_assist',
                        'set_by_user_id'  => $authorId,
                        'set_at'          => now()->subDays(rand(30, 180)),
                    ]
                );
            }
        }

        $this->command->line("  Clinical data seeded: <comment>{$noteCount}</comment> notes · <comment>{$vitalCount}</comment> vitals · <comment>{$assessmentCount}</comment> assessments · <comment>{$problemCount}</comment> problems · <comment>{$allergyCount}</comment> allergies · <comment>{$adlCount}</comment> ADL records");
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function randomNoteType(): string
    {
        $types = ['progress_nursing', 'social_work', 'dietary', 'therapy_pt'];
        return $types[array_rand($types)];
    }

    private function assessmentResponses(string $type): array
    {
        return match ($type) {
            'phq9_depression' => array_fill_keys(['q1','q2','q3','q4','q5','q6','q7','q8','q9'], '1'),
            'fall_risk_morse' => [
                'fall_history' => '25', 'secondary_diagnosis' => '15',
                'ambulatory_aid' => '15', 'iv_access' => '0',
                'gait' => '10', 'mental_status' => '0',
            ],
            default => ['notes' => 'See attached documentation.'],
        };
    }

    private function assessmentScore(string $type): ?int
    {
        return match ($type) {
            'phq9_depression' => rand(0, 15),
            'fall_risk_morse' => rand(20, 70),
            default           => null,
        };
    }

    private function randomProblems(int $count): array
    {
        $pool = [
            ['code' => 'I10',   'desc' => 'Essential (primary) hypertension',          'cat' => 'Cardiovascular'],
            ['code' => 'E11.9', 'desc' => 'Type 2 diabetes mellitus without complications', 'cat' => 'Endocrine'],
            ['code' => 'M19.90','desc' => 'Unspecified osteoarthritis, unspecified site','cat' => 'Musculoskeletal'],
            ['code' => 'F03.90','desc' => 'Unspecified dementia without behavioral disturbance', 'cat' => 'Neurological'],
            ['code' => 'J44.9', 'desc' => 'Chronic obstructive pulmonary disease, unspecified', 'cat' => 'Respiratory'],
            ['code' => 'N18.3', 'desc' => 'Chronic kidney disease, stage 3',             'cat' => 'Renal'],
            ['code' => 'I50.9', 'desc' => 'Heart failure, unspecified',                  'cat' => 'Cardiovascular'],
            ['code' => 'F32.9', 'desc' => 'Major depressive disorder, single episode, unspecified', 'cat' => 'Psychiatric'],
            ['code' => 'M54.5', 'desc' => 'Low back pain',                               'cat' => 'Musculoskeletal'],
            ['code' => 'E78.5', 'desc' => 'Hyperlipidemia, unspecified',                 'cat' => 'Endocrine'],
        ];

        shuffle($pool);
        return array_slice($pool, 0, $count);
    }

    private function randomAllergy(int $idx): array
    {
        $choices = [
            ['drug',               'Penicillin',        'Rash / hives',      'moderate'],
            ['food',               'Shellfish',          'GI upset',          'mild'],
            ['drug',               'Sulfa',              'Rash',              'severe'],
            ['dietary_restriction','Low sodium diet',    'Dietary need',      'intolerance'],
            ['drug',               'NSAIDs',             'GI bleeding',       'moderate'],
            ['food',               'Peanuts',            'Anaphylaxis',       'life_threatening'],
            ['environmental',      'Dust mites',         'Respiratory distress', 'mild'],
        ];
        return $choices[$idx % count($choices)];
    }

    /**
     * Pick a random key from an array weighted by its integer values.
     * @param array<string, int> $weights  key → relative weight
     */
    private function weightedRandom(array $weights): string
    {
        $total  = array_sum($weights);
        $target = rand(1, $total);
        $cumulative = 0;
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($target <= $cumulative) {
                return $key;
            }
        }
        return array_key_last($weights);
    }
}
