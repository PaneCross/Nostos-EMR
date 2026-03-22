<?php

// ─── Phase5CDataSeeder ────────────────────────────────────────────────────────
// Seeds medication data for demo participants: active medication lists,
// eMAR records for the past 7 days, and a medication reconciliation record.
//
// Run after MedicationsReferenceSeeder (depends on emr_medications_reference).
// Run after ClinicalDataSeeder (depends on existing participants).
//
// Each demo participant gets:
//   - 4-8 active medications (mix of common PACE drugs, 1-2 controlled)
//   - 1-2 PRN medications
//   - 1-3 discontinued medications (historical record)
//   - eMAR records for the past 7 days (given/missed/refused, realistic mix)
//   - 1 medication reconciliation record (idt_review type)
//   - 0-1 unacknowledged drug interaction alerts (for UI demo)
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Medication;
use App\Models\MedReconciliation;
use App\Models\EmarRecord;
use App\Models\DrugInteractionAlert;
use App\Models\Participant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Phase5CDataSeeder extends Seeder
{
    // Common PACE medication sets (drug_name must exist in emr_medications_reference)
    private const MED_SETS = [
        [
            ['drug_name' => 'Lisinopril',  'dose' => 10,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Metoprolol',  'dose' => 25,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'BID',   'status' => 'active'],
            ['drug_name' => 'Furosemide',  'dose' => 40,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Atorvastatin','dose' => 40,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Aspirin',     'dose' => 81,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Omeprazole',  'dose' => 20,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Acetaminophen','dose' => 500, 'dose_unit' => 'mg','route' => 'oral', 'frequency' => 'Q6H',   'status' => 'prn',    'is_prn' => true],
        ],
        [
            ['drug_name' => 'Metformin',   'dose' => 500, 'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'BID',   'status' => 'active'],
            ['drug_name' => 'Glipizide',   'dose' => 5,   'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Amlodipine',  'dose' => 5,   'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Atorvastatin','dose' => 40,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Sertraline',  'dose' => 50,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Levothyroxine','dose'=> 50,  'dose_unit' => 'mcg','route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Lorazepam',   'dose' => 0.5, 'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'PRN',   'status' => 'prn',    'is_prn' => true, 'is_controlled' => true, 'controlled_schedule' => 'IV'],
        ],
        [
            ['drug_name' => 'Warfarin',    'dose' => 5,   'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Digoxin',     'dose' => 0.125,'dose_unit' => 'mg','route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Furosemide',  'dose' => 40,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'BID',   'status' => 'active'],
            ['drug_name' => 'Metoprolol',  'dose' => 25,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'BID',   'status' => 'active'],
            ['drug_name' => 'Donepezil',   'dose' => 10,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
            ['drug_name' => 'Aspirin',     'dose' => 81,  'dose_unit' => 'mg', 'route' => 'oral', 'frequency' => 'daily', 'status' => 'active'],
        ],
    ];

    public function run(): void
    {
        $participants = Participant::whereIn('enrollment_status', ['enrolled'])->get();

        if ($participants->isEmpty()) {
            $this->command?->warn('Phase5CDataSeeder: No enrolled participants found. Skipping.');
            return;
        }

        // Find a primary_care prescriber user (for prescribing_provider_user_id)
        $prescriber = User::where('department', 'primary_care')->where('is_active', true)->first();

        foreach ($participants as $i => $participant) {
            $medSet = self::MED_SETS[$i % count(self::MED_SETS)];

            $savedMeds = [];
            $startDate = now()->subMonths(6)->toDateString();

            // Seed active + PRN medications
            foreach ($medSet as $medData) {
                $med = Medication::create([
                    'participant_id'               => $participant->id,
                    'tenant_id'                    => $participant->tenant_id,
                    'drug_name'                    => $medData['drug_name'],
                    'dose'                         => $medData['dose'],
                    'dose_unit'                    => $medData['dose_unit'],
                    'route'                        => $medData['route'],
                    'frequency'                    => $medData['frequency'],
                    'is_prn'                       => $medData['is_prn'] ?? false,
                    'prn_indication'               => ($medData['is_prn'] ?? false) ? 'As needed for pain or anxiety' : null,
                    'prescribing_provider_user_id' => $prescriber?->id,
                    'prescribed_date'              => $startDate,
                    'start_date'                   => $startDate,
                    'end_date'                     => null,
                    'status'                       => $medData['status'],
                    'is_controlled'                => $medData['is_controlled'] ?? false,
                    'controlled_schedule'          => $medData['controlled_schedule'] ?? null,
                    'refills_remaining'            => rand(3, 11),
                    'last_filled_date'             => now()->subDays(rand(7, 30))->toDateString(),
                ]);

                $savedMeds[] = $med;
            }

            // Add 1 discontinued medication for history
            if (!empty($savedMeds)) {
                Medication::create([
                    'participant_id'               => $participant->id,
                    'tenant_id'                    => $participant->tenant_id,
                    'drug_name'                    => 'Naproxen',
                    'dose'                         => 250,
                    'dose_unit'                    => 'mg',
                    'route'                        => 'oral',
                    'frequency'                    => 'BID',
                    'is_prn'                       => false,
                    'prescribing_provider_user_id' => $prescriber?->id,
                    'start_date'                   => now()->subYear()->toDateString(),
                    'end_date'                     => now()->subMonths(3)->toDateString(),
                    'discontinued_reason'          => 'Switched to acetaminophen due to renal concerns',
                    'status'                       => 'discontinued',
                    'is_controlled'                => false,
                    'refills_remaining'            => 0,
                    'last_filled_date'             => now()->subMonths(4)->toDateString(),
                ]);
            }

            // Seed 7 days of eMAR records for schedulable (non-PRN) active meds
            $schedulableMeds = array_filter($savedMeds, fn ($m) => $m->status === 'active' && !$m->is_prn);
            $this->seedEmarHistory($participant, $schedulableMeds, $prescriber);

            // Seed a medication reconciliation record
            if (!empty($savedMeds)) {
                $this->seedReconciliation($participant, $savedMeds, $prescriber);
            }

            // Seed a demo drug interaction alert (Warfarin + Aspirin if both present)
            $warfarin = array_values(array_filter($savedMeds, fn ($m) => $m->drug_name === 'Warfarin'));
            $aspirin  = array_values(array_filter($savedMeds, fn ($m) => $m->drug_name === 'Aspirin'));
            if (!empty($warfarin) && !empty($aspirin)) {
                DrugInteractionAlert::create([
                    'participant_id'  => $participant->id,
                    'tenant_id'       => $participant->tenant_id,
                    'medication_id_1' => $warfarin[0]->id,
                    'medication_id_2' => $aspirin[0]->id,
                    'drug_name_1'     => 'Warfarin',
                    'drug_name_2'     => 'Aspirin',
                    'severity'        => 'major',
                    'description'     => 'Concurrent use of warfarin and aspirin significantly increases bleeding risk.',
                    'is_acknowledged' => false,
                ]);
            }
        }

        $this->command?->info('Phase5CDataSeeder: medications and eMAR data seeded for '
            . $participants->count() . ' participants.');
    }

    /** Seed 7 days of eMAR records for a participant's schedulable medications. */
    private function seedEmarHistory(Participant $participant, array $meds, ?User $nurse): void
    {
        if (empty($meds)) return;

        $freqTimes = [
            'daily'  => ['08:00'],
            'BID'    => ['08:00', '20:00'],
            'TID'    => ['08:00', '14:00', '20:00'],
            'QID'    => ['08:00', '12:00', '16:00', '20:00'],
            'Q4H'    => ['06:00', '10:00', '14:00', '18:00', '22:00'],
            'Q6H'    => ['06:00', '12:00', '18:00'],
            'Q8H'    => ['06:00', '14:00', '22:00'],
            'Q12H'   => ['08:00', '20:00'],
            'weekly' => ['08:00'],
            'monthly'=> ['08:00'],
            'once'   => ['08:00'],
        ];

        // Seed past 7 days (yesterday back) plus today
        for ($daysAgo = 7; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo)->toDateString();

            foreach ($meds as $med) {
                $times = $freqTimes[$med->frequency] ?? ['08:00'];

                foreach ($times as $time) {
                    $scheduledTime = Carbon::parse("{$date} {$time}");
                    $isPast        = $scheduledTime->isPast();

                    // For past doses: realistic admin status distribution
                    if ($isPast && $daysAgo > 0) {
                        $rand   = rand(1, 100);
                        $status = $rand <= 85 ? 'given'    // 85% given
                                : ($rand <= 92 ? 'refused'  // 7% refused
                                : ($rand <= 97 ? 'missed'   // 5% missed
                                : 'held'));                  // 3% held

                        EmarRecord::create([
                            'participant_id'          => $participant->id,
                            'medication_id'           => $med->id,
                            'tenant_id'               => $participant->tenant_id,
                            'scheduled_time'          => $scheduledTime,
                            'administered_at'         => $status === 'given' ? $scheduledTime->copy()->addMinutes(rand(0, 20)) : null,
                            'administered_by_user_id' => $status === 'given' ? $nurse?->id : null,
                            'status'                  => $status,
                            'dose_given'              => $status === 'given' ? "{$med->dose} {$med->dose_unit}" : null,
                            'route_given'             => $status === 'given' ? $med->route : null,
                            'reason_not_given'        => in_array($status, ['refused', 'held', 'missed'])
                                ? match($status) {
                                    'refused' => 'Patient refused medication',
                                    'held'    => 'Held per MD order',
                                    'missed'  => 'Patient not present at dose time',
                                }
                                : null,
                        ]);
                    } else {
                        // Today's future doses: status = scheduled
                        EmarRecord::create([
                            'participant_id' => $participant->id,
                            'medication_id'  => $med->id,
                            'tenant_id'      => $participant->tenant_id,
                            'scheduled_time' => $scheduledTime,
                            'status'         => 'scheduled',
                        ]);
                    }
                }
            }
        }
    }

    /** Seed one medication reconciliation record for demo. */
    private function seedReconciliation(Participant $participant, array $meds, ?User $prescriber): void
    {
        if (!$prescriber) return;

        $reconciledMeds = array_map(fn ($m) => [
            'medication_id'    => $m->id,
            'drug_name'        => $m->drug_name,
            'action'           => 'continue',
            'discrepancy_note' => null,
        ], array_slice($meds, 0, min(count($meds), 5)));

        MedReconciliation::create([
            'participant_id'          => $participant->id,
            'tenant_id'               => $participant->tenant_id,
            'reconciled_by_user_id'   => $prescriber->id,
            'reconciling_department'  => 'primary_care',
            'reconciliation_type'     => 'idt_review',
            'reconciled_medications'  => $reconciledMeds,
            'reconciled_at'           => now()->subWeek(),
            'clinical_notes'          => 'Medications reviewed at IDT. All medications continued per plan.',
            'has_discrepancies'       => false,
        ]);
    }
}
