<?php

// ─── UserDesignationTest ──────────────────────────────────────────────────────
// Tests for the User Designations system — accountability sub-roles used for
// targeted alerting and workflow routing.
//
// Coverage:
//   - PATCH /it-admin/users/{user}/designations (updateDesignations)
//   - User::withDesignation() scope
//   - User::hasDesignation() helper
//   - GET /grievances/escalation-staff (escalationStaff endpoint)
//   - POST /grievances/{id}/escalate — escalated_to_user_id stored + alert created
//   - Cross-tenant isolation on designation update
//   - Non-IT-admin denied designation update (403)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Grievance;
use App\Models\Participant;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDesignationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeItAdmin(?int $tenantId = null): User
    {
        $attrs = ['department' => 'it_admin'];
        if ($tenantId) $attrs['tenant_id'] = $tenantId;
        return User::factory()->create($attrs);
    }

    private function makeQaUser(?int $tenantId = null): User
    {
        $attrs = ['department' => 'qa_compliance'];
        if ($tenantId) $attrs['tenant_id'] = $tenantId;
        return User::factory()->create($attrs);
    }

    private function makeUser(string $dept = 'primary_care', ?int $tenantId = null): User
    {
        $attrs = ['department' => $dept];
        if ($tenantId) $attrs['tenant_id'] = $tenantId;
        return User::factory()->create($attrs);
    }

    private function makeGrievance(User $user, array $overrides = []): Grievance
    {
        $participant = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => Site::factory()->create(['tenant_id' => $user->tenant_id])->id,
        ]);
        return Grievance::factory()->create(array_merge([
            'tenant_id'      => $user->tenant_id,
            'site_id'        => $participant->site_id,
            'participant_id' => $participant->id,
        ], $overrides));
    }

    // ── updateDesignations ────────────────────────────────────────────────────

    public function test_it_admin_can_assign_designations_to_user(): void
    {
        $admin = $this->makeItAdmin();
        $user  = $this->makeUser('qa_compliance', $admin->tenant_id);

        $this->actingAs($admin)
            ->patchJson("/it-admin/users/{$user->id}/designations", [
                'designations' => ['compliance_officer'],
            ])
            ->assertOk()
            ->assertJsonPath('user.designations.0', 'compliance_officer');

        $this->assertDatabaseHas('shared_users', [
            'id' => $user->id,
        ]);

        $user->refresh();
        $this->assertTrue($user->hasDesignation('compliance_officer'));
    }

    public function test_can_assign_multiple_designations(): void
    {
        $admin = $this->makeItAdmin();
        $user  = $this->makeUser('it_admin', $admin->tenant_id);

        $this->actingAs($admin)
            ->patchJson("/it-admin/users/{$user->id}/designations", [
                'designations' => ['medical_director', 'program_director'],
            ])
            ->assertOk();

        $user->refresh();
        $this->assertTrue($user->hasDesignation('medical_director'));
        $this->assertTrue($user->hasDesignation('program_director'));
        $this->assertFalse($user->hasDesignation('compliance_officer'));
    }

    public function test_can_clear_all_designations(): void
    {
        $admin = $this->makeItAdmin();
        $user  = $this->makeUser('qa_compliance', $admin->tenant_id);
        $user->update(['designations' => ['compliance_officer']]);

        $this->actingAs($admin)
            ->patchJson("/it-admin/users/{$user->id}/designations", [
                'designations' => [],
            ])
            ->assertOk();

        $user->refresh();
        $this->assertEmpty($user->designations);
    }

    public function test_invalid_designation_key_returns_422(): void
    {
        $admin = $this->makeItAdmin();
        $user  = $this->makeUser('qa_compliance', $admin->tenant_id);

        $this->actingAs($admin)
            ->patchJson("/it-admin/users/{$user->id}/designations", [
                'designations' => ['not_a_real_designation'],
            ])
            ->assertStatus(422);
    }

    public function test_non_it_admin_cannot_update_designations(): void
    {
        $actor = $this->makeUser('qa_compliance');
        $user  = $this->makeUser('primary_care', $actor->tenant_id);

        $this->actingAs($actor)
            ->patchJson("/it-admin/users/{$user->id}/designations", [
                'designations' => ['compliance_officer'],
            ])
            ->assertForbidden();
    }

    public function test_cannot_update_designations_for_cross_tenant_user(): void
    {
        $admin      = $this->makeItAdmin();
        $otherUser  = $this->makeUser('qa_compliance'); // different tenant

        $this->actingAs($admin)
            ->patchJson("/it-admin/users/{$otherUser->id}/designations", [
                'designations' => ['compliance_officer'],
            ])
            ->assertForbidden();
    }

    // ── Model helpers ─────────────────────────────────────────────────────────

    public function test_with_designation_scope_returns_matching_users(): void
    {
        $admin      = $this->makeItAdmin();
        $tenantId   = $admin->tenant_id;

        $officer = $this->makeUser('qa_compliance', $tenantId);
        $officer->update(['designations' => ['compliance_officer']]);

        $other = $this->makeUser('primary_care', $tenantId);

        $results = User::where('tenant_id', $tenantId)
            ->withDesignation('compliance_officer')
            ->get();

        $this->assertTrue($results->contains($officer));
        $this->assertFalse($results->contains($other));
    }

    public function test_has_designation_returns_correct_values(): void
    {
        $user = $this->makeUser();
        $user->update(['designations' => ['medical_director', 'compliance_officer']]);

        $this->assertTrue($user->hasDesignation('medical_director'));
        $this->assertTrue($user->hasDesignation('compliance_officer'));
        $this->assertFalse($user->hasDesignation('pharmacy_director'));
    }

    // ── escalationStaff endpoint ──────────────────────────────────────────────

    public function test_escalation_staff_endpoint_returns_designation_holders(): void
    {
        $qa      = $this->makeQaUser();
        $officer = $this->makeUser('qa_compliance', $qa->tenant_id);
        $officer->update(['designations' => ['compliance_officer']]);

        // User with no relevant designation should not appear
        $nurse = $this->makeUser('primary_care', $qa->tenant_id);

        $this->actingAs($qa)->getJson('/grievances/escalation-staff')
            ->assertOk()
            ->assertJsonFragment(['id' => $officer->id])
            ->assertJsonMissing(['id' => $nurse->id]);
    }

    public function test_escalation_staff_requires_qa_admin(): void
    {
        $user = $this->makeUser('primary_care');

        $this->actingAs($user)->getJson('/grievances/escalation-staff')
            ->assertForbidden();
    }

    // ── Grievance escalate with named assignee ────────────────────────────────

    public function test_escalate_with_named_assignee_stores_escalated_to_user_id(): void
    {
        $qa      = $this->makeQaUser();
        $officer = $this->makeUser('qa_compliance', $qa->tenant_id);
        $officer->update(['designations' => ['compliance_officer']]);

        $grievance = $this->makeGrievance($qa, ['status' => 'under_review']);

        $this->actingAs($qa)
            ->postJson("/grievances/{$grievance->id}/escalate", [
                'escalation_reason'    => 'Unresolved after 20 days. Escalating per policy.',
                'escalated_to_user_id' => $officer->id,
            ])
            ->assertOk()
            ->assertJsonPath('grievance.status', 'escalated');

        $this->assertDatabaseHas('emr_grievances', [
            'id'                   => $grievance->id,
            'escalated_to_user_id' => $officer->id,
        ]);
    }

    public function test_escalate_creates_escalation_alert(): void
    {
        $qa        = $this->makeQaUser();
        $grievance = $this->makeGrievance($qa, ['status' => 'under_review']);

        $this->actingAs($qa)
            ->postJson("/grievances/{$grievance->id}/escalate", [
                'escalation_reason' => 'Unresolved after 25 days. Escalating to compliance.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('emr_alerts', [
            'tenant_id'     => $qa->tenant_id,
            'alert_type'    => 'grievance_escalated',
            'severity'      => 'critical',
            'source_module' => 'grievances',
        ]);
    }

    public function test_escalate_rejects_cross_tenant_assignee(): void
    {
        $qa         = $this->makeQaUser();
        $otherUser  = $this->makeUser('qa_compliance'); // different tenant
        $grievance  = $this->makeGrievance($qa, ['status' => 'under_review']);

        $this->actingAs($qa)
            ->postJson("/grievances/{$grievance->id}/escalate", [
                'escalation_reason'    => 'Cross-tenant escalation attempt.',
                'escalated_to_user_id' => $otherUser->id,
            ])
            ->assertStatus(422);
    }

    public function test_users_page_includes_designation_data(): void
    {
        $admin   = $this->makeItAdmin();
        $officer = $this->makeUser('qa_compliance', $admin->tenant_id);
        $officer->update(['designations' => ['compliance_officer']]);

        $this->actingAs($admin)->get('/it-admin/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ItAdmin/Users')
                ->has('designationLabels')
            );
    }
}
