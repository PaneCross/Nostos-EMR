<?php

// ─── Phase9BDataSeeder ────────────────────────────────────────────────────────
// Demo data for Phase 9B billing engine features.
//
// Seeds:
//   - HCC mappings (delegates to HccMappingSeeder)
//   - Sample encounter records with full 837P billing fields populated
//   - Sample EDI batch files (draft + submitted)
//   - Sample capitation records with HCC risk scores
//   - Sample PDE records (Part D prescriptions) with TrOOP accumulation
//   - Sample HPMS submissions (enrollment + quality data)
//   - Sample HOS-M survey records
//
// Prerequisite: DemoEnvironmentSeeder must have run (needs tenant, participants, users).
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\CapitationRecord;
use App\Models\EncounterLog;
use App\Models\EdiBatch;
use App\Models\HosMSurvey;
use App\Models\HpmsSubmission;
use App\Models\Participant;
use App\Models\PdeRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Phase9BDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('  Phase 9B Billing Engine Data Seeder');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // ── Seed HCC Mappings ─────────────────────────────────────────────────
        $this->call(HccMappingSeeder::class);

        // ── Resolve demo tenant ───────────────────────────────────────────────
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->first();
        if (! $tenant) {
            $this->command->warn('Demo tenant not found — run DemoEnvironmentSeeder first.');
            return;
        }

        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->take(10)
            ->get();

        if ($participants->isEmpty()) {
            $this->command->warn('No enrolled participants found — run DemoEnvironmentSeeder first.');
            return;
        }

        // Finance admin user for created_by fields
        $financeUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'finance')
            ->first();

        if (! $financeUser) {
            $this->command->warn('No finance user found — encounter records will have no created_by.');
        }

        $financeUserId = $financeUser?->id;
        $tenantId      = $tenant->id;
        $currentMonth  = now()->format('Y-m');
        $lastMonth     = now()->subMonth()->format('Y-m');

        // ── Update Encounters with Billing Fields ─────────────────────────────
        $this->command->line('  Updating encounter log with billing fields…');
        $encounters = EncounterLog::where('tenant_id', $tenantId)
            ->take(20)
            ->get();

        $pos = ['11', '12', '65', '02'];
        foreach ($encounters as $i => $enc) {
            // Only update if not already populated
            if (! empty($enc->billing_provider_npi)) {
                continue;
            }
            $enc->billing_provider_npi   = '1234567890';
            $enc->rendering_provider_npi = '9876543210';
            $enc->service_facility_npi   = '1122334455';
            $enc->place_of_service_code  = $pos[$i % count($pos)];
            $enc->units                  = 1.00;
            $enc->charge_amount          = round(150 + ($i * 12.50), 2);
            $enc->claim_type             = 'internal_capitated';
            $enc->submission_status      = $i < 8 ? 'accepted' : ($i < 14 ? 'submitted' : 'pending');
            $enc->diagnosis_codes        = $this->sampleDiagnosisCodes($i);
            if ($enc->submission_status !== 'pending') {
                $enc->submitted_at = now()->subDays(rand(3, 30));
            }
            // Save via DB query to bypass append-only guard (no updated_at column)
            DB::table('emr_encounter_log')
                ->where('id', $enc->id)
                ->update([
                    'billing_provider_npi'   => $enc->billing_provider_npi,
                    'rendering_provider_npi'  => $enc->rendering_provider_npi,
                    'service_facility_npi'    => $enc->service_facility_npi,
                    'place_of_service_code'   => $enc->place_of_service_code,
                    'units'                   => $enc->units,
                    'charge_amount'           => $enc->charge_amount,
                    'claim_type'              => $enc->claim_type,
                    'submission_status'       => $enc->submission_status,
                    'diagnosis_codes'         => json_encode($enc->diagnosis_codes),
                    'submitted_at'            => $enc->submitted_at,
                ]);
        }

        // ── Capitation Records with HCC Fields ────────────────────────────────
        $this->command->line('  Seeding capitation records with HCC risk scores…');
        $rafScores = [1.0823, 0.8942, 1.2156, 0.7834, 1.4201, 0.9318, 1.1067, 0.8451, 1.3290, 0.9876];

        foreach ($participants->take(8) as $idx => $participant) {
            $baseRate = 1450.00;
            $raf      = $rafScores[$idx % count($rafScores)];
            $total    = round($baseRate * $raf * 1.15, 2); // frailty × raf × base

            CapitationRecord::updateOrCreate(
                [
                    'participant_id' => $participant->id,
                    'month_year'     => $currentMonth,
                ],
                [
                    'tenant_id'          => $tenantId,
                    'medicare_a_rate'    => round($baseRate * 0.45, 2),
                    'medicare_b_rate'    => round($baseRate * 0.30, 2),
                    'medicare_d_rate'    => round($baseRate * 0.10, 2),
                    'medicaid_rate'      => round($baseRate * 0.15, 2),
                    'total_capitation'   => $total,
                    'eligibility_category' => 'nursing_facility',
                    'hcc_risk_score'     => $raf,
                    'frailty_score'      => 0.1500,
                    'county_fips_code'   => '06037',   // Los Angeles County, CA
                    'adjustment_type'    => 'initial',
                    'rate_effective_date' => now()->startOfMonth()->toDateString(),
                    'recorded_at'        => now(),
                ]
            );
        }
        $this->command->line("  Capitation records: " . $participants->take(8)->count() . " records seeded.");

        // ── EDI Batches ───────────────────────────────────────────────────────
        $this->command->line('  Seeding EDI batch records…');

        $batchIds = EncounterLog::where('tenant_id', $tenantId)
            ->where('submission_status', 'submitted')
            ->take(5)
            ->pluck('id')
            ->toArray();

        if (! empty($batchIds)) {
            $batch = EdiBatch::create([
                'tenant_id'           => $tenantId,
                'batch_type'          => 'edr',
                'file_name'           => 'EDR_' . now()->format('Ymd') . '_001.txt',
                'file_content'        => $this->sampleX12Preamble(),
                'record_count'        => count($batchIds),
                'total_charge_amount' => 3250.00,
                'status'              => 'submitted',
                'submitted_at'        => now()->subDays(5),
                'submission_method'   => 'clearinghouse',
                'created_by_user_id'  => $financeUserId,
            ]);
            // Link encounters to this batch
            DB::table('emr_encounter_log')
                ->whereIn('id', $batchIds)
                ->update(['edi_batch_id' => $batch->id]);
        }

        // Draft batch
        EdiBatch::create([
            'tenant_id'           => $tenantId,
            'batch_type'          => 'edr',
            'file_name'           => 'EDR_' . now()->format('Ymd') . '_002.txt',
            'file_content'        => $this->sampleX12Preamble(),
            'record_count'        => 3,
            'total_charge_amount' => 1475.00,
            'status'              => 'draft',
            'created_by_user_id'  => $financeUserId,
        ]);
        $this->command->line("  EDI batches: 2 records seeded (1 submitted, 1 draft).");

        // ── PDE Records ───────────────────────────────────────────────────────
        $this->command->line('  Seeding PDE records…');
        $drugs = [
            ['name' => 'Metformin 500mg',    'ndc' => '00093-7214-01', 'cost' => 12.50,  'fee' => 2.00],
            ['name' => 'Lisinopril 10mg',    'ndc' => '00093-5124-98', 'cost' => 8.75,   'fee' => 2.00],
            ['name' => 'Atorvastatin 40mg',  'ndc' => '00071-0156-23', 'cost' => 22.40,  'fee' => 2.00],
            ['name' => 'Amlodipine 5mg',     'ndc' => '00069-1540-30', 'cost' => 10.20,  'fee' => 2.00],
            ['name' => 'Furosemide 40mg',    'ndc' => '00904-5669-61', 'cost' => 6.80,   'fee' => 2.00],
            ['name' => 'Omeprazole 20mg',    'ndc' => '00378-4131-93', 'cost' => 18.30,  'fee' => 2.00],
            ['name' => 'Warfarin 5mg',       'ndc' => '00056-0170-90', 'cost' => 35.60,  'fee' => 2.00],
        ];

        $troopAccum = [0, 0, 4200, 7600, 1800, 0, 550]; // simulate varying TrOOP levels

        foreach ($participants->take(7) as $idx => $participant) {
            $drug   = $drugs[$idx % count($drugs)];
            $troop  = min($troopAccum[$idx], $drug['cost']);

            PdeRecord::create([
                'tenant_id'          => $tenantId,
                'participant_id'     => $participant->id,
                'drug_name'          => $drug['name'],
                'ndc_code'           => $drug['ndc'],
                'dispense_date'      => now()->subDays(rand(1, 30))->toDateString(),
                'days_supply'        => 30,
                'quantity_dispensed' => 30.0,
                'ingredient_cost'    => $drug['cost'],
                'dispensing_fee'     => $drug['fee'],
                'patient_pay'        => round(($drug['cost'] + $drug['fee']) * 0.10, 2),
                'troop_amount'       => $troop,
                'pharmacy_npi'       => '1234509876',
                'prescriber_npi'     => '9876501234',
                'submission_status'  => $idx < 3 ? 'submitted' : 'pending',
                'pde_id'             => 'PDE' . str_pad($idx + 1, 8, '0', STR_PAD_LEFT),
            ]);
        }
        $this->command->line("  PDE records: 7 records seeded (3 submitted, 4 pending).");

        // ── HPMS Submissions ──────────────────────────────────────────────────
        $this->command->line('  Seeding HPMS submission records…');

        $enrolledCount = $participants->count();
        $enrollFile    = "CONTRACT_ID|HIOS_PLAN_ID|SUBSCRIBER_ID|FIRST_NAME|LAST_NAME|SEX|DOB|EFFECTIVE_DATE\n";
        foreach ($participants as $p) {
            $enrollFile .= "H9999|001|{$p->medicare_id}|{$p->first_name}|{$p->last_name}|1|{$p->dob}|{$currentMonth}-01\n";
        }

        HpmsSubmission::create([
            'tenant_id'       => $tenantId,
            'submission_type' => 'enrollment',
            'file_content'    => $enrollFile,
            'record_count'    => $enrolledCount,
            'period_start'    => $currentMonth . '-01',
            'period_end'      => now()->endOfMonth()->toDateString(),
            'status'          => 'submitted',
            'submitted_at'    => now()->subDays(3),
            'created_by_user_id' => $financeUserId,
        ]);

        HpmsSubmission::create([
            'tenant_id'       => $tenantId,
            'submission_type' => 'quality_data',
            'file_content'    => "CONTRACT_ID|MEASURE_ID|VALUE|PERIOD_YEAR|QUARTER\nH9999|FALL_RATE|12.5|2025|1\nH9999|HOSP_RATE|8.2|2025|1\n",
            'record_count'    => 2,
            'period_start'    => '2025-01-01',
            'period_end'      => '2025-03-31',
            'status'          => 'draft',
            'created_by_user_id' => $financeUserId,
        ]);
        $this->command->line("  HPMS submissions: 2 records seeded (1 submitted enrollment, 1 draft quality).");

        // ── HOS-M Surveys ─────────────────────────────────────────────────────
        $this->command->line('  Seeding HOS-M survey records…');
        $primaryCareUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')
            ->first();

        foreach ($participants->take(6) as $idx => $participant) {
            HosMSurvey::firstOrCreate(
                [
                    'participant_id' => $participant->id,
                    'survey_year'    => 2025,
                ],
                [
                    'tenant_id'           => $tenantId,
                    'administered_by_user_id' => $primaryCareUser?->id ?? $financeUserId,
                    'administered_at'     => now()->subDays(rand(10, 90)),
                    'completed'           => $idx < 4,
                    'submitted_to_cms'    => $idx < 2,
                    'cms_submission_date' => $idx < 2 ? now()->subDays(5)->toDateString() : null,
                    'responses'           => [
                        'physical_health'  => rand(2, 4),
                        'mental_health'    => rand(2, 4),
                        'pain'             => rand(1, 5),
                        'falls_past_year'  => rand(0, 3),
                        'fall_injuries'    => (bool) rand(0, 1),
                    ],
                ]
            );
        }
        $this->command->line("  HOS-M surveys: 6 records seeded (4 complete, 2 submitted to CMS).");

        $this->command->info('');
        $this->command->info('  Phase 9B data seeding complete.');
        $this->command->info('');
    }

    /**
     * Returns sample ICD-10 diagnosis codes based on index for variety.
     */
    private function sampleDiagnosisCodes(int $index): array
    {
        $sets = [
            ['E119', 'I4891'],
            ['I50.9', 'N183'],
            ['E1140', 'I7000'],
            ['J449', 'I4891'],
            ['F0150', 'E119'],
            ['N184', 'I50.9'],
            ['G20', 'F329'],
            ['E1165', 'N185'],
        ];
        return $sets[$index % count($sets)];
    }

    /**
     * Returns a minimal X12 ISA/GS preamble for demo EDI batch file_content.
     */
    private function sampleX12Preamble(): string
    {
        $ts     = now()->format('ymd*Hi');
        $date   = now()->format('Ymd');
        $time   = now()->format('Hi');
        $ctrlNr = str_pad(rand(1, 999999999), 9, '0', STR_PAD_LEFT);

        return "ISA*00*          *00*          *ZZ*NOSTOSEMR      *ZZ*CMSEDSTEST     *{$date}*{$time}*^*00501*{$ctrlNr}*0*P*:\n" .
               "GS*HC*NOSTOSEMR*CMSEDS*{$date}*{$time}*1*X*005010X222A2\n" .
               "ST*837*0001*005010X222A2\n" .
               "BHT*0019*00*{$ctrlNr}*{$date}*{$time}*CH\n" .
               "NM1*41*2*SUNRISE PACE DEMO ORGANIZATION*****XX*1234567890\n" .
               "PER*IC*FINANCE DEPT*TE*5555551234\n" .
               "[... encounter transactions truncated for demo ...]\n" .
               "SE*99*0001\n" .
               "GE*1*1\n" .
               "IEA*1*{$ctrlNr}\n";
    }
}
