<?php

// ─── HpmsSubmissionFactory ────────────────────────────────────────────────────
// Generates emr_hpms_submissions rows for tests and Phase 9B demo seeder.
//
// State helpers:
//   ->submitted()       — marks submission as submitted to CMS
//   ->enrollment()      — enrollment file type
//   ->disenrollment()   — disenrollment file type
//   ->qualityData()     — quality data report type
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\HpmsSubmission;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class HpmsSubmissionFactory extends Factory
{
    protected $model = HpmsSubmission::class;

    public function definition(): array
    {
        $type        = $this->faker->randomElement(array_keys(HpmsSubmission::SUBMISSION_TYPES));
        $periodStart = Carbon::now()->startOfMonth()->subMonth();
        $periodEnd   = $periodStart->copy()->endOfMonth();
        $recordCount = $this->faker->numberBetween(1, 40);

        $fileContent = "HPMS_{$type}|{$periodStart->format('Y-m')}|PACE|V2025.1\n"
            . implode("\n", array_fill(0, $recordCount, 'RECORD|PLACEHOLDER|DATA'));

        return [
            'tenant_id'          => Tenant::factory(),
            'submission_type'    => $type,
            'file_content'       => $fileContent,
            'record_count'       => $recordCount,
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'status'             => 'draft',
            'submitted_at'       => null,
            'created_by_user_id' => User::factory(),
        ];
    }

    /** Submission that has been sent to HPMS. */
    public function submitted(): static
    {
        return $this->state(fn () => [
            'status'       => 'submitted',
            'submitted_at' => now()->subDays($this->faker->numberBetween(1, 14)),
        ]);
    }

    public function enrollment(): static
    {
        return $this->state(fn () => ['submission_type' => 'enrollment']);
    }

    public function disenrollment(): static
    {
        return $this->state(fn () => ['submission_type' => 'disenrollment']);
    }

    public function qualityData(): static
    {
        return $this->state(fn () => ['submission_type' => 'quality_data']);
    }
}
