<?php

namespace Database\Factories;

use App\Models\CarePlan;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class CarePlanFactory extends Factory
{
    protected $model = CarePlan::class;

    public function definition(): array
    {
        $effectiveDate   = Carbon::instance($this->faker->dateTimeBetween('-6 months', 'now'));
        $reviewDueDate   = $effectiveDate->copy()->addMonths(6);

        return [
            'participant_id'    => fn () => Participant::factory()->create()->id,
            'tenant_id'         => fn (array $attrs) => Participant::find($attrs['participant_id'])?->tenant_id ?? Tenant::factory()->create()->id,
            'version'           => 1,
            'status'            => 'active',
            'effective_date'    => $effectiveDate,
            'review_due_date'   => $reviewDueDate,
            'approved_by_user_id' => null,
            'approved_at'       => null,
            'overall_goals_text'=> $this->faker->paragraph(2),
        ];
    }

    /** Create a draft care plan. */
    public function draft(): static
    {
        return $this->state([
            'status'          => 'draft',
            'effective_date'  => null,
            'review_due_date' => null,
            'approved_by_user_id' => null,
            'approved_at'     => null,
        ]);
    }

    /** Create an archived care plan (superseded by newer version). */
    public function archived(): static
    {
        return $this->state(['status' => 'archived']);
    }

    /** Create a care plan due for review soon (within 30 days). */
    public function dueSoon(): static
    {
        $effectiveDate = Carbon::now()->subMonths(5)->subWeeks(2);
        return $this->state([
            'effective_date'  => $effectiveDate,
            'review_due_date' => $effectiveDate->copy()->addMonths(6),
        ]);
    }
}
