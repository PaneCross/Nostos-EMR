<?php

// ─── BillingDemoSeeder ────────────────────────────────────────────────────────
// Seeds realistic billing data for the W3-7 demo.
//
// Creates per-participant (30 total):
//   - 3 months of capitation records (CapitationRecord)
//   - HCC risk score for the current payment year (ParticipantRiskScore)
//   - 15-25 encounter log entries over last 60 days (EncounterLog)
//   - 3-5 PDE records for participants with medications (PdeRecord)
//   - 1 HOS-M survey per participant (HosMSurvey)
//
// Creates org-level:
//   - 1 EDI batch in 'acknowledged' status (EdiBatch)
//
// Math targets (verified by CapitationMathTest):
//   Monthly total per participant: ~$4,800 (A+B $2,800-$4,200, D $180-$320, Medicaid $1,400-$2,600)
//   Risk score average: ~2.5 (range 1.2-3.8)
//   Encounter submission rate: ~70% (pending 30%, submitted 60%, accepted 10%)
//   HOS-M completion: 83% (25 of 30 completed)
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\CapitationRecord;
use App\Models\EdiBatch;
use App\Models\EncounterLog;
use App\Models\HosMSurvey;
use App\Models\ParticipantRiskScore;
use App\Models\Participant;
use App\Models\PdeRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BillingDemoSeeder extends Seeder
{
    // PACE-appropriate HCC code clusters. Each cluster represents a common
    // multi-condition combination seen in the frail elderly PACE population.
    private const HCC_CLUSTERS = [
        ['HCC18', 'HCC85', 'HCC108'],                   // Diabetes + CHF + Vascular
        ['HCC19', 'HCC85', 'HCC111'],                   // Diabetes w/complications + CHF + COPD
        ['HCC22', 'HCC96', 'HCC108', 'HCC134'],         // Diabetes + COPD + Vascular + Bladder
        ['HCC85', 'HCC111', 'HCC23'],                   // CHF + COPD + Hemiplegia
        ['HCC18', 'HCC40', 'HCC85'],                    // Diabetes + Renal + CHF
        ['HCC85', 'HCC108', 'HCC134', 'HCC161'],        // CHF + Vascular + Bladder + Fracture
        ['HCC18', 'HCC85', 'HCC161', 'HCC96'],          // Diabetes + CHF + Fracture + COPD
        ['HCC23', 'HCC108', 'HCC85', 'HCC40', 'HCC18'],// Hemiplegia + Vascular + CHF + Renal + Diabetes
        ['HCC19', 'HCC22', 'HCC161'],                   // DM complications + Hemiplegia + Fracture
        ['HCC85', 'HCC40', 'HCC18', 'HCC96', 'HCC23', 'HCC108'], // Complex frail — 6 HCCs
    ];

    private const CPT_CODES = [
        '99213', '99213', '99213', '99213', // office visit — most common (4x weight)
        '99214',                             // detailed office visit
        '97110',                             // PT therapeutic exercise
        '97530',                             // OT therapeutic activities
        '90837',                             // therapy 60 min
        '90791',                             // psych eval
        '97001',                             // PT eval
        '97003',                             // OT eval
        '97150',                             // group therapeutic exercises
    ];

    private const PLACE_OF_SERVICE = [
        '65', '65', '65', '65', '65', '65', '65', // PACE center (70%)
        '11', '11',                                 // office (20%)
        '12',                                       // home (10%)
    ];

    private const CLAIM_TYPES = [
        'internal_capitated', 'internal_capitated', 'internal_capitated',
        'internal_capitated', 'internal_capitated', 'internal_capitated',
        'internal_capitated', // 70%
        'external_claim', 'external_claim', // 20%
        'chart_review_crr',                  // 10%
    ];

    private const DIAGNOSIS_CODES = ['Z00.00', 'E11.9', 'I50.9', 'J44.1', 'I10', 'N18.3', 'M54.5'];

    private const COUNTY_FIPS = '39049'; // Franklin County, Ohio

    private const ADJUSTMENT_TYPES = [
        'interim_january', 'interim_january', 'interim_january',
        'interim_january', 'interim_january', 'interim_january',
        'interim_january', 'interim_january', 'interim_january',
        'interim_january', 'interim_january', 'interim_january',
        'interim_january', 'interim_january', 'interim_january',
        'interim_january', 'interim_january', 'interim_january',
        'interim_january', 'interim_january',   // 20 participants
        'mid_year_june', 'mid_year_june', 'mid_year_june',
        'mid_year_june', 'mid_year_june', 'mid_year_june',
        'mid_year_june', 'mid_year_june',        // 8 participants
        'final_settlement', 'final_settlement',  // 2 participants
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();

        // Finance admin user for created_by fields
        $financeUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'finance')
            ->first();

        $primaryCareUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')
            ->first();

        if (! $financeUser || ! $primaryCareUser) {
            $this->command->warn('  BillingDemoSeeder: Required demo users not found — skipping.');
            return;
        }

        // ── 1. EDI batch ─────────────────────────────────────────────────────
        $ediBatch = EdiBatch::create([
            'tenant_id'             => $tenant->id,
            'batch_type'            => 'edr',
            'file_name'             => 'batch_' . now()->format('Ymd') . '_001.837p',
            'file_content'          => $this->fake837Header($tenant->id),
            'record_count'          => 45,
            'total_charge_amount'   => 87_450.00,
            'status'                => 'acknowledged',
            'submitted_at'          => now()->subDays(3),
            'submission_method'     => 'clearinghouse',
            'clearinghouse_reference' => 'CLR-' . strtoupper(substr(md5((string) $tenant->id), 0, 8)),
            'cms_response_code'     => '999',
            'created_by_user_id'    => $financeUser->id,
        ]);

        $this->command->line("  EDI batch: <comment>{$ediBatch->file_name}</comment> (acknowledged)");

        // ── 2. Per-participant billing data ───────────────────────────────────
        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->get();

        $pptCount = $participants->count();
        $this->command->line("  Seeding billing data for {$pptCount} enrolled participants...");

        $adjustmentIndex = 0;
        $riskScores      = [];

        foreach ($participants as $i => $participant) {
            $riskScore   = round(1.2 + mt_rand(0, 1000) / 384.6, 4); // 1.2–3.8 range
            $frailty     = round(0.15 + mt_rand(0, 1000) / 3333.3, 4); // 0.15–0.45
            $hccCluster  = self::HCC_CLUSTERS[$i % count(self::HCC_CLUSTERS)];
            $adjType     = self::ADJUSTMENT_TYPES[$adjustmentIndex++ % count(self::ADJUSTMENT_TYPES)];

            // ── Risk score ─────────────────────────────────────────────────
            ParticipantRiskScore::firstOrCreate(
                [
                    'participant_id' => $participant->id,
                    'payment_year'   => now()->year,
                ],
                [
                    'tenant_id'           => $tenant->id,
                    'risk_score'          => $riskScore,
                    'frailty_score'       => $frailty,
                    'hcc_categories'      => $hccCluster,
                    'diagnoses_submitted' => mt_rand(8, 24),
                    'diagnoses_accepted'  => mt_rand(6, 22), // small rejection rate
                    'score_source'        => 'cms_import',
                    'effective_date'      => now()->startOfYear()->toDateString(),
                    'imported_at'         => now()->subDays(mt_rand(10, 60)),
                ]
            );

            $riskScores[] = $riskScore;

            // ── Capitation records (last 3 months) ─────────────────────────
            for ($month = 2; $month >= 0; $month--) {
                $monthDate  = now()->startOfMonth()->subMonths($month);
                $monthYear  = $monthDate->format('Y-m');

                // Rate scales with risk: higher risk → higher A+B rate
                $riskMultiplier = ($riskScore - 1.0) / 2.8; // 0.07–1.0
                $abRate  = round(2800 + ($riskMultiplier * 1400), 2); // $2,800–$4,200
                $dRate   = round(180  + mt_rand(0, 140), 2);           // $180–$320
                $medRate = round(1400 + mt_rand(0, 1200), 2);          // $1,400–$2,600
                $total   = round($abRate + $dRate + $medRate, 2);

                CapitationRecord::firstOrCreate(
                    [
                        'participant_id' => $participant->id,
                        'month_year'     => $monthYear,
                    ],
                    [
                        'tenant_id'          => $tenant->id,
                        'medicare_ab_rate'   => $abRate,
                        'medicare_a_rate'    => round($abRate * 0.68, 2),
                        'medicare_b_rate'    => round($abRate * 0.32, 2),
                        'medicare_d_rate'    => $dRate,
                        'medicaid_rate'      => $medRate,
                        'total_capitation'   => $total,
                        'hcc_risk_score'     => $riskScore,
                        'frailty_score'      => $frailty,
                        'county_fips_code'   => self::COUNTY_FIPS,
                        'adjustment_type'    => $adjType,
                        'eligibility_category' => 'full_benefit_dual',
                        'rate_effective_date'  => $monthDate->toDateString(),
                        'recorded_at'          => $monthDate->addDays(5),
                    ]
                );
            }

            // ── Encounter log (15-25 encounters, last 60 days) ─────────────
            $encounterCount = mt_rand(15, 25);
            for ($e = 0; $e < $encounterCount; $e++) {
                $serviceDate = now()->subDays(mt_rand(1, 60));

                // Submission status distribution: 30% pending, 60% submitted, 10% accepted
                $rand   = mt_rand(1, 10);
                $status = match (true) {
                    $rand <= 3  => 'pending',
                    $rand <= 9  => 'submitted',
                    default     => 'accepted',
                };

                EncounterLog::create([
                    'tenant_id'              => $tenant->id,
                    'participant_id'         => $participant->id,
                    'service_date'           => $serviceDate->toDateString(),
                    'service_type'           => 'outpatient',
                    'procedure_code'         => self::CPT_CODES[array_rand(self::CPT_CODES)],
                    'provider_user_id'       => $primaryCareUser->id,
                    'billing_provider_npi'   => '1234567890',
                    'rendering_provider_npi' => '1234567890',
                    'diagnosis_codes'        => [self::DIAGNOSIS_CODES[array_rand(self::DIAGNOSIS_CODES)]],
                    'place_of_service_code'  => self::PLACE_OF_SERVICE[array_rand(self::PLACE_OF_SERVICE)],
                    'claim_type'             => self::CLAIM_TYPES[array_rand(self::CLAIM_TYPES)],
                    'submission_status'      => $status,
                    'submitted_at'           => in_array($status, ['submitted', 'accepted'])
                                                    ? $serviceDate->addDays(mt_rand(1, 5))
                                                    : null,
                    'edi_batch_id'           => in_array($status, ['submitted', 'accepted'])
                                                    ? $ediBatch->id
                                                    : null,
                    'units'                  => 1,
                    'charge_amount'          => round(45 + mt_rand(0, 200), 2),
                    'created_by_user_id'     => $primaryCareUser->id,
                ]);
            }

            // ── PDE records (for every 3rd participant — simulates controlled meds) ──
            if ($i % 3 === 0) {
                $pdeCount = mt_rand(3, 5);
                $hasTroop = ($i % 9 === 0); // every 9th participant accumulating TrOOP

                for ($p = 0; $p < $pdeCount; $p++) {
                    PdeRecord::create([
                        'tenant_id'         => $tenant->id,
                        'participant_id'    => $participant->id,
                        'drug_name'         => $this->controlledDrugName($p),
                        'ndc_code'          => $this->fakeNdc($p),
                        'dispense_date'     => now()->subDays(mt_rand(1, 30))->toDateString(),
                        'days_supply'       => 30,
                        'quantity_dispensed' => mt_rand(30, 90),
                        'ingredient_cost'   => round(15 + mt_rand(0, 180), 2),
                        'dispensing_fee'    => round(2.50 + mt_rand(0, 250) / 100, 2),
                        'patient_pay'       => round(mt_rand(0, 800) / 100, 2),
                        'troop_amount'      => $hasTroop
                                                ? round(200 + mt_rand(0, 600), 2)  // accumulating toward $7,400
                                                : 0.00,
                        'pharmacy_npi'      => '9876543210',
                        'prescriber_npi'    => '1234567890',
                        'submission_status' => mt_rand(0, 1) === 0 ? 'pending' : 'submitted',
                    ]);
                }
            }

            // ── HOS-M survey ──────────────────────────────────────────────
            // Target: ~83% completed (5 of every 30 not completed).
            // Use modulo on index so the distribution is deterministic regardless
            // of how many enrolled participants exist (works for 25 or 30 enrolled).
            $completed      = ($i % 6) !== 5; // every 6th participant is not completed (~83%)
            $submittedToCms = $completed && ($i % 7) !== 6; // a few completed but not yet submitted

            HosMSurvey::firstOrCreate(
                [
                    'participant_id' => $participant->id,
                    'survey_year'    => now()->year,
                ],
                [
                    'tenant_id'               => $tenant->id,
                    'administered_by_user_id' => $primaryCareUser->id,
                    'administered_at'         => now()->subDays(mt_rand(30, 90)),
                    'completed'               => $completed,
                    'submitted_to_cms'        => $submittedToCms,
                    'submitted_at'            => $submittedToCms ? now()->subDays(mt_rand(20, 60)) : null,
                    'responses'               => $completed ? $this->fakeSurveyResponses() : [],
                ]
            );
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $avgRisk = count($riskScores) > 0
            ? round(array_sum($riskScores) / count($riskScores), 2)
            : 0;

        $hosMCompleted = HosMSurvey::where('tenant_id', $tenant->id)
            ->where('survey_year', now()->year)->where('completed', true)->count();
        $this->command->line("  Capitation: <comment>3 months x {$pptCount} participants</comment> seeded");
        $this->command->line("  Risk scores: <comment>avg {$avgRisk}</comment> (target ~2.5)");
        $this->command->line("  HOS-M: <comment>{$hosMCompleted} completed</comment> of {$pptCount}");
        $this->command->line('  <info>BillingDemoSeeder complete.</info>');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fake837Header(int $tenantId): string
    {
        $date   = now()->format('ymd');
        $time   = now()->format('Hi');
        $isa13  = str_pad((string) $tenantId, 9, '0', STR_PAD_LEFT);
        return "ISA*00*          *00*          *ZZ*NOSTOSEMR      *ZZ*CMSHOST        *{$date}*{$time}*^*00501*{$isa13}*0*P*:\n" .
               "GS*HC*NOSTOSEMR*CMSHOST*{$date}*{$time}*1*X*005010X222A1\n";
    }

    private function controlledDrugName(int $index): string
    {
        return [
            'Oxycodone HCl 5mg',
            'Lorazepam 0.5mg',
            'Alprazolam 0.25mg',
            'Hydrocodone/Acetaminophen 5/325mg',
            'Clonazepam 0.5mg',
        ][$index % 5];
    }

    private function fakeNdc(int $index): string
    {
        // Format: 5-4-2 (labeler-product-package)
        $labelers = ['00406', '00093', '62756', '00591', '49884'];
        $products = ['0315', '0126', '0458', '7800', '3290'];
        return $labelers[$index % 5] . '-' . $products[$index % 5] . '-01';
    }

    private function fakeSurveyResponses(): array
    {
        return [
            'q1_mobility'       => mt_rand(1, 5),
            'q2_pain'           => mt_rand(1, 5),
            'q3_social'         => mt_rand(1, 5),
            'q4_emotional'      => mt_rand(1, 5),
            'q5_falls'          => mt_rand(0, 1),
            'q6_hospitalizations'=> mt_rand(0, 2),
        ];
    }
}
