<?php

namespace Database\Factories;

use App\Models\BreakGlassEvent;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BreakGlassEventFactory extends Factory
{
    protected $model = BreakGlassEvent::class;

    public function definition(): array
    {
        $grantedAt = $this->faker->dateTimeBetween('-7 days', 'now');

        return [
            'user_id'           => User::factory(),
            'tenant_id'         => Tenant::factory(),
            'participant_id'    => Participant::factory(),
            'justification'     => 'Emergency access required: ' . $this->faker->sentence(),
            'access_granted_at' => $grantedAt,
            'access_expires_at' => (clone $grantedAt)->modify('+4 hours'),
            'ip_address'        => $this->faker->ipv4(),
            'acknowledged_by_supervisor_user_id' => null,
            'acknowledged_at'   => null,
        ];
    }

    /** Active access — expiry in the future. */
    public function active(): static
    {
        $grantedAt = now()->subHour();

        return $this->state([
            'access_granted_at' => $grantedAt,
            'access_expires_at' => now()->addHours(3),
        ]);
    }

    /** Expired access — expiry in the past. */
    public function expired(): static
    {
        $grantedAt = now()->subHours(6);

        return $this->state([
            'access_granted_at' => $grantedAt,
            'access_expires_at' => now()->subHours(2),
        ]);
    }

    /** Acknowledged by a supervisor. */
    public function acknowledged(): static
    {
        return $this->state([
            'acknowledged_by_supervisor_user_id' => User::factory(),
            'acknowledged_at'                    => now()->subHour(),
        ]);
    }
}
