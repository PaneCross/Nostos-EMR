<?php

// ─── ParticipantUIActionsTest ──────────────────────────────────────────────────
// Tests all interactive UI actions on the Participant Profile page:
//   - Adding and resolving flags
//   - Adding contacts (with phone number validation)
//   - Tab data isolation (each tab loads correct data)
//   - Deactivate action
//   - Phone number formats accepted by the backend
//   - Permission-gated actions (canEdit, canDelete, canViewAudit)
//
// These tests back the UI buttons and forms in Show.tsx so that regressions
// are caught automatically as the codebase evolves.
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\ParticipantContact;
use App\Models\ParticipantFlag;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantUIActionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'UI',
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
            'department' => 'enrollment', 'role' => 'admin',
        ]);
        $this->participant = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);
    }

    // ─── Inertia helper ───────────────────────────────────────────────────────
    private function inertiaGet(string $url): array
    {
        $this->app->bind(
            \App\Http\Middleware\HandleInertiaRequests::class,
            fn () => new class extends \App\Http\Middleware\HandleInertiaRequests {
                public function version(\Illuminate\Http\Request $r): ?string { return null; }
            }
        );

        $resp = $this->actingAs($this->user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get($url);

        $resp->assertOk();
        return $resp->json('props');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // FLAGS TAB
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function add_flag_button_creates_flag_and_persists(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/flags", [
                'flag_type'   => 'fall_risk',
                'severity'    => 'high',
                'description' => 'Documented fall last week',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['flag_type' => 'fall_risk', 'severity' => 'high', 'is_active' => true]);

        // Verify the flag exists in the database
        $this->assertDatabaseHas('emr_participant_flags', [
            'participant_id' => $this->participant->id,
            'flag_type'      => 'fall_risk',
            'severity'       => 'high',
            'is_active'      => true,
        ]);
    }

    /** @test */
    public function flag_appears_in_profile_props_after_creation(): void
    {
        ParticipantFlag::factory()->create([
            'participant_id'     => $this->participant->id,
            'tenant_id'          => $this->tenant->id,
            'flag_type'          => 'oxygen',
            'severity'           => 'critical',
            'is_active'          => true,
            'created_by_user_id' => $this->user->id,
        ]);

        $props = $this->inertiaGet("/participants/{$this->participant->id}");
        $flags = $props['flags'];

        $this->assertNotEmpty($flags);
        $flagTypes = array_column($flags, 'flag_type');
        $this->assertContains('oxygen', $flagTypes);
    }

    /** @test */
    public function resolve_flag_button_marks_flag_inactive(): void
    {
        $flag = ParticipantFlag::factory()->create([
            'participant_id'     => $this->participant->id,
            'tenant_id'          => $this->tenant->id,
            'flag_type'          => 'behavioral',
            'severity'           => 'medium',
            'is_active'          => true,
            'created_by_user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/flags/{$flag->id}/resolve");

        $response->assertOk();
        $response->assertJsonFragment(['resolved' => true]);

        $this->assertDatabaseHas('emr_participant_flags', [
            'id'        => $flag->id,
            'is_active' => false,
        ]);
    }

    /** @test */
    public function resolved_flag_no_longer_appears_as_active(): void
    {
        $flag = ParticipantFlag::factory()->create([
            'participant_id'     => $this->participant->id,
            'tenant_id'          => $this->tenant->id,
            'flag_type'          => 'dnr',
            'severity'           => 'high',
            'is_active'          => true,
            'created_by_user_id' => $this->user->id,
        ]);

        // Resolve it
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/flags/{$flag->id}/resolve");

        // Reload page props — resolved flag should have is_active = false
        $props       = $this->inertiaGet("/participants/{$this->participant->id}");
        $activeFlags = array_filter($props['flags'], fn ($f) => $f['is_active']);

        $activeIds = array_column(array_values($activeFlags), 'id');
        $this->assertNotContains($flag->id, $activeIds);
    }

    /** @test */
    public function cannot_add_flag_with_invalid_flag_type(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/flags", [
                'flag_type' => 'not_a_real_flag',
                'severity'  => 'low',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['flag_type']);
    }

    /** @test */
    public function cannot_add_flag_with_invalid_severity(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/flags", [
                'flag_type' => 'fall_risk',
                'severity'  => 'extreme', // not a valid severity
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['severity']);
    }

    /** @test */
    public function cannot_resolve_flag_belonging_to_different_participant(): void
    {
        $otherPpt = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);
        $flag = ParticipantFlag::factory()->create([
            'participant_id'     => $otherPpt->id,
            'tenant_id'          => $this->tenant->id,
            'flag_type'          => 'fall_risk',
            'severity'           => 'low',
            'is_active'          => true,
            'created_by_user_id' => $this->user->id,
        ]);

        // Attempt to resolve via wrong participant route
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/flags/{$flag->id}/resolve")
            ->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // CONTACTS TAB
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function add_contact_button_creates_contact_and_persists(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/contacts", [
                'contact_type'            => 'emergency',
                'first_name'              => 'Jane',
                'last_name'               => 'Doe',
                'relationship'            => 'Daughter',
                'phone_primary'           => '(555) 123-4567',
                'is_emergency_contact'    => true,
                'is_legal_representative' => false,
                'priority_order'          => 1,
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['first_name' => 'Jane', 'last_name' => 'Doe']);

        $this->assertDatabaseHas('emr_participant_contacts', [
            'participant_id' => $this->participant->id,
            'first_name'     => 'Jane',
            'last_name'      => 'Doe',
            'phone_primary'  => '(555) 123-4567',
        ]);
    }

    /** @test */
    public function contact_appears_in_profile_props_after_creation(): void
    {
        ParticipantContact::factory()->create([
            'participant_id'  => $this->participant->id,
            'first_name'      => 'Robert',
            'last_name'       => 'Smith',
            'contact_type'    => 'next_of_kin',
            'priority_order'  => 1,
        ]);

        $props    = $this->inertiaGet("/participants/{$this->participant->id}");
        $contacts = $props['contacts'];

        $this->assertNotEmpty($contacts);
        $names = array_map(fn ($c) => $c['first_name'], $contacts);
        $this->assertContains('Robert', $names);
    }

    /** @test */
    public function phone_number_in_formatted_style_is_accepted(): void
    {
        // Frontend sends formatted (xxx) xxx-xxxx strings — backend must accept them
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/contacts", [
                'contact_type'            => 'poa',
                'first_name'              => 'Mary',
                'last_name'               => 'Jones',
                'phone_primary'           => '(310) 555-0199',
                'phone_secondary'         => '(800) 555-0100',
                'is_emergency_contact'    => false,
                'is_legal_representative' => true,
                'priority_order'          => 1,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('emr_participant_contacts', [
            'participant_id' => $this->participant->id,
            'phone_primary'  => '(310) 555-0199',
        ]);
    }

    /** @test */
    public function contact_requires_first_and_last_name(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/contacts", [
                'contact_type'   => 'emergency',
                'priority_order' => 1,
                // Missing first_name and last_name
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    /** @test */
    public function contact_requires_valid_contact_type(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/contacts", [
                'contact_type' => 'invalid_type',
                'first_name'   => 'Test',
                'last_name'    => 'User',
                'priority_order' => 1,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_type']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DEACTIVATE BUTTON
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function deactivate_button_soft_deletes_participant(): void
    {
        $response = $this->actingAs($this->user)
            ->delete("/participants/{$this->participant->id}");

        // Should redirect back to the participant directory
        $response->assertRedirect('/participants');

        // Participant record should still exist but have deleted_at set (soft-delete)
        $this->assertSoftDeleted('emr_participants', ['id' => $this->participant->id]);
    }

    /** @test */
    public function deactivated_participant_does_not_appear_in_directory(): void
    {
        // Deactivate the participant
        $this->actingAs($this->user)->delete("/participants/{$this->participant->id}");

        // Directory should not include them
        $props = $this->inertiaGet('/participants');
        $ids   = array_column($props['participants']['data'] ?? [], 'id');

        $this->assertNotContains($this->participant->id, $ids);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TAB DATA ISOLATION
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function flags_tab_data_is_scoped_to_the_current_participant(): void
    {
        // Create a flag for our participant
        ParticipantFlag::factory()->create([
            'participant_id'     => $this->participant->id,
            'tenant_id'          => $this->tenant->id,
            'flag_type'          => 'hospice',
            'severity'           => 'high',
            'is_active'          => true,
            'created_by_user_id' => $this->user->id,
        ]);

        // Create a flag for a different participant — should NOT appear
        $otherPpt = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);
        ParticipantFlag::factory()->create([
            'participant_id'     => $otherPpt->id,
            'tenant_id'          => $this->tenant->id,
            'flag_type'          => 'dnr',
            'severity'           => 'critical',
            'is_active'          => true,
            'created_by_user_id' => $this->user->id,
        ]);

        $props = $this->inertiaGet("/participants/{$this->participant->id}");
        $flags = $props['flags'];

        $participantIds = array_unique(array_column($flags, 'participant_id'));
        $this->assertCount(1, $participantIds);
        $this->assertEquals($this->participant->id, $participantIds[0]);
    }

    /** @test */
    public function contacts_tab_data_is_scoped_to_the_current_participant(): void
    {
        ParticipantContact::factory()->create([
            'participant_id' => $this->participant->id,
            'first_name'     => 'Alice',
            'last_name'      => 'Test',
            'contact_type'   => 'emergency',
            'priority_order' => 1,
        ]);

        $otherPpt = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);
        ParticipantContact::factory()->create([
            'participant_id' => $otherPpt->id,
            'first_name'     => 'Bob',
            'last_name'      => 'Other',
            'contact_type'   => 'poa',
            'priority_order' => 1,
        ]);

        $props    = $this->inertiaGet("/participants/{$this->participant->id}");
        $contacts = $props['contacts'];

        $names = array_column($contacts, 'first_name');
        $this->assertContains('Alice', $names);
        $this->assertNotContains('Bob', $names);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PERMISSION-GATED UI PROPS
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function enrollment_admin_can_edit_and_delete(): void
    {
        // Users in enrollment dept with admin role have full edit/delete rights
        $props = $this->inertiaGet("/participants/{$this->participant->id}");

        $this->assertTrue($props['canEdit'],   'enrollment admin should have canEdit');
        $this->assertTrue($props['canDelete'], 'enrollment admin should have canDelete');
    }

    /** @test */
    public function qa_compliance_staff_cannot_edit_or_delete(): void
    {
        $auditor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
            'department' => 'qa_compliance', 'role' => 'standard',
        ]);

        $this->app->bind(
            \App\Http\Middleware\HandleInertiaRequests::class,
            fn () => new class extends \App\Http\Middleware\HandleInertiaRequests {
                public function version(\Illuminate\Http\Request $r): ?string { return null; }
            }
        );

        $props = $this->actingAs($auditor)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get("/participants/{$this->participant->id}")
            ->json('props');

        $this->assertFalse($props['canEdit'],   'read_only_auditor should NOT have canEdit');
        $this->assertFalse($props['canDelete'], 'read_only_auditor should NOT have canDelete');
    }

    /** @test */
    public function read_only_auditor_can_view_audit_trail(): void
    {
        $auditor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
            'department' => 'qa_compliance', 'role' => 'standard',
        ]);

        $this->app->bind(
            \App\Http\Middleware\HandleInertiaRequests::class,
            fn () => new class extends \App\Http\Middleware\HandleInertiaRequests {
                public function version(\Illuminate\Http\Request $r): ?string { return null; }
            }
        );

        $props = $this->actingAs($auditor)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get("/participants/{$this->participant->id}")
            ->json('props');

        $this->assertTrue($props['canViewAudit'], 'read_only_auditor should have canViewAudit');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // UNAUTHENTICATED GUARDS ON ACTION ENDPOINTS
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function unauthenticated_cannot_add_flag(): void
    {
        $this->postJson("/participants/{$this->participant->id}/flags", [
            'flag_type' => 'fall_risk',
            'severity'  => 'low',
        ])->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_cannot_add_contact(): void
    {
        $this->postJson("/participants/{$this->participant->id}/contacts", [
            'contact_type' => 'emergency',
            'first_name'   => 'Test',
            'last_name'    => 'User',
            'priority_order' => 1,
        ])->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_cannot_deactivate_participant(): void
    {
        // Unauthenticated DELETE → redirected to the named 'login' route: GET /login
        $this->delete("/participants/{$this->participant->id}")
            ->assertRedirect('/login');
    }
}
