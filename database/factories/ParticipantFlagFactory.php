<?php

// ─── ParticipantFlagFactory ────────────────────────────────────────────────────
// Creates ParticipantFlag model instances for tests and seeders.
// Requires participant_id, tenant_id, and created_by_user_id to be set —
// either via factory states or explicit overrides in test setUp().
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\Participant;
use App\Models\ParticipantFlag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ParticipantFlagFactory extends Factory
{
    protected $model = ParticipantFlag::class;

    public function definition(): array
    {
        return [
            'participant_id'     => Participant::factory(),
            'tenant_id'          => Tenant::factory(),
            'flag_type'          => $this->faker->randomElement([
                'fall_risk', 'wandering_risk', 'isolation', 'dnr',
                'dietary_restriction', 'hospice', 'other',
            ]),
            'description'        => $this->faker->optional(0.5)->sentence(),
            'severity'           => $this->faker->randomElement(['low', 'medium', 'high', 'critical']),
            'is_active'          => true,
            'created_by_user_id' => User::factory(),
            'resolved_by_user_id'=> null,
            'resolved_at'        => null,
        ];
    }

    /** Create an already-resolved flag */
    public function resolved(): static
    {
        return $this->state(fn () => [
            'is_active'          => false,
            'resolved_by_user_id'=> User::factory(),
            'resolved_at'        => now()->subDays(rand(1, 30)),
        ]);
    }

    /** Create a transport-relevant flag (synced to Nostos transport platform) */
    public function transportRelevant(): static
    {
        return $this->state([
            'flag_type' => $this->faker->randomElement(['wheelchair', 'stretcher', 'oxygen', 'behavioral']),
        ]);
    }
}
