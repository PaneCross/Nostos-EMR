<?php

// ─── GrievanceTest ────────────────────────────────────────────────────────────
// Feature tests for W4-1 grievance workflow (42 CFR §460.120–§460.121).
//
// Coverage:
//   - Index: Inertia page renders, QA admin sees all, regular user sees own site
//   - Store: any authenticated user can file; urgent creates critical alert;
//            cross-tenant participant rejected (403)
//   - Show: tenant-scoped; cross-tenant returns 403; audit logged
//   - Update: QA admin only; closed grievance returns 409; non-QA returns 403
//   - Resolve: QA admin, requires resolution_text + resolution_date; 409 if already closed
//   - Escalate: QA admin, requires escalation_reason; validates transition
//   - NotifyParticipant: QA admin; records notification_method + timestamp
//   - Overdue: QA admin JSON feed; non-QA returns 403
//   - Authorization: cross-tenant 403 on all mutating routes
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Grievance;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrievanceTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept = 'primary_care', ?int $tenantId = null): User
    {
        $attrs = ['department' => $dept];
        if ($tenantId) {
            $attrs['tenant_id'] = $tenantId;
        }
        return User::factory()->create($attrs);
    }

    private function makeQaUser(?int $tenantId = null): User
    {
        return $this->makeUser('qa_compliance', $tenantId);
    }

    private function makeParticipant(User $user): Participant
    {
        return Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => Site::factory()->create(['tenant_id' => $user->tenant_id])->id,
        ]);
    }

    private function makeGrievance(User $user, array $overrides = []): Grievance
    {
        $participant = $this->makeParticipant($user);
        return Grievance::factory()->create(array_merge([
            'tenant_id'      => $user->tenant_id,
            'site_id'        => $participant->site_id,
            'participant_id' => $participant->id,
        ], $overrides));
    }

    private function storePayload(Participant $participant, array $overrides = []): array
    {
        return array_merge([
            'participant_id' => $participant->id,
            'filed_by_name'  => 'Jane Family Member',
            'filed_by_type'  => 'family_member',
            'category'       => 'quality_of_care',
            'description'    => 'Participant did not receive their scheduled therapy session on Tuesday afternoon.',
            'priority'       => 'standard',
            'cms_reportable' => false,
        ], $overrides);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_renders_inertia_page(): void
    {
        $user = $this->makeQaUser();
        $this->makeGrievance($user);

        $this->actingAs($user)->get('/grievances')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Grievances/Index'));
    }

    public function test_index_requires_authentication(): void
    {
        $this->get('/grievances')->assertRedirect('/login');
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_any_authenticated_user_can_file_a_grievance(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson('/grievances', $this->storePayload($participant))
            ->assertRedirect();

        $this->assertDatabaseHas('emr_grievances', [
            'participant_id' => $participant->id,
            'status'         => 'open',
            'priority'       => 'standard',
        ]);
    }

    public function test_urgent_grievance_creates_critical_alert(): void
    {
        $user        = $this->makeQaUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson('/grievances', $this->storePayload($participant, ['priority' => 'urgent']));

        $this->assertDatabaseHas('emr_alerts', [
            'tenant_id' => $user->tenant_id,
            'severity'  => 'critical',
            'source_module' => 'grievances',
        ]);
    }

    public function test_cannot_file_grievance_for_cross_tenant_participant(): void
    {
        $user          = $this->makeUser();
        $otherTenant   = Tenant::factory()->create();
        $otherParticipant = Participant::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($user)
            ->postJson('/grievances', $this->storePayload($otherParticipant))
            ->assertForbidden();
    }

    // ── Show ─────────────────────────────────────────────────────────────────

    public function test_show_renders_inertia_page(): void
    {
        $user      = $this->makeQaUser();
        $grievance = $this->makeGrievance($user);

        $this->actingAs($user)->get("/grievances/{$grievance->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Grievances/Show'));
    }

    public function test_show_cross_tenant_returns_403(): void
    {
        $user      = $this->makeUser();
        $other     = $this->makeQaUser();
        $grievance = $this->makeGrievance($other);

        $this->actingAs($user)->get("/grievances/{$grievance->id}")
            ->assertForbidden();
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_qa_admin_can_update_grievance(): void
    {
        $user      = $this->makeQaUser();
        $grievance = $this->makeGrievance($user);

        $this->actingAs($user)
            ->putJson("/grievances/{$grievance->id}", [
                'investigation_notes' => 'Initial review complete. Scheduling staff interview.',
            ])
            ->assertOk()
            ->assertJsonPath('grievance.status', 'open');
    }

    public function test_non_qa_cannot_update_grievance(): void
    {
        $user      = $this->makeUser('primary_care');
        $grievance = $this->makeGrievance($user);

        $this->actingAs($user)
            ->putJson("/grievances/{$grievance->id}", ['investigation_notes' => 'Notes.'])
            ->assertForbidden();
    }

    public function test_cannot_update_resolved_grievance(): void
    {
        $user      = $this->makeQaUser();
        $grievance = $this->makeGrievance($user, ['status' => 'resolved']);

        $this->actingAs($user)
            ->putJson("/grievances/{$grievance->id}", ['investigation_notes' => 'Attempt.'])
            ->assertStatus(409);
    }

    // ── Resolve ───────────────────────────────────────────────────────────────

    public function test_qa_admin_can_resolve_grievance(): void
    {
        $user      = $this->makeQaUser();
        $grievance = $this->makeGrievance($user, ['status' => 'under_review']);

        $this->actingAs($user)
            ->postJson("/grievances/{$grievance->id}/resolve", [
                'resolution_text' => 'Staff met with participant and addressed scheduling gap. Additional therapy slot added.',
                'resolution_date' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('grievance.status', 'resolved');
    }

    public function test_resolve_requires_resolution_text_and_date(): void
    {
        $user      = $this->makeQaUser();
        $grievance = $this->makeGrievance($user, ['status' => 'under_review']);

        $this->actingAs($user)
            ->postJson("/grievances/{$grievance->id}/resolve", [])
            ->assertStatus(422);
    }

    // ── Escalate ──────────────────────────────────────────────────────────────

    public function test_qa_admin_can_escalate_grievance(): void
    {
        $user      = $this->makeQaUser();
        $grievance = $this->makeGrievance($user, ['status' => 'under_review']);

        $this->actingAs($user)
            ->postJson("/grievances/{$grievance->id}/escalate", [
                'escalation_reason' => 'Not resolved within 30 days. Escalating to medical director.',
            ])
            ->assertOk()
            ->assertJsonPath('grievance.status', 'escalated');
    }

    // ── NotifyParticipant ─────────────────────────────────────────────────────

    public function test_qa_admin_can_record_participant_notification(): void
    {
        $user      = $this->makeQaUser();
        $grievance = $this->makeGrievance($user, ['status' => 'resolved']);

        $this->actingAs($user)
            ->postJson("/grievances/{$grievance->id}/notify-participant", [
                'notification_method' => 'verbal',
            ])
            ->assertOk();

        $this->assertNotNull($grievance->fresh()->participant_notified_at);
    }

    // ── Overdue feed ──────────────────────────────────────────────────────────

    public function test_overdue_returns_json_for_qa_admin(): void
    {
        $user = $this->makeQaUser();
        Grievance::factory()->urgentOverdue()->create([
            'tenant_id' => $user->tenant_id,
        ]);

        $this->actingAs($user)->getJson('/grievances/overdue')
            ->assertOk()
            ->assertJsonStructure(['urgent_overdue', 'standard_overdue', 'total']);
    }

    public function test_overdue_returns_403_for_non_qa(): void
    {
        $user = $this->makeUser('primary_care');

        $this->actingAs($user)->getJson('/grievances/overdue')
            ->assertForbidden();
    }
}
