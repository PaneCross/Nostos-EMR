<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssessmentFactory extends Factory
{
    protected $model = Assessment::class;

    public function definition(): array
    {
        $type        = $this->faker->randomElement(Assessment::TYPES);
        $completedAt = $this->faker->dateTimeBetween('-6 months', 'now');
        $score       = isset(Assessment::SCORE_MAX[$type])
            ? $this->faker->numberBetween(0, Assessment::SCORE_MAX[$type])
            : null;

        return [
            'participant_id'      => Participant::factory(),
            'tenant_id'           => Tenant::factory(),
            'authored_by_user_id' => User::factory(),
            'department'          => $this->faker->randomElement([
                'primary_care', 'therapies', 'social_work',
                'behavioral_health', 'dietary',
            ]),
            'assessment_type'     => $type,
            'responses'           => $this->fakeResponses($type),
            'score'               => $score,
            'completed_at'        => $completedAt,
            'next_due_date'       => $this->faker->boolean(75)
                ? (clone $completedAt)->modify('+1 year')
                : null,
            'threshold_flags'     => null,
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    public function phq9(): static
    {
        return $this->state(fn () => [
            'assessment_type' => 'phq9_depression',
            'score'           => $this->faker->numberBetween(0, 27),
            'responses'       => $this->fakeResponses('phq9_depression'),
        ]);
    }

    public function fallRisk(): static
    {
        return $this->state(fn () => [
            'assessment_type' => 'fall_risk_morse',
            'score'           => $this->faker->numberBetween(0, 125),
            'responses'       => $this->fakeResponses('fall_risk_morse'),
        ]);
    }

    /** Assessment that is overdue (next_due_date in the past). */
    public function overdue(): static
    {
        return $this->state([
            'next_due_date' => $this->faker->dateTimeBetween('-3 months', '-1 day'),
        ]);
    }

    /** Assessment due within the next 14 days. */
    public function dueSoon(): static
    {
        return $this->state([
            'next_due_date' => $this->faker->dateTimeBetween('now', '+14 days'),
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

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Generate type-appropriate fake responses for the responses JSONB column. */
    private function fakeResponses(string $type): array
    {
        return match ($type) {
            'phq9_depression' => array_fill_keys(
                ['q1','q2','q3','q4','q5','q6','q7','q8','q9'],
                (string) $this->faker->numberBetween(0, 3)
            ),
            'gad7_anxiety' => array_fill_keys(
                ['q1','q2','q3','q4','q5','q6','q7'],
                (string) $this->faker->numberBetween(0, 3)
            ),
            'mmse_cognitive' => [
                'orientation_time'  => $this->faker->numberBetween(0, 5),
                'orientation_place' => $this->faker->numberBetween(0, 5),
                'registration'      => $this->faker->numberBetween(0, 3),
                'attention'         => $this->faker->numberBetween(0, 5),
                'recall'            => $this->faker->numberBetween(0, 3),
                'language'          => $this->faker->numberBetween(0, 9),
            ],
            'fall_risk_morse' => [
                'fall_history'       => $this->faker->randomElement(['0', '25']),
                'secondary_diagnosis'=> $this->faker->randomElement(['0', '15']),
                'ambulatory_aid'     => $this->faker->randomElement(['0', '15', '30']),
                'iv_access'          => $this->faker->randomElement(['0', '20']),
                'gait'               => $this->faker->randomElement(['0', '10', '20']),
                'mental_status'      => $this->faker->randomElement(['0', '15']),
            ],
            default => [
                'notes'   => $this->faker->paragraph(),
                'findings' => $this->faker->sentence(),
            ],
        };
    }
}
