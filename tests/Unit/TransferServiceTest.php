<?php

// ─── TransferServiceTest ───────────────────────────────────────────────────────
// Unit tests for Phase 10A TransferService.
//
// Coverage:
//   - test_request_transfer_creates_pending_record
//   - test_request_transfer_writes_audit_log
//   - test_approve_transfer_sets_approved_status
//   - test_approve_already_cancelled_transfer_throws
//   - test_cancel_pending_transfer_sets_cancelled
//   - test_cancel_completed_transfer_throws
//   - test_complete_transfer_moves_participant_site
//   - test_complete_non_approved_transfer_is_no_op
//   - test_prior_site_has_read_access_within_90_days
//   - test_prior_site_loses_read_access_after_90_days
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\ParticipantSiteTransfer;
use App\Models\Site;
use App\Models\User;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): TransferService
    {
        return new TransferService();
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_request_transfer_creates_pending_record(): void
    {
        $user        = User::factory()->create(['department' => 'enrollment']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $transfer = $this->makeService()->requestTransfer(
            participant: $participant,
            toSiteId: $toSite->id,
            reason: 'relocation',
            notes: null,
            effectiveDate: now()->addDays(7)->format('Y-m-d'),
            requestedBy: $user,
        );

        $this->assertEquals('pending', $transfer->status);
        $this->assertEquals($participant->id, $transfer->participant_id);
        $this->assertEquals($toSite->id, $transfer->to_site_id);
        $this->assertEquals($participant->site_id, $transfer->from_site_id);
    }

    public function test_request_transfer_writes_audit_log(): void
    {
        $user        = User::factory()->create(['department' => 'enrollment']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->makeService()->requestTransfer(
            participant: $participant,
            toSiteId: $toSite->id,
            reason: 'capacity',
            notes: 'Overcrowding',
            effectiveDate: now()->addDays(14)->format('Y-m-d'),
            requestedBy: $user,
        );

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'        => 'participant.transfer.requested',
            'resource_type' => 'participant',
            'resource_id'   => $participant->id,
        ]);
    }

    public function test_approve_transfer_sets_approved_status(): void
    {
        $user        = User::factory()->create(['department' => 'enrollment']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $participant->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'pending',
        ]);

        $approver = User::factory()->create(['tenant_id' => $user->tenant_id, 'department' => 'enrollment']);
        $updated  = $this->makeService()->approveTransfer($transfer, $approver);

        $this->assertEquals('approved', $updated->status);
        $this->assertEquals($approver->id, $updated->approved_by_user_id);
        $this->assertNotNull($updated->approved_at);
    }

    public function test_approve_already_cancelled_transfer_throws(): void
    {
        $user        = User::factory()->create(['department' => 'enrollment']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $participant->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'cancelled',
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->makeService()->approveTransfer($transfer, $user);
    }

    public function test_cancel_pending_transfer_sets_cancelled(): void
    {
        $user        = User::factory()->create(['department' => 'enrollment']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $participant->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'pending',
        ]);

        $updated = $this->makeService()->cancelTransfer($transfer, $user);
        $this->assertEquals('cancelled', $updated->status);
    }

    public function test_cancel_completed_transfer_throws(): void
    {
        $user        = User::factory()->create(['department' => 'enrollment']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $participant->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'completed',
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->makeService()->cancelTransfer($transfer, $user);
    }

    public function test_complete_transfer_moves_participant_site(): void
    {
        $user        = User::factory()->create(['department' => 'enrollment']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $participant->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'approved',
            'approved_by_user_id'  => $user->id,
            'approved_at'          => now()->subDay(),
            'effective_date'       => now()->toDateString(),
        ]);

        $this->makeService()->completeTransfer($transfer);

        $participant->refresh();
        $this->assertEquals($toSite->id, $participant->site_id);

        $transfer->refresh();
        $this->assertEquals('completed', $transfer->status);
        $this->assertTrue($transfer->notification_sent);
    }

    public function test_complete_non_approved_transfer_is_no_op(): void
    {
        $user        = User::factory()->create(['department' => 'enrollment']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = Site::factory()->create(['tenant_id' => $user->tenant_id]);
        $originalSite = $participant->site_id;

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $participant->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'pending',
        ]);

        $this->makeService()->completeTransfer($transfer);

        // Participant site should NOT change
        $participant->refresh();
        $this->assertEquals($originalSite, $participant->site_id);
    }

    public function test_prior_site_has_read_access_within_90_days(): void
    {
        $user        = User::factory()->create(['department' => 'enrollment']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = Site::factory()->create(['tenant_id' => $user->tenant_id]);
        $fromSiteId  = $participant->site_id;

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $participant->tenant_id,
            'from_site_id'         => $fromSiteId,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'completed',
            'effective_date'       => now()->subDays(30)->toDateString(),
        ]);

        $this->assertTrue($transfer->priorSiteHasReadAccess($fromSiteId));
    }

    public function test_prior_site_loses_read_access_after_90_days(): void
    {
        $user        = User::factory()->create(['department' => 'enrollment']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = Site::factory()->create(['tenant_id' => $user->tenant_id]);
        $fromSiteId  = $participant->site_id;

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $participant->tenant_id,
            'from_site_id'         => $fromSiteId,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'completed',
            'effective_date'       => now()->subDays(91)->toDateString(),
        ]);

        $this->assertFalse($transfer->priorSiteHasReadAccess($fromSiteId));
    }
}
