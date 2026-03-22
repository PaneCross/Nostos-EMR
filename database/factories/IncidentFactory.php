<?php

// ─── IncidentFactory ────────────────────────────────────────────────────────
// Generates emr_incidents rows for testing.
//
// Base state: open fall incident reported by the current user.
//
// States:
//   open()              — status='open' (default)
//   underReview()       — status='under_review' (RCA submitted)
//   closed()            — status='closed' (resolved incident)
//   rcaRequired()       — incident type that auto-triggers RCA (fall/medication_error etc.)
//   rcaComplete()       — rca_required=true, rca_completed=true, rca_text filled
//   rcaPending()        — rca_required=true, rca_completed=false (blocks close)
//   cmsReportable()     — cms_reportable=true (CMS 42 CFR 460.136 reportable)
//   withInjury()        — injuries_sustained=true with description
//   withWitnesses()     — witnesses JSONB array populated
//   medication()        — incident_type='medication_error' (auto RCA-required type)
//   hospitalization()   — incident_type='hospitalization' (auto RCA-required type)
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\Incident;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        return [
            'tenant_id'              => Tenant::factory(),
            'participant_id'         => Participant::factory(),
            'incident_type'          => 'fall',
            'occurred_at'            => now()->subHours(fake()->numberBetween(1, 72)),
            'location_of_incident'   => fake()->randomElement(['Day Center - Main Hall', 'Bathroom', 'Parking Lot', null]),
            'reported_by_user_id'    => User::factory(),
            'reported_at'            => now()->subMinutes(fake()->numberBetween(5, 120)),
            'description'            => fake()->sentences(3, true),
            'immediate_actions_taken'=> fake()->sentence(),
            'injuries_sustained'     => false,
            'injury_description'     => null,
            'witnesses'              => null,
            'rca_required'           => false, // Set by IncidentService, not factory by default
            'rca_completed'          => false,
            'rca_text'               => null,
            'rca_completed_by_user_id' => null,
            'cms_reportable'         => false,
            'cms_reported_at'        => null,
            'status'                 => 'open',
        ];
    }

    // ── Status states ──────────────────────────────────────────────────────────

    /** Open incident awaiting resolution */
    public function open(): static
    {
        return $this->state(['status' => 'open']);
    }

    /** Incident in 'under_review' — RCA has been submitted */
    public function underReview(): static
    {
        return $this->state([
            'status'        => 'under_review',
            'rca_required'  => true,
            'rca_completed' => true,
            'rca_text'      => fake()->paragraphs(2, true),
        ]);
    }

    /** Closed incident — all requirements met */
    public function closed(): static
    {
        return $this->state(['status' => 'closed']);
    }

    // ── RCA states ────────────────────────────────────────────────────────────

    /** Incident type that requires RCA (fall is also RCA-required; use this for an
     *  explicit medication_error to test the auto-assignment logic in service tests) */
    public function rcaRequired(): static
    {
        return $this->state([
            'incident_type' => 'medication_error',
            'rca_required'  => true,
            'rca_completed' => false,
        ]);
    }

    /** RCA fully submitted — rca_completed=true, text filled, by a user */
    public function rcaComplete(): static
    {
        return $this->state([
            'rca_required'             => true,
            'rca_completed'            => true,
            'rca_text'                 => fake()->paragraphs(3, true),
            'rca_completed_by_user_id' => User::factory(),
        ]);
    }

    /** RCA required but not yet completed — blocks close action */
    public function rcaPending(): static
    {
        return $this->state([
            'rca_required'  => true,
            'rca_completed' => false,
            'rca_text'      => null,
        ]);
    }

    // ── CMS / Injury states ───────────────────────────────────────────────────

    /** CMS-reportable incident (42 CFR 460.136 threshold met) */
    public function cmsReportable(): static
    {
        return $this->state(['cms_reportable' => true]);
    }

    /** Incident that resulted in an injury */
    public function withInjury(): static
    {
        return $this->state([
            'injuries_sustained' => true,
            'injury_description' => 'Laceration to left forearm requiring wound care.',
        ]);
    }

    /** Incident with witness information recorded */
    public function withWitnesses(): static
    {
        return $this->state([
            'witnesses' => [
                ['name' => 'Jane Smith', 'contact' => '555-1234'],
                ['name' => 'Bob Jones',  'contact' => null],
            ],
        ]);
    }

    // ── Type states ───────────────────────────────────────────────────────────

    /** Medication error — auto-requires RCA per CMS 42 CFR 460.136 */
    public function medication(): static
    {
        return $this->state([
            'incident_type' => 'medication_error',
            'rca_required'  => true,
        ]);
    }

    /** Hospitalization — auto-requires RCA per CMS 42 CFR 460.136 */
    public function hospitalization(): static
    {
        return $this->state([
            'incident_type'  => 'hospitalization',
            'rca_required'   => true,
            'cms_reportable' => true,
        ]);
    }
}
