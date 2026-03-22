<?php

// ─── NavigationTest ────────────────────────────────────────────────────────────
// Verifies that all primary navigation links route to the correct pages and that
// authenticated vs unauthenticated access is enforced.
//
// Route reference (see routes/web.php):
//   GET  /login                       → OtpController@showLogin  (guest only)
//   GET  /dashboard/{department}      → DashboardController@show  (auth)
//   GET  /participants                → ParticipantController@index (auth)
//   GET  /participants/{id}           → ParticipantController@show  (auth)
//   POST /auth/logout                 → OtpController@logout        (auth)
//   GET  /                            → redirects to dept dashboard  (auth)
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
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
            'mrn_prefix' => 'NAV',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'enrollment',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
        ]);
    }

    // ── Inertia helper: returns the page props array ──────────────────────────
    // Sends X-Inertia: true and overrides the version check to avoid 409 Conflict.
    private function inertiaGet(string $url, ?User $user = null): array
    {
        $this->app->bind(
            \App\Http\Middleware\HandleInertiaRequests::class,
            fn () => new class extends \App\Http\Middleware\HandleInertiaRequests {
                public function version(\Illuminate\Http\Request $r): ?string { return null; }
            }
        );

        $resp = $this->actingAs($user ?? $this->user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get($url);

        $resp->assertOk();
        return $resp->json('props') ?? [];
    }

    // ── Raw Inertia response (for checking component + status) ────────────────
    private function inertiaResponse(string $url, ?User $user = null): \Illuminate\Testing\TestResponse
    {
        $this->app->bind(
            \App\Http\Middleware\HandleInertiaRequests::class,
            fn () => new class extends \App\Http\Middleware\HandleInertiaRequests {
                public function version(\Illuminate\Http\Request $r): ?string { return null; }
            }
        );

        return $this->actingAs($user ?? $this->user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get($url);
    }

    // ─── Unauthenticated redirects ─────────────────────────────────────────────
    // Laravel redirects unauthenticated users to the named 'login' route: GET /login

    /** @test */
    public function unauthenticated_user_is_redirected_from_participant_directory(): void
    {
        $this->get('/participants')->assertRedirect('/login');
    }

    /** @test */
    public function unauthenticated_user_is_redirected_from_participant_profile(): void
    {
        $this->get("/participants/{$this->participant->id}")->assertRedirect('/login');
    }

    /** @test */
    public function unauthenticated_user_is_redirected_from_root(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    // ─── Login page ────────────────────────────────────────────────────────────

    /** @test */
    public function login_page_is_accessible_when_unauthenticated(): void
    {
        $this->get('/login')->assertOk();
    }

    /** @test */
    public function authenticated_user_is_redirected_away_from_login(): void
    {
        // The guest middleware redirects authenticated users away from /login
        $this->actingAs($this->user)->get('/login')->assertRedirect();
    }

    // ─── Authenticated navigation ──────────────────────────────────────────────

    /** @test */
    public function root_url_redirects_authenticated_user_to_their_department_dashboard(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertRedirect("/dashboard/{$this->user->department}");
    }

    /** @test */
    public function department_dashboard_returns_200(): void
    {
        $this->inertiaResponse("/dashboard/{$this->user->department}")
            ->assertOk()
            ->assertJsonPath('component', 'Dashboard/Index');
    }

    /** @test */
    public function participant_directory_returns_200(): void
    {
        $this->inertiaResponse('/participants')
            ->assertOk()
            ->assertJsonPath('component', 'Participants/Index');
    }

    /** @test */
    public function participant_profile_returns_200(): void
    {
        $this->inertiaResponse("/participants/{$this->participant->id}")
            ->assertOk()
            ->assertJsonPath('component', 'Participants/Show');
    }

    // ─── Page props ────────────────────────────────────────────────────────────

    /** @test */
    public function inertia_pages_receive_nav_groups_prop(): void
    {
        // nav_groups is always present (may be empty if no role permissions are seeded)
        $props = $this->inertiaGet('/participants');
        $this->assertArrayHasKey('nav_groups', $props);
    }

    /** @test */
    public function inertia_pages_receive_auth_prop(): void
    {
        $props = $this->inertiaGet('/participants');
        $this->assertArrayHasKey('auth', $props);
        $this->assertEquals($this->user->id, $props['auth']['user']['id']);
    }

    /** @test */
    public function participant_profile_has_all_required_props(): void
    {
        $props = $this->inertiaGet("/participants/{$this->participant->id}");

        foreach (['participant', 'addresses', 'contacts', 'flags', 'insurances', 'canEdit', 'canDelete', 'canViewAudit'] as $key) {
            $this->assertArrayHasKey($key, $props, "Profile page props must include '$key'");
        }
    }

    /** @test */
    public function participant_profile_shows_correct_mrn(): void
    {
        $props = $this->inertiaGet("/participants/{$this->participant->id}");

        $this->assertEquals($this->participant->mrn, $props['participant']['mrn']);
    }

    // ─── Cross-tenant isolation ────────────────────────────────────────────────

    /** @test */
    public function cannot_view_participant_from_different_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSite   = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTH']);
        $otherPpt    = Participant::factory()->create([
            'tenant_id' => $otherTenant->id,
            'site_id'   => $otherSite->id,
        ]);

        $this->actingAs($this->user)
            ->get("/participants/{$otherPpt->id}")
            ->assertStatus(403);
    }

    // ─── Logout ───────────────────────────────────────────────────────────────

    /** @test */
    public function logout_redirects_to_login(): void
    {
        $this->actingAs($this->user)
            ->post('/auth/logout')
            ->assertRedirect('/login');
    }

    /** @test */
    public function after_logout_participant_directory_redirects_to_login(): void
    {
        $this->actingAs($this->user)->post('/auth/logout');
        $this->get('/participants')->assertRedirect('/login');
    }
}
