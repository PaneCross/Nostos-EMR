<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\Procedure;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProcedureFactory extends Factory
{
    protected $model = Procedure::class;

    public function definition(): array
    {
        $procedures = [
            ['name' => 'Hip Replacement (Total)', 'cpt' => '27447', 'snomed' => '52734007'],
            ['name' => 'Cataract Extraction', 'cpt' => '66984', 'snomed' => '75732000'],
            ['name' => 'Colonoscopy', 'cpt' => '45378', 'snomed' => '73761001'],
            ['name' => 'Echocardiogram', 'cpt' => '93306', 'snomed' => '40701008'],
            ['name' => 'Cardiac Catheterization', 'cpt' => '93454', 'snomed' => '41976001'],
            ['name' => 'Physical Therapy Evaluation', 'cpt' => '97161', 'snomed' => '229070002'],
            ['name' => 'Wound Debridement', 'cpt' => '97597', 'snomed' => '36977001'],
        ];
        $proc = $this->faker->randomElement($procedures);

        return [
            'participant_id'       => Participant::factory(),
            'tenant_id'            => Tenant::factory(),
            'performed_by_user_id' => null,
            'procedure_name'       => $proc['name'],
            'cpt_code'             => $proc['cpt'],
            'snomed_code'          => $proc['snomed'],
            'performed_date'       => $this->faker->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'facility'             => $this->faker->randomElement(['PACE Center', 'Regional Hospital', 'Outpatient Clinic', null]),
            'body_site'            => null,
            'outcome'              => $this->faker->randomElement(['Completed successfully', 'No complications', null]),
            'notes'                => null,
            'source'               => $this->faker->randomElement(Procedure::SOURCES),
        ];
    }

    public function internal(): static
    {
        return $this->state(fn () => ['source' => 'internal']);
    }

    public function externalReport(): static
    {
        return $this->state(fn () => ['source' => 'external_report']);
    }

    public function patientReported(): static
    {
        return $this->state(fn () => ['source' => 'patient_reported']);
    }
}
