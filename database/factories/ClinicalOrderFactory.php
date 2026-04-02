<?php

// ─── ClinicalOrderFactory ─────────────────────────────────────────────────────
// Factory for emr_clinical_orders in tests and seeders.
//
// States:
//   pending()    — fresh order awaiting acknowledgment (default)
//   acknowledged() — order has been acknowledged by target dept
//   completed()  — fully completed order
//   stat()       — stat priority, creates critical alert scenario
//   overdue()    — past due (routine, due_date in the past)
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\ClinicalOrder;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClinicalOrderFactory extends Factory
{
    protected $model = ClinicalOrder::class;

    public function definition(): array
    {
        $tenant      = Tenant::factory()->create();
        $site        = Site::factory()->create(['tenant_id' => $tenant->id]);
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id, 'site_id' => $site->id]);
        $orderedBy   = User::factory()->create(['tenant_id' => $tenant->id, 'department' => 'primary_care']);

        $orderType       = $this->faker->randomElement(ClinicalOrder::ORDER_TYPES);
        $targetDept      = ClinicalOrder::DEPARTMENT_ROUTING[$orderType] ?? 'primary_care';

        return [
            'participant_id'      => $participant->id,
            'tenant_id'           => $tenant->id,
            'site_id'             => $site->id,
            'ordered_by_user_id'  => $orderedBy->id,
            'ordered_at'          => now()->subHours(rand(1, 24)),
            'order_type'          => $orderType,
            'priority'            => 'routine',
            'status'              => 'pending',
            'instructions'        => $this->faker->sentence(10),
            'clinical_indication' => $this->faker->sentence(8),
            'target_department'   => $targetDept,
            'target_facility'     => null,
            'due_date'            => now()->addDays(rand(1, 14))->format('Y-m-d'),
        ];
    }

    /** Order that has been acknowledged by the target department. */
    public function acknowledged(): static
    {
        return $this->state(fn () => [
            'status'                    => 'acknowledged',
            'acknowledged_at'           => now()->subHours(2),
            'acknowledged_by_user_id'   => User::factory()->create(['department' => 'therapies'])->id,
        ]);
    }

    /** Fully completed order with result summary. */
    public function completed(): static
    {
        return $this->state(fn () => [
            'status'         => 'completed',
            'acknowledged_at'=> now()->subHours(8),
            'completed_at'   => now()->subHours(2),
            'result_summary' => 'Order completed successfully. Results within normal limits.',
        ]);
    }

    /** Stat priority order — triggers critical alert creation. */
    public function stat(): static
    {
        return $this->state(fn () => [
            'priority'   => 'stat',
            'status'     => 'pending',
            'ordered_at' => now()->subMinutes(30),
        ]);
    }

    /** Urgent priority order. */
    public function urgent(): static
    {
        return $this->state(fn () => [
            'priority' => 'urgent',
            'status'   => 'pending',
        ]);
    }

    /** Overdue routine order — due_date in the past. */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'priority'   => 'routine',
            'status'     => 'pending',
            'ordered_at' => now()->subDays(10),
            'due_date'   => now()->subDays(3)->format('Y-m-d'),
        ]);
    }
}
