<?php

// ─── ImpersonationTest ────────────────────────────────────────────────────────
// Feature tests for the super-admin impersonation system.
//
// Coverage:
//   - Super-admin can impersonate a same-tenant user
//   - Super-admin cannot impersonate a different-tenant user (403)
//   - Non-super-admin cannot start impersonation (403)
//   - Impersonation sets session + creates audit log with real SA's user ID
//   - Stop impersonation clears session + creates audit log
//   - View-as sets viewing_as_department in session
//   - Clear view-as removes session key
//   - Super-admin can access any department dashboard directly
//   - Impersonating user redirects dashboard to their department
//   - Inertia shared data contains impersonation state
//   - Users endpoint returns only same-tenant users (excludes self)
//   - Users endpoint returns 403 for non-super-admin
//   - CheckDepartmentAccess uses impersonated user's permissions
//   - Audit log always uses real super-admin's user ID (never impersonated)
//   - Invalid view-as department is rejected (422)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Create a super-admin user in a fresh tenant. */
    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * Create a regular user in the same tenant as $superAdmin.
     * Optionally override department.
     */
    private function makeTenantUser(User $superAdmin, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'tenant_id'  => $superAdmin->tenant_id,
            'department' => 'primary_care',
        ], $overrides));
    }

    /** Start impersonation for $superAdmin as $target by posting to the API. */
    private function startImpersonation(User $superAdmin, User $target): void
    {
        $this->actingAs($superAdmin)
            ->postJson("/super-admin/impersonate/{$target->id}")
            ->assertOk();
    }

    // ── Start impersonation ───────────────────────────────────────────────────

    public function test_super_admin_can_impersonate_same_tenant_user(): void
    {
        $sa     = $this->makeSuperAdmin();
        $target = $this->makeTenantUser($sa);

        $this->actingAs($sa)
            ->postJson("/super-admin/impersonate/{$target->id}")
            ->assertOk()
            ->assertJson([
                'impersonating' => true,
                'user' => ['id' => $target->id],
            ]);
    }

    public function test_super_admin_cannot_impersonate_different_tenant_user(): void
    {
        $sa = $this->makeSuperAdmin();

        // User from a completely different tenant
        $otherTenantUser = User::factory()->create();

        $this->actingAs($sa)
            ->postJson("/super-admin/impersonate/{$otherTenantUser->id}")
            ->assertForbidden();
    }

    public function test_non_super_admin_cannot_start_impersonation(): void
    {
        $admin  = User::factory()->create(['role' => 'admin', 'department' => 'it_admin']);
        $target = $this->makeTenantUser($admin);

        $this->actingAs($admin)
            ->postJson("/super-admin/impersonate/{$target->id}")
            ->assertForbidden();
    }

    public function test_impersonation_sets_session_and_creates_audit_log(): void
    {
        $sa     = $this->makeSuperAdmin();
        $target = $this->makeTenantUser($sa);

        $this->actingAs($sa)
            ->postJson("/super-admin/impersonate/{$target->id}")
            ->assertOk();

        // Session should store the target's ID
        $this->assertEquals($target->id, session(ImpersonationService::SESSION_USER_ID));

        // Audit log should record the REAL super-admin's user ID
        $this->assertDatabaseHas('shared_audit_logs', [
            'action'  => 'super_admin.impersonation.start',
            'user_id' => $sa->id,           // <-- real SA, not target
        ]);
    }

    // ── Stop impersonation ────────────────────────────────────────────────────

    public function test_stop_impersonation_clears_session_and_creates_audit_log(): void
    {
        $sa     = $this->makeSuperAdmin();
        $target = $this->makeTenantUser($sa);

        $this->startImpersonation($sa, $target);

        $this->actingAs($sa)
            ->deleteJson('/super-admin/impersonate')
            ->assertOk()
            ->assertJson(['impersonating' => false]);

        // Session key should be gone
        $this->assertNull(session(ImpersonationService::SESSION_USER_ID));

        // Stop event logged with real SA's ID
        $this->assertDatabaseHas('shared_audit_logs', [
            'action'  => 'super_admin.impersonation.stop',
            'user_id' => $sa->id,
        ]);
    }

    public function test_audit_log_always_uses_real_super_admin_id(): void
    {
        $sa     = $this->makeSuperAdmin();
        $target = $this->makeTenantUser($sa);

        $this->startImpersonation($sa, $target);

        // The start log's user_id must be the SA's ID, never the target's
        $log = AuditLog::where('action', 'super_admin.impersonation.start')->first();
        $this->assertNotNull($log);
        $this->assertEquals($sa->id, $log->user_id);
        $this->assertNotEquals($target->id, $log->user_id);
    }

    // ── Dashboard view-as ─────────────────────────────────────────────────────

    public function test_view_as_sets_viewing_department_in_session(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)
            ->postJson('/super-admin/view-as', ['department' => 'primary_care'])
            ->assertOk()
            ->assertJson(['viewing_as_department' => 'primary_care']);

        $this->assertEquals('primary_care', session(ImpersonationService::SESSION_VIEW_AS));
    }

    public function test_clear_view_as_removes_session_key(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)->postJson('/super-admin/view-as', ['department' => 'finance']);

        $this->actingAs($sa)
            ->deleteJson('/super-admin/view-as')
            ->assertOk()
            ->assertJson(['viewing_as_department' => null]);

        $this->assertNull(session(ImpersonationService::SESSION_VIEW_AS));
    }

    public function test_invalid_view_as_department_is_rejected(): void
    {
        $sa = $this->makeSuperAdmin();

        $this->actingAs($sa)
            ->postJson('/super-admin/view-as', ['department' => 'not_a_real_dept'])
            ->assertUnprocessable();
    }

    // ── Dashboard routing ─────────────────────────────────────────────────────

    public function test_super_admin_can_access_any_department_dashboard_directly(): void
    {
        $sa = $this->makeSuperAdmin();

        // Super-admin should NOT get 403 when hitting any dept dashboard
        $this->actingAs($sa)
            ->get('/dashboard/finance')
            ->assertOk();

        $this->actingAs($sa)
            ->get('/dashboard/qa_compliance')
            ->assertOk();
    }

    public function test_impersonating_user_redirects_dashboard_to_their_department(): void
    {
        $sa     = $this->makeSuperAdmin();
        $target = $this->makeTenantUser($sa, ['department' => 'social_work']);

        $this->startImpersonation($sa, $target);

        // Without X-Inertia header, Inertia::location() issues a standard 302 redirect
        // to the impersonated user's dept dashboard.
        $this->actingAs($sa)
            ->get('/dashboard/primary_care')
            ->assertRedirect('/dashboard/social_work');
    }

    // ── Users endpoint ────────────────────────────────────────────────────────

    public function test_users_endpoint_returns_only_same_tenant_users(): void
    {
        $sa      = $this->makeSuperAdmin();
        $sameA   = $this->makeTenantUser($sa);
        $sameB   = $this->makeTenantUser($sa);
        $other   = User::factory()->create(); // different tenant

        $response = $this->actingAs($sa)
            ->getJson('/super-admin/users')
            ->assertOk();

        $ids = collect($response->json('users'))->pluck('id')->toArray();

        $this->assertContains($sameA->id, $ids);
        $this->assertContains($sameB->id, $ids);
        $this->assertNotContains($other->id, $ids);
        // Super-admin themselves should not appear
        $this->assertNotContains($sa->id, $ids);
    }

    public function test_users_endpoint_returns_403_for_non_super_admin(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'department' => 'it_admin']);

        $this->actingAs($user)
            ->getJson('/super-admin/users')
            ->assertForbidden();
    }

    // ── CheckDepartmentAccess with impersonation ──────────────────────────────

    public function test_checkdepartmentaccess_uses_impersonated_user_permissions(): void
    {
        $sa     = $this->makeSuperAdmin();
        $target = $this->makeTenantUser($sa, ['department' => 'primary_care', 'role' => 'standard']);

        $this->startImpersonation($sa, $target);

        // Verify ImpersonationService correctly exposes the impersonated user's
        // dept/role, which CheckDepartmentAccess uses for RBAC checks.
        $service = app(\App\Services\ImpersonationService::class);

        $this->assertTrue($service->isImpersonating());

        $effective = $service->getImpersonatedUser();
        $this->assertNotNull($effective);
        $this->assertEquals($target->id, $effective->id);
        $this->assertEquals('primary_care', $effective->department);
        $this->assertEquals('standard', $effective->role);

        // Super-admin is NOT the effective user when impersonating
        $this->assertNotEquals($sa->id, $effective->id);
    }

    // ── Inertia shared data ───────────────────────────────────────────────────

    public function test_impersonation_props_returned_in_inertia_shared_data(): void
    {
        $sa     = $this->makeSuperAdmin();
        $target = $this->makeTenantUser($sa, ['department' => 'therapies']);

        $this->startImpersonation($sa, $target);

        // assertInertia() from the Inertia testing package handles version headers
        // automatically and lets us assert on the rendered component + props.
        $this->actingAs($sa)
            ->get('/dashboard/therapies')
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->where('auth.user.department', 'therapies')
                ->where('auth.user.is_super_admin', false)
                ->where('auth.real_user.id', $sa->id)
                ->where('impersonation.active', true)
                ->where('impersonation.user.id', $target->id)
            );
    }
}
