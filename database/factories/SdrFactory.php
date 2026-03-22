<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\Sdr;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class SdrFactory extends Factory
{
    protected $model = Sdr::class;

    private static array $deptPairs = [
        ['from' => 'primary_care',   'to' => 'pharmacy'],
        ['from' => 'social_work',    'to' => 'transportation'],
        ['from' => 'primary_care',   'to' => 'therapies'],
        ['from' => 'idt',            'to' => 'home_care'],
        ['from' => 'primary_care',   'to' => 'social_work'],
        ['from' => 'enrollment',     'to' => 'idt'],
    ];

    public function definition(): array
    {
        $pair        = $this->faker->randomElement(self::$deptPairs);
        $submittedAt = Carbon::instance($this->faker->dateTimeBetween('-5 days', 'now'));

        return [
            'participant_id'        => fn () => Participant::factory()->create()->id,
            'tenant_id'             => fn (array $attrs) => Participant::find($attrs['participant_id'])?->tenant_id ?? Tenant::factory()->create()->id,
            'requesting_user_id'    => fn (array $attrs) => User::where('tenant_id', $attrs['tenant_id'])->first()?->id ?? User::factory()->create(['tenant_id' => $attrs['tenant_id']])->id,
            'requesting_department' => $pair['from'],
            'assigned_to_user_id'   => null,
            'assigned_department'   => $pair['to'],
            'request_type'          => $this->faker->randomElement(Sdr::REQUEST_TYPES),
            'description'           => $this->faker->paragraph(),
            'priority'              => $this->faker->randomElement(['routine', 'routine', 'urgent']),  // weight toward routine
            'status'                => 'submitted',
            'submitted_at'          => $submittedAt,
            // due_at will be auto-set by Sdr::boot() to submitted_at + 72h
        ];
    }

    /** Create an overdue SDR (submitted > 72h ago, not completed). */
    public function overdue(): static
    {
        $submittedAt = Carbon::now()->subHours(80);  // 80h ago — 8h past 72h window
        return $this->state([
            'submitted_at' => $submittedAt,
            'status'       => 'in_progress',
        ]);
    }

    /** Create a completed SDR. */
    public function completed(): static
    {
        $submittedAt = Carbon::now()->subHours(48);
        return $this->state([
            'submitted_at'    => $submittedAt,
            'status'          => 'completed',
            'completed_at'    => Carbon::now()->subHours(10),
            'completion_notes'=> $this->faker->sentence(),
        ]);
    }

    /** Create a high-urgency emergent SDR. */
    public function emergent(): static
    {
        return $this->state(['priority' => 'emergent']);
    }

    /** Create an escalated SDR (missed 72h window). */
    public function escalated(): static
    {
        $submittedAt = Carbon::now()->subHours(90);
        return $this->state([
            'submitted_at'       => $submittedAt,
            'status'             => 'in_progress',
            'escalated'          => true,
            'escalation_reason'  => 'SDR not completed within 72-hour window. Escalated automatically.',
            'escalated_at'       => Carbon::now()->subHours(18),
        ]);
    }
}
