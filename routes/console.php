<?php

use App\Jobs\DigestNotificationJob;
use App\Jobs\DocumentationComplianceJob;
use App\Jobs\GrievanceOverdueJob;
use App\Jobs\IdtReviewFrequencyJob;
use App\Jobs\SdrDeadlineEnforcementJob;
use App\Jobs\TransferCompletionJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Purge expired OTP codes nightly
Schedule::command('otp:purge-expired')->daily();

// ─── Phase 4: SDR 72-hour deadline enforcement ────────────────────────────────
// Runs every 15 minutes to check all open SDRs and:
//   - Create info alert at 24h remaining
//   - Create warning alert at 8h remaining
//   - Escalate and create critical alert when overdue
// Alert deduplication is handled inside SdrDeadlineService.
Schedule::job(SdrDeadlineEnforcementJob::class, 'sdr-enforcement')->everyFifteenMinutes()
    ->name('sdr-deadline-enforcement')
    ->withoutOverlapping();  // Skip if previous run is still processing

// ─── Phase 5C: Late eMAR dose detection ───────────────────────────────────────
// Runs every 30 minutes to flag medication doses that were not administered
// within 30 minutes of their scheduled_time. Sets status='late' and creates
// a warning alert for primary_care and therapies departments.
Schedule::job(\App\Jobs\LateMarDetectionJob::class, 'mar-detection')->everyThirtyMinutes()
    ->name('late-mar-detection')
    ->withoutOverlapping();  // Skip if previous run is still processing

// ─── Phase 6B: Documentation compliance scan ──────────────────────────────────
// Runs daily at 6 AM to:
//   - Flag unsigned notes older than 24h → warning alert to dept admin
//   - Flag overdue assessments → info alert to responsible dept
// Alert deduplication handled inside the job.
Schedule::job(DocumentationComplianceJob::class, 'compliance')->dailyAt('06:00')
    ->name('documentation-compliance')
    ->withoutOverlapping();

// ─── Phase 7C: Digest notification emails ─────────────────────────────────────
// Runs every 2 hours. Collects users whose preference for any alert type is
// 'email_digest', checks their cached pending-notification counter, and
// sends a single DigestNotificationMail per user with the total count.
// Contains ZERO PHI per HIPAA requirements.
Schedule::job(DigestNotificationJob::class, 'notifications')->everyTwoHours()
    ->name('digest-notifications')
    ->withoutOverlapping();

// ─── W4-1: Grievance overdue check (42 CFR §460.120–§460.121) ────────────────
// Runs daily at 8:00 AM across all active tenants:
//   - Escalates standard grievances open > 30 days to 'escalated' status
//   - Creates critical alert if urgent grievance open > 72 hours without resolution
//   - Creates info alerts for approaching deadlines
// Per-tenant error handling ensures a single tenant failure doesn't abort the batch.
Schedule::job(GrievanceOverdueJob::class, 'compliance')->dailyAt('08:00')
    ->name('grievance-overdue')
    ->withoutOverlapping();

// ─── W4-5: IDT reassessment frequency check (42 CFR §460.104(c)) ─────────────
// Runs daily at 7:30 AM. Scans all active enrolled participants across all tenants
// and creates warning alerts for participants whose last IDT reassessment was
// more than 180 days ago (or who have never been reviewed and enrolled > 180 days).
// Alert deduplication: skips participants with an existing unacknowledged
// 'idt_review_overdue' alert. This is a common CMS survey deficiency finding.
Schedule::job(IdtReviewFrequencyJob::class, 'idt-review')->dailyAt('07:30')
    ->name('idt-review-frequency')
    ->withoutOverlapping();

// ─── Phase 10A: Participant site transfer completion ──────────────────────────
// Runs daily at 7:00 AM. Finds approved transfers whose effective_date <= today
// and calls TransferService::completeTransfer() for each:
//   - Sets participant.site_id = to_site_id
//   - Posts IDT chat alerts at both old and new sites
//   - Marks transfer status=completed, notification_sent=true
//   - Writes audit log entry
Schedule::job(TransferCompletionJob::class, 'transfers')->dailyAt('07:00')
    ->name('transfer-completion')
    ->withoutOverlapping();
