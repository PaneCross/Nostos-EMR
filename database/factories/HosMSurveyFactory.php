<?php

// ─── HosMSurveyFactory ────────────────────────────────────────────────────────
// Generates emr_hos_m_surveys rows for tests and Phase 9B demo seeder.
//
// State helpers:
//   ->incomplete()       — survey started but not completed
//   ->submittedToCms()   — survey completed and submitted to CMS
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\HosMSurvey;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HosMSurveyFactory extends Factory
{
    protected $model = HosMSurvey::class;

    public function definition(): array
    {
        return [
            'participant_id'           => Participant::factory(),
            'tenant_id'                => Tenant::factory(),
            'survey_year'              => 2025,
            'administered_by_user_id'  => User::factory(),
            'administered_at'          => $this->faker->dateTimeBetween('-6 months', 'now'),
            'completed'                => true,
            'responses'                => [
                'physical_health'  => $this->faker->numberBetween(1, 5),
                'mental_health'    => $this->faker->numberBetween(1, 5),
                'pain'             => $this->faker->numberBetween(1, 5),
                'falls_past_year'  => $this->faker->boolean(30) ? 1 : 0,
                'fall_injuries'    => $this->faker->boolean(15) ? 1 : 0,
            ],
            'submitted_to_cms' => false,
            'submitted_at'     => null,
        ];
    }

    /** Survey that was started but not yet completed by the participant. */
    public function incomplete(): static
    {
        return $this->state(fn () => [
            'completed'  => false,
            'responses'  => [],
        ]);
    }

    /** Survey completed and submitted to CMS HPMS. */
    public function submittedToCms(): static
    {
        return $this->state(fn () => [
            'completed'        => true,
            'submitted_to_cms' => true,
            'submitted_at'     => now()->subDays($this->faker->numberBetween(1, 30)),
        ]);
    }
}
