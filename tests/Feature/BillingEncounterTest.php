<?php

// ─── BillingEncounterTest ─────────────────────────────────────────────────────
// Feature tests for the Phase 9B BillingEncounterController.
//
// Coverage:
//   - test_finance_user_can_list_encounters
//   - test_encounter_index_filters_by_submission_status
//   - test_encounter_index_filters_by_service_type
//   - test_finance_user_can_update_pending_encounter
//   - test_cannot_update_submitted_encounter
//   - test_create_837p_batch_from_pending_encounters
//   - test_batch_creation_requires_finance_role
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\EncounterLog;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingEncounterTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function financeUser(): User
    {
        return User::factory()->create(['department' => 'finance']);
    }

    private function makeEncounter(User $user, array $attrs = []): EncounterLog
    {
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        return EncounterLog::factory()->create(array_merge([
            'tenant_id'          => $user->tenant_id,
            'participant_id'     => $participant->id,
            'submission_status'  => 'pending',
            'billing_provider_npi' => '1234567890',
            'procedure_code'     => '99213',
            'diagnosis_codes'    => ['E119'],
            'charge_amount'      => 150.00,
        ], $attrs));
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_finance_user_can_list_encounters(): void
    {
        $user = $this->financeUser();
        $this->makeEncounter($user);
        $this->makeEncounter($user);

        $this->actingAs($user)
            ->getJson('/billing/encounters')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_encounter_index_filters_by_submission_status(): void
    {
        $user = $this->financeUser();
        $this->makeEncounter($user, ['submission_status' => 'pending']);
        $this->makeEncounter($user, ['submission_status' => 'accepted']);

        $resp = $this->actingAs($user)
            ->getJson('/billing/encounters?submission_status=pending')
            ->assertOk();

        $data = $resp->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $row) {
            $this->assertEquals('pending', $row['submission_status']);
        }
    }

    public function test_encounter_index_filters_by_service_type(): void
    {
        $user = $this->financeUser();
        $this->makeEncounter($user, ['service_type' => 'primary_care']);
        $this->makeEncounter($user, ['service_type' => 'therapy']);

        $resp = $this->actingAs($user)
            ->getJson('/billing/encounters?service_type=primary_care')
            ->assertOk();

        $data = $resp->json('data');
        foreach ($data as $row) {
            $this->assertEquals('primary_care', $row['service_type']);
        }
    }

    public function test_finance_user_can_update_pending_encounter(): void
    {
        $user = $this->financeUser();
        $enc  = $this->makeEncounter($user, ['submission_status' => 'pending']);

        $this->actingAs($user)
            ->patchJson("/billing/encounters/{$enc->id}", [
                'billing_provider_npi' => '1111111111',
                'charge_amount'        => 200.00,
                'diagnosis_codes'      => ['I50.9', 'E119'],
            ])
            ->assertOk()
            ->assertJsonPath('billing_provider_npi', '1111111111');
    }

    public function test_cannot_update_submitted_encounter(): void
    {
        $user = $this->financeUser();
        $enc  = $this->makeEncounter($user, ['submission_status' => 'submitted']);

        $this->actingAs($user)
            ->patchJson("/billing/encounters/{$enc->id}", [
                'charge_amount' => 999.00,
            ])
            ->assertStatus(409);
    }

    public function test_create_837p_batch_from_pending_encounters(): void
    {
        $user = $this->financeUser();
        $enc1 = $this->makeEncounter($user);
        $enc2 = $this->makeEncounter($user);

        $resp = $this->actingAs($user)
            ->postJson('/billing/encounters/batch', [
                'encounter_ids' => [$enc1->id, $enc2->id],
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'batch_type', 'record_count', 'status', 'file_name']);

        // file_content must never appear in JSON responses
        $this->assertArrayNotHasKey('file_content', $resp->json());
    }

    public function test_batch_creation_requires_finance_role(): void
    {
        $nurse = User::factory()->create(['department' => 'primary_care']);
        $enc   = $this->makeEncounter(
            User::factory()->create(['department' => 'finance', 'tenant_id' => $nurse->tenant_id]),
        );

        $this->actingAs($nurse)
            ->postJson('/billing/encounters/batch', ['encounter_ids' => [$enc->id]])
            ->assertForbidden();
    }
}
