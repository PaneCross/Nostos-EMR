<?php

// ─── IdtFrequencyTest ──────────────────────────────────────────────────────────
// Feature tests for W4-5 IDT review frequency tracking (42 CFR §460.104(c)).
//
// Coverage:
//   - idtReviewOverdue(): true when participant has never been reviewed + enrolled > 180 days
//   - idtReviewOverdue(): false when participant was reviewed recently
//   - idtReviewOverdue(): true when last review was > 180 days ago
//   - idtReviewOverdue(): false when enrolled < 180 days and never reviewed
//   - IDT dashboard overdue widget returns correct count
//   - Widget is inaccessible to non-IDT departments
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\IdtMeeting;
use App\Models\IdtParticipantReview;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdtFrequencyTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $dept = 'idt'): User
    {
        return User::factory()->create(['department' => $dept]);
    }

    private function makeEnrolledParticipant(User $user, int $enrolledDaysAgo = 200): Participant
    {
        $site = Site::factory()->create(['tenant_id' => $user->tenant_id]);
        return Participant::factory()->create([
            'tenant_id'         => $user->tenant_id,
            'site_id'           => $site->id,
            'enrollment_status' => 'enrolled',
            'is_active'         => true,
            'enrollment_date'   => now()->subDays($enrolledDaysAgo)->toDateString(),
        ]);
    }

    private function createReviewForParticipant(Participant $participant, User $user, int $daysAgo): IdtParticipantReview
    {
        $meeting = IdtMeeting::factory()->create([
            'tenant_id'   => $participant->tenant_id,
            'site_id'     => $participant->site_id,
            'meeting_date' => now()->subDays($daysAgo)->toDateString(),
        ]);

        return IdtParticipantReview::create([
            'meeting_id'    => $meeting->id,
            'participant_id' => $participant->id,
            'summary_text'  => 'Test review',
            'reviewed_at'   => now()->subDays($daysAgo),
        ]);
    }

    // ── Model method tests ────────────────────────────────────────────────────

    /**
     * @test
     * A participant enrolled > 180 days ago with NO reviews is overdue.
     * 42 CFR §460.104(c): IDT must reassess at least every 6 months.
     */
    public function test_overdue_when_never_reviewed_and_enrolled_over_180_days(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeEnrolledParticipant($user, enrolledDaysAgo: 200);

        $this->assertTrue($participant->idtReviewOverdue());
        $this->assertNull($participant->lastIdtReviewedAt());
    }

    /**
     * @test
     * A participant enrolled < 180 days with no reviews is NOT overdue yet.
     * Give new participants their first 6 months before flagging.
     */
    public function test_not_overdue_when_never_reviewed_but_recently_enrolled(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeEnrolledParticipant($user, enrolledDaysAgo: 90);

        $this->assertFalse($participant->idtReviewOverdue());
    }

    /**
     * @test
     * A participant reviewed recently (within 180 days) is NOT overdue.
     */
    public function test_not_overdue_when_reviewed_within_180_days(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeEnrolledParticipant($user, enrolledDaysAgo: 400);
        $this->createReviewForParticipant($participant, $user, daysAgo: 30);

        $this->assertFalse($participant->idtReviewOverdue());
    }

    /**
     * @test
     * A participant whose last review was > 180 days ago IS overdue.
     */
    public function test_overdue_when_last_review_over_180_days_ago(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeEnrolledParticipant($user, enrolledDaysAgo: 400);
        $this->createReviewForParticipant($participant, $user, daysAgo: 185);

        $this->assertTrue($participant->idtReviewOverdue());
    }

    /**
     * @test
     * lastIdtReviewedAt() returns the date of the most recent review.
     */
    public function test_last_reviewed_at_returns_most_recent_review_date(): void
    {
        $user        = $this->makeUser();
        $participant = $this->makeEnrolledParticipant($user, enrolledDaysAgo: 400);

        $this->createReviewForParticipant($participant, $user, daysAgo: 100);
        $this->createReviewForParticipant($participant, $user, daysAgo: 50);

        $lastReview = $participant->lastIdtReviewedAt();

        $this->assertNotNull($lastReview);
        // Should be approximately 50 days ago (within a day of precision)
        $this->assertEqualsWithDelta(50, $lastReview->diffInDays(now()), 1);
    }

    // ── Dashboard widget tests ─────────────────────────────────────────────────

    /**
     * @test
     * IDT dashboard overdue widget returns the correct count of overdue participants.
     */
    public function test_idt_overdue_widget_returns_correct_count(): void
    {
        $user = $this->makeUser('idt');

        // Create 2 overdue participants (no review, enrolled 200+ days)
        $this->makeEnrolledParticipant($user, 200);
        $this->makeEnrolledParticipant($user, 220);

        // Create 1 non-overdue participant (reviewed 30 days ago)
        $currentParticipant = $this->makeEnrolledParticipant($user, 300);
        $this->createReviewForParticipant($currentParticipant, $user, 30);

        $response = $this->actingAs($user)
            ->getJson('/dashboards/idt/idt-review-overdue');

        $response->assertOk();
        $response->assertJsonStructure(['participants', 'overdue_count']);

        $count = $response->json('overdue_count');
        $this->assertGreaterThanOrEqual(2, $count, 'At least 2 overdue participants expected');
    }

    /**
     * @test
     * IDT overdue widget is blocked for non-IDT departments.
     */
    public function test_idt_overdue_widget_blocked_for_non_idt_dept(): void
    {
        $user = $this->makeUser('pharmacy');

        $this->actingAs($user)
            ->getJson('/dashboards/idt/idt-review-overdue')
            ->assertForbidden();
    }

    /**
     * @test
     * Super admin can access the IDT overdue widget.
     */
    public function test_idt_overdue_widget_accessible_to_super_admin(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($user)
            ->getJson('/dashboards/idt/idt-review-overdue')
            ->assertOk();
    }
}
