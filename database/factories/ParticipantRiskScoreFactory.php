<?php

// ─── ParticipantRiskScoreFactory ───────────────────────────────────────────────
// Generates test ParticipantRiskScore records with realistic PACE RAF values.
//
// PACE RAF scores typically range 1.0–3.5 (complex elderly population).
// Standard Medicare Advantage averages ~1.0; PACE is significantly higher
// due to frailty and multiple chronic conditions.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\Participant;
use App\Models\ParticipantRiskScore;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ParticipantRiskScoreFactory extends Factory
{
    protected $model = ParticipantRiskScore::class;

    public function definition(): array
    {
        $tenant      = Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $tenant->id]);

        return [
            'participant_id'      => $participant->id,
            'tenant_id'           => $tenant->id,
            'payment_year'        => now()->year,
            'risk_score'          => $this->faker->randomFloat(4, 1.0, 3.5),
            'frailty_score'       => $this->faker->randomFloat(4, 0.10, 0.45),
            'hcc_categories'      => ['HCC18', 'HCC19', 'HCC85'],  // Common PACE HCCs: diabetes, CHF
            'diagnoses_submitted' => $this->faker->numberBetween(5, 20),
            'diagnoses_accepted'  => fn (array $attrs) => $this->faker->numberBetween(
                (int) ($attrs['diagnoses_submitted'] * 0.7),
                $attrs['diagnoses_submitted']
            ),
            'score_source'        => 'calculated',
            'effective_date'      => now()->startOfYear(),
            'imported_at'         => null,
        ];
    }

    /**
     * Record imported from CMS remittance/rate notice.
     * Simulates a finance team importing the authoritative CMS RAF value.
     */
    public function fromCms(): static
    {
        return $this->state([
            'score_source' => 'cms_import',
            'imported_at'  => now()->subDays(rand(1, 30)),
        ]);
    }

    /**
     * Record calculated locally by RiskAdjustmentService from emr_problems.
     * The most common state during active use before CMS file import arrives.
     */
    public function calculated(): static
    {
        return $this->state([
            'score_source' => 'calculated',
            'imported_at'  => null,
        ]);
    }

    /**
     * Record manually entered by finance staff.
     * Used when CMS import fails or for corrections.
     */
    public function manual(): static
    {
        return $this->state([
            'score_source' => 'manual',
            'imported_at'  => null,
        ]);
    }
}
