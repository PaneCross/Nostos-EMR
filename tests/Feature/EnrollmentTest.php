<?php

// ─── EnrollmentTest ────────────────────────────────────────────────────────────
// Feature tests for the Phase 6A Enrollment & Intake module.
//
// Coverage:
//   - Referral CRUD (index, store, show, update)
//   - Valid state machine transitions (new → intake_scheduled → ... → enrolled)
//   - Invalid transition returns 422
//   - Terminal referral cannot be updated (409)
//   - enrollment transition links participant + sets enrollment_status
//   - Disenrollment sets correct fields on participant
//   - Authorization: only enrollment/it_admin can create/transition referrals
//   - Tenant isolation: cross-tenant access returns 403
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Referral;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept = 'enrollment'): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeReferral(User $user, array $overrides = []): Referral
    {
        return Referral::factory()->create(array_merge([
            'tenant_id'          => $user->tenant_id,
            'created_by_user_id' => $user->id,
        ], $overrides));
    }

    // ── Index / Show ──────────────────────────────────────────────────────────

    public function test_index_returns_enrollment_pipeline_page(): void
    {
        $user = $this->makeUser();
        $this->makeReferral($user);

        $response = $this->actingAs($user)->get('/enrollment/referrals');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Enrollment/Index')
            ->has('pipeline')
            ->has('statuses')
            ->has('pipelineOrder')
        );
    }

    public function test_show_returns_referral_json(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user);

        $response = $this->actingAs($user)->getJson("/enrollment/referrals/{$referral->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $referral->id]);
    }

    public function test_show_rejects_cross_tenant_referral(): void
    {
        $user      = $this->makeUser();
        $otherUser = User::factory()->create(); // different tenant
        $referral  = $this->makeReferral($otherUser);

        $response = $this->actingAs($user)->getJson("/enrollment/referrals/{$referral->id}");

        $response->assertStatus(403);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_referral_with_new_status(): void
    {
        $user = $this->makeUser();
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $payload = [
            'site_id'          => $site->id,
            'referred_by_name' => 'Dr. Jane Smith',
            'referral_date'    => '2025-06-01',
            'referral_source'  => 'physician',
        ];

        $response = $this->actingAs($user)->postJson('/enrollment/referrals', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment(['status' => 'new'])
            ->assertJsonFragment(['referred_by_name' => 'Dr. Jane Smith']);

        $this->assertDatabaseHas('emr_referrals', [
            'referred_by_name' => 'Dr. Jane Smith',
            'status'           => 'new',
            'tenant_id'        => $user->tenant_id,
        ]);
    }

    public function test_store_requires_enrollment_department(): void
    {
        $user = $this->makeUser('social_work'); // not enrollment
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);

        $payload = [
            'site_id'          => $site->id,
            'referred_by_name' => 'Test Referrer',
            'referral_date'    => '2025-06-01',
            'referral_source'  => 'hospital',
        ];

        $response = $this->actingAs($user)->postJson('/enrollment/referrals', $payload);

        $response->assertStatus(403);
    }

    public function test_store_rejects_invalid_referral_source(): void
    {
        $user = $this->makeUser();

        $payload = [
            'site_id'          => $user->site_id,
            'referred_by_name' => 'Referrer',
            'referral_date'    => '2025-06-01',
            'referral_source'  => 'not_a_valid_source',
        ];

        $response = $this->actingAs($user)->postJson('/enrollment/referrals', $payload);

        $response->assertStatus(422);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_non_status_fields(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, ['status' => 'new']);

        $response = $this->actingAs($user)->putJson("/enrollment/referrals/{$referral->id}", [
            'notes' => 'Updated notes for this referral.',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Updated notes for this referral.', $referral->fresh()->notes);
    }

    public function test_update_rejects_terminal_referral(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, ['status' => 'enrolled']);

        $response = $this->actingAs($user)->putJson("/enrollment/referrals/{$referral->id}", [
            'notes' => 'Should not be allowed.',
        ]);

        $response->assertStatus(409);
    }

    // ── Transitions ───────────────────────────────────────────────────────────

    public function test_valid_transition_new_to_intake_scheduled(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, ['status' => 'new']);

        $response = $this->actingAs($user)->postJson(
            "/enrollment/referrals/{$referral->id}/transition",
            ['new_status' => 'intake_scheduled'],
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'intake_scheduled']);

        $this->assertEquals('intake_scheduled', $referral->fresh()->status);
    }

    public function test_valid_transition_chain_to_enrolled(): void
    {
        $user      = $this->makeUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $referral  = $this->makeReferral($user, [
            'status'         => 'pending_enrollment',
            'participant_id' => $participant->id,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/enrollment/referrals/{$referral->id}/transition",
            ['new_status' => 'enrolled'],
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'enrolled']);
    }

    public function test_enrollment_transition_sets_participant_enrollment_status(): void
    {
        $user        = $this->makeUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $referral    = $this->makeReferral($user, [
            'status'         => 'pending_enrollment',
            'participant_id' => $participant->id,
        ]);

        $this->actingAs($user)->postJson(
            "/enrollment/referrals/{$referral->id}/transition",
            ['new_status' => 'enrolled'],
        );

        $this->assertEquals('enrolled', $participant->fresh()->enrollment_status);
    }

    public function test_invalid_transition_returns_422(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, ['status' => 'new']);

        // Cannot skip directly from new → enrolled
        $response = $this->actingAs($user)->postJson(
            "/enrollment/referrals/{$referral->id}/transition",
            ['new_status' => 'enrolled'],
        );

        $response->assertStatus(422);
    }

    public function test_transition_to_declined_requires_decline_reason(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, ['status' => 'new']);

        $response = $this->actingAs($user)->postJson(
            "/enrollment/referrals/{$referral->id}/transition",
            ['new_status' => 'declined'], // no decline_reason
        );

        $response->assertStatus(422);
    }

    public function test_transition_to_declined_succeeds_with_reason(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, ['status' => 'new']);

        $response = $this->actingAs($user)->postJson(
            "/enrollment/referrals/{$referral->id}/transition",
            ['new_status' => 'declined', 'decline_reason' => 'outside_service_area'],
        );

        $response->assertStatus(200)
            ->assertJsonFragment(['status' => 'declined']);

        $this->assertEquals('outside_service_area', $referral->fresh()->decline_reason);
    }

    public function test_transition_requires_enrollment_department(): void
    {
        $user     = $this->makeUser('primary_care');
        $referral = $this->makeReferral($user, ['status' => 'new']);

        $response = $this->actingAs($user)->postJson(
            "/enrollment/referrals/{$referral->id}/transition",
            ['new_status' => 'intake_scheduled'],
        );

        $response->assertStatus(403);
    }

    // ── Disenrollment ─────────────────────────────────────────────────────────

    public function test_disenroll_sets_disenrolled_fields_on_participant(): void
    {
        $user        = $this->makeUser();
        $participant = Participant::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'enrollment_status' => 'enrolled',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/disenroll",
            [
                'reason'                   => 'voluntary',
                'effective_date'           => '2025-07-01',
                'cms_notification_required'=> true,
                'notes'                    => 'Participant requested disenrollment.',
            ],
        );

        $response->assertStatus(200);

        $fresh = $participant->fresh();
        $this->assertEquals('disenrolled', $fresh->enrollment_status);
        $this->assertEquals('2025-07-01', $fresh->disenrollment_date->format('Y-m-d'));
        $this->assertEquals('voluntary', $fresh->disenrollment_reason);
        $this->assertFalse((bool) $fresh->is_active);
    }

    public function test_disenroll_rejects_non_enrolled_participant(): void
    {
        $user        = $this->makeUser();
        $participant = Participant::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'enrollment_status' => 'pending', // not enrolled
        ]);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/disenroll",
            [
                'reason'                    => 'voluntary',
                'effective_date'            => '2025-07-01',
                'cms_notification_required' => false,
            ],
        );

        $response->assertStatus(409);
    }

    public function test_disenroll_requires_enrollment_admin_department(): void
    {
        $user        = $this->makeUser('dietary'); // not enrollment
        $participant = Participant::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'enrollment_status' => 'enrolled',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/participants/{$participant->id}/disenroll",
            [
                'reason'                    => 'voluntary',
                'effective_date'            => '2025-07-01',
                'cms_notification_required' => false,
            ],
        );

        $response->assertStatus(403);
    }
}
