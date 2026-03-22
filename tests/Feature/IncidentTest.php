<?php

// ─── IncidentTest ───────────────────────────────────────────────────────────────
// Feature tests for Phase 6B incident reporting and RCA workflow.
//
// Coverage:
//   - Index: paginated list, optional status filter
//   - Store: any authenticated user can create; RCA auto-set for required types;
//            cross-tenant participant is rejected (403)
//   - Show: tenant-scoped; cross-tenant returns 403
//   - Update: QA admin only; closed incident returns 409; non-QA returns 403
//   - RCA: QA/primary_care can submit; non-authorized dept returns 403;
//          closed incident returns 409; rca_text too short returns 422
//   - Close: QA admin can close non-RCA incident; RCA-pending blocks close (409);
//             non-QA returns 403
//   - rca_required auto-set for CMS-mandated types (fall, medication_error, etc.)
//   - Tenant isolation: all cross-tenant accesses return 403
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept = 'primary_care'): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeQaUser(): User
    {
        return $this->makeUser('qa_compliance');
    }

    private function makeParticipant(User $user): Participant
    {
        return Participant::factory()->create(['tenant_id' => $user->tenant_id]);
    }

    private function basePayload(User $user, array $overrides = []): array
    {
        $participant = $this->makeParticipant($user);
        return array_merge([
            'participant_id'   => $participant->id,
            'incident_type'    => 'fall',
            'occurred_at'      => now()->subHour()->toDateTimeString(),
            'description'      => 'Participant was found on the floor in the hallway near the dining room.',
            'injuries_sustained' => false,
        ], $overrides);
    }

    private function makeIncident(User $user, array $overrides = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => Participant::factory()->create(['tenant_id' => $user->tenant_id])->id,
        ], $overrides));
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_incidents(): void
    {
        $user = $this->makeUser();
        $this->makeIncident($user);
        $this->makeIncident($user);

        $response = $this->actingAs($user)->getJson('/qa/incidents');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'total', 'per_page']);
    }

    public function test_index_filters_by_status(): void
    {
        $user = $this->makeUser();
        $this->makeIncident($user, ['status' => 'open']);
        $this->makeIncident($user, ['status' => 'closed']);

        $response = $this->actingAs($user)->getJson('/qa/incidents?status=open');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $incident) {
            $this->assertEquals('open', $incident['status']);
        }
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_any_authenticated_user_can_create_incident(): void
    {
        $user    = $this->makeUser('dietary'); // non-clinical dept
        $payload = $this->basePayload($user);

        $response = $this->actingAs($user)->postJson('/qa/incidents', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment(['incident_type' => 'fall', 'status' => 'open']);
    }

    public function test_store_persists_to_database(): void
    {
        $user    = $this->makeUser();
        $payload = $this->basePayload($user);

        $this->actingAs($user)->postJson('/qa/incidents', $payload);

        $this->assertDatabaseHas('emr_incidents', [
            'incident_type' => 'fall',
            'status'        => 'open',
            'tenant_id'     => $user->tenant_id,
        ]);
    }

    public function test_rca_required_auto_set_for_fall(): void
    {
        $user    = $this->makeUser();
        $payload = $this->basePayload($user, ['incident_type' => 'fall']);

        $response = $this->actingAs($user)->postJson('/qa/incidents', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment(['rca_required' => true]);
    }

    public function test_rca_required_auto_set_for_medication_error(): void
    {
        $user    = $this->makeUser();
        $payload = $this->basePayload($user, ['incident_type' => 'medication_error']);

        $response = $this->actingAs($user)->postJson('/qa/incidents', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment(['rca_required' => true]);
    }

    public function test_rca_not_required_for_non_mandated_type(): void
    {
        $user    = $this->makeUser();
        $payload = $this->basePayload($user, ['incident_type' => 'behavioral']);

        $response = $this->actingAs($user)->postJson('/qa/incidents', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment(['rca_required' => false]);
    }

    public function test_store_rejects_cross_tenant_participant(): void
    {
        $user        = $this->makeUser();
        $otherUser   = User::factory()->create(); // different tenant
        $participant = Participant::factory()->create(['tenant_id' => $otherUser->tenant_id]);

        $response = $this->actingAs($user)->postJson('/qa/incidents', [
            'participant_id'   => $participant->id,
            'incident_type'    => 'fall',
            'occurred_at'      => now()->subHour()->toDateTimeString(),
            'description'      => 'Cross-tenant attempt to report incident.',
            'injuries_sustained' => false,
        ]);

        $response->assertStatus(403);
    }

    public function test_store_requires_description_min_10_chars(): void
    {
        $user    = $this->makeUser();
        $payload = $this->basePayload($user, ['description' => 'Short']);

        $response = $this->actingAs($user)->postJson('/qa/incidents', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_store_requires_injury_description_when_injuries_sustained(): void
    {
        $user    = $this->makeUser();
        $payload = $this->basePayload($user, [
            'injuries_sustained' => true,
            'injury_description' => null,
        ]);

        $response = $this->actingAs($user)->postJson('/qa/incidents', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['injury_description']);
    }

    public function test_store_accepts_injury_description_when_injuries_sustained(): void
    {
        $user    = $this->makeUser();
        $payload = $this->basePayload($user, [
            'injuries_sustained' => true,
            'injury_description' => 'Laceration to left forearm, wound care applied.',
        ]);

        $response = $this->actingAs($user)->postJson('/qa/incidents', $payload);

        $response->assertStatus(201);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_incident_with_relations(): void
    {
        $user     = $this->makeUser();
        $incident = $this->makeIncident($user);

        $response = $this->actingAs($user)->getJson("/qa/incidents/{$incident->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $incident->id]);
    }

    public function test_show_rejects_cross_tenant_access(): void
    {
        $user      = $this->makeUser();
        $otherUser = User::factory()->create();
        $incident  = $this->makeIncident($otherUser);

        $response = $this->actingAs($user)->getJson("/qa/incidents/{$incident->id}");

        $response->assertStatus(403);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_qa_admin_can_update_incident(): void
    {
        $user     = $this->makeQaUser();
        $incident = $this->makeIncident($user);

        $response = $this->actingAs($user)->putJson("/qa/incidents/{$incident->id}", [
            'location_of_incident' => 'Updated: Hallway near exit',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Updated: Hallway near exit', $incident->fresh()->location_of_incident);
    }

    public function test_non_qa_cannot_update_incident(): void
    {
        $user     = $this->makeUser('social_work');
        $incident = $this->makeIncident($user);

        $response = $this->actingAs($user)->putJson("/qa/incidents/{$incident->id}", [
            'location_of_incident' => 'Should fail',
        ]);

        $response->assertStatus(403);
    }

    public function test_update_rejects_closed_incident(): void
    {
        $user     = $this->makeQaUser();
        $incident = $this->makeIncident($user, ['status' => 'closed']);

        $response = $this->actingAs($user)->putJson("/qa/incidents/{$incident->id}", [
            'location_of_incident' => 'Cannot edit closed',
        ]);

        $response->assertStatus(409);
    }

    // ── RCA ───────────────────────────────────────────────────────────────────

    public function test_qa_user_can_submit_rca(): void
    {
        $user     = $this->makeQaUser();
        $incident = $this->makeIncident($user, ['rca_required' => true, 'rca_completed' => false]);

        $response = $this->actingAs($user)->postJson("/qa/incidents/{$incident->id}/rca", [
            'rca_text' => str_repeat('This is the root cause analysis text. ', 5),
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['rca_completed' => true, 'status' => 'under_review']);
    }

    public function test_primary_care_can_submit_rca(): void
    {
        $user     = $this->makeUser('primary_care');
        $incident = $this->makeIncident($user, ['rca_required' => true, 'rca_completed' => false]);

        $response = $this->actingAs($user)->postJson("/qa/incidents/{$incident->id}/rca", [
            'rca_text' => str_repeat('Detailed root cause analysis findings and recommendations. ', 3),
        ]);

        $response->assertStatus(200);
    }

    public function test_non_clinical_cannot_submit_rca(): void
    {
        $user     = $this->makeUser('dietary');
        $incident = $this->makeIncident($user);

        $response = $this->actingAs($user)->postJson("/qa/incidents/{$incident->id}/rca", [
            'rca_text' => str_repeat('This is the root cause analysis. ', 5),
        ]);

        $response->assertStatus(403);
    }

    public function test_rca_text_must_be_at_least_50_chars(): void
    {
        $user     = $this->makeQaUser();
        $incident = $this->makeIncident($user);

        $response = $this->actingAs($user)->postJson("/qa/incidents/{$incident->id}/rca", [
            'rca_text' => 'Too short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rca_text']);
    }

    public function test_cannot_submit_rca_on_closed_incident(): void
    {
        $user     = $this->makeQaUser();
        $incident = $this->makeIncident($user, [
            'status'       => 'closed',
            'rca_required' => true,
            'rca_completed'=> true,
        ]);

        $response = $this->actingAs($user)->postJson("/qa/incidents/{$incident->id}/rca", [
            'rca_text' => str_repeat('Attempting to add RCA to a closed incident. ', 3),
        ]);

        $response->assertStatus(409);
    }

    // ── Close ─────────────────────────────────────────────────────────────────

    public function test_qa_can_close_incident_without_rca_required(): void
    {
        $user     = $this->makeQaUser();
        $incident = $this->makeIncident($user, ['rca_required' => false]);

        $response = $this->actingAs($user)->postJson("/qa/incidents/{$incident->id}/close");

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'closed']);
    }

    public function test_qa_can_close_incident_with_completed_rca(): void
    {
        $user     = $this->makeQaUser();
        $incident = $this->makeIncident($user, [
            'rca_required'  => true,
            'rca_completed' => true,
            'rca_text'      => 'RCA completed.',
        ]);

        $response = $this->actingAs($user)->postJson("/qa/incidents/{$incident->id}/close");

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'closed']);
    }

    public function test_close_blocked_when_rca_pending(): void
    {
        $user     = $this->makeQaUser();
        $incident = $this->makeIncident($user, [
            'rca_required'  => true,
            'rca_completed' => false,
        ]);

        $response = $this->actingAs($user)->postJson("/qa/incidents/{$incident->id}/close");

        $response->assertStatus(409);
    }

    public function test_non_qa_cannot_close_incident(): void
    {
        $user     = $this->makeUser('social_work');
        $incident = $this->makeIncident($user, ['rca_required' => false]);

        $response = $this->actingAs($user)->postJson("/qa/incidents/{$incident->id}/close");

        $response->assertStatus(403);
    }
}
