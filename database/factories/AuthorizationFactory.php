<?php

namespace Database\Factories;

use App\Models\Authorization;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuthorizationFactory extends Factory
{
    protected $model = Authorization::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-60 days', '-10 days');
        $end   = $this->faker->dateTimeBetween('+10 days', '+180 days');

        return [
            'tenant_id'        => Tenant::factory(),
            'participant_id'   => Participant::factory(),
            'service_type'     => $this->faker->randomElement(array_keys(Authorization::SERVICE_TYPES)),
            'authorized_units' => $this->faker->boolean(70) ? $this->faker->numberBetween(4, 24) : null,
            'authorized_start' => $start->format('Y-m-d'),
            'authorized_end'   => $end->format('Y-m-d'),
            'status'           => 'active',
            'notes'            => $this->faker->boolean(30) ? $this->faker->sentence() : null,
        ];
    }

    /** Authorization expiring within 30 days. */
    public function expiringSoon(): static
    {
        return $this->state([
            'status'          => 'active',
            'authorized_start'=> now()->subMonths(3)->toDateString(),
            'authorized_end'  => now()->addDays($this->faker->numberBetween(5, 25))->toDateString(),
        ]);
    }

    /** Already expired authorization. */
    public function expired(): static
    {
        return $this->state([
            'status'          => 'expired',
            'authorized_start'=> now()->subMonths(6)->toDateString(),
            'authorized_end'  => now()->subDays(10)->toDateString(),
        ]);
    }

    /** Cancelled authorization. */
    public function cancelled(): static
    {
        return $this->state(['status' => 'cancelled']);
    }
}
