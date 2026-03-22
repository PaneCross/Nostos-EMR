<?php

// ─── EnrollmentStateMachineTest ────────────────────────────────────────────────
// Unit tests for EnrollmentService covering:
//   - All valid forward transitions succeed
//   - All terminal-status transitions to new states throw
//   - Invalid skip transitions throw InvalidStateTransitionException
//   - declined/withdrawn transitions persist reason fields
//   - handleEnrollment() side effects: sets enrollment_status + enrollment_date
//   - disenroll() side effects: sets disenrolled status + date + is_active=false
// ──────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Participant;
use App\Models\Referral;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private EnrollmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EnrollmentService();
    }

    private function makeUser(string $dept = 'enrollment'): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeReferral(User $user, string $status = 'new'): Referral
    {
        return Referral::factory()->create([
            'tenant_id'          => $user->tenant_id,
            'created_by_user_id' => $user->id,
            'status'             => $status,
        ]);
    }

    // ── Valid forward transitions ──────────────────────────────────────────────

    public function test_new_to_intake_scheduled(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'new');

        $this->service->transition($referral, 'intake_scheduled', $user);

        $this->assertEquals('intake_scheduled', $referral->fresh()->status);
    }

    public function test_intake_scheduled_to_intake_in_progress(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'intake_scheduled');

        $this->service->transition($referral, 'intake_in_progress', $user);

        $this->assertEquals('intake_in_progress', $referral->fresh()->status);
    }

    public function test_intake_in_progress_to_intake_complete(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'intake_in_progress');

        $this->service->transition($referral, 'intake_complete', $user);

        $this->assertEquals('intake_complete', $referral->fresh()->status);
    }

    public function test_intake_complete_to_eligibility_pending(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'intake_complete');

        $this->service->transition($referral, 'eligibility_pending', $user);

        $this->assertEquals('eligibility_pending', $referral->fresh()->status);
    }

    public function test_eligibility_pending_to_pending_enrollment(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'eligibility_pending');

        $this->service->transition($referral, 'pending_enrollment', $user);

        $this->assertEquals('pending_enrollment', $referral->fresh()->status);
    }

    public function test_pending_enrollment_to_enrolled(): void
    {
        $user        = $this->makeUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $referral    = $this->makeReferral($user, 'pending_enrollment');
        $referral->update(['participant_id' => $participant->id]);

        $this->service->transition($referral, 'enrolled', $user);

        $this->assertEquals('enrolled', $referral->fresh()->status);
    }

    // ── Any non-terminal → declined / withdrawn ────────────────────────────────

    public function test_new_to_declined_with_reason(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'new');

        $this->service->transition($referral, 'declined', $user, ['decline_reason' => 'outside_service_area']);

        $fresh = $referral->fresh();
        $this->assertEquals('declined', $fresh->status);
        $this->assertEquals('outside_service_area', $fresh->decline_reason);
    }

    public function test_intake_scheduled_to_withdrawn(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'intake_scheduled');

        $this->service->transition($referral, 'withdrawn', $user, ['withdrawn_reason' => 'Changed their mind.']);

        $fresh = $referral->fresh();
        $this->assertEquals('withdrawn', $fresh->status);
        $this->assertEquals('Changed their mind.', $fresh->withdrawn_reason);
    }

    // ── Invalid skip transitions ───────────────────────────────────────────────

    public function test_new_to_enrolled_throws(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'new');

        $this->expectException(InvalidStateTransitionException::class);
        $this->service->transition($referral, 'enrolled', $user);
    }

    public function test_new_to_pending_enrollment_throws(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'new');

        $this->expectException(InvalidStateTransitionException::class);
        $this->service->transition($referral, 'pending_enrollment', $user);
    }

    public function test_intake_complete_to_enrolled_throws(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'intake_complete');

        $this->expectException(InvalidStateTransitionException::class);
        $this->service->transition($referral, 'enrolled', $user);
    }

    // ── Terminal state transitions ─────────────────────────────────────────────

    public function test_enrolled_to_any_status_throws(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'enrolled');

        $this->expectException(InvalidStateTransitionException::class);
        $this->service->transition($referral, 'new', $user);
    }

    public function test_declined_to_any_status_throws(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'declined');

        $this->expectException(InvalidStateTransitionException::class);
        $this->service->transition($referral, 'new', $user);
    }

    public function test_withdrawn_to_any_status_throws(): void
    {
        $user     = $this->makeUser();
        $referral = $this->makeReferral($user, 'withdrawn');

        $this->expectException(InvalidStateTransitionException::class);
        $this->service->transition($referral, 'new', $user);
    }

    // ── Enrollment side effects ────────────────────────────────────────────────

    public function test_enrolled_transition_sets_participant_enrollment_date(): void
    {
        $user        = $this->makeUser();
        $participant = Participant::factory()->create(['tenant_id' => $user->tenant_id]);
        $referral    = $this->makeReferral($user, 'pending_enrollment');
        $referral->update(['participant_id' => $participant->id]);

        $this->service->transition($referral, 'enrolled', $user);

        $fresh = $participant->fresh();
        $this->assertEquals('enrolled', $fresh->enrollment_status);
        $this->assertNotNull($fresh->enrollment_date);
    }

    // ── Disenrollment ──────────────────────────────────────────────────────────

    public function test_disenroll_sets_all_disenrollment_fields(): void
    {
        $user        = $this->makeUser();
        $participant = Participant::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'enrollment_status' => 'enrolled',
        ]);

        $this->service->disenroll(
            participant:             $participant,
            reason:                  'deceased',
            effectiveDate:           '2025-08-01',
            notes:                   'Participant passed away.',
            cmsNotificationRequired: true,
            user:                    $user,
        );

        $fresh = $participant->fresh();
        $this->assertEquals('disenrolled', $fresh->enrollment_status);
        $this->assertEquals('2025-08-01', $fresh->disenrollment_date->format('Y-m-d'));
        $this->assertEquals('deceased', $fresh->disenrollment_reason);
        $this->assertFalse((bool) $fresh->is_active);
    }
}
