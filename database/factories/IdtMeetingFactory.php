<?php

namespace Database\Factories;

use App\Models\IdtMeeting;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IdtMeetingFactory extends Factory
{
    protected $model = IdtMeeting::class;

    public function definition(): array
    {
        return [
            'tenant_id'          => fn () => Tenant::factory()->create()->id,
            'site_id'            => null,
            'meeting_date'       => $this->faker->dateTimeBetween('-30 days', '+30 days'),
            'meeting_time'       => $this->faker->time('H:i', '16:00'),
            'meeting_type'       => $this->faker->randomElement(['weekly', 'daily', 'care_plan_review']),
            'facilitator_user_id'=> null,
            'attendees'          => [],
            'minutes_text'       => null,
            'decisions'          => [],
            'status'             => 'scheduled',
        ];
    }

    /** Create a completed meeting with minutes. */
    public function completed(): static
    {
        return $this->state([
            'status'       => 'completed',
            'meeting_date' => $this->faker->dateTimeBetween('-30 days', '-1 day'),
            'minutes_text' => $this->faker->paragraph(3),
        ]);
    }

    /** Create an in-progress meeting. */
    public function inProgress(): static
    {
        return $this->state([
            'status'       => 'in_progress',
            'meeting_date' => now()->toDateString(),
        ]);
    }

    /** Create a weekly IDT review meeting. */
    public function weekly(): static
    {
        return $this->state(['meeting_type' => 'weekly']);
    }
}
