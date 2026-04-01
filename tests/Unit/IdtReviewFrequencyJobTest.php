<?php

// ─── IdtReviewFrequencyJobTest ─────────────────────────────────────────────────
// Unit tests for IdtReviewFrequencyJob (W4-5, 42 CFR §460.104(c)).
//
// Coverage:
//   - Job creates alert for enrolled participant who has never been reviewed after 180 days
//   - Job creates alert for enrolled participant whose last review was > 180 days ago
//   - Job does NOT create duplicate alerts if an active alert already exists
//   - Job skips non-enrolled participants
//   - Job skips participants enrolled < 180 days with no reviews
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Jobs\IdtReviewFrequencyJob;
use App\Models\Alert;
use App\Models\IdtMeeting;
use App\Models\IdtParticipantReview;
use App\Models\Participant;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdtReviewFrequencyJobTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeEnrolledParticipant(int $enrolledDaysAgo = 200): Participant
    {
        $site = Site::factory()->create();
        return Participant::factory()->create([
            'tenant_id'         => $site->tenant_id,
            'site_id'           => $site->id,
            'enrollment_status' => 'enrolled',
            'is_active'         => true,
            'enrollment_date'   => now()->subDays($enrolledDaysAgo)->toDateString(),
        ]);
    }

    private function addReview(Participant $participant, int $daysAgo): void
    {
        $meeting = IdtMeeting::factory()->create([
            'tenant_id'    => $participant->tenant_id,
            'site_id'      => $participant->site_id,
            'meeting_date' => now()->subDays($daysAgo)->toDateString(),
        ]);

        IdtParticipantReview::create([
            'meeting_id'     => $meeting->id,
            'participant_id' => $participant->id,
            'summary_text'   => 'Test review',
            'reviewed_at'    => now()->subDays($daysAgo),
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * @test
     * Job creates an idt_review_overdue alert for a participant enrolled > 180 days
     * with no review on record.
     */
    public function test_job_creates_alert_for_participant_never_reviewed(): void
    {
        $participant = $this->makeEnrolledParticipant(enrolledDaysAgo: 200);

        (new IdtReviewFrequencyJob())->handle();

        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $participant->id,
            'alert_type'     => 'idt_review_overdue',
            'is_active'      => true,
        ]);
    }

    /**
     * @test
     * Job creates alert for participant whose last review was > 180 days ago.
     */
    public function test_job_creates_alert_for_participant_with_stale_review(): void
    {
        $participant = $this->makeEnrolledParticipant(enrolledDaysAgo: 400);
        $this->addReview($participant, daysAgo: 185);

        (new IdtReviewFrequencyJob())->handle();

        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $participant->id,
            'alert_type'     => 'idt_review_overdue',
            'is_active'      => true,
        ]);
    }

    /**
     * @test
     * Job does NOT create a duplicate alert if an active idt_review_overdue alert
     * already exists for the participant.
     */
    public function test_job_does_not_create_duplicate_alert(): void
    {
        $participant = $this->makeEnrolledParticipant(enrolledDaysAgo: 200);

        // Run job twice
        (new IdtReviewFrequencyJob())->handle();
        (new IdtReviewFrequencyJob())->handle();

        $alertCount = Alert::where('participant_id', $participant->id)
            ->where('alert_type', 'idt_review_overdue')
            ->count();

        $this->assertSame(1, $alertCount, 'Duplicate alert should not be created on second run');
    }

    /**
     * @test
     * Job does NOT create an alert for a participant enrolled < 180 days (not yet overdue).
     */
    public function test_job_skips_recently_enrolled_participant(): void
    {
        $participant = $this->makeEnrolledParticipant(enrolledDaysAgo: 90);

        (new IdtReviewFrequencyJob())->handle();

        $this->assertDatabaseMissing('emr_alerts', [
            'participant_id' => $participant->id,
            'alert_type'     => 'idt_review_overdue',
        ]);
    }

    /**
     * @test
     * Job does NOT create an alert for a participant recently reviewed (within 180 days).
     */
    public function test_job_skips_recently_reviewed_participant(): void
    {
        $participant = $this->makeEnrolledParticipant(enrolledDaysAgo: 300);
        $this->addReview($participant, daysAgo: 30);

        (new IdtReviewFrequencyJob())->handle();

        $this->assertDatabaseMissing('emr_alerts', [
            'participant_id' => $participant->id,
            'alert_type'     => 'idt_review_overdue',
        ]);
    }

    /**
     * @test
     * Job skips disenrolled participants entirely.
     */
    public function test_job_skips_disenrolled_participants(): void
    {
        $site = Site::factory()->create();
        $disenrolled = Participant::factory()->create([
            'tenant_id'         => $site->tenant_id,
            'site_id'           => $site->id,
            'enrollment_status' => 'disenrolled',
            'is_active'         => false,
            'enrollment_date'   => now()->subDays(400)->toDateString(),
        ]);

        (new IdtReviewFrequencyJob())->handle();

        $this->assertDatabaseMissing('emr_alerts', [
            'participant_id' => $disenrolled->id,
            'alert_type'     => 'idt_review_overdue',
        ]);
    }

    /**
     * @test
     * After a participant is reviewed, subsequent job run does NOT re-alert
     * (previous alert was auto-resolved or dedup prevents it).
     */
    public function test_job_creates_no_new_alert_after_review_within_180_days(): void
    {
        $participant = $this->makeEnrolledParticipant(enrolledDaysAgo: 300);

        // First run — alert created (never reviewed)
        (new IdtReviewFrequencyJob())->handle();
        $this->assertDatabaseHas('emr_alerts', [
            'participant_id' => $participant->id,
            'alert_type'     => 'idt_review_overdue',
        ]);

        // Acknowledge (resolve) the existing alert
        Alert::where('participant_id', $participant->id)
            ->where('alert_type', 'idt_review_overdue')
            ->update(['is_active' => false]);

        // Review the participant
        $this->addReview($participant, daysAgo: 5);

        // Second run — participant is no longer overdue, no new alert created
        (new IdtReviewFrequencyJob())->handle();

        $activeAlerts = Alert::where('participant_id', $participant->id)
            ->where('alert_type', 'idt_review_overdue')
            ->where('is_active', true)
            ->count();

        $this->assertSame(0, $activeAlerts);
    }
}
