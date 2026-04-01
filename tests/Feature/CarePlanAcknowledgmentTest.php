<?php

// ─── CarePlanAcknowledgmentTest ───────────────────────────────────────────────
// Feature tests for W4-5 care plan participant acknowledgment (42 CFR §460.104(d)).
//
// Coverage:
//   - updateParticipation: records offered/response/offered_at on a care plan
//   - updateParticipation: validates participant_response enum values
//   - updateParticipation: cross-tenant returns 403
//   - updateParticipation: non-participant plan returns 404
//   - approve: returns participation_warning=true when not yet offered
//   - approve: returns participation_warning=false when already offered
//   - approve: still approves plan even when participation not documented (soft enforcement)
//   - show: returns participation fields in care plan JSON response
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\CarePlan;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarePlanAcknowledgmentTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept = 'idt'): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeParticipantWithPlan(User $user, array $planOverrides = []): array
    {
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $site->id,
        ]);

        $plan = CarePlan::factory()->create(array_merge([
            'participant_id' => $participant->id,
            'tenant_id'      => $user->tenant_id,
            'status'         => 'draft',
        ], $planOverrides));

        return [$participant, $plan];
    }

    // ── updateParticipation tests ─────────────────────────────────────────────

    /**
     * @test
     * Basic participation record: offered=true, response=accepted.
     */
    public function test_update_participation_records_offered_and_response(): void
    {
        $user = $this->makeUser('idt');
        [$participant, $plan] = $this->makeParticipantWithPlan($user);

        $response = $this->actingAs($user)
            ->patchJson("/participants/{$participant->id}/careplan/{$plan->id}/participation", [
                'participant_offered_participation' => true,
                'participant_response'              => 'accepted',
                'offered_at'                        => '2026-04-01',
            ]);

        $response->assertOk();

        $plan->refresh();
        $this->assertTrue($plan->participant_offered_participation);
        $this->assertSame('accepted', $plan->participant_response);
        $this->assertNotNull($plan->offered_at);
        $this->assertNotNull($plan->offered_by_user_id);
    }

    /**
     * @test
     * Participation can be recorded as declined (participant refused to participate).
     */
    public function test_update_participation_declined_response(): void
    {
        $user = $this->makeUser('enrollment');
        [$participant, $plan] = $this->makeParticipantWithPlan($user);

        $response = $this->actingAs($user)
            ->patchJson("/participants/{$participant->id}/careplan/{$plan->id}/participation", [
                'participant_offered_participation' => true,
                'participant_response'              => 'declined',
            ]);

        $response->assertOk();
        $this->assertSame('declined', $plan->fresh()->participant_response);
    }

    /**
     * @test
     * participant_response must be one of: accepted | declined | no_response.
     * Invalid values return 422.
     */
    public function test_update_participation_rejects_invalid_response_value(): void
    {
        $user = $this->makeUser('idt');
        [$participant, $plan] = $this->makeParticipantWithPlan($user);

        $response = $this->actingAs($user)
            ->patchJson("/participants/{$participant->id}/careplan/{$plan->id}/participation", [
                'participant_offered_participation' => true,
                'participant_response'              => 'maybe', // invalid
            ]);

        $response->assertUnprocessable();
    }

    /**
     * @test
     * Cross-tenant participant → 403.
     */
    public function test_update_participation_cross_tenant_is_forbidden(): void
    {
        $user  = $this->makeUser('idt');
        $other = User::factory()->create(); // different tenant

        $site = Site::factory()->create(['tenant_id' => $other->tenant_id]);
        $participant = Participant::factory()->create([
            'tenant_id' => $other->tenant_id,
            'site_id'   => $site->id,
        ]);
        $plan = CarePlan::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $other->tenant_id,
            'status'         => 'draft',
        ]);

        $response = $this->actingAs($user)
            ->patchJson("/participants/{$participant->id}/careplan/{$plan->id}/participation", [
                'participant_offered_participation' => true,
                'participant_response'              => 'accepted',
            ]);

        $response->assertForbidden();
    }

    /**
     * @test
     * Care plan belonging to a different participant within the same tenant → 404.
     */
    public function test_update_participation_wrong_participant_returns_404(): void
    {
        $user = $this->makeUser('idt');
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $participantA = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $site->id,
        ]);
        $participantB = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $site->id,
        ]);

        // Plan belongs to participantB but we route via participantA
        $planB = CarePlan::factory()->create([
            'participant_id' => $participantB->id,
            'tenant_id'      => $user->tenant_id,
            'status'         => 'draft',
        ]);

        $response = $this->actingAs($user)
            ->patchJson("/participants/{$participantA->id}/careplan/{$planB->id}/participation", [
                'participant_offered_participation' => true,
            ]);

        $response->assertNotFound();
    }

    // ── approve() participation_warning tests ─────────────────────────────────

    /**
     * @test
     * Approving a plan where participation has NOT been offered returns
     * participation_warning=true in the response.
     * 42 CFR §460.104(d): soft-enforcement — plan still approved, but warning surfaced.
     */
    public function test_approve_returns_participation_warning_when_not_offered(): void
    {
        // IDT admin user (idt dept + admin role) can approve plans
        $user = User::factory()->create([
            'department' => 'idt',
            'role'       => 'admin',
        ]);
        [$participant, $plan] = $this->makeParticipantWithPlan($user, ['status' => 'draft']);

        $response = $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/careplan/{$plan->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('participation_warning', true);
    }

    /**
     * @test
     * Approving a plan where participation WAS documented returns
     * participation_warning=false.
     */
    public function test_approve_no_warning_when_participation_documented(): void
    {
        $user = User::factory()->create([
            'department' => 'idt',
            'role'       => 'admin',
        ]);
        [$participant, $plan] = $this->makeParticipantWithPlan($user, [
            'status'                            => 'draft',
            'participant_offered_participation' => true,
            'participant_response'              => 'accepted',
            'offered_at'                        => now()->subDay(),
            'offered_by_user_id'                => null, // will be set by DB default
        ]);

        $response = $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/careplan/{$plan->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('participation_warning', false);
    }

    /**
     * @test
     * Plan is approved (status becomes 'active') even without participation documented.
     * The soft-enforcement only produces a warning, not a block.
     */
    public function test_approve_succeeds_without_participation_documented(): void
    {
        $user = User::factory()->create([
            'department' => 'idt',
            'role'       => 'admin',
        ]);
        [$participant, $plan] = $this->makeParticipantWithPlan($user, ['status' => 'draft']);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/careplan/{$plan->id}/approve")
            ->assertOk();

        $this->assertSame('active', $plan->fresh()->status);
    }

    /**
     * @test
     * The care plan show endpoint includes participation fields in the JSON response.
     */
    public function test_care_plan_show_includes_participation_fields(): void
    {
        $user = $this->makeUser('primary_care');
        [$participant, $plan] = $this->makeParticipantWithPlan($user, [
            'status'                            => 'draft',
            'participant_offered_participation' => true,
            'participant_response'              => 'accepted',
            'offered_at'                        => '2026-03-15',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/careplan/{$plan->id}");

        $response->assertOk();
        $response->assertJsonPath('participant_offered_participation', true);
        $response->assertJsonPath('participant_response', 'accepted');
        $this->assertNotNull($response->json('offered_at'));
    }
}
