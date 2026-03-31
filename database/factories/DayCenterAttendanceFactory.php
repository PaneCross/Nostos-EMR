<?php

namespace Database\Factories;

use App\Models\DayCenterAttendance;
use App\Models\Participant;
use App\Models\Site;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class DayCenterAttendanceFactory extends Factory
{
    protected $model = DayCenterAttendance::class;

    public function definition(): array
    {
        $tenant      = Tenant::factory()->create();
        $site        = Site::factory()->create(['tenant_id' => $tenant->id]);
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id, 'site_id' => $site->id]);
        $recorder    = User::factory()->create(['tenant_id' => $tenant->id]);
        $status      = $this->faker->randomElement(['present', 'present', 'present', 'absent', 'late']);

        return [
            'tenant_id'          => $tenant->id,
            'participant_id'     => $participant->id,
            'site_id'            => $site->id,
            'attendance_date'    => now()->toDateString(),
            'status'             => $status,
            'check_in_time'      => in_array($status, ['present', 'late']) ? '09:00:00' : null,
            'check_out_time'     => in_array($status, ['present', 'late']) ? '15:30:00' : null,
            'absent_reason'      => $status === 'absent' ? 'illness' : null,
            'notes'              => null,
            'recorded_by_user_id' => $recorder->id,
        ];
    }

    /** Participant is present for the day. */
    public function present(): static
    {
        return $this->state(['status' => 'present', 'check_in_time' => '09:00:00', 'check_out_time' => null, 'absent_reason' => null]);
    }

    /** Participant is absent with a recorded reason. */
    public function absent(?string $reason = 'illness'): static
    {
        return $this->state(['status' => 'absent', 'check_in_time' => null, 'check_out_time' => null, 'absent_reason' => $reason]);
    }
}
