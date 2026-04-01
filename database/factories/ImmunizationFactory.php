<?php

namespace Database\Factories;

use App\Models\Immunization;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImmunizationFactory extends Factory
{
    protected $model = Immunization::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(Immunization::VACCINE_TYPES);

        return [
            'participant_id'            => Participant::factory(),
            'tenant_id'                 => Tenant::factory(),
            'vaccine_type'              => $type,
            'vaccine_name'              => $this->vaccineNameFor($type),
            'cvx_code'                  => Immunization::CVX_CODES[$type] ?? null,
            'administered_date'         => $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'administered_by_user_id'   => null,
            'administered_at_location'  => $this->faker->randomElement(['PACE Center East', 'PACE Center West', 'Pharmacy', null]),
            'lot_number'                => strtoupper($this->faker->bothify('??######')),
            'manufacturer'              => $this->faker->randomElement(['Pfizer', 'Moderna', 'Merck', 'Sanofi', 'GSK']),
            'dose_number'               => $this->faker->numberBetween(1, 3),
            'next_dose_due'             => $this->faker->optional(0.4)->dateTimeBetween('now', '+1 year')?->format('Y-m-d'),
            'refused'                   => false,
            'refusal_reason'            => null,
            // W4-4 QW-11: VIS documentation fields
            'vis_given'                 => $this->faker->boolean(70),
            'vis_publication_date'      => $this->faker->optional(0.7)->dateTimeBetween('-2 years', 'now')?->format('Y-m-d'),
        ];
    }

    /** Flu vaccine — most common in PACE population. */
    public function influenza(): static
    {
        return $this->state(fn () => [
            'vaccine_type' => 'influenza',
            'vaccine_name' => 'Influenza Vaccine (High-Dose)',
            'cvx_code'     => '141',
            'next_dose_due'=> now()->addYear()->format('Y-m-d'),
        ]);
    }

    /** Pneumococcal PPSV23 — standard for PACE participants. */
    public function pneumococcal(): static
    {
        return $this->state(fn () => [
            'vaccine_type' => 'pneumococcal_ppsv23',
            'vaccine_name' => 'Pneumovax 23 (PPSV23)',
            'cvx_code'     => '33',
        ]);
    }

    /** Refused immunization with reason. */
    public function refused(): static
    {
        return $this->state(fn () => [
            'refused'        => true,
            'refusal_reason' => $this->faker->randomElement([
                'Patient declined - allergic history',
                'Patient declined - personal preference',
                'Patient declined - religious beliefs',
                'Contraindicated per physician order',
            ]),
            'next_dose_due'  => null,
        ]);
    }

    /** Overdue for next dose. */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'next_dose_due' => now()->subMonths(3)->format('Y-m-d'),
            'refused'       => false,
        ]);
    }

    private function vaccineNameFor(string $type): string
    {
        return match ($type) {
            'influenza'           => 'Influenza Vaccine (High-Dose)',
            'pneumococcal_ppsv23' => 'Pneumovax 23 (PPSV23)',
            'pneumococcal_pcv15'  => 'Prevnar 15 (PCV15)',
            'pneumococcal_pcv20'  => 'Prevnar 20 (PCV20)',
            'covid_19'            => 'COVID-19 Vaccine (mRNA)',
            'tdap'                => 'Tdap (Boostrix)',
            'shingles'            => 'Shingrix (Zoster Recombinant)',
            'hepatitis_b'         => 'Hepatitis B Vaccine',
            default               => 'Vaccine',
        };
    }
}
