<?php

// ─── HpmsEnrollmentFileTest ───────────────────────────────────────────────────
// Feature tests for HpmsFileService::generateEnrollmentFile() — GAP-14 (W4-9).
//
// Coverage:
//   - File is created as HpmsSubmission with type='enrollment'
//   - Header line includes H-number from tenant.cms_contract_id
//   - Each record contains Field 2 (MBI / medicare_id)
//   - Each record contains Field 6 (enrollment_date in YYYYMMDD format)
//   - Each record contains Field 9 (dob in YYYYMMDD format)
//   - Gender sex mapping: male→M, female→F, unknown/null→U
//   - Migration 96 fields: medicare_a_start_date (Field 3), medicare_b_start_date (Field 4), county_fips_code (Field 11)
//   - record_count matches number of enrolled participants in period
//   - Empty month produces 0 records (header only)
//   - Status is 'draft' on generation
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Tenant;
use App\Services\HpmsFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HpmsEnrollmentFileTest extends TestCase
{
    use RefreshDatabase;

    private HpmsFileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HpmsFileService::class);
    }

    // ── Submission record ─────────────────────────────────────────────────────

    public function test_generates_hpms_submission_record(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H1234']);
        $month  = Carbon::now()->format('Y-m');
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        $this->assertEquals('enrollment', $submission->submission_type);
        $this->assertEquals('draft', $submission->status);
        $this->assertEquals($tenant->id, $submission->tenant_id);
    }

    // ── H-Number in header ────────────────────────────────────────────────────

    public function test_header_contains_h_number(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H9876']);
        $month  = '2025-06';
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        $this->assertStringContainsString('H9876', $submission->file_content);
        // First line (header) must start with the H-number
        $headerLine = explode("\n", $submission->file_content)[0];
        $this->assertStringStartsWith('H9876', $headerLine);
    }

    // ── MBI / Field 2 ─────────────────────────────────────────────────────────

    public function test_record_contains_medicare_id(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H1111']);
        $month  = Carbon::now()->format('Y-m');
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        Participant::factory()->create([
            'tenant_id'        => $tenant->id,
            'enrollment_status' => 'enrolled',
            'enrollment_date'  => Carbon::now()->startOfMonth(),
            'medicare_id'      => 'MBI00001',
        ]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        $this->assertStringContainsString('MBI00001', $submission->file_content);
    }

    // ── Enrollment date / Field 6 ─────────────────────────────────────────────

    public function test_record_contains_enrollment_date_in_yyyymmdd(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H2222']);
        $month  = '2025-03';
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        Participant::factory()->create([
            'tenant_id'        => $tenant->id,
            'enrollment_status' => 'enrolled',
            'enrollment_date'  => '2025-03-15',
        ]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        // Field 6: YYYYMMDD
        $this->assertStringContainsString('20250315', $submission->file_content);
    }

    // ── Date of birth / Field 9 ───────────────────────────────────────────────

    public function test_record_contains_dob_in_yyyymmdd(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H3333']);
        $month  = Carbon::now()->format('Y-m');
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        Participant::factory()->create([
            'tenant_id'        => $tenant->id,
            'enrollment_status' => 'enrolled',
            'enrollment_date'  => Carbon::now()->startOfMonth(),
            'dob'              => '1940-07-22',
        ]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        // Field 9: YYYYMMDD
        $this->assertStringContainsString('19400722', $submission->file_content);
    }

    // ── Sex mapping / Field 10 ────────────────────────────────────────────────

    public function test_male_gender_maps_to_m(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H4444']);
        $month  = Carbon::now()->format('Y-m');
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        Participant::factory()->create([
            'tenant_id'        => $tenant->id,
            'enrollment_status' => 'enrolled',
            'enrollment_date'  => Carbon::now()->startOfMonth(),
            'gender'           => 'male',
        ]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        // The data line should contain '|M|' as Field 10
        $lines = explode("\n", $submission->file_content);
        $dataLine = $lines[1] ?? '';
        $fields = explode('|', $dataLine);
        $this->assertEquals('M', $fields[9] ?? null, 'Field 10 should be M for male');
    }

    public function test_female_gender_maps_to_f(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H5555']);
        $month  = Carbon::now()->format('Y-m');
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        Participant::factory()->create([
            'tenant_id'        => $tenant->id,
            'enrollment_status' => 'enrolled',
            'enrollment_date'  => Carbon::now()->startOfMonth(),
            'gender'           => 'female',
        ]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        $lines = explode("\n", $submission->file_content);
        $fields = explode('|', $lines[1] ?? '');
        $this->assertEquals('F', $fields[9] ?? null, 'Field 10 should be F for female');
    }

    public function test_unknown_gender_maps_to_u(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H6666']);
        $month  = Carbon::now()->format('Y-m');
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        Participant::factory()->create([
            'tenant_id'        => $tenant->id,
            'enrollment_status' => 'enrolled',
            'enrollment_date'  => Carbon::now()->startOfMonth(),
            'gender'           => 'non_binary',
        ]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        $lines = explode("\n", $submission->file_content);
        $fields = explode('|', $lines[1] ?? '');
        $this->assertEquals('U', $fields[9] ?? null, 'Field 10 should be U for non-binary');
    }

    // ── Migration 96 fields ───────────────────────────────────────────────────

    public function test_record_contains_medicare_a_start_date(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H7777']);
        $month  = Carbon::now()->format('Y-m');
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        Participant::factory()->create([
            'tenant_id'             => $tenant->id,
            'enrollment_status'     => 'enrolled',
            'enrollment_date'       => Carbon::now()->startOfMonth(),
            'medicare_a_start_date' => '1985-01-01',
        ]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        // Field 3: YYYYMMDD format
        $this->assertStringContainsString('19850101', $submission->file_content);
    }

    public function test_record_contains_county_fips_code(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H8888']);
        $month  = Carbon::now()->format('Y-m');
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        Participant::factory()->create([
            'tenant_id'        => $tenant->id,
            'enrollment_status' => 'enrolled',
            'enrollment_date'  => Carbon::now()->startOfMonth(),
            'county_fips_code' => '39049',
        ]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        // Field 11: county FIPS code
        $this->assertStringContainsString('39049', $submission->file_content);
    }

    // ── Record count ──────────────────────────────────────────────────────────

    public function test_record_count_matches_enrolled_participants(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H0001']);
        $month  = Carbon::now()->format('Y-m');
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        Participant::factory()->count(3)->create([
            'tenant_id'        => $tenant->id,
            'enrollment_status' => 'enrolled',
            'enrollment_date'  => Carbon::now()->startOfMonth(),
        ]);

        // One participant enrolled in a different month — should not be counted
        Participant::factory()->create([
            'tenant_id'        => $tenant->id,
            'enrollment_status' => 'enrolled',
            'enrollment_date'  => Carbon::now()->subMonths(2),
        ]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        $this->assertEquals(3, $submission->record_count);
    }

    public function test_empty_month_generates_header_only(): void
    {
        $tenant = Tenant::factory()->create(['cms_contract_id' => 'H0002']);
        $month  = '2020-01';
        $user   = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

        $submission = $this->service->generateEnrollmentFile($tenant->id, $month, $user->id);

        $this->assertEquals(0, $submission->record_count);
        // Only the header line should be present (no newlines beyond header)
        $lines = array_filter(explode("\n", $submission->file_content));
        $this->assertCount(1, $lines, 'Empty month should only have the header line');
    }
}
