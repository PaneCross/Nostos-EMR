<?php

namespace Database\Factories;

use App\Models\EmarRecord;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmarRecordFactory extends Factory
{
    protected $model = EmarRecord::class;

    public function definition(): array
    {
        $scheduledTime = Carbon::today()->setHour($this->faker->randomElement([8, 12, 14, 16, 20]));

        return [
            'participant_id'          => Participant::factory(),
            'medication_id'           => Medication::factory(),
            'tenant_id'               => Tenant::factory(),
            'scheduled_time'          => $scheduledTime,
            'administered_at'         => null,
            'administered_by_user_id' => null,
            'status'                  => 'scheduled',
            'dose_given'              => null,
            'route_given'             => null,
            'reason_not_given'        => null,
            'witness_user_id'         => null,
            'notes'                   => null,
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    /** Simulate a dose that was successfully given. */
    public function given(?int $nursId = null): static
    {
        return $this->state(fn () => [
            'status'                  => 'given',
            'administered_at'         => now()->subMinutes($this->faker->numberBetween(1, 30)),
            'administered_by_user_id' => $nursId,
            'dose_given'              => '10 mg',
            'route_given'             => 'oral',
        ]);
    }

    /** Simulate a dose that was refused by the participant. */
    public function refused(): static
    {
        return $this->state([
            'status'          => 'refused',
            'reason_not_given'=> 'Patient refused medication this morning',
        ]);
    }

    /** Simulate a dose whose window has passed without administration (late). */
    public function late(): static
    {
        return $this->state([
            'status'         => 'late',
            'scheduled_time' => now()->subHours(2),
        ]);
    }

    /** Simulate a pre-scheduled dose (not yet acted on). */
    public function scheduled(): static
    {
        return $this->state([
            'status'         => 'scheduled',
            'administered_at'=> null,
        ]);
    }

    /** Place the scheduled_time in the past so LateMarDetectionJob would flag it. */
    public function overdue(): static
    {
        return $this->state([
            'status'         => 'scheduled',
            'scheduled_time' => now()->subMinutes(45),
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
}
