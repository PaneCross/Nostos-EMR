<?php

namespace Database\Factories;

use App\Models\AdlRecord;
use App\Models\AdlThreshold;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdlThresholdFactory extends Factory
{
    protected $model = AdlThreshold::class;

    public function definition(): array
    {
        return [
            'participant_id'   => Participant::factory(),
            'tenant_id'        => Tenant::factory(),
            'adl_category'     => $this->faker->randomElement(AdlRecord::CATEGORIES),
            'alert_level'      => $this->faker->randomElement(AdlRecord::LEVELS),
            'set_by_user_id'   => User::factory(),
            'set_at'           => $this->faker->dateTimeBetween('-6 months', 'now'),
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    /** Set threshold to trigger only on the worst level. */
    public function highTolerance(): static
    {
        return $this->state(['alert_level' => 'total_dependent']);
    }

    /** Set threshold to trigger as soon as any assist is needed. */
    public function lowTolerance(): static
    {
        return $this->state(['alert_level' => 'supervision']);
    }

    public function forCategory(string $category): static
    {
        return $this->state(['adl_category' => $category]);
    }

    public function atLevel(string $level): static
    {
        return $this->state(['alert_level' => $level]);
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
