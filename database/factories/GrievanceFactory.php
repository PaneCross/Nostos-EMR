<?php

// ─── GrievanceFactory ─────────────────────────────────────────────────────────
// Generates test Grievance records. States cover the full workflow lifecycle.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\Grievance;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GrievanceFactory extends Factory
{
    protected $model = Grievance::class;

    public function definition(): array
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);

        return [
            'participant_id'       => Participant::factory()->create([
                'tenant_id' => $tenant->id,
                'site_id'   => $site->id,
            ])->id,
            'tenant_id'            => $tenant->id,
            'site_id'              => $site->id,
            'filed_by_name'        => $this->faker->name(),
            'filed_by_type'        => $this->faker->randomElement(Grievance::FILED_BY_TYPES),
            'filed_at'             => now()->subDays(rand(1, 10)),
            'received_by_user_id'  => null,
            'category'             => $this->faker->randomElement(Grievance::CATEGORIES),
            'description'          => $this->faker->paragraph(),
            'status'               => 'open',
            'priority'             => 'standard',
            'assigned_to_user_id'  => null,
            'investigation_notes'  => null,
            'resolution_text'      => null,
            'resolution_date'      => null,
            'participant_notified_at' => null,
            'notification_method'  => null,
            'cms_reportable'       => false,
            'cms_reported_at'      => null,
        ];
    }

    /** Grievance under active investigation */
    public function underReview(): static
    {
        return $this->state(['status' => 'under_review']);
    }

    /** Resolved grievance with required resolution fields */
    public function resolved(): static
    {
        return $this->state([
            'status'          => 'resolved',
            'resolution_text' => 'Grievance investigated and resolved. Corrective action taken.',
            'resolution_date' => now()->subDays(2)->toDateString(),
        ]);
    }

    /** Escalated grievance (not resolved within timeframe) */
    public function escalated(): static
    {
        return $this->state([
            'status'            => 'escalated',
            'escalation_reason' => 'Grievance not resolved within standard timeframe.',
        ]);
    }

    /** Urgent priority — health/safety concern, 72h resolution clock */
    public function urgent(): static
    {
        return $this->state(['priority' => 'urgent']);
    }

    /** CMS-reportable grievance */
    public function cmsReportable(): static
    {
        return $this->state([
            'cms_reportable'   => true,
            'cms_reported_at'  => now()->subDay(),
        ]);
    }

    /**
     * Overdue urgent grievance — filed more than 72h ago, still open.
     * Used in GrievanceOverdueJob tests.
     */
    public function urgentOverdue(): static
    {
        return $this->state([
            'priority'  => 'urgent',
            'status'    => 'open',
            'filed_at'  => now()->subHours(80),
        ]);
    }

    /**
     * Overdue standard grievance — filed more than 30 days ago, still open.
     */
    public function standardOverdue(): static
    {
        return $this->state([
            'priority' => 'standard',
            'status'   => 'open',
            'filed_at' => now()->subDays(35),
        ]);
    }
}
