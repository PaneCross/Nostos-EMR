<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Sdr;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SdrTest extends TestCase
{
    use RefreshDatabase;

    private Tenant      $tenant;
    private Site        $site;
    private User        $user;
    private User        $qaUser;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'mrn_prefix' => 'SDR',
        ]);
        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->qaUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ─── Submit SDR ───────────────────────────────────────────────────────────

    public function test_submit_sdr_returns_201(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/sdrs', [
                'participant_id'      => $this->participant->id,
                'assigned_department' => 'pharmacy',
                'request_type'        => 'lab_order',
                'description'         => 'Lab order for CBC and BMP.',
                'priority'            => 'routine',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('emr_sdrs', [
            'participant_id'        => $this->participant->id,
            'requesting_department' => 'primary_care',
            'assigned_department'   => 'pharmacy',
            'request_type'          => 'lab_order',
            'status'                => 'submitted',
        ]);
    }

    public function test_due_at_auto_set_to_72h_after_submitted_at(): void
    {
        // Use second-level precision: Eloquent's datetime cast truncates microseconds
        $submittedAt = Carbon::now()->subHours(5)->startOfSecond();

        $sdr = Sdr::create([
            'participant_id'        => $this->participant->id,
            'tenant_id'             => $this->tenant->id,
            'requesting_user_id'    => $this->user->id,
            'requesting_department' => 'primary_care',
            'assigned_department'   => 'pharmacy',
            'request_type'          => 'lab_order',
            'description'           => 'Test SDR',
            'priority'              => 'routine',
            'status'                => 'submitted',
            'submitted_at'          => $submittedAt,
        ]);

        // due_at must always be exactly submitted_at + 72h
        $expectedDue = $submittedAt->copy()->addHours(72);
        $this->assertSame(0, (int) abs($sdr->due_at->diffInSeconds($expectedDue)));
    }

    public function test_cannot_set_due_at_beyond_72h_window(): void
    {
        $sdr = Sdr::factory()->create([
            'participant_id'  => $this->participant->id,
            'tenant_id'       => $this->tenant->id,
        ]);

        $this->expectException(\LogicException::class);

        // Attempt to push due_at beyond allowed window
        $sdr->due_at = $sdr->submitted_at->copy()->addHours(80);
        $sdr->save();
    }

    // ─── Update status ────────────────────────────────────────────────────────

    public function test_update_status_to_in_progress(): void
    {
        $sdr = Sdr::factory()->create([
            'participant_id'  => $this->participant->id,
            'tenant_id'       => $this->tenant->id,
            'status'          => 'submitted',
            'assigned_department' => 'pharmacy',
        ]);

        $pharmUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'pharmacy',
            'role'       => 'standard',
            'is_active'  => true,
        ]);

        $this->actingAs($pharmUser)
            ->patchJson("/sdrs/{$sdr->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonFragment(['status' => 'in_progress']);
    }

    public function test_complete_sdr_requires_completion_notes(): void
    {
        $sdr = Sdr::factory()->create([
            'participant_id'  => $this->participant->id,
            'tenant_id'       => $this->tenant->id,
            'status'          => 'in_progress',
        ]);

        // Missing completion_notes → should fail validation
        $this->actingAs($this->user)
            ->patchJson("/sdrs/{$sdr->id}", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['completion_notes']);
    }

    public function test_complete_sdr_with_notes_succeeds(): void
    {
        $sdr = Sdr::factory()->create([
            'participant_id'  => $this->participant->id,
            'tenant_id'       => $this->tenant->id,
            'status'          => 'in_progress',
        ]);

        $this->actingAs($this->user)
            ->patchJson("/sdrs/{$sdr->id}", [
                'status'           => 'completed',
                'completion_notes' => 'Request fulfilled. Participant notified.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('emr_sdrs', [
            'id'     => $sdr->id,
            'status' => 'completed',
        ]);
        $this->assertNotNull(Sdr::find($sdr->id)->completed_at);
    }

    // ─── QA visibility ────────────────────────────────────────────────────────

    public function test_qa_user_sees_all_sdrs_tab(): void
    {
        // QA compliance user should see 'allSdrs' prop (not null)
        $response = $this->actingAs($this->qaUser)
            ->get('/sdrs');

        $response->assertOk()
            ->assertInertia(fn ($page) =>
                $page->has('allSdrs')
                     ->where('userDept', 'qa_compliance')
            );
    }

    public function test_non_qa_user_gets_null_all_sdrs(): void
    {
        $response = $this->actingAs($this->user)
            ->get('/sdrs');

        $response->assertOk()
            ->assertInertia(fn ($page) =>
                $page->where('allSdrs', null)
                     ->where('userDept', 'primary_care')
            );
    }

    // ─── Soft delete (cancel) ─────────────────────────────────────────────────

    public function test_delete_sdr_soft_deletes(): void
    {
        $sdr = Sdr::factory()->create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/sdrs/{$sdr->id}")
            ->assertStatus(204);

        // Soft deleted — still exists in DB but with deleted_at set
        $this->assertSoftDeleted('emr_sdrs', ['id' => $sdr->id]);
    }
}
