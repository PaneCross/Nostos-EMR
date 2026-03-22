<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TransportRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransportRequestFactory extends Factory
{
    protected $model = TransportRequest::class;

    public function definition(): array
    {
        // Default to a pickup sometime within the next 7 days (business hours)
        $pickupTime = $this->faker->dateTimeBetween('now', '+7 days');
        $pickupTime->setTime($this->faker->numberBetween(7, 16), $this->faker->randomElement([0, 30]), 0);

        return [
            'tenant_id'              => fn () => Tenant::factory()->create()->id,
            'participant_id'         => fn (array $a) => Participant::factory()->create(['tenant_id' => $a['tenant_id']])->id,
            'appointment_id'         => null,
            'requesting_user_id'     => fn (array $a) => User::factory()->create(['tenant_id' => $a['tenant_id']])->id,
            'requesting_department'  => $this->faker->randomElement(['primary_care', 'transportation', 'social_work']),
            'trip_type'              => $this->faker->randomElement(TransportRequest::TRIP_TYPES),
            'pickup_location_id'     => fn (array $a) => Location::factory()->create(['tenant_id' => $a['tenant_id']])->id,
            'dropoff_location_id'    => fn (array $a) => Location::factory()->create(['tenant_id' => $a['tenant_id']])->id,
            'requested_pickup_time'  => $pickupTime,
            'scheduled_pickup_time'  => null,
            'actual_pickup_time'     => null,
            'actual_dropoff_time'    => null,
            'special_instructions'   => $this->faker->optional(0.4)->sentence(),
            // Empty snapshot by default; use withFlags() state for tests that need flags
            'mobility_flags_snapshot' => [],
            'status'                 => 'requested',
            'transport_trip_id'      => null,
            'driver_notes'           => null,
            'last_synced_at'         => null,
        ];
    }

    /**
     * Simulate a transport request that has been successfully bridged to the transport app.
     * transport_trip_id is the cross-app reference (no FK — foreign transport_trips table).
     */
    public function bridged(): static
    {
        return $this->state([
            'status'            => 'scheduled',
            'transport_trip_id' => $this->faker->numberBetween(1000, 9999),
            'last_synced_at'    => now(),
        ]);
    }

    /**
     * Simulate a completed transport trip (participant picked up and delivered).
     */
    public function completed(): static
    {
        $pickupTime = $this->faker->dateTimeBetween('-7 days', '-1 hour');
        $dropoffTime = (clone $pickupTime)->modify('+45 minutes');

        return $this->state([
            'status'              => 'completed',
            'actual_pickup_time'  => $pickupTime,
            'actual_dropoff_time' => $dropoffTime,
            'last_synced_at'      => now(),
        ]);
    }

    /**
     * Simulate a cancelled transport request.
     */
    public function cancelled(): static
    {
        return $this->state([
            'status'       => 'cancelled',
            'driver_notes' => $this->faker->randomElement([
                'Participant declined transport',
                'Participant hospitalized — trip cancelled',
                'Duplicate request',
            ]),
        ]);
    }

    /**
     * Simulate an add-on request (unscheduled same-day trip).
     * These enter the Add-On Queue for Transportation Team review.
     */
    public function addOn(): static
    {
        return $this->state(['trip_type' => 'add_on', 'status' => 'requested']);
    }

    /**
     * Simulate a request with mobility flags captured in the snapshot.
     * The snapshot mirrors what was active on the participant at request time.
     */
    public function withFlags(array $flags = []): static
    {
        $defaultFlags = $flags ?: [
            ['type' => 'wheelchair', 'severity' => 'standard'],
        ];

        return $this->state(['mobility_flags_snapshot' => $defaultFlags]);
    }
}
