<?php

namespace Tests\Feature;

use App\Models\IdtMeeting;
use App\Models\IdtParticipantReview;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdtMeetingTest extends TestCase
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
            'mrn_prefix' => 'IDT',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'idt',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ─── Create meeting ───────────────────────────────────────────────────────

    public function test_create_meeting_returns_201(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/idt/meetings', [
                'meeting_date' => now()->addDay()->format('Y-m-d'),
                'meeting_time' => '10:00',
                'meeting_type' => 'weekly',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('emr_idt_meetings', [
            'tenant_id'    => $this->tenant->id,
            'meeting_type' => 'weekly',
            'status'       => 'scheduled',
        ]);
    }

    public function test_create_meeting_requires_valid_type(): void
    {
        $this->actingAs($this->user)
            ->postJson('/idt/meetings', [
                'meeting_date' => now()->addDay()->format('Y-m-d'),
                'meeting_type' => 'invalid_type',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['meeting_type']);
    }

    // ─── Start meeting ────────────────────────────────────────────────────────

    public function test_start_meeting_changes_status_to_in_progress(): void
    {
        $meeting = IdtMeeting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'scheduled',
        ]);

        $this->actingAs($this->user)
            ->postJson("/idt/meetings/{$meeting->id}/start")
            ->assertOk()
            ->assertJsonFragment(['status' => 'in_progress']);

        $this->assertDatabaseHas('emr_idt_meetings', [
            'id'     => $meeting->id,
            'status' => 'in_progress',
        ]);
    }

    // ─── Add participant to queue ─────────────────────────────────────────────

    public function test_add_participant_to_meeting_queue(): void
    {
        $meeting = IdtMeeting::factory()->inProgress()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/idt/meetings/{$meeting->id}/participants", [
                'participant_id' => $this->participant->id,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('emr_idt_participant_reviews', [
            'meeting_id'     => $meeting->id,
            'participant_id' => $this->participant->id,
        ]);
    }

    public function test_cannot_add_participant_to_completed_meeting(): void
    {
        $meeting = IdtMeeting::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAs($this->user)
            ->postJson("/idt/meetings/{$meeting->id}/participants", [
                'participant_id' => $this->participant->id,
            ])
            ->assertStatus(403);
    }

    // ─── Mark participant reviewed ────────────────────────────────────────────

    public function test_mark_reviewed_sets_reviewed_at(): void
    {
        $meeting = IdtMeeting::factory()->inProgress()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $review = IdtParticipantReview::create([
            'meeting_id'     => $meeting->id,
            'participant_id' => $this->participant->id,
            'queue_order'    => 1,
            'action_items'   => [],
        ]);

        $this->actingAs($this->user)
            ->postJson("/idt/meetings/{$meeting->id}/participants/{$review->id}/reviewed")
            ->assertOk();

        $this->assertDatabaseHas('emr_idt_participant_reviews', [
            'id'         => $review->id,
        ]);
        $this->assertNotNull(IdtParticipantReview::find($review->id)->reviewed_at);
    }

    // ─── Complete (lock) meeting ──────────────────────────────────────────────

    public function test_complete_meeting_locks_it(): void
    {
        $meeting = IdtMeeting::factory()->inProgress()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAs($this->user)
            ->postJson("/idt/meetings/{$meeting->id}/complete")
            ->assertOk()
            ->assertJsonFragment(['status' => 'completed']);

        $this->assertDatabaseHas('emr_idt_meetings', [
            'id'     => $meeting->id,
            'status' => 'completed',
        ]);
    }

    public function test_cannot_edit_completed_meeting(): void
    {
        $meeting = IdtMeeting::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/idt/meetings/{$meeting->id}", ['minutes_text' => 'Editing locked meeting.'])
            ->assertStatus(403);
    }

    // ─── Tenant isolation ─────────────────────────────────────────────────────

    public function test_cannot_access_meeting_from_different_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $meeting     = IdtMeeting::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($this->user)
            ->postJson("/idt/meetings/{$meeting->id}/complete")
            ->assertStatus(403);
    }
}
