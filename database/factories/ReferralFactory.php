<?php

// ─── ReferralFactory ────────────────────────────────────────────────────────
// Generates test referral records across the CMS enrollment pipeline.
//
// Default state: active referral in 'new' status.
//
// Available states:
//   intakeScheduled()    — referral has an intake appointment booked
//   intakeInProgress()   — intake is currently being conducted
//   intakeComplete()     — intake finished; ready for eligibility review
//   eligibilityPending() — CMS eligibility review in progress
//   pendingEnrollment()  — approved, awaiting enrollment date
//   enrolled()           — participant fully enrolled in PACE
//   declined()           — referral declined (decline_reason set)
//   withdrawn()          — participant withdrew (withdrawn_reason set)
//
// Usage:
//   Referral::factory()->create()                         → new referral
//   Referral::factory()->enrolled()->create([$overrides]) → terminal enrolled
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\Referral;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReferralFactory extends Factory
{
    protected $model = Referral::class;

    public function definition(): array
    {
        return [
            'tenant_id'           => Tenant::factory(),
            'site_id'             => Site::factory(),
            'referred_by_name'    => $this->faker->name(),
            'referred_by_org'     => $this->faker->optional(0.7)->company(),
            'referral_date'       => $this->faker->dateTimeBetween('-90 days', 'now')->format('Y-m-d'),
            'referral_source'     => $this->faker->randomElement(Referral::SOURCES),
            'participant_id'      => null,
            'assigned_to_user_id' => null,
            'status'              => 'new',
            'decline_reason'      => null,
            'withdrawn_reason'    => null,
            'notes'               => $this->faker->optional(0.4)->sentence(),
            'created_by_user_id'  => User::factory(),
        ];
    }

    // ── Pipeline States ───────────────────────────────────────────────────────

    /** Referral has a scheduled intake appointment. */
    public function intakeScheduled(): static
    {
        return $this->state(['status' => 'intake_scheduled']);
    }

    /** Intake is currently being conducted. */
    public function intakeInProgress(): static
    {
        return $this->state(['status' => 'intake_in_progress']);
    }

    /** Intake complete; participant record may now be linked. */
    public function intakeComplete(): static
    {
        return $this->state(['status' => 'intake_complete']);
    }

    /** Eligibility review submitted to CMS — awaiting determination. */
    public function eligibilityPending(): static
    {
        return $this->state(['status' => 'eligibility_pending']);
    }

    /** CMS-approved; awaiting enrollment effective date. */
    public function pendingEnrollment(): static
    {
        return $this->state(['status' => 'pending_enrollment']);
    }

    /** Terminal: participant fully enrolled in PACE. */
    public function enrolled(): static
    {
        return $this->state(['status' => 'enrolled']);
    }

    /** Terminal: referral declined (reason required by business rule). */
    public function declined(): static
    {
        return $this->state([
            'status'         => 'declined',
            'decline_reason' => $this->faker->randomElement([
                'not_eligible_medicaid',
                'not_eligible_medicare',
                'outside_service_area',
                'declined_by_participant',
                'medical_ineligibility',
                'other',
            ]),
        ]);
    }

    /** Terminal: participant withdrew from the enrollment process. */
    public function withdrawn(): static
    {
        return $this->state([
            'status'           => 'withdrawn',
            'withdrawn_reason' => $this->faker->sentence(),
        ]);
    }

    // ── Assignment helper ─────────────────────────────────────────────────────

    /** Referral assigned to an enrollment staff member. */
    public function assignedTo(User $user): static
    {
        return $this->state(['assigned_to_user_id' => $user->id]);
    }
}
