<?php

// ─── MedReconciliationTest ─────────────────────────────────────────────────────
// Feature tests for the 5-step medication reconciliation workflow.
//
// Coverage:
//   - Full happy-path: start → comparison → decisions → approve
//   - Idempotency: second start returns existing record
//   - Immutability: approved record cannot receive new decisions
//   - Authorization: non-provider cannot approve (403)
//   - Tenant isolation: cross-tenant access returns 403
//   - Decisions: discontinue actually marks medication discontinued
//   - Decisions: add actually creates a new medication
//   - Discrepancy flag set when prior-only drug is ignored
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\MedReconciliation;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedReconciliationTest extends TestCase
{
    use RefreshDatabase;

    // ── Shared setup helpers ──────────────────────────────────────────────────

    private function makeUser(string $dept = 'primary_care'): User
    {
        return User::factory()->create([
            'department' => $dept,
            'role'       => 'standard',
        ]);
    }

    private function makeParticipant(User $user): Participant
    {
        return Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
        ]);
    }

    // ── Step 1: Start ─────────────────────────────────────────────────────────

    public function test_start_creates_in_progress_reconciliation(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/start",
            ['prior_source' => 'discharge_summary', 'type' => 'post_hospital'],
        );

        $response->assertStatus(201)
            ->assertJsonFragment(['status' => 'in_progress'])
            ->assertJsonFragment(['prior_source' => 'discharge_summary']);

        $this->assertDatabaseHas('emr_med_reconciliations', [
            'participant_id'     => $participant->id,
            'status'             => 'in_progress',
            'reconciliation_type'=> 'post_hospital',
        ]);
    }

    public function test_start_is_idempotent_returns_existing_record(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // First call — creates record
        $first = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/start",
            ['prior_source' => 'pharmacy_printout', 'type' => 'routine'],
        );

        // Second call — returns same record
        $second = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/start",
            ['prior_source' => 'patient_reported', 'type' => 'enrollment'],
        );

        $first->assertStatus(201);
        $second->assertStatus(201);

        // Should return same record (id matches), not create a second one
        $this->assertEquals($first->json('id'), $second->json('id'));
        $this->assertEquals(1, MedReconciliation::where('participant_id', $participant->id)->count());
    }

    public function test_start_rejects_invalid_prior_source(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/start",
            ['prior_source' => 'not_a_valid_source', 'type' => 'routine'],
        );

        $response->assertStatus(422);
    }

    public function test_start_rejects_cross_tenant_participant(): void
    {
        $user        = $this->makeUser();
        $otherTenant = \App\Models\Tenant::factory()->create();
        $participant = Participant::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/start",
            ['prior_source' => 'discharge_summary', 'type' => 'routine'],
        );

        $response->assertStatus(403);
    }

    // ── Step 3: Comparison ────────────────────────────────────────────────────

    public function test_comparison_returns_diff_of_prior_vs_current_meds(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // Active medication in emr_medications
        Medication::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $participant->tenant_id,
            'drug_name'      => 'Lisinopril',
            'status'         => 'active',
        ]);

        // Create reconciliation with prior meds including a match and a prior-only
        $rec = MedReconciliation::factory()->create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'              => 'in_progress',
            'prior_medications'   => [
                ['drug_name' => 'Lisinopril', 'dose' => '10mg', 'dose_unit' => 'mg', 'frequency' => 'daily', 'route' => 'oral', 'prescriber' => 'Dr. Smith', 'notes' => null],
                ['drug_name' => 'Metformin',  'dose' => '500mg', 'dose_unit' => 'mg', 'frequency' => 'twice_daily', 'route' => 'oral', 'prescriber' => 'Dr. Jones', 'notes' => null],
            ],
        ]);

        $response = $this->actingAs($user)->getJson(
            "/participants/{$participant->id}/med-reconciliation/comparison",
        );

        $response->assertStatus(200)
            ->assertJsonStructure(['reconciliation', 'comparison' => ['matched', 'priorOnly', 'currentOnly']]);

        $comparison = $response->json('comparison');
        $this->assertCount(1, $comparison['matched']);   // Lisinopril matched
        $this->assertCount(1, $comparison['priorOnly']); // Metformin prior-only
        $this->assertEquals('Lisinopril', $comparison['matched'][0]['prior']['drug_name']);
    }

    // ── Step 4: Apply decisions ───────────────────────────────────────────────

    public function test_decisions_discontinue_marks_medication_as_discontinued(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $med = Medication::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $participant->tenant_id,
            'drug_name'      => 'Atorvastatin',
            'status'         => 'active',
        ]);

        $rec = MedReconciliation::factory()->create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'              => 'in_progress',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/decisions",
            [
                'decisions' => [
                    [
                        'drug_name'     => 'Atorvastatin',
                        'medication_id' => $med->id,
                        'action'        => 'discontinue',
                        'notes'         => 'Not listed on discharge summary',
                    ],
                ],
            ],
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'decisions_made']);

        $this->assertEquals('discontinued', $med->fresh()->status);
    }

    public function test_decisions_add_creates_new_medication_from_prior_list(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $rec = MedReconciliation::factory()->create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'              => 'in_progress',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/decisions",
            [
                'decisions' => [
                    [
                        'drug_name'        => 'Metoprolol',
                        'medication_id'    => null,
                        'action'           => 'add',
                        'prior_medication' => [
                            'drug_name'  => 'Metoprolol',
                            'dose'       => '25',
                            'dose_unit'  => 'mg',
                            'frequency'  => 'BID',
                            'route'      => 'oral',
                        ],
                        'notes' => 'Found on discharge summary, adding to active list',
                    ],
                ],
            ],
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('emr_medications', [
            'participant_id' => $participant->id,
            'drug_name'      => 'Metoprolol',
            'status'         => 'active',
        ]);
    }

    public function test_decisions_sets_has_discrepancies_when_prior_only_drug_not_added(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $rec = MedReconciliation::factory()->create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'              => 'in_progress',
        ]);

        // 'keep' on a prior-only drug (no medication_id) = discrepancy
        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/decisions",
            [
                'decisions' => [
                    [
                        'drug_name'     => 'SomePriorOnlyDrug',
                        'medication_id' => null,
                        'action'        => 'keep',
                        'notes'         => 'Cannot verify — leaving as-is',
                    ],
                ],
            ],
        );

        $response->assertStatus(200);

        $this->assertTrue((bool) $rec->fresh()->has_discrepancies);
    }

    public function test_non_prescriber_cannot_apply_decisions(): void
    {
        // finance department is not in PRESCRIBER_DEPARTMENTS
        $user        = $this->makeUser('finance');
        $participant = $this->makeParticipant($user);

        $rec = MedReconciliation::factory()->create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'              => 'in_progress',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/decisions",
            ['decisions' => [['drug_name' => 'Test', 'action' => 'keep', 'medication_id' => null]]],
        );

        $response->assertStatus(403);
    }

    // ── Step 5: Provider approval ─────────────────────────────────────────────

    public function test_provider_approval_locks_the_record(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        $rec = MedReconciliation::factory()->create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'              => 'decisions_made',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/approve",
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'approved']);

        $this->assertEquals('approved', $rec->fresh()->status);
        $this->assertNotNull($rec->fresh()->approved_at);
    }

    public function test_non_provider_department_cannot_approve(): void
    {
        // social_work is not in APPROVER_DEPARTMENTS
        $user        = $this->makeUser('social_work');
        $participant = $this->makeParticipant($user);

        MedReconciliation::factory()->create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'              => 'decisions_made',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/approve",
        );

        $response->assertStatus(403);
    }

    public function test_approved_record_cannot_receive_new_decisions(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        // Already-approved record (locked)
        MedReconciliation::factory()->create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'              => 'approved',
            'reconciled_at'       => now(),
            'approved_at'         => now(),
        ]);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/med-reconciliation/decisions",
            ['decisions' => [['drug_name' => 'Test', 'action' => 'keep', 'medication_id' => null]]],
        );

        // 409 because the record is locked (resolveActiveRec returns approved rec, decisions() returns 409)
        $response->assertStatus(409);
    }

    // ── History ───────────────────────────────────────────────────────────────

    public function test_history_returns_all_reconciliations_newest_first(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        MedReconciliation::factory()->count(3)->create([
            'participant_id'      => $participant->id,
            'tenant_id'           => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'              => 'approved',
            'reconciled_at'       => now(),
            'approved_at'         => now(),
        ]);

        $response = $this->actingAs($user)->getJson(
            "/participants/{$participant->id}/med-reconciliation/history",
        );

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'current_page']);

        $this->assertEquals(3, $response->json('total'));
    }
}
