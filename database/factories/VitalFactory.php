<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Database\Eloquent\Factories\Factory;

class VitalFactory extends Factory
{
    protected $model = Vital::class;

    // ── PACE-appropriate vital ranges for realistic test data ─────────────────
    // Elderly patients lean toward borderline-high BP and borderline-low O2.

    public function definition(): array
    {
        $systolic  = $this->faker->numberBetween(100, 180);
        $diastolic = $this->faker->numberBetween(60, 110);

        return [
            'participant_id'    => Participant::factory(),
            'tenant_id'         => Tenant::factory(),
            'recorded_by_user_id' => User::factory(),
            'recorded_at'       => $this->faker->dateTimeBetween('-90 days', 'now'),
            'bp_systolic'       => $systolic,
            'bp_diastolic'      => $diastolic,
            'pulse'             => $this->faker->numberBetween(50, 110),
            'temperature_f'     => $this->faker->randomFloat(1, 96.5, 99.5),
            'respiratory_rate'  => $this->faker->numberBetween(12, 22),
            'o2_saturation'     => $this->faker->numberBetween(88, 99),
            'weight_lbs'        => $this->faker->randomFloat(1, 100, 280),
            'height_in'         => $this->faker->numberBetween(58, 74),
            'pain_score'        => $this->faker->numberBetween(0, 10),
            'notes'             => $this->faker->boolean(20) ? $this->faker->sentence() : null,
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    /** Vitals with elevated BP (stage 2 hypertension range). */
    public function hypertensive(): static
    {
        return $this->state([
            'bp_systolic'  => $this->faker->numberBetween(160, 200),
            'bp_diastolic' => $this->faker->numberBetween(100, 120),
        ]);
    }

    /** Vitals with low O2 saturation (concerning for PACE population). */
    public function hypoxic(): static
    {
        return $this->state([
            'o2_saturation' => $this->faker->numberBetween(82, 91),
        ]);
    }

    /** Minimal vitals — only BP and pulse recorded (common in quick checks). */
    public function minimal(): static
    {
        return $this->state([
            'temperature_f'    => null,
            'respiratory_rate' => null,
            'o2_saturation'    => null,
            'weight_lbs'       => null,
            'height_in'        => null,
            'pain_score'       => null,
        ]);
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
