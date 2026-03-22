<?php

// ─── StateMedicaidConfigFactory ───────────────────────────────────────────────
// Generates test StateMedicaidConfig records for state Medicaid submission
// configuration testing.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\StateMedicaidConfig;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class StateMedicaidConfigFactory extends Factory
{
    protected $model = StateMedicaidConfig::class;

    /** US state codes for test data generation */
    private const STATES = [
        'CA' => 'California',
        'TX' => 'Texas',
        'NY' => 'New York',
        'FL' => 'Florida',
        'PA' => 'Pennsylvania',
        'OH' => 'Ohio',
        'IL' => 'Illinois',
        'MA' => 'Massachusetts',
    ];

    public function definition(): array
    {
        $tenant    = Tenant::factory()->create();
        $statePair = $this->faker->randomElement(array_keys(self::STATES));

        return [
            'tenant_id'              => $tenant->id,
            'state_code'             => $statePair,
            'state_name'             => self::STATES[$statePair],
            'submission_format'      => '837P',
            'companion_guide_notes'  => $this->faker->optional()->sentence(10),
            'submission_endpoint'    => $this->faker->optional()->url(),
            'clearinghouse_name'     => $this->faker->optional()->randomElement(['Availity', 'Change Healthcare', 'State Portal']),
            'days_to_submit'         => 180,
            'effective_date'         => now()->startOfYear(),
            'contact_name'           => $this->faker->optional()->name(),
            'contact_phone'          => $this->faker->optional()->phoneNumber(),
            'contact_email'          => $this->faker->optional()->safeEmail(),
            'is_active'              => true,
        ];
    }

    /**
     * California config (most common PACE state — largest program in the US).
     * Uses 837P format with Availity clearinghouse, 180-day filing limit.
     */
    public function forCalifornia(): static
    {
        return $this->state([
            'state_code'          => 'CA',
            'state_name'          => 'California',
            'submission_format'   => '837P',
            'clearinghouse_name'  => 'Availity',
            'days_to_submit'      => 180,
        ]);
    }

    /**
     * Inactive config — represents a state the org previously operated in
     * but has since ceased enrolling participants.
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
