<?php

// ─── MedReconciliationFactory ──────────────────────────────────────────────────
// Generates MedReconciliation records for testing.
// Default state: in_progress (wizard open, prior meds entry phase).
//
// States:
//   inProgress()      — default, wizard open
//   decisionsMade()   — clinician applied decisions, awaiting provider approval
//   approved()        — fully locked (immutable), signed by provider
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Factories;

use App\Models\MedReconciliation;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MedReconciliationFactory extends Factory
{
    protected $model = MedReconciliation::class;

    public function definition(): array
    {
        // Use lazy factory references (no ->create()) so Laravel only instantiates
        // related models when not overridden by the test caller.
        return [
            'participant_id'         => Participant::factory(),
            'tenant_id'              => Tenant::factory(),
            'reconciled_by_user_id'  => User::factory(),
            'reconciling_department' => 'primary_care',
            'reconciliation_type'    => $this->faker->randomElement(MedReconciliation::TYPES),
            'prior_source'           => $this->faker->randomElement(MedReconciliation::SOURCES),
            'prior_medications'      => [],
            'reconciled_medications' => [],
            'status'                 => 'in_progress',
            'has_discrepancies'      => false,
        ];
    }

    // ── States ────────────────────────────────────────────────────────────────

    /**
     * Wizard is open — prior meds entry phase (default).
     */
    public function inProgress(): static
    {
        return $this->state(['status' => 'in_progress']);
    }

    /**
     * Decisions have been applied — awaiting provider sign-off.
     */
    public function decisionsMade(): static
    {
        return $this->state([
            'status'                 => 'decisions_made',
            'reconciled_medications' => [
                ['drug_name' => 'Lisinopril', 'medication_id' => null, 'action' => 'keep', 'notes' => null],
            ],
            'changes_made'           => [
                ['drug_name' => 'Lisinopril', 'action' => 'keep', 'notes' => null, 'medication_id' => null],
            ],
        ]);
    }

    /**
     * Provider-approved and locked — simulates a completed past reconciliation.
     * The record is immutable in this state (isLocked() returns true).
     */
    public function approved(): static
    {
        return $this->state(function () {
            return [
                'status'       => 'approved',
                'approved_at'  => now(),
                'reconciled_at'=> now(),
            ];
        });
    }
}
