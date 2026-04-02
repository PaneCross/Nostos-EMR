<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WoundAssessment;
use App\Models\WoundRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class WoundAssessmentFactory extends Factory
{
    protected $model = WoundAssessment::class;

    public function definition(): array
    {
        return [
            'wound_record_id'      => WoundRecord::factory(),
            'assessed_by_user_id'  => User::factory(),
            'assessed_at'          => $this->faker->dateTimeBetween('-4 weeks', 'now'),
            'length_cm'            => $this->faker->optional()->randomFloat(1, 0.5, 10.0),
            'width_cm'             => $this->faker->optional()->randomFloat(1, 0.5, 8.0),
            'depth_cm'             => $this->faker->optional()->randomFloat(1, 0.1, 3.0),
            'wound_bed'            => $this->faker->optional()->randomElement(['granulation', 'slough', 'mixed']),
            'exudate_amount'       => $this->faker->optional()->randomElement(['none', 'scant', 'light']),
            'exudate_type'         => null,
            'periwound_skin'       => $this->faker->optional()->randomElement(['intact', 'erythema']),
            'odor'                 => false,
            'pain_score'           => $this->faker->optional()->numberBetween(0, 5),
            'treatment_description'=> $this->faker->optional()->sentence(),
            'status_change'        => $this->faker->optional()->randomElement(['improved', 'unchanged']),
            'notes'                => $this->faker->optional()->sentence(),
        ];
    }

    /** Assessment showing improvement. */
    public function improved(): static
    {
        return $this->state(['status_change' => 'improved']);
    }

    /** Assessment showing deterioration — triggers warning alert. */
    public function deteriorated(): static
    {
        return $this->state(['status_change' => 'deteriorated']);
    }

    /** Assessment marking wound as healed — closes wound record. */
    public function healed(): static
    {
        return $this->state(['status_change' => 'healed']);
    }
}
