<?php

// ─── ParticipantTransferTest ───────────────────────────────────────────────────
// Feature tests for Phase 10A participant site transfers.
//
// Coverage:
//   - test_enrollment_user_can_request_transfer
//   - test_enrollment_user_cannot_transfer_to_current_site
//   - test_duplicate_pending_transfer_returns_409
//   - test_enrollment_user_can_approve_pending_transfer
//   - test_enrollment_user_can_cancel_pending_transfer
//   - test_enrollment_user_can_cancel_approved_transfer
//   - test_cannot_approve_already_cancelled_transfer
//   - test_primary_care_user_cannot_request_transfer
//   - test_non_owner_tenant_cannot_request_transfer
//   - test_transfer_history_returns_all_transfers
//   - test_transfer_admin_page_requires_enrollment_or_it_admin
//   - test_transfer_admin_page_returns_inertia_response
//   - test_it_admin_user_can_request_transfer
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\ParticipantSiteTransfer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantTransferTest extends TestCase
{
    use RefreshDatabase;

    private function enrollmentUser(?int $tenantId = null): User
    {
        $attrs = ['department' => 'enrollment'];
        if ($tenantId !== null) {
            $attrs['tenant_id'] = $tenantId;
        }
        return User::factory()->create($attrs);
    }

    private function makeSite(int $tenantId): Site
    {
        return Site::factory()->create(['tenant_id' => $tenantId]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_enrollment_user_can_request_transfer(): void
    {
        $user        = $this->enrollmentUser();
        $participant = Participant::factory()->create([
            'tenant_id'        => $user->tenant_id,
            'enrollment_status'=> 'enrolled',
        ]);
        $toSite = $this->makeSite($user->tenant_id);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers", [
                'to_site_id'      => $toSite->id,
                'transfer_reason' => 'relocation',
                'effective_date'  => now()->addDays(7)->format('Y-m-d'),
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('to_site.id', $toSite->id);

        $this->assertDatabaseHas('emr_participant_site_transfers', [
            'participant_id' => $participant->id,
            'to_site_id'     => $toSite->id,
            'status'         => 'pending',
        ]);
    }

    public function test_enrollment_user_cannot_transfer_to_current_site(): void
    {
        $user        = $this->enrollmentUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers", [
                'to_site_id'      => $participant->site_id,
                'transfer_reason' => 'relocation',
                'effective_date'  => now()->addDays(7)->format('Y-m-d'),
            ])
            ->assertUnprocessable();
    }

    public function test_duplicate_pending_transfer_returns_409(): void
    {
        $user        = $this->enrollmentUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = $this->makeSite($user->tenant_id);

        // Create a pending transfer
        ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $user->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'pending',
        ]);

        // Attempt second
        $toSite2 = $this->makeSite($user->tenant_id);
        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers", [
                'to_site_id'      => $toSite2->id,
                'transfer_reason' => 'capacity',
                'effective_date'  => now()->addDays(14)->format('Y-m-d'),
            ])
            ->assertStatus(409);
    }

    public function test_enrollment_user_can_approve_pending_transfer(): void
    {
        $user        = $this->enrollmentUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = $this->makeSite($user->tenant_id);

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $user->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'pending',
        ]);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers/{$transfer->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('emr_participant_site_transfers', [
            'id'     => $transfer->id,
            'status' => 'approved',
        ]);
    }

    public function test_enrollment_user_can_cancel_pending_transfer(): void
    {
        $user        = $this->enrollmentUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = $this->makeSite($user->tenant_id);

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $user->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'pending',
        ]);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers/{$transfer->id}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_enrollment_user_can_cancel_approved_transfer(): void
    {
        $user        = $this->enrollmentUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = $this->makeSite($user->tenant_id);

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $user->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'approved',
            'approved_by_user_id'  => $user->id,
            'approved_at'          => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers/{$transfer->id}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_cannot_approve_already_cancelled_transfer(): void
    {
        $user        = $this->enrollmentUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = $this->makeSite($user->tenant_id);

        $transfer = ParticipantSiteTransfer::factory()->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $user->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
            'status'               => 'cancelled',
        ]);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers/{$transfer->id}/approve")
            ->assertStatus(409);
    }

    public function test_primary_care_user_cannot_request_transfer(): void
    {
        $user        = User::factory()->create(['department' => 'primary_care']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = $this->makeSite($user->tenant_id);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers", [
                'to_site_id'      => $toSite->id,
                'transfer_reason' => 'relocation',
                'effective_date'  => now()->addDays(7)->format('Y-m-d'),
            ])
            ->assertForbidden();
    }

    public function test_non_owner_tenant_cannot_request_transfer(): void
    {
        $userA       = $this->enrollmentUser();
        $userB       = $this->enrollmentUser(); // different tenant
        $participant = Participant::factory()->create(['tenant_id' => $userB->tenant_id]);
        $toSite      = $this->makeSite($userB->tenant_id);

        $this->actingAs($userA)
            ->postJson("/participants/{$participant->id}/transfers", [
                'to_site_id'      => $toSite->id,
                'transfer_reason' => 'relocation',
                'effective_date'  => now()->addDays(7)->format('Y-m-d'),
            ])
            ->assertNotFound();
    }

    public function test_transfer_history_returns_all_transfers(): void
    {
        $user        = $this->enrollmentUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = $this->makeSite($user->tenant_id);

        ParticipantSiteTransfer::factory()->count(3)->create([
            'participant_id'       => $participant->id,
            'tenant_id'            => $user->tenant_id,
            'from_site_id'         => $participant->site_id,
            'to_site_id'           => $toSite->id,
            'requested_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/transfers")
            ->assertOk()
            ->assertJsonCount(3, 'transfers');
    }

    public function test_transfer_admin_page_requires_enrollment_or_it_admin(): void
    {
        $user = User::factory()->create(['department' => 'primary_care']);

        $this->actingAs($user)
            ->get('/enrollment/transfers')
            ->assertForbidden();
    }

    public function test_transfer_admin_page_returns_inertia_response(): void
    {
        $user = $this->enrollmentUser();

        $this->actingAs($user)
            ->get('/enrollment/transfers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Enrollment/Transfers')
                ->has('transfers')
                ->has('sites')
                ->has('transferReasons')
            );
    }

    public function test_it_admin_user_can_request_transfer(): void
    {
        $user        = User::factory()->create(['department' => 'it_admin']);
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $toSite      = $this->makeSite($user->tenant_id);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/transfers", [
                'to_site_id'      => $toSite->id,
                'transfer_reason' => 'capacity',
                'effective_date'  => now()->addDays(7)->format('Y-m-d'),
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending');
    }
}
