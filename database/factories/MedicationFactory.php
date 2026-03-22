<?php

namespace Database\Factories;

use App\Models\Medication;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MedicationFactory extends Factory
{
    protected $model = Medication::class;

    // Common PACE medications for realistic test data
    private const DRUGS = [
        ['name' => 'Lisinopril',      'dose' => 10,   'unit' => 'mg',  'route' => 'oral', 'freq' => 'daily', 'class' => 'ACE Inhibitor'],
        ['name' => 'Metoprolol',      'dose' => 25,   'unit' => 'mg',  'route' => 'oral', 'freq' => 'BID',   'class' => 'Beta Blocker'],
        ['name' => 'Furosemide',      'dose' => 40,   'unit' => 'mg',  'route' => 'oral', 'freq' => 'daily', 'class' => 'Loop Diuretic'],
        ['name' => 'Atorvastatin',    'dose' => 40,   'unit' => 'mg',  'route' => 'oral', 'freq' => 'daily', 'class' => 'Statin'],
        ['name' => 'Metformin',       'dose' => 500,  'unit' => 'mg',  'route' => 'oral', 'freq' => 'BID',   'class' => 'Biguanide'],
        ['name' => 'Amlodipine',      'dose' => 5,    'unit' => 'mg',  'route' => 'oral', 'freq' => 'daily', 'class' => 'Calcium Channel Blocker'],
        ['name' => 'Warfarin',        'dose' => 5,    'unit' => 'mg',  'route' => 'oral', 'freq' => 'daily', 'class' => 'Anticoagulant'],
        ['name' => 'Aspirin',         'dose' => 81,   'unit' => 'mg',  'route' => 'oral', 'freq' => 'daily', 'class' => 'Antiplatelet'],
        ['name' => 'Omeprazole',      'dose' => 20,   'unit' => 'mg',  'route' => 'oral', 'freq' => 'daily', 'class' => 'PPI'],
        ['name' => 'Levothyroxine',   'dose' => 50,   'unit' => 'mcg', 'route' => 'oral', 'freq' => 'daily', 'class' => 'Thyroid'],
        ['name' => 'Acetaminophen',   'dose' => 500,  'unit' => 'mg',  'route' => 'oral', 'freq' => 'QID',   'class' => 'Analgesic', 'prn' => true],
        ['name' => 'Gabapentin',      'dose' => 300,  'unit' => 'mg',  'route' => 'oral', 'freq' => 'TID',   'class' => 'Anticonvulsant'],
        ['name' => 'Donepezil',       'dose' => 10,   'unit' => 'mg',  'route' => 'oral', 'freq' => 'daily', 'class' => 'Cholinesterase Inhibitor'],
        ['name' => 'Sertraline',      'dose' => 50,   'unit' => 'mg',  'route' => 'oral', 'freq' => 'daily', 'class' => 'SSRI'],
        ['name' => 'Oxycodone',       'dose' => 5,    'unit' => 'mg',  'route' => 'oral', 'freq' => 'Q6H',   'class' => 'Opioid', 'controlled' => 'II', 'prn' => true],
        ['name' => 'Lorazepam',       'dose' => 0.5,  'unit' => 'mg',  'route' => 'oral', 'freq' => 'PRN',   'class' => 'Benzodiazepine', 'controlled' => 'IV', 'prn' => true],
    ];

    public function definition(): array
    {
        $drug = $this->faker->randomElement(self::DRUGS);
        $startDate = $this->faker->dateTimeBetween('-2 years', '-1 month')->format('Y-m-d');

        return [
            'participant_id'               => Participant::factory(),
            'tenant_id'                    => Tenant::factory(),
            'drug_name'                    => $drug['name'],
            'rxnorm_code'                  => null,
            'dose'                         => $drug['dose'],
            'dose_unit'                    => $drug['unit'],
            'route'                        => $drug['route'],
            'frequency'                    => $drug['freq'],
            'is_prn'                       => $drug['prn'] ?? false,
            'prn_indication'               => ($drug['prn'] ?? false) ? 'As needed for pain or anxiety' : null,
            'prescribing_provider_user_id' => null,
            'prescribed_date'              => $startDate,
            'start_date'                   => $startDate,
            'end_date'                     => null,
            'discontinued_reason'          => null,
            'status'                       => 'active',
            'is_controlled'                => isset($drug['controlled']),
            'controlled_schedule'          => $drug['controlled'] ?? null,
            'refills_remaining'            => $this->faker->numberBetween(0, 11),
            'last_filled_date'             => $this->faker->dateTimeBetween('-90 days', 'now')->format('Y-m-d'),
            'pharmacy_notes'               => null,
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    /** Simulate a PRN (as-needed) medication — not pre-scheduled in eMAR. */
    public function prn(): static
    {
        return $this->state([
            'status'          => 'prn',
            'is_prn'          => true,
            'prn_indication'  => 'As needed for pain > 4/10',
            'frequency'       => 'PRN',
        ]);
    }

    /** Simulate a discontinued medication (historical record). */
    public function discontinued(): static
    {
        return $this->state([
            'status'              => 'discontinued',
            'discontinued_reason' => 'Patient no longer requires this medication',
            'end_date'            => $this->faker->dateTimeBetween('-6 months', '-1 week')->format('Y-m-d'),
        ]);
    }

    /** Simulate a controlled substance requiring witness co-signature (DEA Schedule II). */
    public function controlled(): static
    {
        return $this->state([
            'drug_name'          => 'Oxycodone',
            'dose'               => 5,
            'dose_unit'          => 'mg',
            'is_controlled'      => true,
            'controlled_schedule'=> 'II',
        ]);
    }

    public function forParticipant(int $participantId): static
    {
        return $this->state(['participant_id' => $participantId]);
    }

    public function forTenant(int $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
