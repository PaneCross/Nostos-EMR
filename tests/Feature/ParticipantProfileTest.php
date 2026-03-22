<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\ParticipantFlag;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantProfileTest extends TestCase
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
            'mrn_prefix' => 'TEST',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ─── Inertia helper ───────────────────────────────────────────────────────

    private function inertiaGet(string $url, ?User $actor = null): array
    {
        // Override version() → null so the Inertia middleware skips the version check.
        $this->app->bind(
            \App\Http\Middleware\HandleInertiaRequests::class,
            fn () => new class extends \App\Http\Middleware\HandleInertiaRequests {
                public function version(\Illuminate\Http\Request $r): ?string { return null; }
            }
        );

        $user = $actor ?? $this->user;
        $resp = $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get($url);
        $resp->assertOk();
        return $resp->json('props');
    }

    // ─── Profile load ─────────────────────────────────────────────────────────

    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get("/participants/{$this->participant->id}")
            ->assertRedirect('/login');
    }

    public function test_profile_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get("/participants/{$this->participant->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Participants/Show'));
    }

    public function test_profile_includes_participant_data(): void
    {
        $props = $this->inertiaGet("/participants/{$this->participant->id}");

        $this->assertEquals($this->participant->id, $props['participant']['id']);
        $this->assertEquals($this->participant->mrn, $props['participant']['mrn']);
        $this->assertEquals('enrolled', $props['participant']['enrollment_status']);
    }

    public function test_cannot_view_participant_from_another_tenant(): void
    {
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTHER']);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();

        $this->actingAs($this->user)
            ->get("/participants/{$otherParticipant->id}")
            ->assertForbidden();
    }

    // ─── Flags ────────────────────────────────────────────────────────────────

    public function test_can_add_flag_to_participant(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/flags", [
                'flag_type'   => 'fall_risk',
                'severity'    => 'high',
                'description' => 'History of falls',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_participant_flags', [
            'participant_id' => $this->participant->id,
            'flag_type'      => 'fall_risk',
            'severity'       => 'high',
            'is_active'      => true,
        ]);
    }

    public function test_flag_requires_valid_flag_type(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/flags", [
                'flag_type' => 'invalid_type',
                'severity'  => 'medium',
            ])
            ->assertUnprocessable();
    }

    public function test_can_resolve_a_flag(): void
    {
        $flag = ParticipantFlag::create([
            'participant_id'     => $this->participant->id,
            'tenant_id'          => $this->tenant->id,
            'flag_type'          => 'wheelchair',
            'severity'           => 'medium',
            'is_active'          => true,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/flags/{$flag->id}/resolve")
            ->assertOk();

        $this->assertDatabaseHas('emr_participant_flags', [
            'id'        => $flag->id,
            'is_active' => false,
        ]);
        $this->assertNotNull($flag->fresh()->resolved_at);
    }

    public function test_cannot_resolve_flag_from_another_tenant(): void
    {
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTHER']);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();
        $flag = ParticipantFlag::create([
            'participant_id' => $otherParticipant->id,
            'tenant_id'      => $otherTenant->id,
            'flag_type'      => 'fall_risk',
            'severity'       => 'low',
            'is_active'      => true,
        ]);

        $this->actingAs($this->user)
            ->postJson("/participants/{$otherParticipant->id}/flags/{$flag->id}/resolve")
            ->assertForbidden();
    }

    // ─── Contacts ─────────────────────────────────────────────────────────────

    public function test_can_add_contact_to_participant(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/contacts", [
                'contact_type'            => 'emergency',
                'first_name'              => 'Maria',
                'last_name'               => 'Garcia',
                'relationship'            => 'Daughter',
                'phone_primary'           => '(213) 555-0100',
                'is_emergency_contact'    => true,
                'is_legal_representative' => false,
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_participant_contacts', [
            'participant_id'       => $this->participant->id,
            'first_name'           => 'Maria',
            'last_name'            => 'Garcia',
            'is_emergency_contact' => true,
        ]);
    }

    public function test_contact_requires_first_and_last_name(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/contacts", [
                'contact_type' => 'emergency',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    // ─── Delete authorization ──────────────────────────────────────────────────

    public function test_standard_user_cannot_delete_participant(): void
    {
        $this->actingAs($this->user)
            ->delete("/participants/{$this->participant->id}")
            ->assertForbidden();
    }

    public function test_enrollment_admin_can_delete_participant(): void
    {
        $adminUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'enrollment',
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        $this->actingAs($adminUser)
            ->delete("/participants/{$this->participant->id}")
            ->assertRedirect('/participants');

        $this->assertSoftDeleted('emr_participants', ['id' => $this->participant->id]);
    }

    // ─── Audit trail visibility ────────────────────────────────────────────────

    public function test_audit_trail_hidden_from_standard_users(): void
    {
        $props = $this->inertiaGet("/participants/{$this->participant->id}");
        $this->assertFalse($props['canViewAudit']);
    }

    public function test_audit_trail_visible_to_it_admin(): void
    {
        $itAdmin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'it_admin',
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        $props = $this->inertiaGet("/participants/{$this->participant->id}", $itAdmin);
        $this->assertTrue($props['canViewAudit']);
    }
}
