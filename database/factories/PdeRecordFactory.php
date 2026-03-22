<?php

// ─── PdeRecordFactory ─────────────────────────────────────────────────────────
// Generates emr_pde_records rows for tests and Phase 9B demo seeder.
//
// State helpers:
//   ->submitted()    — PDE already submitted to CMS
//   ->nearThreshold() — TrOOP near catastrophic threshold ($7,400)
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\Participant;
use App\Models\PdeRecord;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PdeRecordFactory extends Factory
{
    protected $model = PdeRecord::class;

    /** Common PACE medications with realistic NDC codes. */
    private const DRUGS = [
        ['name' => 'Metformin 500mg',   'ndc' => '00093-0831-01'],
        ['name' => 'Lisinopril 10mg',   'ndc' => '68180-0513-01'],
        ['name' => 'Atorvastatin 20mg', 'ndc' => '00071-0157-23'],
        ['name' => 'Amlodipine 5mg',    'ndc' => '65862-0175-99'],
        ['name' => 'Omeprazole 20mg',   'ndc' => '60505-0256-05'],
        ['name' => 'Furosemide 40mg',   'ndc' => '00781-1526-10'],
        ['name' => 'Warfarin 5mg',      'ndc' => '00056-0172-70'],
        ['name' => 'Albuterol Inhaler', 'ndc' => '59310-0579-22'],
    ];

    public function definition(): array
    {
        $drug            = $this->faker->randomElement(self::DRUGS);
        $ingredientCost  = $this->faker->randomFloat(2, 10, 200);
        $dispensingFee   = $this->faker->randomFloat(2, 2, 12);
        $patientPay      = $this->faker->randomFloat(2, 0, 10);
        $troopAmount     = $ingredientCost + $dispensingFee - $patientPay;

        return [
            'participant_id'    => Participant::factory(),
            'tenant_id'         => Tenant::factory(),
            'medication_id'     => null,
            'drug_name'         => $drug['name'],
            'ndc_code'          => $drug['ndc'],
            'dispense_date'     => $this->faker->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'days_supply'       => 30,
            'quantity_dispensed'=> $this->faker->randomFloat(3, 30, 90),
            'ingredient_cost'   => $ingredientCost,
            'dispensing_fee'    => $dispensingFee,
            'patient_pay'       => $patientPay,
            'troop_amount'      => max(0, $troopAmount),
            'pharmacy_npi'      => $this->faker->numerify('##########'),
            'prescriber_npi'    => $this->faker->numerify('##########'),
            'submission_status' => 'pending',
            'pde_id'            => null,
        ];
    }

    /** PDE already submitted and accepted by CMS. */
    public function submitted(): static
    {
        return $this->state(fn () => [
            'submission_status' => 'accepted',
            'pde_id'            => 'PDE' . $this->faker->numerify('##########'),
        ]);
    }

    /** Participant near the TrOOP catastrophic threshold. */
    public function nearThreshold(): static
    {
        return $this->state(fn () => [
            'troop_amount' => $this->faker->randomFloat(2, 5920, 7400),
        ]);
    }
}
