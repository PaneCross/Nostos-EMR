<?php

namespace Database\Factories;

use App\Models\QapiProject;
use Illuminate\Database\Eloquent\Factories\Factory;

class QapiProjectFactory extends Factory
{
    protected $model = QapiProject::class;

    public function definition(): array
    {
        return [
            'tenant_id'              => 1,  // overridden in tests
            'title'                  => $this->faker->sentence(5),
            'description'            => $this->faker->paragraph(),
            'aim_statement'          => 'Reduce ' . $this->faker->word() . ' incidents by 20% within 6 months.',
            'domain'                 => $this->faker->randomElement(QapiProject::DOMAINS),
            'status'                 => 'active',
            'start_date'             => now()->subMonths(2)->toDateString(),
            'target_completion_date' => now()->addMonths(4)->toDateString(),
            'actual_completion_date' => null,
            'baseline_metric'        => '42% of participants experienced at least one fall in Q3.',
            'target_metric'          => 'Reduce fall rate to < 30% by end of project.',
            'current_metric'         => null,
            'project_lead_user_id'   => null,
            'team_member_ids'        => [],
            'interventions'          => null,
            'findings'               => null,
            'created_by_user_id'     => null,
        ];
    }

    /** Project in planning phase. */
    public function planning(): static
    {
        return $this->state(fn () => [
            'status'     => 'planning',
            'start_date' => now()->addWeeks(2)->toDateString(),
        ]);
    }

    /** Project in remeasuring phase. */
    public function remeasuring(): static
    {
        return $this->state(fn () => [
            'status'         => 'remeasuring',
            'current_metric' => '35% fall rate — improvement noted.',
        ]);
    }

    /** Completed project. */
    public function completed(): static
    {
        return $this->state(fn () => [
            'status'                  => 'completed',
            'actual_completion_date'  => now()->subMonth()->toDateString(),
            'findings'                => 'Fall rate reduced from 42% to 28% — goal achieved.',
        ]);
    }

    /** Suspended project. */
    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => 'suspended',
        ]);
    }
}
