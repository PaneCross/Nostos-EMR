<?php

// ─── StateMedicaidConfigTest ────────────────────────────────────────────────
// Feature tests for the Phase 9C StateMedicaidConfigController.
//
// Coverage:
//   - test_it_admin_can_view_state_config_page
//   - test_finance_user_can_view_state_config_page
//   - test_it_admin_can_create_state_config
//   - test_it_admin_can_update_state_config
//   - test_it_admin_can_deactivate_state_config
//   - test_non_it_admin_cannot_create_config
//   - test_duplicate_state_code_returns_validation_error
//   - test_cross_tenant_config_cannot_be_updated
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\StateMedicaidConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateMedicaidConfigTest extends TestCase
{
    use RefreshDatabase;

    private function itAdminUser(): User
    {
        return User::factory()->create(['department' => 'it_admin']);
    }

    private function financeUser(): User
    {
        return User::factory()->create(['department' => 'finance']);
    }

    private function validPayload(): array
    {
        return [
            'state_code'         => 'CA',
            'state_name'         => 'California',
            'submission_format'  => '837P',
            'days_to_submit'     => 180,
            'effective_date'     => '2025-01-01',
        ];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_it_admin_can_view_state_config_page(): void
    {
        $user = $this->itAdminUser();

        $this->actingAs($user)
            ->get('/it-admin/state-config')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ItAdmin/StateConfig')
                ->has('configs')
                ->has('submissionFormats')
            );
    }

    public function test_finance_user_can_view_state_config_page(): void
    {
        $user = $this->financeUser();

        $this->actingAs($user)
            ->get('/it-admin/state-config')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('ItAdmin/StateConfig'));
    }

    public function test_it_admin_can_create_state_config(): void
    {
        $user = $this->itAdminUser();

        $this->actingAs($user)
            ->postJson('/it-admin/state-config', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('state_code', 'CA')
            ->assertJsonPath('submission_format', '837P');

        $this->assertDatabaseHas('emr_state_medicaid_configs', [
            'tenant_id'  => $user->tenant_id,
            'state_code' => 'CA',
        ]);
    }

    public function test_it_admin_can_update_state_config(): void
    {
        $user   = $this->itAdminUser();
        $config = StateMedicaidConfig::factory()->create([
            'tenant_id'          => $user->tenant_id,
            'state_code'         => 'TX',
            'state_name'         => 'Texas',
            'submission_format'  => '837P',
            'days_to_submit'     => 180,
        ]);

        $this->actingAs($user)
            ->putJson("/it-admin/state-config/{$config->id}", [
                'days_to_submit'    => 90,
                'clearinghouse_name' => 'Change Healthcare',
            ])
            ->assertOk()
            ->assertJsonPath('days_to_submit', 90);

        $this->assertDatabaseHas('emr_state_medicaid_configs', [
            'id'              => $config->id,
            'days_to_submit'  => 90,
        ]);
    }

    public function test_it_admin_can_deactivate_state_config(): void
    {
        $user   = $this->itAdminUser();
        $config = StateMedicaidConfig::factory()->create([
            'tenant_id'  => $user->tenant_id,
            'state_code' => 'FL',
            'is_active'  => true,
        ]);

        $this->actingAs($user)
            ->deleteJson("/it-admin/state-config/{$config->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Configuration deactivated.');

        $this->assertDatabaseHas('emr_state_medicaid_configs', [
            'id'        => $config->id,
            'is_active' => false,
        ]);
    }

    public function test_non_it_admin_cannot_create_config(): void
    {
        $user = $this->financeUser();

        $this->actingAs($user)
            ->postJson('/it-admin/state-config', $this->validPayload())
            ->assertForbidden();
    }

    public function test_duplicate_state_code_returns_validation_error(): void
    {
        $user = $this->itAdminUser();

        // Create first CA config
        StateMedicaidConfig::factory()->create([
            'tenant_id'  => $user->tenant_id,
            'state_code' => 'CA',
        ]);

        // Attempt to create a second CA config for the same tenant → 422
        $this->actingAs($user)
            ->postJson('/it-admin/state-config', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['state_code']);
    }

    public function test_cross_tenant_config_cannot_be_updated(): void
    {
        $userA  = $this->itAdminUser();
        $userB  = $this->itAdminUser(); // different tenant
        $config = StateMedicaidConfig::factory()->create([
            'tenant_id'  => $userB->tenant_id,
            'state_code' => 'NY',
        ]);

        $this->actingAs($userA)
            ->putJson("/it-admin/state-config/{$config->id}", ['days_to_submit' => 60])
            ->assertForbidden();
    }
}
