<?php

namespace Database\Factories;

use App\Models\CapitationRecord;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CapitationRecordFactory extends Factory
{
    protected $model = CapitationRecord::class;

    public function definition(): array
    {
        $medicareA  = $this->faker->randomFloat(2, 1500, 2500);
        $medicareB  = $this->faker->randomFloat(2, 800, 1500);
        $medicareD  = $this->faker->randomFloat(2, 100, 400);
        $medicaid   = $this->faker->randomFloat(2, 200, 800);

        return [
            'tenant_id'            => Tenant::factory(),
            'participant_id'       => Participant::factory(),
            'month_year'           => now()->format('Y-m'),
            'medicare_a_rate'      => $medicareA,
            'medicare_b_rate'      => $medicareB,
            'medicare_d_rate'      => $medicareD,
            'medicaid_rate'        => $medicaid,
            'total_capitation'     => $medicareA + $medicareB + $medicareD + $medicaid,
            'eligibility_category' => $this->faker->randomElement(['CNA', 'NF', 'PACE']),
            'recorded_at'          => now(),
        ];
    }

    /** Record for a prior month (for historical data). */
    public function priorMonth(int $monthsAgo = 1): static
    {
        return $this->state([
            'month_year' => now()->subMonths($monthsAgo)->format('Y-m'),
        ]);
    }
}
