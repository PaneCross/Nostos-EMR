<?php

// ─── CapitationTest ───────────────────────────────────────────────────────────
// Feature tests for the Phase 9B CapitationController (HCC-augmented).
//
// Coverage:
//   - test_capitation_page_renders_with_kpis
//   - test_capitation_store_includes_hcc_fields
//   - test_capitation_bulk_import_csv
//   - test_cross_tenant_capitation_returns_403
//   - test_capitation_data_endpoint_returns_json
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\CapitationRecord;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CapitationTest extends TestCase
{
    use RefreshDatabase;

    private function financeUser(): User
    {
        return User::factory()->create(['department' => 'finance']);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_capitation_page_renders_with_kpis(): void
    {
        $user = $this->financeUser();

        $this->actingAs($user)
            ->get('/billing/capitation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Capitation')
                ->has('kpis')
                ->has('records')
            );
    }

    public function test_capitation_store_includes_hcc_fields(): void
    {
        $user        = $this->financeUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        $resp = $this->actingAs($user)
            ->postJson('/billing/capitation', [
                'participant_id'     => $participant->id,
                'month_year'         => '2025-03',
                'medicare_a_rate'    => 650.00,
                'medicare_b_rate'    => 420.00,
                'medicare_d_rate'    => 145.00,
                'medicaid_rate'      => 200.00,
                'total_capitation'   => 1415.00,
                'eligibility_category' => 'nursing_facility',
                'hcc_risk_score'     => 1.0823,
                'frailty_score'      => 0.1500,
                'county_fips_code'   => '06037',
                'adjustment_type'    => 'initial',
                'rate_effective_date' => '2025-03-01',
            ])
            ->assertCreated()
            ->assertJsonPath('hcc_risk_score', '1.0823');

        $this->assertDatabaseHas('emr_capitation_records', [
            'participant_id' => $participant->id,
            'county_fips_code' => '06037',
        ]);
    }

    public function test_capitation_bulk_import_csv(): void
    {
        $user        = $this->financeUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        $csvContent = "participant_id,month_year,total_capitation,hcc_risk_score\n"
                    . "{$participant->id},2025-02,1350.00,0.9500\n";

        $file = UploadedFile::fake()->createWithContent('capitation.csv', $csvContent);

        $this->actingAs($user)
            ->post('/billing/capitation/bulk-import', ['csv_file' => $file])
            ->assertOk()
            ->assertJsonStructure(['created', 'errors']);

        $this->assertDatabaseHas('emr_capitation_records', [
            'participant_id' => $participant->id,
            'month_year'     => '2025-02',
        ]);
    }

    public function test_cross_tenant_capitation_returns_403(): void
    {
        $userA = $this->financeUser();
        $userB = $this->financeUser(); // different tenant

        $participantB = Participant::factory()->create(['tenant_id' => $userB->tenant_id]);

        $this->actingAs($userA)
            ->postJson('/billing/capitation', [
                'participant_id'   => $participantB->id,
                'month_year'       => '2025-03',
                'total_capitation' => 1415.00,
            ])
            ->assertForbidden();
    }

    public function test_capitation_data_endpoint_returns_json(): void
    {
        $user = $this->financeUser();

        $this->actingAs($user)
            ->getJson('/billing/capitation/data')
            ->assertOk()
            ->assertJsonStructure(['kpis', 'records']);
    }
}
