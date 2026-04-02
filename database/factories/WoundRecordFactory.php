<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WoundRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

class WoundRecordFactory extends Factory
{
    protected $model = WoundRecord::class;

    public function definition(): array
    {
        $isOpen = $this->faker->boolean(70);

        return [
            'participant_id'         => Participant::factory(),
            'tenant_id'              => Tenant::factory(),
            'site_id'                => Site::factory(),
            'wound_type'             => $this->faker->randomElement(WoundRecord::WOUND_TYPES),
            'location'               => $this->faker->randomElement([
                'Sacrum', 'Right heel', 'Left heel', 'Coccyx', 'Right trochanter',
                'Left ankle', 'Right elbow', 'Left shin', 'Abdomen',
            ]),
            'pressure_injury_stage'  => null,
            'length_cm'              => $this->faker->optional()->randomFloat(1, 0.5, 10.0),
            'width_cm'               => $this->faker->optional()->randomFloat(1, 0.5, 8.0),
            'depth_cm'               => $this->faker->optional()->randomFloat(1, 0.1, 3.0),
            'wound_bed'              => $this->faker->optional()->randomElement(['granulation', 'slough', 'eschar', 'mixed']),
            'exudate_amount'         => $this->faker->optional()->randomElement(['none', 'scant', 'light', 'moderate']),
            'exudate_type'           => $this->faker->optional()->randomElement(['serous', 'serosanguineous']),
            'periwound_skin'         => $this->faker->optional()->randomElement(['intact', 'macerated', 'erythema']),
            'odor'                   => $this->faker->boolean(15),
            'pain_score'             => $this->faker->optional()->numberBetween(0, 7),
            'treatment_description'  => $this->faker->optional()->sentence(),
            'dressing_type'          => $this->faker->optional()->randomElement(['Foam', 'Hydrocolloid', 'Alginate', 'Transparent film']),
            'dressing_change_frequency'=> $this->faker->optional()->randomElement(['Daily', 'Every 2 days', 'Every 3 days', 'Weekly']),
            'goal'                   => $this->faker->randomElement(['healing', 'maintenance', 'palliative']),
            'status'                 => $isOpen ? $this->faker->randomElement(['open', 'healing', 'stable']) : 'healed',
            'first_identified_date'  => $this->faker->dateTimeBetween('-6 months', '-1 week')->format('Y-m-d'),
            'healed_date'            => $isOpen ? null : $this->faker->dateTimeBetween('-1 week', 'now')->format('Y-m-d'),
            'documented_by_user_id'  => User::factory(),
            'photo_taken'            => $this->faker->boolean(30),
            'notes'                  => $this->faker->optional()->sentence(),
        ];
    }

    /** Pressure injury with a specific stage. */
    public function pressureInjury(string $stage = 'stage_2'): static
    {
        return $this->state([
            'wound_type'            => 'pressure_injury',
            'pressure_injury_stage' => $stage,
        ]);
    }

    /** Stage 3+ pressure injury — triggers CMS quality metric alert. */
    public function criticalStage(): static
    {
        return $this->state([
            'wound_type'            => 'pressure_injury',
            'pressure_injury_stage' => $this->faker->randomElement(WoundRecord::CRITICAL_STAGES),
            'status'                => 'open',
        ]);
    }

    /** Open (non-healed) wound. */
    public function open(): static
    {
        return $this->state([
            'status'      => 'open',
            'healed_date' => null,
        ]);
    }

    /** Healed wound with a healed_date. */
    public function healed(): static
    {
        return $this->state([
            'status'      => 'healed',
            'healed_date' => now()->subDays(3)->toDateString(),
        ]);
    }
}
