<?php

// ─── PdeTest ──────────────────────────────────────────────────────────────────
// Feature tests for the Phase 9B PdeController.
//
// Coverage:
//   - test_finance_user_can_list_pde_records
//   - test_troop_summary_returns_participant_accumulations
//   - test_pde_records_are_tenant_isolated
//   - test_non_finance_user_cannot_access_pde
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\PdeRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdeTest extends TestCase
{
    use RefreshDatabase;

    private function financeUser(): User
    {
        return User::factory()->create(['department' => 'finance']);
    }

    private function makePde(User $user, array $attrs = []): PdeRecord
    {
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        return PdeRecord::factory()->create(array_merge([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
        ], $attrs));
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_finance_user_can_list_pde_records(): void
    {
        $user = $this->financeUser();
        $this->makePde($user);
        $this->makePde($user);

        $this->actingAs($user)
            ->getJson('/billing/pde')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_troop_summary_returns_participant_accumulations(): void
    {
        $user        = $this->financeUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        // Seed two PDE records for the same participant to build TrOOP total.
        // Pin dispense_date to current year so the troop endpoint's year filter includes both.
        PdeRecord::factory()->count(2)->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'troop_amount'   => 1200.00,
            'dispense_date'  => now()->format('Y-m-d'),
        ]);

        $resp = $this->actingAs($user)
            ->getJson('/billing/pde/troop')
            ->assertOk()
            ->assertJsonStructure(['summary', 'threshold']);

        $summary = $resp->json('summary');
        $this->assertNotEmpty($summary);

        // The participant should appear with accumulated TrOOP
        $found = collect($summary)->firstWhere('participant_id', $participant->id);
        $this->assertNotNull($found);
        $this->assertEquals(2400.0, (float) $found['ytd_troop']);
    }

    public function test_pde_records_are_tenant_isolated(): void
    {
        $userA = $this->financeUser();
        $userB = $this->financeUser(); // different tenant

        $participantB = Participant::factory()->create(['tenant_id' => $userB->tenant_id]);
        PdeRecord::factory()->create([
            'tenant_id'      => $userB->tenant_id,
            'participant_id' => $participantB->id,
        ]);

        // User A should see zero records from tenant B
        $resp = $this->actingAs($userA)
            ->getJson('/billing/pde')
            ->assertOk();

        foreach ($resp->json('data') as $row) {
            $this->assertNotEquals($userB->tenant_id, $row['tenant_id'] ?? null);
        }
    }

    public function test_non_finance_user_cannot_access_pde(): void
    {
        $nurse = User::factory()->create(['department' => 'primary_care']);

        $this->actingAs($nurse)
            ->getJson('/billing/pde')
            ->assertForbidden();
    }
}
