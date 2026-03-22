<?php

namespace Tests\Unit;

use App\Models\RolePermission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PermissionService $service;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->service = new PermissionService();
        $this->tenant  = Tenant::factory()->create();
    }

    private function user(string $department, string $role = 'standard'): User
    {
        return User::factory()->make([
            'tenant_id'  => $this->tenant->id,
            'department' => $department,
            'role'       => $role,
        ]);
    }

    // ─── IT Admin sees everything ─────────────────────────────────────────────

    public function test_it_admin_can_view_all_modules(): void
    {
        $user    = $this->user('it_admin', 'admin');
        $visible = $this->service->visibleModules($user);

        $this->assertContains('clinical_notes',      $visible->toArray());
        $this->assertContains('billing',             $visible->toArray());
        $this->assertContains('transport_dashboard', $visible->toArray());
        $this->assertContains('audit_log',           $visible->toArray());
        $this->assertContains('system_settings',     $visible->toArray());
    }

    // ─── Finance cannot see clinical modules ──────────────────────────────────

    public function test_finance_cannot_view_clinical_notes(): void
    {
        $user   = $this->user('finance');
        $result = $this->service->can($user, 'clinical_notes', 'can_view');

        $this->assertFalse($result);
    }

    public function test_finance_can_view_billing(): void
    {
        $user   = $this->user('finance');
        $result = $this->service->can($user, 'billing', 'can_view');

        $this->assertTrue($result);
    }

    // ─── Transportation module isolation ──────────────────────────────────────

    public function test_transportation_admin_can_crud_vendors(): void
    {
        $user = $this->user('transportation', 'admin');

        $this->assertTrue($this->service->can($user, 'vendors', 'can_view'));
        $this->assertTrue($this->service->can($user, 'vendors', 'can_create'));
        $this->assertTrue($this->service->can($user, 'vendors', 'can_delete'));
    }

    public function test_primary_care_cannot_view_vendors(): void
    {
        $user = $this->user('primary_care');

        $this->assertFalse($this->service->can($user, 'vendors', 'can_view'));
    }

    public function test_primary_care_cannot_view_broker_settings(): void
    {
        $user = $this->user('primary_care');

        $this->assertFalse($this->service->can($user, 'broker_settings', 'can_view'));
    }

    // ─── QA Compliance ────────────────────────────────────────────────────────

    public function test_qa_compliance_can_view_clinical_notes_readonly(): void
    {
        $user = $this->user('qa_compliance', 'standard');

        $this->assertTrue($this->service->can($user, 'clinical_notes', 'can_view'));
        $this->assertFalse($this->service->can($user, 'clinical_notes', 'can_create'));
        $this->assertFalse($this->service->can($user, 'clinical_notes', 'can_edit'));
    }

    public function test_qa_compliance_can_crud_incident_reports(): void
    {
        $user = $this->user('qa_compliance', 'standard');

        $this->assertTrue($this->service->can($user, 'incident_reports', 'can_view'));
        $this->assertTrue($this->service->can($user, 'incident_reports', 'can_create'));
        $this->assertTrue($this->service->can($user, 'incident_reports', 'can_edit'));
    }

    // ─── Admin role elevation ─────────────────────────────────────────────────

    public function test_admin_role_has_full_access_to_dept_modules(): void
    {
        $standardUser = $this->user('pharmacy', 'standard');
        $adminUser    = $this->user('pharmacy', 'admin');

        // Standard can view medications
        $this->assertTrue($this->service->can($standardUser, 'medications', 'can_view'));

        // Admin can also delete medications
        $this->assertTrue($this->service->can($adminUser, 'medications', 'can_delete'));
    }

    // ─── Permission map structure ─────────────────────────────────────────────

    public function test_permission_map_returns_correct_shape(): void
    {
        $user = $this->user('idt');
        $user->save();  // needs to be persisted for the DB query

        $map = $this->service->permissionMap($user);

        $this->assertIsArray($map);
        $this->assertArrayHasKey('idt_dashboard', $map);

        $perm = $map['idt_dashboard'];
        $this->assertArrayHasKey('view',   $perm);
        $this->assertArrayHasKey('create', $perm);
        $this->assertArrayHasKey('edit',   $perm);
        $this->assertArrayHasKey('delete', $perm);
        $this->assertArrayHasKey('export', $perm);
    }
}
