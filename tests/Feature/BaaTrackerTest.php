<?php

// ─── BaaTrackerTest ───────────────────────────────────────────────────────────
// Feature tests for the W4-2 BAA / SRA Tracker and QA compliance posture.
//
// Covers:
//   1. BAA record CRUD via SecurityComplianceController (/it-admin/baa)
//   2. SRA record CRUD via SecurityComplianceController (/it-admin/sra)
//   3. Model business-logic helpers: isExpired(), isExpiringSoon(), isOverdue()
//   4. Cross-tenant isolation (other-tenant BAA/SRA update → 403)
//   5. Access control: only it_admin may write; non-it_admin gets 403 on POST/PUT
//   6. QA Dashboard compliance_posture prop: structure, BAA count, SRA overdue flag,
//      field encryption detection
//
// HIPAA references:
//   BAA: 45 CFR §164.308(b)(1) — business associate contracts required
//   SRA: 45 CFR §164.308(a)(1)(ii)(A) — annual risk analysis required
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\BaaRecord;
use App\Models\Site;
use App\Models\SraRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaaTrackerTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function itAdminUser(?int $tenantId = null): User
    {
        $tenant = $tenantId
            ? Tenant::find($tenantId)
            : Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $tenant->id]);

        return User::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'department' => 'it_admin',
            'role'       => 'admin',
        ]);
    }

    private function qaUser(): User
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);

        return User::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
        ]);
    }

    // ── BAA access control ────────────────────────────────────────────────────

    /** Non-IT-admin cannot POST to BAA store endpoint */
    public function test_baa_store_requires_it_admin(): void
    {
        $user = $this->qaUser();

        $this->actingAs($user)
             ->postJson('/it-admin/baa', [
                 'vendor_name' => 'Test Vendor',
                 'vendor_type' => 'cloud_provider',
                 'status'      => 'active',
             ])
             ->assertForbidden();
    }

    /** Unauthenticated request to BAA store is redirected to login */
    public function test_baa_store_requires_authentication(): void
    {
        $this->postJson('/it-admin/baa', ['vendor_name' => 'X'])
             ->assertUnauthorized();
    }

    // ── BAA CRUD ─────────────────────────────────────────────────────────────

    /** IT Admin can create a new BAA record with required fields */
    public function test_baa_store_creates_record(): void
    {
        $user = $this->itAdminUser();

        $response = $this->actingAs($user)->postJson('/it-admin/baa', [
            'vendor_name'         => 'AWS HIPAA BAA',
            'vendor_type'         => 'cloud_provider',
            'phi_accessed'        => true,
            'baa_signed_date'     => '2024-01-15',
            'baa_expiration_date' => now()->addMonths(18)->toDateString(),
            'status'              => 'active',
            'contact_name'        => 'AWS HIPAA Support',
            'contact_email'       => 'hipaa@aws.example.com',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_baa_records', [
            'tenant_id'   => $user->tenant_id,
            'vendor_name' => 'AWS HIPAA BAA',
            'vendor_type' => 'cloud_provider',
            'status'      => 'active',
        ]);
    }

    /** IT Admin can update an existing BAA record belonging to their tenant */
    public function test_baa_update_modifies_own_tenant_record(): void
    {
        $user = $this->itAdminUser();
        $baa  = BaaRecord::factory()->create(['tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)->putJson("/it-admin/baa/{$baa->id}", [
            'vendor_name'  => 'Renamed Vendor',
            'vendor_type'  => $baa->vendor_type,
            'phi_accessed' => $baa->phi_accessed,
            'status'       => 'active',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('emr_baa_records', [
            'id'          => $baa->id,
            'vendor_name' => 'Renamed Vendor',
        ]);
    }

    /** Updating a BAA belonging to a different tenant must be forbidden */
    public function test_baa_update_blocked_for_cross_tenant_record(): void
    {
        $user        = $this->itAdminUser();
        $otherTenant = Tenant::factory()->create();
        $baa         = BaaRecord::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($user)
             ->putJson("/it-admin/baa/{$baa->id}", [
                 'vendor_name'  => 'Hacked',
                 'vendor_type'  => 'other',
                 'phi_accessed' => false,
                 'status'       => 'active',
             ])
             ->assertForbidden();
    }

    // ── BAA model business logic ──────────────────────────────────────────────

    /** Factory expired() state produces a BAA with isExpired() = true */
    public function test_baa_expired_factory_state_is_detected(): void
    {
        $baa = BaaRecord::factory()->expired()->create([
            'tenant_id' => Tenant::factory()->create()->id,
        ]);

        $this->assertTrue($baa->isExpired());
        $this->assertFalse($baa->isExpiringSoon());
    }

    /** Factory expiringSoon() state produces a BAA with isExpiringSoon() = true */
    public function test_baa_expiring_soon_factory_state_is_detected(): void
    {
        $baa = BaaRecord::factory()->expiringSoon()->create([
            'tenant_id' => Tenant::factory()->create()->id,
        ]);

        $this->assertTrue($baa->isExpiringSoon());
        $this->assertFalse($baa->isExpired());
    }

    /** Active BAA with far-future expiration is neither expired nor expiring soon */
    public function test_baa_active_with_distant_expiration_is_clean(): void
    {
        $baa = BaaRecord::factory()->create([
            'tenant_id'           => Tenant::factory()->create()->id,
            'baa_expiration_date' => now()->addYears(2)->toDateString(),
            'status'              => 'active',
        ]);

        $this->assertFalse($baa->isExpired());
        $this->assertFalse($baa->isExpiringSoon());
    }

    /** Terminated BAA is never considered expired even with past expiration date */
    public function test_terminated_baa_is_not_considered_expired(): void
    {
        $baa = BaaRecord::factory()->create([
            'tenant_id'           => Tenant::factory()->create()->id,
            'baa_expiration_date' => now()->subMonths(3)->toDateString(),
            'status'              => 'terminated',
        ]);

        $this->assertFalse($baa->isExpired());
    }

    // ── SRA CRUD ─────────────────────────────────────────────────────────────

    /** IT Admin can create a new SRA record */
    public function test_sra_store_creates_record(): void
    {
        $user = $this->itAdminUser();

        $response = $this->actingAs($user)->postJson('/it-admin/sra', [
            'sra_date'          => '2025-01-10',
            'conducted_by'      => 'Internal IT + Compliance Team',
            'scope_description' => 'Full HIPAA Security Rule gap analysis covering emr_* tables',
            'risk_level'        => 'moderate',
            'status'            => 'completed',
            'next_sra_due'      => now()->addMonths(12)->toDateString(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_sra_records', [
            'tenant_id'    => $user->tenant_id,
            'conducted_by' => 'Internal IT + Compliance Team',
            'risk_level'   => 'moderate',
            'status'       => 'completed',
        ]);
    }

    /** IT Admin can update an existing SRA record */
    public function test_sra_update_modifies_own_tenant_record(): void
    {
        $user = $this->itAdminUser();
        $sra  = SraRecord::factory()->create(['tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)->putJson("/it-admin/sra/{$sra->id}", [
            'sra_date'          => $sra->sra_date->toDateString(),
            'conducted_by'      => 'Updated Auditor Name',
            'scope_description' => 'Revised scope after scope change',
            'risk_level'        => 'low',
            'status'            => 'completed',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('emr_sra_records', [
            'id'           => $sra->id,
            'conducted_by' => 'Updated Auditor Name',
            'risk_level'   => 'low',
        ]);
    }

    /** Updating an SRA belonging to a different tenant must be forbidden */
    public function test_sra_update_blocked_for_cross_tenant_record(): void
    {
        $user        = $this->itAdminUser();
        $otherTenant = Tenant::factory()->create();
        $sra         = SraRecord::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($user)
             ->putJson("/it-admin/sra/{$sra->id}", [
                 'sra_date'          => now()->toDateString(),
                 'conducted_by'      => 'Hacker',
                 'scope_description' => 'x',
                 'risk_level'        => 'low',
                 'status'            => 'completed',
             ])
             ->assertForbidden();
    }

    // ── SRA model business logic ──────────────────────────────────────────────

    /** Factory overdue() state produces an SRA with isOverdue() = true */
    public function test_sra_overdue_when_next_due_in_past(): void
    {
        $sra = SraRecord::factory()->overdue()->create([
            'tenant_id' => Tenant::factory()->create()->id,
        ]);

        $this->assertTrue($sra->isOverdue());
    }

    /** SRA with next_sra_due in the future is not overdue */
    public function test_sra_not_overdue_when_next_due_in_future(): void
    {
        $sra = SraRecord::factory()->create([
            'tenant_id'    => Tenant::factory()->create()->id,
            'sra_date'     => now()->subMonths(3)->toDateString(),
            'status'       => 'completed',
            'next_sra_due' => now()->addMonths(9)->toDateString(),
        ]);

        $this->assertFalse($sra->isOverdue());
    }

    /** SRA with no next_sra_due date set is not considered overdue */
    public function test_sra_without_next_due_is_not_overdue(): void
    {
        $sra = SraRecord::factory()->create([
            'tenant_id'    => Tenant::factory()->create()->id,
            'sra_date'     => now()->subMonths(1)->toDateString(),
            'status'       => 'in_progress',
            'next_sra_due' => null,
        ]);

        $this->assertFalse($sra->isOverdue());
    }

    // ── QA Dashboard compliance_posture prop ─────────────────────────────────

    /** GET /qa/dashboard returns Inertia page with compliance_posture prop */
    public function test_qa_dashboard_includes_compliance_posture_prop(): void
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);
        $user   = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
        ]);

        $this->actingAs($user)
             ->get('/qa/dashboard')
             ->assertOk()
             ->assertInertia(fn ($page) =>
                 $page->component('Qa/Dashboard')
                      ->has('compliance_posture')
                      ->has('compliance_posture.expired_baa_count')
                      ->has('compliance_posture.expiring_soon_count')
                      ->has('compliance_posture.sra_overdue')
                      ->has('compliance_posture.session_encrypted')
                      ->has('compliance_posture.db_ssl_enforced')
                      ->has('compliance_posture.field_encryption')
                      ->has('compliance_posture.latest_sra_date')
             );
    }

    /** compliance_posture.expired_baa_count reflects expired BAAs for the tenant */
    public function test_compliance_posture_counts_expired_baa_records(): void
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);
        $user   = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
        ]);

        // 2 expired + 1 active for this tenant; other tenant's expired BAAs must not bleed in
        BaaRecord::factory()->expired()->count(2)->create(['tenant_id' => $tenant->id]);
        BaaRecord::factory()->create([
            'tenant_id'           => $tenant->id,
            'baa_expiration_date' => now()->addYear()->toDateString(),
            'status'              => 'active',
        ]);
        BaaRecord::factory()->expired()->create([
            'tenant_id' => Tenant::factory()->create()->id, // different tenant — must not count
        ]);

        $this->actingAs($user)
             ->get('/qa/dashboard')
             ->assertInertia(fn ($page) =>
                 $page->where('compliance_posture.expired_baa_count', 2)
             );
    }

    /** No completed SRA on record means sra_overdue = true by definition */
    public function test_compliance_posture_no_sra_means_overdue_true(): void
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);
        $user   = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
        ]);

        // Deliberately seed NO SRA records for this tenant
        $this->actingAs($user)
             ->get('/qa/dashboard')
             ->assertInertia(fn ($page) =>
                 $page->where('compliance_posture.sra_overdue', true)
             );
    }

    /** A completed SRA with next_sra_due in the future means sra_overdue = false */
    public function test_compliance_posture_current_sra_means_overdue_false(): void
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);
        $user   = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
        ]);

        SraRecord::factory()->create([
            'tenant_id'    => $tenant->id,
            'sra_date'     => now()->subMonths(3)->toDateString(),
            'status'       => 'completed',
            'next_sra_due' => now()->addMonths(9)->toDateString(),
        ]);

        $this->actingAs($user)
             ->get('/qa/dashboard')
             ->assertInertia(fn ($page) =>
                 $page->where('compliance_posture.sra_overdue', false)
             );
    }

    /**
     * field_encryption must be true because the W4-2 implementation added
     * the 'encrypted' cast to Participant::$casts['medicare_id'].
     */
    public function test_compliance_posture_field_encryption_true_after_w42(): void
    {
        $tenant = Tenant::factory()->create();
        $site   = Site::factory()->create(['tenant_id' => $tenant->id]);
        $user   = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'site_id'    => $site->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
        ]);

        $this->actingAs($user)
             ->get('/qa/dashboard')
             ->assertInertia(fn ($page) =>
                 $page->where('compliance_posture.field_encryption', true)
             );
    }
}
