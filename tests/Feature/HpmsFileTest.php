<?php

// ─── HpmsFileTest ─────────────────────────────────────────────────────────────
// Feature tests for the Phase 9B HpmsController.
//
// Coverage:
//   - test_hpms_page_renders_with_submissions
//   - test_generate_enrollment_file
//   - test_generate_quality_data_file
//   - test_generate_hos_m_file
//   - test_download_returns_text_content_type
//   - test_mark_submitted_changes_status
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\HpmsSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HpmsFileTest extends TestCase
{
    use RefreshDatabase;

    private function financeUser(): User
    {
        return User::factory()->create(['department' => 'finance']);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_hpms_page_renders_with_submissions(): void
    {
        $user = $this->financeUser();

        $this->actingAs($user)
            ->get('/billing/hpms')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Hpms')
                ->has('submissions')
                ->has('submissionTypes')
            );
    }

    public function test_generate_enrollment_file(): void
    {
        $user = $this->financeUser();

        $resp = $this->actingAs($user)
            ->postJson('/billing/hpms/generate', [
                'type'  => 'enrollment',
                'month' => '2025-03',
            ])
            ->assertCreated()
            ->assertJsonStructure(['id', 'submission_type', 'record_count', 'status']);

        // file_content must never appear in API JSON responses
        $this->assertArrayNotHasKey('file_content', $resp->json());

        $this->assertDatabaseHas('emr_hpms_submissions', [
            'submission_type' => 'enrollment',
            'status'          => 'draft',
        ]);
    }

    public function test_generate_quality_data_file(): void
    {
        $user = $this->financeUser();

        $resp = $this->actingAs($user)
            ->postJson('/billing/hpms/generate', [
                'type'    => 'quality_data',
                'year'    => 2025,
                'quarter' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('submission_type', 'quality_data');

        $this->assertArrayNotHasKey('file_content', $resp->json());
    }

    public function test_generate_hos_m_file(): void
    {
        $user = $this->financeUser();

        $resp = $this->actingAs($user)
            ->postJson('/billing/hpms/generate', [
                'type' => 'hos_m',
                'year' => 2025,
            ])
            ->assertCreated()
            ->assertJsonPath('submission_type', 'hos_m');

        $this->assertArrayNotHasKey('file_content', $resp->json());
    }

    public function test_download_returns_text_content_type(): void
    {
        $user       = $this->financeUser();
        $submission = HpmsSubmission::factory()->create([
            'tenant_id'    => $user->tenant_id,
            'file_content' => "CONTRACT_ID|SUBSCRIBER_ID\nH9999|12345678A\n",
        ]);

        $resp = $this->actingAs($user)
            ->get("/billing/hpms/{$submission->id}/download");

        $resp->assertOk();
        $contentType = $resp->headers->get('Content-Type') ?? '';
        $this->assertTrue(
            str_contains($contentType, 'text') || str_contains($contentType, 'octet'),
            "Expected text or octet-stream content type, got: {$contentType}"
        );
    }

    public function test_mark_submitted_changes_status(): void
    {
        $user       = $this->financeUser();
        $submission = HpmsSubmission::factory()->create([
            'tenant_id' => $user->tenant_id,
            'status'    => 'draft',
        ]);

        $this->actingAs($user)
            ->patchJson("/billing/hpms/{$submission->id}/submit")
            ->assertOk()
            ->assertJsonPath('status', 'submitted');

        $this->assertDatabaseHas('emr_hpms_submissions', [
            'id'     => $submission->id,
            'status' => 'submitted',
        ]);
    }
}
