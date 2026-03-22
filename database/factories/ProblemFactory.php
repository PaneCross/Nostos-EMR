<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\Problem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProblemFactory extends Factory
{
    protected $model = Problem::class;

    // ── Realistic ICD-10 codes common in the PACE elderly population ──────────
    private const PACE_PROBLEMS = [
        ['I10',    'Essential (primary) hypertension',            'Cardiovascular'],
        ['I50.9',  'Heart failure, unspecified',                  'Cardiovascular'],
        ['E11.9',  'Type 2 diabetes mellitus without complications', 'Endocrine'],
        ['M19.90', 'Unspecified osteoarthritis, unspecified site','Musculoskeletal'],
        ['F03.90', 'Unspecified dementia without behavioral disturbance', 'Neurological'],
        ['J44.9',  'Chronic obstructive pulmonary disease, unspecified', 'Respiratory'],
        ['N18.3',  'Chronic kidney disease, stage 3',             'Renal'],
        ['F32.9',  'Major depressive disorder, single episode, unspecified', 'Psychiatric'],
        ['Z87.39', 'Personal history of other musculoskeletal disorders', 'History'],
        ['M54.5',  'Low back pain',                               'Musculoskeletal'],
    ];

    public function definition(): array
    {
        $problem = $this->faker->randomElement(self::PACE_PROBLEMS);

        return [
            'participant_id'      => Participant::factory(),
            'tenant_id'           => Tenant::factory(),
            'added_by_user_id'    => User::factory(),
            'icd10_code'          => $problem[0],
            'icd10_description'   => $problem[1],
            'status'              => $this->faker->randomElement(Problem::STATUSES),
            'onset_date'          => $this->faker->boolean(60)
                ? $this->faker->dateTimeBetween('-10 years', '-6 months')->format('Y-m-d')
                : null,
            'resolved_date'       => null,
            'is_primary_diagnosis' => $this->faker->boolean(20),
            'notes'               => $this->faker->boolean(30) ? $this->faker->sentence() : null,
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    public function active(): static
    {
        return $this->state([
            'status'        => 'active',
            'resolved_date' => null,
        ]);
    }

    public function chronic(): static
    {
        return $this->state([
            'status'        => 'chronic',
            'resolved_date' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status'        => 'resolved',
            'resolved_date' => $this->faker->dateTimeBetween('-2 years', '-1 month')->format('Y-m-d'),
        ]);
    }

    public function primaryDiagnosis(): static
    {
        return $this->state([
            'is_primary_diagnosis' => true,
            'status'               => 'active',
        ]);
    }

    public function withCode(string $code, string $description): static
    {
        return $this->state([
            'icd10_code'         => $code,
            'icd10_description'  => $description,
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
