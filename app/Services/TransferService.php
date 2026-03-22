<?php

// ─── TransferService ──────────────────────────────────────────────────────────
// Manages the full lifecycle of participant site transfers.
//
// Workflow:
//   1. requestTransfer()  — creates pending transfer record
//   2. approveTransfer()  — marks approved, sets approved_by + approved_at
//   3. cancelTransfer()   — marks cancelled (only when pending or approved)
//   4. completeTransfer() — moves participant to new site, sends IDT notifications,
//                           marks completed. Called by TransferCompletionJob.
//
// Data visibility after completion:
//   - Prior site staff retain read-only access for 90 days (enforced in
//     CheckDepartmentAccess via ParticipantSiteTransfer::priorSiteHasReadAccess())
//
// Phase 10A
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\ChatChannel;
use App\Models\ChatMessage;
use App\Models\Participant;
use App\Models\ParticipantSiteTransfer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransferService
{
    // ── Request ───────────────────────────────────────────────────────────────

    /**
     * Submit a new transfer request.
     *
     * @param  Participant  $participant
     * @param  int  $toSiteId
     * @param  string  $reason           One of ParticipantSiteTransfer::TRANSFER_REASONS
     * @param  string|null  $notes
     * @param  \DateTimeInterface|string  $effectiveDate
     * @param  User  $requestedBy
     * @return ParticipantSiteTransfer
     */
    public function requestTransfer(
        Participant $participant,
        int $toSiteId,
        string $reason,
        ?string $notes,
        string $effectiveDate,
        User $requestedBy,
    ): ParticipantSiteTransfer {
        $transfer = ParticipantSiteTransfer::create([
            'participant_id'        => $participant->id,
            'tenant_id'             => $participant->tenant_id,
            'from_site_id'          => $participant->site_id,
            'to_site_id'            => $toSiteId,
            'transfer_reason'       => $reason,
            'transfer_reason_notes' => $notes,
            'requested_by_user_id'  => $requestedBy->id,
            'requested_at'          => now(),
            'effective_date'        => $effectiveDate,
            'status'                => 'pending',
        ]);

        AuditLog::record(
            action: 'participant.transfer.requested',
            tenantId: $participant->tenant_id,
            userId: $requestedBy->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Transfer request #{$transfer->id} submitted: site {$participant->site_id} → {$toSiteId}, effective {$effectiveDate}",
        );

        return $transfer;
    }

    // ── Approve ───────────────────────────────────────────────────────────────

    /**
     * Approve a pending transfer request.
     */
    public function approveTransfer(ParticipantSiteTransfer $transfer, User $approvedBy): ParticipantSiteTransfer
    {
        if (!$transfer->isPending()) {
            abort(409, "Transfer #{$transfer->id} is not in pending status.");
        }

        $transfer->update([
            'status'               => 'approved',
            'approved_by_user_id'  => $approvedBy->id,
            'approved_at'          => now(),
        ]);

        AuditLog::record(
            action: 'participant.transfer.approved',
            tenantId: $transfer->tenant_id,
            userId: $approvedBy->id,
            resourceType: 'participant',
            resourceId: $transfer->participant_id,
            description: "Transfer #{$transfer->id} approved by {$approvedBy->first_name} {$approvedBy->last_name}",
        );

        return $transfer->fresh();
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    /**
     * Cancel a pending or approved transfer request.
     */
    public function cancelTransfer(ParticipantSiteTransfer $transfer, User $cancelledBy): ParticipantSiteTransfer
    {
        if (!in_array($transfer->status, ['pending', 'approved'], true)) {
            abort(409, "Transfer #{$transfer->id} cannot be cancelled (status: {$transfer->status}).");
        }

        $transfer->update(['status' => 'cancelled']);

        AuditLog::record(
            action: 'participant.transfer.cancelled',
            tenantId: $transfer->tenant_id,
            userId: $cancelledBy->id,
            resourceType: 'participant',
            resourceId: $transfer->participant_id,
            description: "Transfer #{$transfer->id} cancelled by {$cancelledBy->first_name} {$cancelledBy->last_name}",
        );

        return $transfer->fresh();
    }

    // ── Complete ──────────────────────────────────────────────────────────────

    /**
     * Complete an approved transfer whose effective_date has arrived.
     *
     * Actions:
     *   1. Move participant to new site (site_id update + enrollment_status if needed)
     *   2. Alert IDT channels at both old and new sites
     *   3. Mark transfer completed, notification_sent=true
     *   4. Audit log
     *
     * Runs inside a DB transaction.
     */
    public function completeTransfer(ParticipantSiteTransfer $transfer): void
    {
        if (!$transfer->isApproved()) {
            return;
        }

        DB::transaction(function () use ($transfer) {
            $participant = $transfer->participant;
            $fromSite    = Site::find($transfer->from_site_id);
            $toSite      = Site::find($transfer->to_site_id);

            // 1. Move participant to new site
            $participant->update(['site_id' => $transfer->to_site_id]);

            // 2. Send alerts to IDT channels at both sites
            $participantName = $participant->first_name . ' ' . $participant->last_name;
            $fromSiteName    = $fromSite?->name ?? "Site #{$transfer->from_site_id}";
            $toSiteName      = $toSite?->name   ?? "Site #{$transfer->to_site_id}";

            $this->postIdtTransferAlert(
                tenantId: $transfer->tenant_id,
                participantId: $participant->id,
                message: "🔄 {$participantName} has transferred FROM this site to {$toSiteName} as of {$transfer->effective_date->format('M j, Y')}. Clinical records remain accessible for 90 days.",
            );

            $this->postIdtTransferAlert(
                tenantId: $transfer->tenant_id,
                participantId: $participant->id,
                message: "🆕 {$participantName} has transferred to this site FROM {$fromSiteName} as of {$transfer->effective_date->format('M j, Y')}. Please schedule IDT review.",
            );

            // 3. Mark complete
            $transfer->update([
                'status'            => 'completed',
                'notification_sent' => true,
            ]);

            // 4. Audit
            AuditLog::record(
                action: 'participant.transfer.completed',
                tenantId: $transfer->tenant_id,
                userId: null,   // system job — no active user
                resourceType: 'participant',
                resourceId: $participant->id,
                description: "Transfer #{$transfer->id} completed: {$participantName} moved {$fromSiteName} → {$toSiteName} (effective {$transfer->effective_date->format('Y-m-d')})",
            );
        });
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Post a system message to the participant's IDT chat channel.
     * Gracefully no-ops if no channel exists.
     */
    private function postIdtTransferAlert(int $tenantId, int $participantId, string $message): void
    {
        $channel = ChatChannel::where('tenant_id', $tenantId)
            ->where('channel_type', 'participant_idt')
            ->where('participant_id', $participantId)
            ->first();

        if (!$channel) {
            return;
        }

        ChatMessage::create([
            'channel_id'   => $channel->id,
            'user_id'      => null,   // system message
            'message_text' => $message,
            'sent_at'      => now(),
        ]);
    }
}
