<?php

namespace Database\Factories;

use App\Models\SraRecord;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for SraRecord.
 *
 * Default: completed annual SRA with next_sra_due ~12 months in the future.
 * States document HIPAA-specific scenarios for test authors.
 */
class SraRecordFactory extends Factory
{
    protected $model = SraRecord::class;

    public function definition(): array
    {
        return [
            'tenant_id'           => Tenant::factory(),
            'sra_date'            => now()->subMonths(rand(2, 6))->toDateString(),
            'conducted_by'        => $this->faker->name(),
            'scope_description'   => 'Annual HIPAA Security Risk Analysis covering all ePHI systems, '
                . 'access controls, audit logging, and third-party integrations.',
            'risk_level'          => $this->faker->randomElement(SraRecord::RISK_LEVELS),
            'findings_summary'    => $this->faker->paragraph(),
            'next_sra_due'        => now()->addMonths(rand(8, 14))->toDateString(),
            'status'              => 'completed',
            'reviewed_by_user_id' => null,
        ];
    }

    /**
     * SRA where next_sra_due is in the past.
     * Simulates an organization that missed its annual SRA renewal deadline.
     * Triggers the 'sra_overdue' flag in QaDashboardController compliance_posture.
     */
    public function overdue(): static
    {
        return $this->state([
            'sra_date'     => now()->subMonths(rand(14, 24))->toDateString(),
            'next_sra_due' => now()->subDays(rand(1, 90))->toDateString(),
            'status'       => 'needs_update',
        ]);
    }

    /**
     * SRA that is currently in progress (findings not yet documented).
     * Common mid-year state while the assessment is being conducted.
     */
    public function inProgress(): static
    {
        return $this->state([
            'findings_summary' => null,
            'next_sra_due'     => null,
            'status'           => 'in_progress',
        ]);
    }
}
