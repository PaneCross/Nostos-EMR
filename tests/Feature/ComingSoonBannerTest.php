<?php

// ─── ComingSoonBannerTest ──────────────────────────────────────────────────────
// Verifies that Phase 8A nav audit routing is correct:
//
// CAT1 (transport stubs)  — return 200 Inertia ComingSoon page, mode='transport'
// CAT2 (live redirects)   — return 302 redirect to correct live pages
// CAT3 (planned features) — return 200 Inertia ComingSoon page, mode='planned'
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComingSoonBannerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User   $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'CSB',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $site->id,
            'department' => 'transportation',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
    }

    // ── Inertia helper: bypasses version conflict (409) ──────────────────────

    private function inertiaGet(string $url): array
    {
        // Bind a no-op version check to avoid Inertia 409 Conflict in tests
        $this->app->bind(
            \App\Http\Middleware\HandleInertiaRequests::class,
            fn () => new class extends \App\Http\Middleware\HandleInertiaRequests {
                public function version(\Illuminate\Http\Request $r): ?string { return null; }
            }
        );

        $response = $this->actingAs($this->user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get($url);

        $response->assertOk();
        return $response->json('props') ?? [];
    }

    // ── CAT1: Transport stubs return Inertia ComingSoon with mode='transport' ──

    public function test_transport_scheduler_returns_coming_soon_transport_mode(): void
    {
        $props = $this->inertiaGet('/transport/scheduler');
        $this->assertEquals('transport', $props['mode'] ?? null);
    }

    public function test_transport_map_returns_coming_soon_transport_mode(): void
    {
        $props = $this->inertiaGet('/transport/map');
        $this->assertEquals('transport', $props['mode'] ?? null);
    }

    public function test_transport_vehicles_returns_coming_soon_transport_mode(): void
    {
        $props = $this->inertiaGet('/transport/vehicles');
        $this->assertEquals('transport', $props['mode'] ?? null);
    }

    // ── CAT2: Live redirects ────────────────────────────────────────────────────

    public function test_idt_minutes_redirects_to_idt_meetings(): void
    {
        // W3-2: /idt/minutes now redirects to the live Meetings list, not /idt dashboard
        $this->actingAs($this->user)
            ->get('/idt/minutes')
            ->assertRedirect('/idt/meetings');
    }

    public function test_idt_sdr_redirects_to_sdrs(): void
    {
        $this->actingAs($this->user)
            ->get('/idt/sdr')
            ->assertRedirect('/sdrs');
    }

    public function test_admin_locations_returns_locations_page(): void
    {
        // /admin/locations is a live Inertia page (LocationController::managePage),
        // not a redirect — it directly serves the locations management page.
        $this->actingAs($this->user)
            ->get('/admin/locations')
            ->assertOk();
    }

    public function test_admin_users_redirects_to_it_admin_users(): void
    {
        $this->actingAs($this->user)
            ->get('/admin/users')
            ->assertRedirect('/it-admin/users');
    }

    public function test_billing_index_redirects_to_finance_dashboard(): void
    {
        $this->actingAs($this->user)
            ->get('/billing')
            ->assertRedirect('/finance/dashboard');
    }

    public function test_billing_capitation_requires_finance_access(): void
    {
        // Phase 9B: /billing/capitation is now a live Inertia page (not a redirect).
        // Non-finance users (e.g. transportation) are denied with 403.
        $this->actingAs($this->user)
            ->get('/billing/capitation')
            ->assertForbidden();
    }

    // ── CAT3: Planned features return Inertia ComingSoon with mode='planned' ───

    public function test_clinical_orders_returns_coming_soon_planned_mode(): void
    {
        $props = $this->inertiaGet('/clinical/orders');
        $this->assertEquals('planned', $props['mode'] ?? null);
    }

    public function test_scheduling_day_center_returns_ok(): void
    {
        // W3-2: /scheduling/day-center is now a live Inertia page (DayCenterController)
        $this->actingAs($this->user)
            ->get('/scheduling/day-center')
            ->assertOk();
    }

    public function test_billing_claims_redirects_to_encounters(): void
    {
        // Phase 9B: /billing/claims now redirects to the live encounter submission queue.
        $this->actingAs($this->user)
            ->get('/billing/claims')
            ->assertRedirect('/billing/encounters');
    }

    public function test_reports_returns_ok(): void
    {
        // W3-2: /reports is now a live Inertia page (ReportsController)
        $this->actingAs($this->user)
            ->get('/reports')
            ->assertOk();
    }

    public function test_admin_settings_returns_ok(): void
    {
        // W3-2: /admin/settings is now a live Inertia page (SystemSettingsController)
        $this->actingAs($this->user)
            ->get('/admin/settings')
            ->assertOk();
    }

    // ── Auth guard ─────────────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected_from_transport_stubs(): void
    {
        $this->get('/transport/scheduler')
            ->assertRedirect('/login');
    }
}
