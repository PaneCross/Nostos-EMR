<?php

namespace Database\Factories;

use App\Models\AdlRecord;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdlRecordFactory extends Factory
{
    protected $model = AdlRecord::class;

    // ── Assistive devices common in PACE ADL observations ─────────────────────
    private const ASSISTIVE_DEVICES = [
        'None', 'Cane', 'Walker', 'Rollator', 'Wheelchair',
        'Transfer belt', 'Hoyer lift', 'Grab bars',
    ];

    public function definition(): array
    {
        return [
            'participant_id'      => Participant::factory(),
            'tenant_id'           => Tenant::factory(),
            'recorded_by_user_id' => User::factory(),
            'recorded_at'         => $this->faker->dateTimeBetween('-90 days', 'now'),
            'adl_category'        => $this->faker->randomElement(AdlRecord::CATEGORIES),
            'independence_level'  => $this->faker->randomElement(AdlRecord::LEVELS),
            'assistive_device_used' => $this->faker->randomElement(self::ASSISTIVE_DEVICES),
            'notes'               => $this->faker->boolean(25) ? $this->faker->sentence() : null,
            'threshold_breached'  => false,
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    /** Best functional state — no assistance needed. */
    public function independent(): static
    {
        return $this->state([
            'independence_level'    => 'independent',
            'assistive_device_used' => 'None',
            'threshold_breached'    => false,
        ]);
    }

    /** Worst functional state — triggers alert threshold breach. */
    public function totalDependent(): static
    {
        return $this->state([
            'independence_level'  => 'total_dependent',
            'threshold_breached'  => true,
        ]);
    }

    public function withBreach(): static
    {
        return $this->state(['threshold_breached' => true]);
    }

    public function forCategory(string $category): static
    {
        return $this->state(['adl_category' => $category]);
    }

    public function withLevel(string $level): static
    {
        return $this->state(['independence_level' => $level]);
    }

    public function recordedAt(\DateTimeInterface $at): static
    {
        return $this->state(['recorded_at' => $at]);
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
