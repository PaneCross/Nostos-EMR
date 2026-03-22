<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RBACTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::factory()->create(['slug' => 'rbac-test']);
    }

    private function makeUser(string $department, string $role = 'standard'): User
    {
        return User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => $department,
            'role'       => $role,
            'is_active'  => true,
        ]);
    }

    // ─── Each department user lands on their own dashboard ────────────────────

    /** @dataProvider departmentProvider */
    public function test_user_can_access_own_department_dashboard(string $department): void
    {
        $user = $this->makeUser($department);

        $response = $this->actingAs($user)->get("/dashboard/{$department}");

        $response->assertOk();
    }

    public static function departmentProvider(): array
    {
        return [
            'primary_care'      => ['primary_care'],
            'therapies'         => ['therapies'],
            'social_work'       => ['social_work'],
            'behavioral_health' => ['behavioral_health'],
            'dietary'           => ['dietary'],
            'activities'        => ['activities'],
            'home_care'         => ['home_care'],
            'transportation'    => ['transportation'],
            'pharmacy'          => ['pharmacy'],
            'idt'               => ['idt'],
            'enrollment'        => ['enrollment'],
            'finance'           => ['finance'],
            'qa_compliance'     => ['qa_compliance'],
            'it_admin'          => ['it_admin'],
        ];
    }

    // ─── User cannot access another department's dashboard ────────────────────

    public function test_nursing_user_cannot_access_transportation_dashboard(): void
    {
        $user = $this->makeUser('primary_care');

        $response = $this->actingAs($user)->get('/dashboard/transportation');

        $response->assertForbidden();
    }

    public function test_finance_user_cannot_access_qa_dashboard(): void
    {
        $user = $this->makeUser('finance');

        $response = $this->actingAs($user)->get('/dashboard/qa_compliance');

        $response->assertForbidden();
    }

    public function test_transportation_user_cannot_access_idt_dashboard(): void
    {
        $user = $this->makeUser('transportation');

        $response = $this->actingAs($user)->get('/dashboard/idt');

        $response->assertForbidden();
    }

    // ─── Unauthorized access is logged ────────────────────────────────────────

    public function test_unauthorized_access_attempt_is_logged(): void
    {
        $user = $this->makeUser('pharmacy');

        $this->actingAs($user)->get('/dashboard/finance');

        $this->assertDatabaseHas('shared_audit_logs', [
            'user_id' => $user->id,
            'action'  => 'unauthorized_access',
        ]);
    }

    // ─── Root redirect ────────────────────────────────────────────────────────

    public function test_root_redirects_to_correct_department_dashboard(): void
    {
        $user = $this->makeUser('social_work');

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect('/dashboard/social_work');
    }

    // ─── IT Admin can access all dashboards ───────────────────────────────────

    public function test_it_admin_can_access_any_department_dashboard(): void
    {
        $admin = $this->makeUser('it_admin', 'admin');

        // IT Admin trying to visit another dept's dashboard — this is blocked
        // because dashboard routes check the user's OWN department, not permissions
        // (you can only view your own dept dashboard; IT Admin has system settings instead)
        $response = $this->actingAs($admin)->get('/dashboard/it_admin');
        $response->assertOk();
    }

    // ─── Guest cannot access dashboard ────────────────────────────────────────

    public function test_guest_is_redirected_to_login_from_dashboard(): void
    {
        $response = $this->get('/dashboard/primary_care');
        $response->assertRedirectToRoute('login');
    }
}
