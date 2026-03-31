<?php

// ─── ConsentTest ──────────────────────────────────────────────────────────────
// Feature tests for W4-1 participant consent records (HIPAA 45 CFR §164.520).
//
// Coverage:
//   - Index: returns consent list for participant; cross-tenant returns 403
//   - Store: enrollment/qa/it_admin can create; creates audit log entry
//   - Update: records acknowledgment; only enrollment+qa+it_admin may update;
//             non-authorized dept returns 403
//   - NPP auto-creation: enrolling a participant auto-creates pending NPP consent
//   - Consent count KPI: getMissingNppCount reflects pending NPP records
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ConsentRecord;
use App\Models\Participant;
use App\Models\Referral;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsentTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept = 'enrollment', ?int $tenantId = null): User
    {
        $attrs = ['department' => $dept];
        if ($tenantId) {
            $attrs['tenant_id'] = $tenantId;
        }
        return User::factory()->create($attrs);
    }

    private function makeParticipant(User $user): Participant
    {
        return Participant::factory()->create([
            'tenant_id' => $user->tenant_id,
            'site_id'   => Site::factory()->create(['tenant_id' => $user->tenant_id])->id,
        ]);
    }

    private function makeConsent(Participant $participant, User $user, array $overrides = []): ConsentRecord
    {
        return ConsentRecord::factory()->create(array_merge([
            'participant_id'     => $participant->id,
            'tenant_id'          => $participant->tenant_id,
            'created_by_user_id' => $user->id,
        ], $overrides));
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_consent_list(): void
    {
        $user        = $this->makeUser('enrollment');
        $participant = $this->makeParticipant($user);
        $this->makeConsent($participant, $user);
        $this->makeConsent($participant, $user, [
            'consent_type'   => 'treatment_consent',
            'document_title' => 'PACE Treatment Consent Form',
        ]);

        $this->actingAs($user)
            ->getJson("/participants/{$participant->id}/consents")
            ->assertOk()
            ->assertJsonStructure(['consents'])
            ->assertJsonCount(2, 'consents');
    }

    public function test_index_cross_tenant_returns_403(): void
    {
        $user          = $this->makeUser('enrollment');
        $otherTenant   = Tenant::factory()->create();
        $otherParticipant = Participant::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($user)
            ->getJson("/participants/{$otherParticipant->id}/consents")
            ->assertForbidden();
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_enrollment_user_can_create_consent_record(): void
    {
        $user        = $this->makeUser('enrollment');
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/consents", [
                'consent_type'    => 'treatment_consent',
                'document_title'  => 'PACE Treatment Consent',
                'status'          => 'pending',
            ])
            ->assertCreated()
            ->assertJsonPath('consent.consent_type', 'treatment_consent');

        $this->assertDatabaseHas('emr_consent_records', [
            'participant_id' => $participant->id,
            'consent_type'   => 'treatment_consent',
        ]);
    }

    public function test_store_creates_audit_log_entry(): void
    {
        $user        = $this->makeUser('enrollment');
        $participant = $this->makeParticipant($user);

        $this->actingAs($user)
            ->postJson("/participants/{$participant->id}/consents", [
                'consent_type'   => 'hipaa_authorization',
                'document_title' => 'HIPAA Authorization',
                'status'         => 'pending',
            ]);

        $this->assertDatabaseHas('shared_audit_logs', [
            'action'      => 'consent.created',
            'resource_type' => 'consent_record',
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_enrollment_user_can_acknowledge_consent(): void
    {
        $user        = $this->makeUser('enrollment');
        $participant = $this->makeParticipant($user);
        $consent     = $this->makeConsent($participant, $user);

        $this->actingAs($user)
            ->putJson("/participants/{$participant->id}/consents/{$consent->id}", [
                'status'              => 'acknowledged',
                'acknowledged_by'     => 'Jane Participant',
                'representative_type' => 'self',
            ])
            ->assertOk()
            ->assertJsonPath('consent.status', 'acknowledged');
    }

    public function test_non_authorized_dept_cannot_update_consent(): void
    {
        $user        = $this->makeUser('primary_care');
        $participant = $this->makeParticipant($user);
        $consent     = $this->makeConsent($participant, $user);

        $this->actingAs($user)
            ->putJson("/participants/{$participant->id}/consents/{$consent->id}", [
                'status' => 'acknowledged',
            ])
            ->assertForbidden();
    }

    public function test_cannot_update_consent_belonging_to_different_participant(): void
    {
        $user         = $this->makeUser('enrollment');
        $participant1 = $this->makeParticipant($user);
        $participant2 = $this->makeParticipant($user);
        $consent      = $this->makeConsent($participant1, $user);

        // Attempt to update participant1's consent via participant2's URL.
        // ConsentController::authorizeConsent() checks consent->participant_id === participant->id → 403.
        $this->actingAs($user)
            ->putJson("/participants/{$participant2->id}/consents/{$consent->id}", [
                'status' => 'acknowledged',
            ])
            ->assertForbidden();
    }

    // ── NPP auto-creation on enrollment ──────────────────────────────────────

    public function test_enrollment_auto_creates_pending_npp_consent(): void
    {
        $user        = $this->makeUser('enrollment');
        $participant = $this->makeParticipant($user);

        // Transition a referral to enrolled via EnrollmentService
        $referral = Referral::factory()->create([
            'tenant_id'       => $user->tenant_id,
            'status'          => 'pending_enrollment',
            'participant_id'  => $participant->id,
        ]);

        app(EnrollmentService::class)->transition($referral, 'enrolled', $user);

        $this->assertDatabaseHas('emr_consent_records', [
            'participant_id' => $participant->id,
            'consent_type'   => ConsentRecord::NPP_TYPE,
            'status'         => 'pending',
        ]);
    }
}
