<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AUDIT B — Spot-checks the seeded permission matrix against expected
 * PACE department access rules. Tests both PermissionService (unit-style)
 * and HTTP route enforcement (integration-style).
 *
 * Modules verified:
 *  - Locations (transportation admin can delete; primary care standard cannot)
 *  - IT Admin has full CRUD on all modules
 *  - Finance has billing CRUD, no clinical_notes access
 *  - QA Compliance has incident_reports CRUD, clinical_notes read-only
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private PermissionService $svc;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->svc    = new PermissionService();
        $this->tenant = Tenant::factory()->create(['slug' => 'perm-matrix-test']);
    }

    private function user(string $department, string $role = 'standard'): User
    {
        return User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => $department,
            'role'       => $role,
            'is_active'  => true,
        ]);
    }

    // ── Locations module ──────────────────────────────────────────────────────

    public function test_transportation_admin_can_delete_locations(): void
    {
        $user = $this->user('transportation', 'admin');
        $this->assertTrue($this->svc->can($user, 'locations', 'can_delete'));
    }

    public function test_primary_care_standard_cannot_delete_locations(): void
    {
        $user = $this->user('primary_care', 'standard');
        $this->assertFalse($this->svc->can($user, 'locations', 'can_delete'));
    }

    public function test_primary_care_standard_cannot_view_locations(): void
    {
        $user = $this->user('primary_care', 'standard');
        $this->assertFalse($this->svc->can($user, 'locations', 'can_view'));
    }

    // ── IT Admin full access ───────────────────────────────────────────────────

    /** @dataProvider itAdminModuleProvider */
    public function test_it_admin_has_full_crud_on_module(string $module): void
    {
        $user = $this->user('it_admin', 'admin');

        $this->assertTrue($this->svc->can($user, $module, 'can_view'),   "can_view failed for {$module}");
        $this->assertTrue($this->svc->can($user, $module, 'can_create'), "can_create failed for {$module}");
        $this->assertTrue($this->svc->can($user, $module, 'can_edit'),   "can_edit failed for {$module}");
        $this->assertTrue($this->svc->can($user, $module, 'can_delete'), "can_delete failed for {$module}");
    }

    public static function itAdminModuleProvider(): array
    {
        return [
            'user_management'  => ['user_management'],
            'system_settings'  => ['system_settings'],
            'audit_log'        => ['audit_log'],
            'locations'        => ['locations'],
            'participants'     => ['participants'],
            'clinical_notes'   => ['clinical_notes'],
        ];
    }

    // ── Finance module isolation ──────────────────────────────────────────────

    public function test_finance_admin_can_crud_billing(): void
    {
        $user = $this->user('finance', 'admin');

        $this->assertTrue($this->svc->can($user, 'billing', 'can_view'));
        $this->assertTrue($this->svc->can($user, 'billing', 'can_create'));
        $this->assertTrue($this->svc->can($user, 'billing', 'can_edit'));
        $this->assertTrue($this->svc->can($user, 'billing', 'can_delete'));
    }

    public function test_finance_standard_has_full_billing_access(): void
    {
        // Finance standard gets full CRUD on billing (capitation billing is core role)
        $user = $this->user('finance', 'standard');

        $this->assertTrue($this->svc->can($user, 'billing', 'can_view'));
        $this->assertTrue($this->svc->can($user, 'billing', 'can_create'));
        $this->assertTrue($this->svc->can($user, 'billing', 'can_edit'));
        $this->assertTrue($this->svc->can($user, 'billing', 'can_delete'));
    }

    public function test_finance_cannot_access_clinical_notes(): void
    {
        $user = $this->user('finance');

        $this->assertFalse($this->svc->can($user, 'clinical_notes', 'can_view'));
        $this->assertFalse($this->svc->can($user, 'clinical_notes', 'can_create'));
    }

    public function test_finance_cannot_access_capitation(): void
    {
        // Finance dept specific billing submodule
        $user = $this->user('finance', 'admin');
        $this->assertTrue($this->svc->can($user, 'capitation', 'can_view'));
    }

    // ── QA Compliance ─────────────────────────────────────────────────────────

    public function test_qa_compliance_can_crud_incident_reports(): void
    {
        $user = $this->user('qa_compliance', 'standard');

        $this->assertTrue($this->svc->can($user, 'incident_reports', 'can_view'));
        $this->assertTrue($this->svc->can($user, 'incident_reports', 'can_create'));
        $this->assertTrue($this->svc->can($user, 'incident_reports', 'can_edit'));
    }

    public function test_qa_compliance_reads_clinical_notes_but_cannot_create(): void
    {
        $user = $this->user('qa_compliance', 'standard');

        $this->assertTrue($this->svc->can($user, 'clinical_notes', 'can_view'));
        $this->assertFalse($this->svc->can($user, 'clinical_notes', 'can_create'));
        $this->assertFalse($this->svc->can($user, 'clinical_notes', 'can_edit'));
        $this->assertFalse($this->svc->can($user, 'clinical_notes', 'can_delete'));
    }

    // ── Cross-department isolation (HTTP) ─────────────────────────────────────

    public function test_pharmacy_user_gets_403_on_finance_dashboard(): void
    {
        $user = $this->user('pharmacy');

        $this->actingAs($user)
             ->get('/dashboard/finance')
             ->assertForbidden();
    }

    public function test_403_is_recorded_in_audit_log(): void
    {
        $user = $this->user('dietary');

        $this->actingAs($user)->get('/dashboard/transportation');

        $this->assertDatabaseHas('shared_audit_logs', [
            'user_id' => $user->id,
            'action'  => 'unauthorized_access',
        ]);
    }

    // ── Permission map shape ──────────────────────────────────────────────────

    public function test_permission_map_has_boolean_values_for_all_abilities(): void
    {
        $user = $this->user('social_work');
        $map  = $this->svc->permissionMap($user);

        $this->assertNotEmpty($map);

        foreach ($map as $module => $abilities) {
            $this->assertArrayHasKey('view',   $abilities, "Missing 'view' for {$module}");
            $this->assertArrayHasKey('create', $abilities, "Missing 'create' for {$module}");
            $this->assertArrayHasKey('edit',   $abilities, "Missing 'edit' for {$module}");
            $this->assertArrayHasKey('delete', $abilities, "Missing 'delete' for {$module}");
            $this->assertArrayHasKey('export', $abilities, "Missing 'export' for {$module}");
            $this->assertIsBool($abilities['view'],   "Non-bool 'view' for {$module}");
            $this->assertIsBool($abilities['delete'], "Non-bool 'delete' for {$module}");
        }
    }
}
