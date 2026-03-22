<?php

// ─── BillingComplianceChecklistTest ───────────────────────────────────────────
// Feature tests for the Phase 9C BillingComplianceController.
//
// Coverage:
//   - test_finance_user_can_access_compliance_checklist_page
//   - test_compliance_data_endpoint_returns_json
//   - test_checklist_has_all_five_categories
//   - test_overall_status_is_present
//   - test_non_finance_user_cannot_access_checklist
//   - test_it_admin_can_access_checklist
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingComplianceChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function financeUser(): User
    {
        return User::factory()->create(['department' => 'finance']);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_finance_user_can_access_compliance_checklist_page(): void
    {
        $user = $this->financeUser();

        $this->actingAs($user)
            ->get('/billing/compliance-checklist')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/ComplianceChecklist')
                ->has('checklist')
            );
    }

    public function test_compliance_data_endpoint_returns_json(): void
    {
        $user = $this->financeUser();

        $this->actingAs($user)
            ->getJson('/billing/compliance-checklist/data')
            ->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'overall_status',
                'categories',
            ]);
    }

    public function test_checklist_has_all_five_categories(): void
    {
        $user = $this->financeUser();

        $resp = $this->actingAs($user)
            ->getJson('/billing/compliance-checklist/data')
            ->assertOk();

        $categories = $resp->json('categories');
        $this->assertArrayHasKey('encounter_data', $categories);
        $this->assertArrayHasKey('risk_adjustment', $categories);
        $this->assertArrayHasKey('capitation', $categories);
        $this->assertArrayHasKey('hpms', $categories);
        $this->assertArrayHasKey('part_d', $categories);
    }

    public function test_overall_status_is_present(): void
    {
        $user = $this->financeUser();

        $resp = $this->actingAs($user)
            ->getJson('/billing/compliance-checklist/data')
            ->assertOk();

        $this->assertContains($resp->json('overall_status'), ['pass', 'warn', 'fail']);
    }

    public function test_each_category_has_label_and_checks(): void
    {
        $user = $this->financeUser();

        $resp = $this->actingAs($user)
            ->getJson('/billing/compliance-checklist/data')
            ->assertOk();

        foreach ($resp->json('categories') as $cat) {
            $this->assertArrayHasKey('label', $cat);
            $this->assertArrayHasKey('checks', $cat);
            $this->assertIsArray($cat['checks']);
            foreach ($cat['checks'] as $check) {
                $this->assertArrayHasKey('label', $check);
                $this->assertArrayHasKey('status', $check);
                $this->assertArrayHasKey('value', $check);
                $this->assertArrayHasKey('detail', $check);
                $this->assertContains($check['status'], ['pass', 'warn', 'fail']);
            }
        }
    }

    public function test_non_finance_user_cannot_access_checklist(): void
    {
        $user = User::factory()->create(['department' => 'primary_care']);

        $this->actingAs($user)
            ->getJson('/billing/compliance-checklist/data')
            ->assertForbidden();
    }

    public function test_it_admin_can_access_checklist(): void
    {
        $user = User::factory()->create(['department' => 'it_admin']);

        $this->actingAs($user)
            ->getJson('/billing/compliance-checklist/data')
            ->assertOk();
    }
}
