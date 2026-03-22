<?php

// ─── FinanceTest ──────────────────────────────────────────────────────────────
// Feature tests for Phase 6C Finance module.
//
// Coverage:
//   - Finance dashboard renders Inertia page with 4 KPI keys
//   - Capitation index: paginated, scoped to tenant
//   - Capitation store: creates record, returns 201; cross-tenant 403; validates fields
//   - Encounter index: filtered by service_type and date range
//   - Encounter store: creates record, returns 201; validates service_type enum
//   - Auth index: filtered by expiring_days
//   - Auth store: creates authorization, returns 201; after_start date validation
//   - Auth update: changes status to cancelled, returns 200; cross-tenant 403
//   - CSV export: capitation/encounters/authorizations types return text/csv
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Authorization;
use App\Models\CapitationRecord;
use App\Models\EncounterLog;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeFinanceUser(): User
    {
        return User::factory()->create(['department' => 'finance']);
    }

    private function makeParticipant(User $user): Participant
    {
        return Participant::factory()->create(['tenant_id' => $user->tenant_id]);
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function test_finance_dashboard_renders_inertia_page(): void
    {
        $user = $this->makeFinanceUser();

        $this->actingAs($user)
            ->get('/finance/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Finance/Dashboard'));
    }

    public function test_finance_dashboard_has_all_four_kpi_keys(): void
    {
        $user = $this->makeFinanceUser();

        $this->actingAs($user)
            ->get('/finance/dashboard')
            ->assertInertia(fn ($page) => $page
                ->has('kpis.capitation_this_month')
                ->has('kpis.auths_expiring_30d')
                ->has('kpis.encounters_this_month')
                ->has('kpis.active_participants')
            );
    }

    public function test_finance_dashboard_requires_auth(): void
    {
        $this->get('/finance/dashboard')->assertRedirect('/login');
    }

    // ── Capitation ────────────────────────────────────────────────────────────

    public function test_capitation_index_returns_paginated_records(): void
    {
        $user = $this->makeFinanceUser();
        CapitationRecord::factory()->count(3)->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)
            ->getJson('/finance/capitation')
            ->assertOk()
            ->assertJsonStructure(['data', 'total']);
    }

    public function test_capitation_index_scoped_to_tenant(): void
    {
        $user  = $this->makeFinanceUser();
        $other = User::factory()->create(['department' => 'finance']); // different tenant

        CapitationRecord::factory()->count(2)->create(['tenant_id' => $user->tenant_id]);
        CapitationRecord::factory()->count(3)->create(['tenant_id' => $other->tenant_id]);

        $response = $this->actingAs($user)->getJson('/finance/capitation')->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    public function test_capitation_store_returns_201(): void
    {
        $user        = $this->makeFinanceUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson('/finance/capitation', [
                'participant_id'   => $participant->id,
                'month_year'       => now()->format('Y-m'),
                'medicare_a_rate'  => 1800.00,
                'medicare_b_rate'  => 1200.00,
                'medicare_d_rate'  => 250.00,
                'medicaid_rate'    => 500.00,
                'total_capitation' => 3750.00,
            ])
            ->assertCreated()
            ->assertJsonPath('participant_id', $participant->id);

        $this->assertDatabaseHas('emr_capitation_records', [
            'participant_id' => $participant->id,
            'month_year'     => now()->format('Y-m'),
        ]);
    }

    public function test_capitation_store_rejects_cross_tenant_participant(): void
    {
        $user  = $this->makeFinanceUser();
        $other = User::factory()->create(['department' => 'finance']);
        $cross = Participant::factory()->create(['tenant_id' => $other->tenant_id]);

        $this->actingAs($user)
            ->postJson('/finance/capitation', [
                'participant_id'   => $cross->id,
                'month_year'       => now()->format('Y-m'),
                'medicare_a_rate'  => 1800.00,
                'medicare_b_rate'  => 1200.00,
                'medicare_d_rate'  => 250.00,
                'medicaid_rate'    => 500.00,
                'total_capitation' => 3750.00,
            ])
            ->assertForbidden();
    }

    public function test_capitation_store_validates_month_year_format(): void
    {
        $user        = $this->makeFinanceUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson('/finance/capitation', [
                'participant_id'   => $participant->id,
                'month_year'       => '2026/03', // wrong format — should be YYYY-MM
                'medicare_a_rate'  => 1800.00,
                'medicare_b_rate'  => 1200.00,
                'medicare_d_rate'  => 250.00,
                'medicaid_rate'    => 500.00,
                'total_capitation' => 3750.00,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month_year']);
    }

    // ── Encounter Log ─────────────────────────────────────────────────────────

    public function test_encounter_store_returns_201(): void
    {
        $user        = $this->makeFinanceUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson('/finance/encounters', [
                'participant_id' => $participant->id,
                'service_date'   => now()->toDateString(),
                'service_type'   => 'primary_care',
            ])
            ->assertCreated()
            ->assertJsonPath('participant_id', $participant->id);
    }

    public function test_encounter_store_rejects_invalid_service_type(): void
    {
        $user        = $this->makeFinanceUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson('/finance/encounters', [
                'participant_id' => $participant->id,
                'service_date'   => now()->toDateString(),
                'service_type'   => 'invalid_type',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_type']);
    }

    public function test_encounter_index_filtered_by_service_type(): void
    {
        $user        = $this->makeFinanceUser();
        $participant = $this->makeParticipant($user);

        EncounterLog::factory()->count(3)->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'service_type'   => 'primary_care',
        ]);
        EncounterLog::factory()->count(2)->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'service_type'   => 'therapy',
        ]);

        $response = $this->actingAs($user)
            ->getJson('/finance/encounters?service_type=primary_care')
            ->assertOk();

        $this->assertEquals(3, $response->json('total'));
    }

    // ── Authorizations ────────────────────────────────────────────────────────

    public function test_auth_store_returns_201(): void
    {
        $user        = $this->makeFinanceUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson('/finance/authorizations', [
                'participant_id'   => $participant->id,
                'service_type'     => 'home_care',
                'authorized_start' => now()->toDateString(),
                'authorized_end'   => now()->addMonths(3)->toDateString(),
                'authorized_units' => 12,
            ])
            ->assertCreated()
            ->assertJsonPath('service_type', 'home_care');
    }

    public function test_auth_store_validates_end_after_start(): void
    {
        $user        = $this->makeFinanceUser();
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson('/finance/authorizations', [
                'participant_id'   => $participant->id,
                'service_type'     => 'home_care',
                'authorized_start' => now()->toDateString(),
                'authorized_end'   => now()->subDay()->toDateString(), // before start
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['authorized_end']);
    }

    public function test_auth_update_changes_status_to_cancelled(): void
    {
        $user        = $this->makeFinanceUser();
        $participant = $this->makeParticipant($user);

        $auth = Authorization::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'active',
        ]);

        $this->actingAs($user)
            ->putJson("/finance/authorizations/{$auth->id}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('emr_authorizations', ['id' => $auth->id, 'status' => 'cancelled']);
    }

    public function test_auth_update_rejects_cross_tenant(): void
    {
        $user  = $this->makeFinanceUser();
        $other = User::factory()->create(['department' => 'finance']);
        $cross = Authorization::factory()->create(['tenant_id' => $other->tenant_id]);

        $this->actingAs($user)
            ->putJson("/finance/authorizations/{$cross->id}", ['status' => 'cancelled'])
            ->assertForbidden();
    }

    public function test_auth_index_filtered_by_expiring_days(): void
    {
        $user        = $this->makeFinanceUser();
        $participant = $this->makeParticipant($user);

        // One expiring in 10 days, one in 60 days
        Authorization::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'active',
            'authorized_start' => now()->subMonth()->toDateString(),
            'authorized_end'   => now()->addDays(10)->toDateString(),
        ]);
        Authorization::factory()->create([
            'tenant_id'      => $user->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'active',
            'authorized_start' => now()->subMonth()->toDateString(),
            'authorized_end'   => now()->addDays(60)->toDateString(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/finance/authorizations?expiring_days=30')
            ->assertOk();

        $this->assertEquals(1, $response->json('total'));
    }

    // ── CSV Export ────────────────────────────────────────────────────────────

    public function test_csv_export_capitation_returns_csv(): void
    {
        $user = $this->makeFinanceUser();

        $this->actingAs($user)
            ->get('/finance/reports/export?type=capitation')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_csv_export_encounters_returns_csv(): void
    {
        $user = $this->makeFinanceUser();

        $this->actingAs($user)
            ->get('/finance/reports/export?type=encounters')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_csv_export_authorizations_returns_csv(): void
    {
        $user = $this->makeFinanceUser();

        $this->actingAs($user)
            ->get('/finance/reports/export?type=authorizations')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_csv_export_has_correct_content_disposition(): void
    {
        $user = $this->makeFinanceUser();

        $response = $this->actingAs($user)
            ->get('/finance/reports/export?type=encounters');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('finance_encounters_', $disposition);
    }
}
