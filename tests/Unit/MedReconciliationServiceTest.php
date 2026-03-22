<?php

// ─── MedReconciliationServiceTest ─────────────────────────────────────────────
// Unit tests for MedReconciliationService covering:
//   - generateComparison() diff logic (matched/priorOnly/currentOnly)
//   - applyDecisions() action execution (keep/add/discontinue/modify)
//   - providerApproval() state transition
//   - assertNotLocked() guard (LogicException on approved record)
//   - Idempotency of startReconciliation()
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\MedReconciliation;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\User;
use App\Services\MedReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class MedReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private MedReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MedReconciliationService();
    }

    private function makeUser(string $dept = 'primary_care'): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeParticipant(User $user): Participant
    {
        return Participant::factory()->create(['tenant_id' => $user->tenant_id]);
    }

    // ── startReconciliation ───────────────────────────────────────────────────

    public function test_start_creates_new_in_progress_record(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $rec = $this->service->startReconciliation($participant, 'discharge_summary', 'post_hospital', $user);

        $this->assertEquals('in_progress', $rec->status);
        $this->assertEquals('discharge_summary', $rec->prior_source);
        $this->assertEquals('post_hospital', $rec->reconciliation_type);
        $this->assertDatabaseHas('emr_med_reconciliations', ['id' => $rec->id, 'status' => 'in_progress']);
    }

    public function test_start_returns_existing_active_record_idempotently(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $first  = $this->service->startReconciliation($participant, 'discharge_summary', 'routine', $user);
        $second = $this->service->startReconciliation($participant, 'pharmacy_printout', 'enrollment', $user);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, MedReconciliation::where('participant_id', $participant->id)->count());
    }

    // ── generateComparison ────────────────────────────────────────────────────

    public function test_comparison_correctly_identifies_matched_medications(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Medication::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $participant->tenant_id,
            'drug_name'      => 'Lisinopril',
            'status'         => 'active',
        ]);

        $rec = MedReconciliation::factory()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'                => 'in_progress',
            'prior_medications'     => [
                ['drug_name' => 'Lisinopril', 'dose' => '10mg', 'dose_unit' => 'mg', 'frequency' => 'daily', 'route' => 'oral', 'prescriber' => 'Dr. A', 'notes' => null],
            ],
        ]);

        $result = $this->service->generateComparison($rec);

        $this->assertCount(1, $result['matched']);
        $this->assertCount(0, $result['priorOnly']);
        $this->assertCount(0, $result['currentOnly']);
        $this->assertEquals('keep', $result['matched'][0]['recommendation']);
    }

    public function test_comparison_case_insensitive_matching(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Medication::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $participant->tenant_id,
            'drug_name'      => 'METFORMIN',  // uppercase in DB
            'status'         => 'active',
        ]);

        $rec = MedReconciliation::factory()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'                => 'in_progress',
            'prior_medications'     => [
                ['drug_name' => 'metformin', 'dose' => '500mg', 'dose_unit' => 'mg', 'frequency' => 'twice_daily', 'route' => 'oral', 'prescriber' => 'Dr. B', 'notes' => null],
            ],
        ]);

        $result = $this->service->generateComparison($rec);

        // Should match despite case difference
        $this->assertCount(1, $result['matched']);
        $this->assertCount(0, $result['priorOnly']);
    }

    public function test_comparison_identifies_prior_only_medications(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        // No active medications in emr_medications
        $rec = MedReconciliation::factory()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'                => 'in_progress',
            'prior_medications'     => [
                ['drug_name' => 'Warfarin', 'dose' => '5mg', 'dose_unit' => 'mg', 'frequency' => 'daily', 'route' => 'oral', 'prescriber' => 'Dr. C', 'notes' => null],
            ],
        ]);

        $result = $this->service->generateComparison($rec);

        $this->assertCount(0, $result['matched']);
        $this->assertCount(1, $result['priorOnly']);
        $this->assertEquals('add_or_ignore', $result['priorOnly'][0]['recommendation']);
    }

    public function test_comparison_identifies_current_only_medications(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        Medication::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $participant->tenant_id,
            'drug_name'      => 'Amlodipine',
            'status'         => 'active',
        ]);

        // Prior list does NOT include Amlodipine
        $rec = MedReconciliation::factory()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'                => 'in_progress',
            'prior_medications'     => [],
        ]);

        $result = $this->service->generateComparison($rec);

        $this->assertCount(1, $result['currentOnly']);
        $this->assertEquals('keep_or_discontinue', $result['currentOnly'][0]['recommendation']);
    }

    // ── applyDecisions ────────────────────────────────────────────────────────

    public function test_apply_decisions_sets_status_to_decisions_made(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $rec         = MedReconciliation::factory()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'                => 'in_progress',
        ]);

        $this->service->applyDecisions($rec, [
            ['drug_name' => 'TestDrug', 'action' => 'keep', 'medication_id' => null, 'notes' => null],
        ], $user);

        $this->assertEquals('decisions_made', $rec->fresh()->status);
    }

    public function test_apply_decisions_modify_updates_medication_fields(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);

        $med = Medication::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $participant->tenant_id,
            'drug_name'      => 'Atorvastatin',
            'dose'           => '10',
            'frequency'      => 'daily',
            'status'         => 'active',
        ]);

        $rec = MedReconciliation::factory()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'                => 'in_progress',
        ]);

        $this->service->applyDecisions($rec, [
            [
                'drug_name'     => 'Atorvastatin',
                'medication_id' => $med->id,
                'action'        => 'modify',
                'new_dose'      => '20',
                'new_frequency' => 'BID',
                'notes'         => null,
            ],
        ], $user);

        $updated = $med->fresh();
        // dose is stored as DECIMAL — compare numerically to avoid '20' vs '20.000'
        $this->assertEquals(20.0, (float) $updated->dose);
        $this->assertEquals('BID', $updated->frequency);
    }

    // ── providerApproval ──────────────────────────────────────────────────────

    public function test_provider_approval_sets_status_to_approved(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $rec         = MedReconciliation::factory()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'                => 'decisions_made',
        ]);

        $this->service->providerApproval($rec, $user);

        $fresh = $rec->fresh();
        $this->assertEquals('approved', $fresh->status);
        $this->assertEquals($user->id, $fresh->approved_by_user_id);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_provider_approval_requires_decisions_made_status(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $rec         = MedReconciliation::factory()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'                => 'in_progress',  // not decisions_made
        ]);

        $this->expectException(LogicException::class);
        $this->service->providerApproval($rec, $user);
    }

    // ── Immutability ──────────────────────────────────────────────────────────

    public function test_adding_prior_meds_to_locked_record_throws_logic_exception(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $rec         = MedReconciliation::factory()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'                => 'approved',  // locked
            'reconciled_at'         => now(),
            'approved_at'           => now(),
        ]);

        $this->expectException(LogicException::class);
        $this->service->addPriorMedications($rec, [
            ['drug_name' => 'SomeDrug', 'dose' => '5mg', 'dose_unit' => 'mg', 'frequency' => 'daily', 'route' => 'oral'],
        ]);
    }

    public function test_applying_decisions_to_locked_record_throws_logic_exception(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeParticipant($user);
        $rec         = MedReconciliation::factory()->create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'reconciled_by_user_id' => $user->id,
            'status'                => 'approved',  // locked
            'reconciled_at'         => now(),
            'approved_at'           => now(),
        ]);

        $this->expectException(LogicException::class);
        $this->service->applyDecisions($rec, [
            ['drug_name' => 'Test', 'action' => 'keep', 'medication_id' => null],
        ], $user);
    }
}
