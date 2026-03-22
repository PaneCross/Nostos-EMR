<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\SocialDeterminant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class SocialDeterminantFactory extends Factory
{
    protected $model = SocialDeterminant::class;

    public function definition(): array
    {
        return [
            'participant_id'        => Participant::factory(),
            'tenant_id'             => Tenant::factory(),
            'assessed_by_user_id'   => null,
            'assessed_at'           => now(),
            'housing_stability'     => $this->faker->randomElement(SocialDeterminant::HOUSING_VALUES),
            'food_security'         => $this->faker->randomElement(SocialDeterminant::FOOD_VALUES),
            'transportation_access' => $this->faker->randomElement(SocialDeterminant::TRANSPORT_VALUES),
            'social_isolation_risk' => $this->faker->randomElement(SocialDeterminant::ISOLATION_VALUES),
            'caregiver_strain'      => $this->faker->randomElement(SocialDeterminant::STRAIN_VALUES),
            'financial_strain'      => $this->faker->randomElement(SocialDeterminant::STRAIN_VALUES),
            'safety_concerns'       => null,
            'notes'                 => null,
        ];
    }

    /** All domains at low/no risk. */
    public function lowRisk(): static
    {
        return $this->state(fn () => [
            'housing_stability'     => 'stable',
            'food_security'         => 'secure',
            'transportation_access' => 'adequate',
            'social_isolation_risk' => 'low',
            'caregiver_strain'      => 'none',
            'financial_strain'      => 'none',
            'safety_concerns'       => null,
        ]);
    }

    /** Multiple elevated risk domains. */
    public function highRisk(): static
    {
        return $this->state(fn () => [
            'housing_stability'     => 'at_risk',
            'food_security'         => 'insecure',
            'transportation_access' => 'none',
            'social_isolation_risk' => 'high',
            'caregiver_strain'      => 'severe',
            'financial_strain'      => 'moderate',
            'safety_concerns'       => 'Participant reports unsafe home environment.',
        ]);
    }
}
