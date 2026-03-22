<?php

// ─── RiskAdjustmentTest ────────────────────────────────────────────────────────
// Feature tests for the Phase 9C RiskAdjustmentController.
//
// Coverage:
//   - test_finance_user_can_access_risk_adjustment_page
//   - test_risk_adjustment_data_endpoint_returns_json
//   - test_participant_endpoint_returns_participant_detail
//   - test_recalculate_creates_risk_score_record
//   - test_non_finance_user_cannot_access_risk_adjustment
//   - test_cross_tenant_participant_returns_404
//   - test_risk_scores_are_tenant_isolated
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\ParticipantRiskScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiskAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function financeUser(): User
    {
        return User::factory()->create(['department' => 'finance']);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_finance_user_can_access_risk_adjustment_page(): void
    {
        $user = $this->financeUser();

        $this->actingAs($user)
            ->get('/billing/risk-adjustment')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/RiskAdjustment')
                ->has('gapSummary')
                ->has('riskScores')
                ->has('year')
            );
    }

    public function test_risk_adjustment_data_endpoint_returns_json(): void
    {
        $user = $this->financeUser();

        $this->actingAs($user)
            ->getJson('/billing/risk-adjustment/data')
            ->assertOk()
            ->assertJsonStructure(['gap_summary', 'risk_scores', 'year']);
    }

    public function test_participant_endpoint_returns_participant_detail(): void
    {
        $user        = $this->financeUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)
            ->getJson("/billing/risk-adjustment/participant/{$participant->id}")
            ->assertOk()
            ->assertJsonStructure([
                'participant',
                'year',
                'risk_score',
                'diagnoses',
                'hcc_gaps',
            ]);
    }

    public function test_recalculate_creates_risk_score_record(): void
    {
        $user        = $this->financeUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)
            ->postJson("/billing/risk-adjustment/recalculate/{$participant->id}", [
                'year' => now()->year,
            ])
            ->assertOk()
            ->assertJsonStructure(['message', 'risk_score']);

        $this->assertDatabaseHas('emr_participant_risk_scores', [
            'participant_id' => $participant->id,
            'payment_year'   => now()->year,
            'score_source'   => 'calculated',
        ]);
    }

    public function test_non_finance_user_cannot_access_risk_adjustment(): void
    {
        $user = User::factory()->create(['department' => 'primary_care']);

        $this->actingAs($user)
            ->getJson('/billing/risk-adjustment/data')
            ->assertForbidden();
    }

    public function test_cross_tenant_participant_returns_404(): void
    {
        $userA       = $this->financeUser();
        $userB       = $this->financeUser(); // different tenant
        $participantB = Participant::factory()->create(['tenant_id' => $userB->tenant_id]);

        $this->actingAs($userA)
            ->getJson("/billing/risk-adjustment/participant/{$participantB->id}")
            ->assertNotFound();
    }

    public function test_risk_scores_are_tenant_isolated(): void
    {
        $userA = $this->financeUser();
        $userB = $this->financeUser(); // different tenant

        $participantB = Participant::factory()->create(['tenant_id' => $userB->tenant_id]);
        ParticipantRiskScore::factory()->create([
            'tenant_id'      => $userB->tenant_id,
            'participant_id' => $participantB->id,
            'payment_year'   => now()->year,
        ]);

        $resp = $this->actingAs($userA)
            ->getJson('/billing/risk-adjustment/data')
            ->assertOk();

        // User A should see zero risk scores from tenant B
        foreach ($resp->json('risk_scores') as $row) {
            $this->assertNotEquals($userB->tenant_id, $row['tenant_id'] ?? null);
        }
    }
}
