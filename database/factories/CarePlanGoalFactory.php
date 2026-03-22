<?php

namespace Database\Factories;

use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CarePlanGoalFactory extends Factory
{
    protected $model = CarePlanGoal::class;

    private static array $goalTemplates = [
        'medical'    => ['Maintain stable blood pressure below 140/90 mmHg', 'Achieve HbA1c < 7.5% within 3 months'],
        'nursing'    => ['Participant will adhere to medication regimen with >90% compliance', 'Skin integrity maintained; no new pressure injuries'],
        'social'     => ['Participant will maintain social engagement via center attendance 3x/week', 'Family caregiver education completed within 30 days'],
        'behavioral' => ['PHQ-9 score reduced by ≥5 points within 90 days', 'Participant will practice 2 coping strategies independently'],
        'therapy_pt' => ['Improve 6-minute walk test by 20% within 60 days', 'Independent ambulation with walker on all surfaces'],
        'therapy_ot' => ['Independent with upper body dressing using adaptive equipment', 'Meal preparation with supervision level of assist within 45 days'],
        'therapy_st' => ['Maintain safe oral diet with thin liquids without aspiration', 'Use AAC device for 5+ functional communications per session'],
        'dietary'    => ['Achieve and maintain BMI 22–27 within 90 days', 'Adequate protein intake (1.0–1.2 g/kg/day) documented weekly'],
        'activities' => ['Participate in 2+ structured activities per week', 'Demonstrate engagement with creative arts program 2x/month'],
        'home_care'  => ['Home environment assessed and safety modifications completed', 'Participant reports satisfaction with personal care assistance ≥8/10'],
        'transportation' => ['Attend PACE center on all scheduled days without transport incident', 'Caregiver trained on accessible vehicle boarding protocol'],
        'pharmacy'   => ['Medication reconciliation completed and discrepancies resolved', 'Participant understands purpose and side effects of all medications'],
    ];

    public function definition(): array
    {
        $domain = $this->faker->randomElement(CarePlanGoal::DOMAINS);
        $goals  = self::$goalTemplates[$domain] ?? ['Individualized goal to be defined by care team'];

        return [
            'care_plan_id'          => fn () => CarePlan::factory()->create()->id,
            'domain'                => $domain,
            'goal_description'      => $this->faker->randomElement($goals),
            'target_date'           => $this->faker->dateTimeBetween('+30 days', '+6 months'),
            'measurable_outcomes'   => $this->faker->sentence(10),
            'interventions'         => $this->faker->sentence(12),
            'status'                => 'active',
            'authored_by_user_id'   => null,
            'last_updated_by_user_id' => null,
        ];
    }

    /** Create a goal for a specific domain. */
    public function forDomain(string $domain): static
    {
        $goals = self::$goalTemplates[$domain] ?? ['Goal for ' . $domain];
        return $this->state([
            'domain'           => $domain,
            'goal_description' => $this->faker->randomElement($goals),
        ]);
    }

    /** Create a met (completed) goal. */
    public function met(): static
    {
        return $this->state(['status' => 'met']);
    }
}
