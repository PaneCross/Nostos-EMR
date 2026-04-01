<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssessmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'TEST',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function test_create_assessment_returns_201(): void
    {
        $responses = array_fill_keys(
            ['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9'],
            '1'
        );

        $response = $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'phq9_depression',
                'department'      => 'primary_care',
                'responses'       => $responses,
                'score'           => 9,
                'completed_at'    => now()->format('Y-m-d H:i:s'),
                'next_due_date'   => now()->addYear()->format('Y-m-d'),
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('emr_assessments', [
            'participant_id'      => $this->participant->id,
            'assessment_type'     => 'phq9_depression',
            'score'               => 9,
            'authored_by_user_id' => $this->user->id,
        ]);
    }

    public function test_create_assessment_requires_valid_type(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'fake_assessment',
                'responses'       => ['q1' => '1'],
                'completed_at'    => now()->format('Y-m-d H:i:s'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assessment_type']);
    }

    public function test_create_assessment_without_responses_succeeds(): void
    {
        // W4-4: responses is nullable — assessments like Braden/MoCA/OHAT only
        // store a total score, not subscale responses. Omitting responses is valid.
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'phq9_depression',
                'completed_at'    => now()->format('Y-m-d H:i:s'),
                // responses omitted intentionally — now nullable
            ])
            ->assertCreated();
    }

    public function test_create_assessment_requires_completed_at(): void
    {
        $this->actingAs($this->user)
            ->postJson("/participants/{$this->participant->id}/assessments", [
                'assessment_type' => 'phq9_depression',
                'responses'       => ['q1' => '1'],
                // completed_at omitted
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['completed_at']);
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    public function test_index_returns_assessments_ordered_newest_first(): void
    {
        Assessment::factory()->count(3)
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['authored_by_user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/assessments");

        $response->assertOk();
        $this->assertCount(3, $response->json());
    }

    // ─── Due endpoint ─────────────────────────────────────────────────────────

    public function test_due_endpoint_returns_overdue_assessments(): void
    {
        // Overdue — next_due_date in the past
        Assessment::factory()
            ->overdue()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['authored_by_user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/assessments/due");

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('overdue', $data);
        $this->assertCount(1, $data['overdue']);
    }

    public function test_due_endpoint_returns_due_soon_assessments(): void
    {
        // Due in 7 days (within the 14-day window)
        Assessment::factory()
            ->dueSoon()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create(['authored_by_user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/assessments/due");

        $response->assertOk();
        $data = $response->json();
        $this->assertArrayHasKey('due_soon', $data);
        $this->assertCount(1, $data['due_soon']);
    }

    public function test_due_endpoint_excludes_current_assessments(): void
    {
        // Current — next_due_date more than 14 days away
        Assessment::factory()
            ->forParticipant($this->participant->id)
            ->forTenant($this->tenant->id)
            ->create([
                'authored_by_user_id' => $this->user->id,
                'next_due_date'       => now()->addDays(60)->format('Y-m-d'),
            ]);

        $response = $this->actingAs($this->user)
            ->getJson("/participants/{$this->participant->id}/assessments/due");

        $response->assertOk();
        $data = $response->json();
        $this->assertEmpty($data['overdue'] ?? []);
        $this->assertEmpty($data['due_soon'] ?? []);
    }

    // ─── Tenant isolation ─────────────────────────────────────────────────────

    public function test_cannot_view_assessments_from_different_tenant(): void
    {
        $otherTenant      = Tenant::factory()->create();
        $otherSite        = Site::factory()->create([
            'tenant_id'  => $otherTenant->id,
            'mrn_prefix' => 'OTHER',
        ]);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)
            ->forSite($otherSite->id)
            ->create();

        $this->actingAs($this->user)
            ->getJson("/participants/{$otherParticipant->id}/assessments")
            ->assertForbidden();
    }
}
