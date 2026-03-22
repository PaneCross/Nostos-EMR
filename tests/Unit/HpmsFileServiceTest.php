<?php

// ─── HpmsFileServiceTest ──────────────────────────────────────────────────────
// Unit tests for HpmsFileService.
//
// Coverage:
//   - test_generate_enrollment_file_creates_hpms_submission
//   - test_enrollment_file_is_pipe_delimited
//   - test_generate_quality_data_file_creates_submission
//   - test_generate_hos_m_file_creates_submission
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\HpmsSubmission;
use App\Models\Participant;
use App\Models\User;
use App\Services\HpmsFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HpmsFileServiceTest extends TestCase
{
    use RefreshDatabase;

    private HpmsFileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HpmsFileService::class);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_generate_enrollment_file_creates_hpms_submission(): void
    {
        $user = User::factory()->create(['department' => 'finance']);

        // Seed an enrolled participant to appear in the file
        Participant::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'enrollment_status' => 'enrolled',
        ]);

        $submission = $this->service->generateEnrollmentFile($user->tenant_id, '2025-03', $user->id);

        $this->assertInstanceOf(HpmsSubmission::class, $submission);
        $this->assertEquals('enrollment', $submission->submission_type);
        $this->assertEquals('draft', $submission->status);
        $this->assertDatabaseHas('emr_hpms_submissions', ['id' => $submission->id]);
    }

    public function test_enrollment_file_is_pipe_delimited(): void
    {
        $user = User::factory()->create(['department' => 'finance']);

        Participant::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'enrollment_status' => 'enrolled',
        ]);

        $submission = $this->service->generateEnrollmentFile($user->tenant_id, '2025-03', $user->id);

        // HPMS enrollment files must be pipe-delimited
        $this->assertStringContainsString('|', $submission->file_content);
        // First line is header
        $firstLine = explode("\n", trim($submission->file_content))[0];
        $this->assertStringContainsString('|', $firstLine);
    }

    public function test_generate_quality_data_file_creates_submission(): void
    {
        $user = User::factory()->create(['department' => 'finance']);

        $submission = $this->service->generateQualityDataFile(
            $user->tenant_id,
            2025,
            1,
            $user->id
        );

        $this->assertInstanceOf(HpmsSubmission::class, $submission);
        $this->assertEquals('quality_data', $submission->submission_type);
        $this->assertNotEmpty($submission->file_content);
    }

    public function test_generate_hos_m_file_creates_submission(): void
    {
        $user = User::factory()->create(['department' => 'finance']);

        $submission = $this->service->generateHosMFile($user->tenant_id, 2025, $user->id);

        $this->assertInstanceOf(HpmsSubmission::class, $submission);
        $this->assertEquals('hos_m', $submission->submission_type);
        $this->assertDatabaseHas('emr_hpms_submissions', [
            'submission_type' => 'hos_m',
        ]);
    }
}
