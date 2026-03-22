<?php

// ─── ItAdminTest ──────────────────────────────────────────────────────────────
// Feature tests for the IT Admin panel.
//
// Coverage:
//   - Integrations page requires it_admin department
//   - Integrations page renders Inertia component
//   - Integration log paginated JSON endpoint
//   - Retry failed integration dispatches job + increments retry_count
//   - Cannot retry non-failed integration (422)
//   - Cannot retry cross-tenant integration (403)
//   - Users page renders with user list
//   - Deactivate user sets is_active=false + 200
//   - Cannot deactivate self (422)
//   - Reactivate user sets is_active=true + 200
//   - Audit page renders Inertia component
//   - Audit log JSON endpoint returns paginated results
//   - Audit log filterable by action
//   - Audit CSV export returns text/csv
//   - Non-it_admin user is rejected (403)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\ProcessHl7AdtJob;
use App\Jobs\ProcessLabResultJob;
use App\Models\IntegrationLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ItAdminTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeItAdmin(): User
    {
        return User::factory()->create(['department' => 'it_admin']);
    }

    private function makeIntegrationLog(User $admin, array $overrides = []): IntegrationLog
    {
        return IntegrationLog::factory()->create(array_merge([
            'tenant_id' => $admin->tenant_id,
        ], $overrides));
    }

    // ── Integrations panel ────────────────────────────────────────────────────

    public function test_integrations_page_requires_it_admin(): void
    {
        $user = User::factory()->create(['department' => 'primary_care']);

        $this->actingAs($user)
            ->get('/it-admin/integrations')
            ->assertForbidden();
    }

    public function test_integrations_page_renders_inertia(): void
    {
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->get('/it-admin/integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ItAdmin/Integrations'));
    }

    public function test_integrations_page_has_summary_and_log_props(): void
    {
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->get('/it-admin/integrations')
            ->assertInertia(fn ($page) => $page
                ->has('summary')
                ->has('recentLog')
                ->has('connectorTypes')
            );
    }

    public function test_integration_log_json_endpoint_returns_paginated(): void
    {
        $admin = $this->makeItAdmin();
        $this->makeIntegrationLog($admin);
        $this->makeIntegrationLog($admin);

        $this->actingAs($admin)
            ->getJson('/it-admin/integrations/log')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'current_page']);
    }

    public function test_integration_log_scoped_to_tenant(): void
    {
        $admin = $this->makeItAdmin();
        $other = User::factory()->create(['department' => 'it_admin']);

        $this->makeIntegrationLog($admin); // own tenant
        IntegrationLog::factory()->create(['tenant_id' => $other->tenant_id]); // other tenant

        $response = $this->actingAs($admin)
            ->getJson('/it-admin/integrations/log')
            ->assertOk();

        $this->assertEquals(1, $response->json('total'));
    }

    public function test_retry_failed_integration_dispatches_job(): void
    {
        Queue::fake();
        $admin = $this->makeItAdmin();
        $log   = $this->makeIntegrationLog($admin, [
            'status'         => 'failed',
            'connector_type' => 'hl7_adt',
            'raw_payload'    => ['message_type' => 'A01', 'patient_mrn' => 'X'],
        ]);

        $this->actingAs($admin)
            ->postJson("/it-admin/integrations/{$log->id}/retry")
            ->assertOk()
            ->assertJsonPath('retried', true);

        Queue::assertPushed(ProcessHl7AdtJob::class);
        $this->assertDatabaseHas('emr_integration_log', ['id' => $log->id, 'status' => 'retried']);
    }

    public function test_retry_dispatches_lab_job_for_lab_connector(): void
    {
        Queue::fake();
        $admin = $this->makeItAdmin();
        $log   = $this->makeIntegrationLog($admin, [
            'status'         => 'failed',
            'connector_type' => 'lab_results',
            'raw_payload'    => ['patient_mrn' => 'X', 'test_code' => 'HGB'],
        ]);

        $this->actingAs($admin)
            ->postJson("/it-admin/integrations/{$log->id}/retry")
            ->assertOk();

        Queue::assertPushed(ProcessLabResultJob::class);
    }

    public function test_cannot_retry_non_failed_integration(): void
    {
        $admin = $this->makeItAdmin();
        $log   = $this->makeIntegrationLog($admin, ['status' => 'processed']);

        $this->actingAs($admin)
            ->postJson("/it-admin/integrations/{$log->id}/retry")
            ->assertUnprocessable();
    }

    public function test_cannot_retry_cross_tenant_integration(): void
    {
        $admin = $this->makeItAdmin();
        $cross = IntegrationLog::factory()->failed()->create(); // different tenant

        $this->actingAs($admin)
            ->postJson("/it-admin/integrations/{$cross->id}/retry")
            ->assertForbidden();
    }

    // ── User management ───────────────────────────────────────────────────────

    public function test_users_page_renders_inertia(): void
    {
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->get('/it-admin/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ItAdmin/Users'));
    }

    public function test_users_page_has_users_prop(): void
    {
        $admin = $this->makeItAdmin();
        // Create another user in same tenant
        User::factory()->create(['tenant_id' => $admin->tenant_id]);

        $this->actingAs($admin)
            ->get('/it-admin/users')
            ->assertInertia(fn ($page) => $page->has('users'));
    }

    public function test_deactivate_user_sets_inactive(): void
    {
        $admin = $this->makeItAdmin();
        $user  = User::factory()->create(['tenant_id' => $admin->tenant_id, 'is_active' => true]);

        $this->actingAs($admin)
            ->postJson("/it-admin/users/{$user->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('deactivated', true);

        $this->assertDatabaseHas('shared_users', ['id' => $user->id, 'is_active' => false]);
    }

    public function test_cannot_deactivate_self(): void
    {
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->postJson("/it-admin/users/{$admin->id}/deactivate")
            ->assertUnprocessable();
    }

    public function test_reactivate_user_sets_active(): void
    {
        $admin = $this->makeItAdmin();
        $user  = User::factory()->create(['tenant_id' => $admin->tenant_id, 'is_active' => false]);

        $this->actingAs($admin)
            ->postJson("/it-admin/users/{$user->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('reactivated', true);

        $this->assertDatabaseHas('shared_users', ['id' => $user->id, 'is_active' => true]);
    }

    public function test_provision_user_creates_record_and_sends_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->postJson('/it-admin/users', [
                'first_name' => 'Alice',
                'last_name'  => 'Demo',
                'email'      => 'alice.demo@sunrisepace-demo.test',
                'department' => 'social_work',
                'role'       => 'standard',
            ])
            ->assertStatus(201)
            ->assertJsonPath('user.email', 'alice.demo@sunrisepace-demo.test');

        $this->assertDatabaseHas('shared_users', ['email' => 'alice.demo@sunrisepace-demo.test', 'is_active' => true]);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\WelcomeEmail::class);
    }

    public function test_provision_user_validates_required_fields(): void
    {
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->postJson('/it-admin/users', [
                'first_name' => 'Alice',
                // missing last_name, email, department, role
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['last_name', 'email', 'department', 'role']);
    }

    public function test_provision_user_rejects_invalid_department(): void
    {
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->postJson('/it-admin/users', [
                'first_name' => 'Alice',
                'last_name'  => 'Demo',
                'email'      => 'alice@example.com',
                'department' => 'not_a_real_dept',
                'role'       => 'standard',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['department']);
    }

    public function test_deactivate_user_invalidates_sessions(): void
    {
        $admin = $this->makeItAdmin();
        $user  = User::factory()->create(['tenant_id' => $admin->tenant_id, 'is_active' => true]);

        // Create a fake session for the user
        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id'         => \Illuminate\Support\Str::random(40),
            'user_id'    => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload'    => base64_encode('{}'),
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)
            ->postJson("/it-admin/users/{$user->id}/deactivate")
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_reset_access_invalidates_sessions(): void
    {
        $admin = $this->makeItAdmin();
        $user  = User::factory()->create(['tenant_id' => $admin->tenant_id, 'is_active' => true]);

        // Create a fake session for the user
        \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id'         => \Illuminate\Support\Str::random(40),
            'user_id'    => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload'    => base64_encode('{}'),
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)
            ->postJson("/it-admin/users/{$user->id}/reset-access")
            ->assertOk()
            ->assertJsonPath('reset', true);

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    // ── Audit log viewer ──────────────────────────────────────────────────────

    public function test_audit_page_renders_inertia(): void
    {
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->get('/it-admin/audit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ItAdmin/Audit'));
    }

    public function test_audit_page_has_initial_count_prop(): void
    {
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->get('/it-admin/audit')
            ->assertInertia(fn ($page) => $page->has('initialCount'));
    }

    public function test_audit_log_json_returns_paginated(): void
    {
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->getJson('/it-admin/audit/log')
            ->assertOk()
            ->assertJsonStructure(['data', 'total', 'current_page']);
    }

    public function test_audit_log_csv_export_returns_csv(): void
    {
        $admin = $this->makeItAdmin();

        $this->actingAs($admin)
            ->get('/it-admin/audit/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_non_it_admin_cannot_access_audit_page(): void
    {
        $user = User::factory()->create(['department' => 'finance']);

        $this->actingAs($user)
            ->get('/it-admin/audit')
            ->assertForbidden();
    }
}
