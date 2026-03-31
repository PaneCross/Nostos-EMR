<?php

// ─── SiteTransferDataTest ─────────────────────────────────────────────────────
// W3-6: Site Transfer Data Integrity feature tests.
//
// Coverage:
//   - test_participant_with_transfers_has_multiple_sites_flag
//   - test_participant_without_transfers_has_no_multiple_sites_flag
//   - test_participant_show_includes_completed_transfers
//   - test_clinical_notes_include_site_data_when_participant_has_transfers
//   - test_transfer_summary_returns_site_periods
//   - test_transfer_summary_empty_for_no_transfers
//   - test_verify_returns_verified_for_clean_data
//   - test_verify_returns_verified_when_no_notes
//   - test_reports_site_transfers_endpoint_returns_transfer_data
//   - test_reports_site_transfers_filters_by_site
//   - test_reports_site_transfers_export_returns_csv
//   - test_reports_site_transfers_requires_allowed_department
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Models\ParticipantSiteTransfer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteTransferDataTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function qaUser(): User
    {
        return User::factory()->create(['department' => 'qa_compliance']);
    }

    private function enrollmentUser(int $tenantId): User
    {
        return User::factory()->create([
            'department' => 'enrollment',
            'tenant_id'  => $tenantId,
        ]);
    }

    private function makeSite(int $tenantId): Site
    {
        return Site::factory()->create(['tenant_id' => $tenantId]);
    }

    /**
     * Create a completed transfer and update the participant's current site.
     */
    private function completeTransfer(
        Participant $participant,
        Site $fromSite,
        Site $toSite,
        User $actor,
        string $effectiveDate
    ): ParticipantSiteTransfer {
        $transfer = ParticipantSiteTransfer::create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $participant->tenant_id,
            'from_site_id'         => $fromSite->id,
            'to_site_id'           => $toSite->id,
            'transfer_reason'      => 'relocation',
            'requested_by_user_id' => $actor->id,
            'requested_at'         => now()->subDays(5),
            'approved_by_user_id'  => $actor->id,
            'approved_at'          => now()->subDays(3),
            'effective_date'       => $effectiveDate,
            'status'               => 'completed',
            'notification_sent'    => true,
        ]);

        $participant->update(['site_id' => $toSite->id]);

        return $transfer;
    }

    // ── Participant model helpers ─────────────────────────────────────────────

    public function test_participant_with_transfers_has_multiple_sites_flag(): void
    {
        $user        = $this->qaUser();
        $fromSite    = $this->makeSite($user->tenant_id);
        $toSite      = $this->makeSite($user->tenant_id);
        $actor       = $this->enrollmentUser($user->tenant_id);
        $participant = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $fromSite->id,
        ]);

        $this->assertFalse($participant->hasMultipleSites());

        $this->completeTransfer($participant, $fromSite, $toSite, $actor, now()->subDays(30)->toDateString());

        $participant->refresh();
        $this->assertTrue($participant->hasMultipleSites());
    }

    public function test_participant_without_transfers_has_no_multiple_sites_flag(): void
    {
        $participant = Participant::factory()->create();
        $this->assertFalse($participant->hasMultipleSites());
    }

    // ── ParticipantController show() props ───────────────────────────────────

    public function test_participant_show_includes_completed_transfers(): void
    {
        $user        = $this->qaUser();
        $fromSite    = $this->makeSite($user->tenant_id);
        $toSite      = $this->makeSite($user->tenant_id);
        $actor       = $this->enrollmentUser($user->tenant_id);
        $participant = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $fromSite->id,
        ]);

        $effectiveDate = now()->subDays(30)->toDateString();
        $this->completeTransfer($participant, $fromSite, $toSite, $actor, $effectiveDate);

        $response = $this->actingAs($user)
            ->get("/participants/{$participant->id}");

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('hasMultipleSites')
                ->where('hasMultipleSites', true)
                ->has('completedTransfers', 1)
                ->where('completedTransfers.0.effective_date', $effectiveDate)
            );
    }

    // ── ClinicalNote site eager-loading ──────────────────────────────────────

    public function test_clinical_notes_include_site_data_when_participant_has_transfers(): void
    {
        $user        = $this->qaUser();
        $site        = $this->makeSite($user->tenant_id);
        $participant = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $site->id,
        ]);

        // Create a note with site_id set
        ClinicalNote::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $user->tenant_id,
            'site_id'              => $site->id,
            'authored_by_user_id'  => $user->id,
            'department'           => 'qa_compliance',
            'status'               => 'signed',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/notes");

        // ClinicalNoteController returns a paginator — items are under 'data' key
        $response->assertOk()
            ->assertJsonPath('data.0.site.id', $site->id);
    }

    // ── TransferController summary() ─────────────────────────────────────────

    public function test_transfer_summary_returns_site_periods(): void
    {
        $user        = $this->qaUser();
        $fromSite    = $this->makeSite($user->tenant_id);
        $toSite      = $this->makeSite($user->tenant_id);
        $actor       = $this->enrollmentUser($user->tenant_id);
        $participant = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $fromSite->id,
        ]);

        $this->completeTransfer($participant, $fromSite, $toSite, $actor, now()->subDays(30)->toDateString());

        $response = $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/transfers/summary");

        $response->assertOk()
            ->assertJsonStructure(['periods' => [['site_name', 'start', 'end', 'note_count', 'vital_count', 'appointment_count']]])
            ->assertJsonCount(2, 'periods');
    }

    public function test_transfer_summary_empty_for_no_transfers(): void
    {
        $user        = $this->qaUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/transfers/summary");

        $response->assertOk()
            ->assertJson(['periods' => []]);
    }

    // ── TransferController verify() ──────────────────────────────────────────

    public function test_verify_returns_verified_for_clean_data(): void
    {
        $user        = $this->qaUser();
        $site        = $this->makeSite($user->tenant_id);
        $participant = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $site->id,
        ]);

        // All notes have a valid site_id
        ClinicalNote::factory()->create([
            'participant_id' => $participant->id,
            'tenant_id'      => $user->tenant_id,
            'site_id'        => $site->id,
            'authored_by_user_id' => $user->id,
            'department'     => 'qa_compliance',
            'status'         => 'signed',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers/verify");

        $response->assertOk()
            ->assertJson(['status' => 'verified', 'anomalies' => []]);
    }

    public function test_verify_returns_verified_when_no_notes(): void
    {
        // Participant with no clinical notes should always be verified
        $user        = $this->qaUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        $response = $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers/verify");

        $response->assertOk()
            ->assertJson(['status' => 'verified', 'anomalies' => []]);
    }

    // ── ReportsController siteTransfers() ────────────────────────────────────

    public function test_reports_site_transfers_endpoint_returns_transfer_data(): void
    {
        $user        = User::factory()->create(['department' => 'finance']);
        $fromSite    = $this->makeSite($user->tenant_id);
        $toSite      = $this->makeSite($user->tenant_id);
        $actor       = $this->enrollmentUser($user->tenant_id);
        $participant = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $fromSite->id,
        ]);

        $this->completeTransfer($participant, $fromSite, $toSite, $actor, now()->subDays(30)->toDateString());

        $response = $this->actingAs($user)
            ->getJson('/reports/site-transfers');

        $response->assertOk()
            ->assertJsonStructure(['participants', 'sites', 'total'])
            ->assertJsonPath('total', 1);
    }

    public function test_reports_site_transfers_filters_by_site(): void
    {
        $user       = User::factory()->create(['department' => 'finance']);
        $siteA      = $this->makeSite($user->tenant_id);
        $siteB      = $this->makeSite($user->tenant_id);
        $siteC      = $this->makeSite($user->tenant_id);
        $actor      = $this->enrollmentUser($user->tenant_id);

        $pptA = Participant::factory()->create(['tenant_id' => $user->tenant_id, 'site_id' => $siteA->id]);
        $pptB = Participant::factory()->create(['tenant_id' => $user->tenant_id, 'site_id' => $siteA->id]);

        $this->completeTransfer($pptA, $siteA, $siteB, $actor, now()->subDays(30)->toDateString());
        $this->completeTransfer($pptB, $siteA, $siteC, $actor, now()->subDays(30)->toDateString());

        // Filter to siteB only — should return 1 participant
        $response = $this->actingAs($user)
            ->getJson("/reports/site-transfers?site_id={$siteB->id}");

        $response->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_reports_site_transfers_export_returns_csv(): void
    {
        $user       = User::factory()->create(['department' => 'finance']);
        $fromSite   = $this->makeSite($user->tenant_id);
        $toSite     = $this->makeSite($user->tenant_id);
        $actor      = $this->enrollmentUser($user->tenant_id);
        $participant = Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => $fromSite->id,
        ]);

        $this->completeTransfer($participant, $fromSite, $toSite, $actor, now()->subDays(30)->toDateString());

        $response = $this->actingAs($user)
            ->get('/reports/site-transfers/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_reports_site_transfers_requires_allowed_department(): void
    {
        // Primary care does not have access to the site-transfers report
        $user = User::factory()->create(['department' => 'primary_care']);

        $response = $this->actingAs($user)
            ->getJson('/reports/site-transfers');

        $response->assertForbidden();
    }
}
