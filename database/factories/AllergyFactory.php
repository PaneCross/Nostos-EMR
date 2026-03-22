<?php

namespace Database\Factories;

use App\Models\Allergy;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AllergyFactory extends Factory
{
    protected $model = Allergy::class;

    // ── Allergen names by type for realistic test data ────────────────────────
    private const ALLERGENS = [
        'drug'                => ['Penicillin', 'Sulfa', 'Aspirin', 'NSAIDs', 'Codeine', 'Lisinopril'],
        'food'                => ['Peanuts', 'Tree nuts', 'Shellfish', 'Eggs', 'Dairy', 'Soy', 'Wheat'],
        'environmental'       => ['Latex', 'Dust mites', 'Pet dander', 'Pollen', 'Mold'],
        'dietary_restriction' => ['Low sodium diet', 'Diabetic diet', 'Texture-modified diet', 'Renal diet'],
        'latex'               => ['Natural rubber latex'],
        'contrast'            => ['Iodinated contrast', 'Gadolinium contrast'],
    ];

    private const REACTIONS = [
        'Anaphylaxis', 'Hives / urticaria', 'Rash', 'Angioedema',
        'Respiratory distress', 'GI upset / nausea', 'Hypotension',
        'Swelling', 'Pruritus',
    ];

    public function definition(): array
    {
        $type     = $this->faker->randomElement(Allergy::ALLERGY_TYPES);
        $allergen = $this->faker->randomElement(self::ALLERGENS[$type] ?? ['Unknown allergen']);

        return [
            'participant_id'      => Participant::factory(),
            'tenant_id'           => Tenant::factory(),
            'allergy_type'        => $type,
            'allergen_name'       => $allergen,
            'reaction_description'=> $this->faker->randomElement(self::REACTIONS),
            'severity'            => $this->faker->randomElement(Allergy::SEVERITIES),
            'onset_date'          => $this->faker->boolean(50)
                ? $this->faker->dateTimeBetween('-20 years', '-1 year')->format('Y-m-d')
                : null,
            'is_active'           => true,
            'verified_by_user_id' => null,
            'verified_at'         => null,
            'notes'               => $this->faker->boolean(20) ? $this->faker->sentence() : null,
        ];
    }

    // ─── States ───────────────────────────────────────────────────────────────

    public function lifeThreatening(): static
    {
        return $this->state([
            'severity'             => Allergy::LIFE_THREATENING,
            'reaction_description' => 'Anaphylaxis',
            'is_active'            => true,
        ]);
    }

    public function drug(): static
    {
        return $this->state(fn () => [
            'allergy_type'  => 'drug',
            'allergen_name' => $this->faker->randomElement(self::ALLERGENS['drug']),
        ]);
    }

    public function dietaryRestriction(): static
    {
        return $this->state(fn () => [
            'allergy_type'  => 'dietary_restriction',
            'allergen_name' => $this->faker->randomElement(self::ALLERGENS['dietary_restriction']),
            'severity'      => 'intolerance',
        ]);
    }

    /** Mark as verified by a provider. */
    public function verified(int $userId): static
    {
        return $this->state([
            'verified_by_user_id' => $userId,
            'verified_at'         => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
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
