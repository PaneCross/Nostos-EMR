<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site   $site;
    private User   $user;

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
            'is_active'  => true,
        ]);
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_search_returns_401(): void
    {
        // JSON request (X-Inertia) triggers a 401 instead of redirect
        $this->getJson('/participants/search?q=Alice')
            ->assertUnauthorized();
    }

    // ─── Minimum length validation ────────────────────────────────────────────

    public function test_search_requires_at_least_two_characters(): void
    {
        $this->actingAs($this->user)
            ->getJson('/participants/search?q=A')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_search_missing_q_returns_validation_error(): void
    {
        $this->actingAs($this->user)
            ->getJson('/participants/search')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
    }

    public function test_search_returns_empty_array_for_no_match(): void
    {
        $this->actingAs($this->user)
            ->getJson('/participants/search?q=ZZZnonexistent')
            ->assertOk()
            ->assertExactJson([]);
    }

    // ─── Results ──────────────────────────────────────────────────────────────

    public function test_search_returns_matching_participant_by_name(): void
    {
        $alice = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create(['first_name' => 'Alice', 'last_name' => 'Testpatient']);

        $response = $this->actingAs($this->user)
            ->getJson('/participants/search?q=Alice');

        $response->assertOk();
        $data = $response->json();

        $this->assertCount(1, $data);
        $this->assertEquals($alice->id, $data[0]['id']);
        $this->assertEquals($alice->mrn, $data[0]['mrn']);
        $this->assertArrayHasKey('name', $data[0]);
        $this->assertArrayHasKey('dob', $data[0]);
        $this->assertArrayHasKey('age', $data[0]);
        $this->assertArrayHasKey('enrollment_status', $data[0]);
        $this->assertArrayHasKey('flags', $data[0]);
    }

    public function test_search_returns_matching_participant_by_mrn(): void
    {
        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson("/participants/search?q={$participant->mrn}");

        $response->assertOk();
        $data = $response->json();

        $this->assertCount(1, $data);
        $this->assertEquals($participant->id, $data[0]['id']);
    }

    public function test_search_returns_matching_participant_by_dob(): void
    {
        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create(['dob' => '1940-07-04']);

        $response = $this->actingAs($this->user)
            ->getJson('/participants/search?q=1940-07-04');

        $response->assertOk();
        $ids = array_column($response->json(), 'id');
        $this->assertContains($participant->id, $ids);
    }

    public function test_search_is_case_insensitive(): void
    {
        $participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create(['first_name' => 'Eleanor', 'last_name' => 'Testpatient']);

        $response = $this->actingAs($this->user)
            ->getJson('/participants/search?q=eleanor');

        $response->assertOk();
        $ids = array_column($response->json(), 'id');
        $this->assertContains($participant->id, $ids);
    }

    // ─── Tenant scoping ───────────────────────────────────────────────────────

    public function test_search_does_not_return_participants_from_other_tenants(): void
    {
        Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create(['first_name' => 'Shared', 'last_name' => 'Testpatient']);

        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTHER']);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create(['first_name' => 'Shared', 'last_name' => 'Testpatient']);

        $response = $this->actingAs($this->user)
            ->getJson('/participants/search?q=Shared');

        $response->assertOk();
        $ids = array_column($response->json(), 'id');
        $this->assertNotContains($otherParticipant->id, $ids);
    }

    // ─── Audit logging ────────────────────────────────────────────────────────

    public function test_search_is_logged_to_audit(): void
    {
        $this->actingAs($this->user)
            ->getJson('/participants/search?q=Eleanor');

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'    => 'participant.searched',
            'user_id'   => $this->user->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    // ─── Result limit ─────────────────────────────────────────────────────────

    public function test_search_returns_at_most_fifteen_results(): void
    {
        Participant::factory()->enrolled()->count(20)
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create(['last_name' => 'Testpatient']);

        $response = $this->actingAs($this->user)
            ->getJson('/participants/search?q=Testpatient');

        $response->assertOk();
        $this->assertCount(15, $response->json());
    }
}
