<?php

// ─── SuperAdminPanelTest ───────────────────────────────────────────────────────
// Feature tests for the Nostos Super Admin Panel (Phase 10B).
//
// Coverage:
//   - isSuperAdmin (role) can access the panel index
//   - isDeptSuperAdmin (department='super_admin') can access the panel index
//   - Regular user cannot access the panel (403)
//   - Tenants endpoint returns all tenants with counts
//   - Health endpoint returns table_counts and queues
//   - Onboard wizard creates tenant + site + user in a transaction
//   - Onboard wizard validates required fields (422)
//   - Onboard wizard rejects duplicate tenant name (422)
//   - Onboard wizard rejects duplicate admin email (422)
//   - Audit log is created on panel access (action: super_admin_panel.accessed)
//   - Audit log is created on tenant onboard (action: tenant.onboarded)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function makeDeptSuperAdmin(): User
    {
        return User::factory()->create(['department' => 'super_admin', 'role' => 'standard']);
    }

    private function makeRegularUser(): User
    {
        return User::factory()->create(['department' => 'it_admin', 'role' => 'admin']);
    }

    private function validOnboardPayload(string $suffix = ''): array
    {
        return [
            'tenant_name'         => 'Test PACE Org' . $suffix,
            'transport_mode'      => 'direct',
            'auto_logout_minutes' => 15,
            'site_name'           => 'Main Site',
            'site_city'           => 'Springfield',
            'site_state'          => 'IL',
            'admin_first_name'    => 'Jane',
            'admin_last_name'     => 'Admin',
            'admin_email'         => 'jane.admin' . $suffix . '@testpace.test',
            'admin_department'    => 'it_admin',
        ];
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_super_admin_role_can_access_panel(): void
    {
        $user = $this->makeSuperAdmin();

        $this->actingAs($user)
            ->get('/super-admin-panel')
            ->assertOk();
    }

    public function test_dept_super_admin_can_access_panel(): void
    {
        $user = $this->makeDeptSuperAdmin();

        $this->actingAs($user)
            ->get('/super-admin-panel')
            ->assertOk();
    }

    public function test_regular_user_cannot_access_panel(): void
    {
        $user = $this->makeRegularUser();

        $this->actingAs($user)
            ->get('/super-admin-panel')
            ->assertForbidden();
    }

    // ── Tenants endpoint ──────────────────────────────────────────────────────

    public function test_tenants_endpoint_returns_tenant_list_with_counts(): void
    {
        $user = $this->makeSuperAdmin();

        $this->actingAs($user)
            ->getJson('/super-admin-panel/tenants')
            ->assertOk()
            ->assertJsonStructure(['tenants' => [
                '*' => ['id', 'name', 'transport_mode', 'user_count', 'participant_count', 'site_count'],
            ]]);
    }

    public function test_regular_user_cannot_access_tenants_endpoint(): void
    {
        $user = $this->makeRegularUser();

        $this->actingAs($user)
            ->getJson('/super-admin-panel/tenants')
            ->assertForbidden();
    }

    // ── Health endpoint ───────────────────────────────────────────────────────

    public function test_health_endpoint_returns_table_counts_and_queues(): void
    {
        $user = $this->makeSuperAdmin();

        $this->actingAs($user)
            ->getJson('/super-admin-panel/health')
            ->assertOk()
            ->assertJsonStructure(['table_counts', 'queues' => ['failed_jobs', 'pending_jobs']]);
    }

    // ── Onboard wizard ────────────────────────────────────────────────────────

    public function test_onboard_creates_tenant_site_and_user(): void
    {
        $actor   = $this->makeSuperAdmin();
        $payload = $this->validOnboardPayload('_onboard1');

        $response = $this->actingAs($actor)
            ->postJson('/super-admin-panel/onboard', $payload)
            ->assertCreated()
            ->assertJsonStructure(['message', 'ids' => ['tenant_id', 'site_id', 'user_id']]);

        $ids = $response->json('ids');

        // Verify all three records exist in the DB
        $this->assertDatabaseHas('shared_tenants', ['id' => $ids['tenant_id'], 'name' => $payload['tenant_name']]);
        $this->assertDatabaseHas('shared_sites',   ['id' => $ids['site_id'],   'tenant_id' => $ids['tenant_id']]);
        $this->assertDatabaseHas('shared_users',   ['id' => $ids['user_id'],   'email' => $payload['admin_email']]);
    }

    public function test_onboard_rejects_missing_required_fields(): void
    {
        $actor = $this->makeSuperAdmin();

        $this->actingAs($actor)
            ->postJson('/super-admin-panel/onboard', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_name', 'transport_mode', 'site_name', 'admin_email']);
    }

    public function test_onboard_rejects_duplicate_tenant_name(): void
    {
        $actor = $this->makeSuperAdmin();

        // Create a tenant with the same name first
        Tenant::factory()->create(['name' => 'Duplicate Org']);

        $payload = $this->validOnboardPayload('_dup');
        $payload['tenant_name'] = 'Duplicate Org';

        $this->actingAs($actor)
            ->postJson('/super-admin-panel/onboard', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_name']);
    }

    public function test_onboard_rejects_duplicate_admin_email(): void
    {
        $actor = $this->makeSuperAdmin();

        // Create a user with the same email first
        User::factory()->create(['email' => 'duplicate@testpace.test']);

        $payload = $this->validOnboardPayload('_dup2');
        $payload['admin_email'] = 'duplicate@testpace.test';

        $this->actingAs($actor)
            ->postJson('/super-admin-panel/onboard', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_email']);
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function test_panel_access_creates_audit_log(): void
    {
        $actor = $this->makeSuperAdmin();

        $this->actingAs($actor)->get('/super-admin-panel');

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'  => 'super_admin_panel.accessed',
            'user_id' => $actor->id,
        ]);
    }

    public function test_onboard_creates_audit_log_with_actor_user_id(): void
    {
        $actor   = $this->makeSuperAdmin();
        $payload = $this->validOnboardPayload('_audit');

        $this->actingAs($actor)
            ->postJson('/super-admin-panel/onboard', $payload)
            ->assertCreated();

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'  => 'tenant.onboarded',
            'user_id' => $actor->id,
        ]);
    }
}
