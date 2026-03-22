<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        // Stagger appointments within business hours (8am–5pm)
        $start = $this->faker->dateTimeBetween('now', '+30 days');
        $start->setTime($this->faker->numberBetween(8, 16), $this->faker->randomElement([0, 30]), 0);
        $durationMinutes = $this->faker->randomElement([30, 45, 60, 90, 120]);
        $end = (clone $start)->modify("+{$durationMinutes} minutes");

        $tenant = null;

        return [
            'tenant_id'          => fn () => ($tenant = Tenant::factory()->create())->id,
            'participant_id'     => fn (array $attrs) => Participant::factory()->create(['tenant_id' => $attrs['tenant_id']])->id,
            'site_id'            => fn (array $attrs) => Site::factory()->create(['tenant_id' => $attrs['tenant_id']])->id,
            'appointment_type'   => $this->faker->randomElement(Appointment::APPOINTMENT_TYPES),
            'provider_user_id'   => null,
            'location_id'        => null,
            'scheduled_start'    => $start,
            'scheduled_end'      => $end,
            'status'             => 'scheduled',
            'transport_required' => $this->faker->boolean(30), // 30% of appointments need transport
            'transport_request_id' => null,
            'notes'              => $this->faker->optional(0.4)->sentence(),
            'cancellation_reason'=> null,
            'created_by_user_id' => fn (array $attrs) => User::factory()->create(['tenant_id' => $attrs['tenant_id']])->id,
        ];
    }

    /** Upcoming appointment (within next 7 days, not cancelled). */
    public function upcoming(): static
    {
        $start = $this->faker->dateTimeBetween('now', '+7 days');
        $start->setTime($this->faker->numberBetween(8, 16), 0, 0);
        $end   = (clone $start)->modify('+60 minutes');

        return $this->state([
            'scheduled_start' => $start,
            'scheduled_end'   => $end,
            'status'          => 'scheduled',
        ]);
    }

    /** Past appointment (within last 30 days) that was completed. */
    public function completed(): static
    {
        $start = $this->faker->dateTimeBetween('-30 days', '-1 day');
        $start->setTime($this->faker->numberBetween(8, 16), 0, 0);
        $end   = (clone $start)->modify('+60 minutes');

        return $this->state([
            'scheduled_start' => $start,
            'scheduled_end'   => $end,
            'status'          => 'completed',
        ]);
    }

    /** Cancelled appointment with a reason. */
    public function cancelled(): static
    {
        return $this->state([
            'status'              => 'cancelled',
            'cancellation_reason' => $this->faker->randomElement([
                'Participant declined',
                'Participant hospitalized',
                'Provider unavailable',
                'Participant requested reschedule',
                'Weather cancellation',
            ]),
        ]);
    }

    /** Appointment that requires transport. */
    public function withTransport(): static
    {
        return $this->state(['transport_required' => true]);
    }

    /** A clinic visit with a provider assigned. */
    public function clinicVisit(): static
    {
        return $this->state([
            'appointment_type' => 'clinic_visit',
        ]);
    }

    /** Therapy appointment (PT, OT, or ST). */
    public function therapy(): static
    {
        return $this->state([
            'appointment_type' => $this->faker->randomElement(['therapy_pt', 'therapy_ot', 'therapy_st']),
        ]);
    }
}
