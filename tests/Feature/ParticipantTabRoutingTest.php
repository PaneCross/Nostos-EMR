<?php

// ─── ParticipantTabRoutingTest ──────────────────────────────────────────────
// Verifies that ?tab=* query parameters on the participant profile page are
// handled gracefully by the server. Tab rendering is client-side only —
// the server always returns the Participants/Show Inertia component regardless
// of the tab value. These tests confirm:
//   - All known tab slugs return HTTP 200 (no route crash or 500)
//   - Unknown/invalid tab slugs return 200 (server ignores unrecognised values)
//   - Cross-tenant access is still rejected (403) when a ?tab= param is present
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParticipantTabRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant      = Tenant::factory()->create();
        $this->site        = Site::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user        = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
        ]);
        $this->participant = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);
    }

    /** Baseline: no ?tab param still returns 200 with correct Inertia component. */
    public function test_participant_profile_returns_show_component(): void
    {
        $response = $this->actingAs($this->user)
            ->get("/participants/{$this->participant->id}");

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->component('Participants/Show'));
    }

    /**
     * Clinical tabs — all must return 200.
     */
    #[DataProvider('clinicalTabProvider')]
    public function test_clinical_tab_query_param_returns_200(string $tab): void
    {
        $response = $this->actingAs($this->user)
            ->get("/participants/{$this->participant->id}?tab={$tab}");

        $response->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function clinicalTabProvider(): array
    {
        return [
            'chart tab'         => ['chart'],
            'vitals tab'        => ['vitals'],
            'assessments tab'   => ['assessments'],
            'medications tab'   => ['medications'],
            'emar tab'          => ['emar'],
            'med-recon tab'     => ['med-recon'],
            'problems tab'      => ['problems'],
            'allergies tab'     => ['allergies'],
            'adl tab'           => ['adl'],
            'careplan tab'      => ['careplan'],
            'immunizations tab' => ['immunizations'],
            'procedures tab'    => ['procedures'],
        ];
    }

    /**
     * Admin tabs — all must return 200.
     */
    #[DataProvider('adminTabProvider')]
    public function test_admin_tab_query_param_returns_200(string $tab): void
    {
        $response = $this->actingAs($this->user)
            ->get("/participants/{$this->participant->id}?tab={$tab}");

        $response->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function adminTabProvider(): array
    {
        return [
            'overview tab'  => ['overview'],
            'contacts tab'  => ['contacts'],
            'flags tab'     => ['flags'],
            'insurance tab' => ['insurance'],
            'documents tab' => ['documents'],
            'sdoh tab'      => ['sdoh'],
            'transfers tab' => ['transfers'],
            'audit tab'     => ['audit'],
        ];
    }

    /** Unknown / garbage tab slug should not crash the server — still returns 200. */
    public function test_unknown_tab_slug_returns_200(): void
    {
        $response = $this->actingAs($this->user)
            ->get("/participants/{$this->participant->id}?tab=totally-invalid-tab");

        $response->assertOk();
    }

    /** Cross-tenant participant returns 403 even when a ?tab= param is provided. */
    public function test_cross_tenant_with_tab_param_returns_403(): void
    {
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherParticipant = Participant::factory()->create([
            'tenant_id' => $otherTenant->id,
            'site_id'   => $otherSite->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get("/participants/{$otherParticipant->id}?tab=careplan");

        $response->assertForbidden();
    }

    /** Server always returns Participants/Show regardless of tab value. */
    public function test_inertia_component_is_always_show_regardless_of_tab(): void
    {
        foreach (['careplan', 'med-recon', 'overview', 'immunizations', 'sdoh'] as $tab) {
            $this->actingAs($this->user)
                ->get("/participants/{$this->participant->id}?tab={$tab}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('Participants/Show'));
        }
    }

    /** Med-recon tab is accessible to primary_care users (data loaded client-side via axios). */
    public function test_med_recon_tab_accessible_to_primary_care(): void
    {
        $response = $this->actingAs($this->user)  // primary_care department
            ->get("/participants/{$this->participant->id}?tab=med-recon");

        $response->assertOk();
    }

    /** Med-recon tab is accessible to pharmacy users. */
    public function test_med_recon_tab_accessible_to_pharmacy(): void
    {
        $pharmacyUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'pharmacy',
            'role'       => 'standard',
        ]);

        $response = $this->actingAs($pharmacyUser)
            ->get("/participants/{$this->participant->id}?tab=med-recon");

        $response->assertOk();
    }
}
