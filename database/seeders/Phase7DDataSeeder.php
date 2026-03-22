<?php

// ─── Phase7DDataSeeder ────────────────────────────────────────────────────────
// Demo polish seeder for Phase 7D. Adds scenario-specific data that makes
// the demo environment look realistic and compelling during walkthroughs.
//
// What this seeder adds (all idempotent — checks before inserting):
//
//   1. Unsigned notes >24h      — 3 draft notes authored >26h ago (no signed_at)
//                                  triggers the QA "unsigned notes" compliance KPI
//   2. Care plans due ≤30 days  — forces review_due_date on 3 care plans into the
//                                  next 7–25 days to surface the care plan alert
//   3. Fall incident (RCA)      — 1 fall incident with rca_required=true, status=
//                                  under_review for a demo participant
//   4. Enrollment referrals     — 2 referrals in active pipeline stages
//                                  (eligibility_pending, pending_enrollment)
//   5. Chat seed messages       — 3–5 realistic messages in every dept channel
//                                  so the chat UI looks active, not empty
//   6. Guaranteed no-show trip  — adds 1 explicit no_show transport request for
//                                  today so the manifest always has one
//
// Run order: must come after all earlier phase seeders (all models must exist).
// Safe to re-run: all blocks check for pre-existing data before inserting.
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\CarePlan;
use App\Models\ChatChannel;
use App\Models\ChatMembership;
use App\Models\ChatMessage;
use App\Models\ClinicalNote;
use App\Models\Incident;
use App\Models\Location;
use App\Models\Participant;
use App\Models\Referral;
use App\Models\Tenant;
use App\Models\TransportRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Phase7DDataSeeder extends Seeder
{
    // ── Chat seed messages per department theme ────────────────────────────────
    // Five messages per department that look like realistic clinical coordination.
    private const DEPT_MESSAGES = [
        'primary_care' => [
            ['text' => 'Morning all — reminder that Mrs. Testpatient (EAST-00003) is due for her HbA1c recheck this week.', 'offset_min' => 2880],
            ['text' => 'Lab values for Testpatient cohort have been updated in the chart. Please review before IDT on Thursday.', 'offset_min' => 1440],
            ['text' => 'Flu shot clinic running today at East site — please flag any participants who still need it.', 'offset_min' => 720],
            ['text' => 'BP trend alert on EAST-00012 — three consecutive readings above 160/95. Escalating to attending.', 'offset_min' => 360],
            ['text' => 'Reminder: any same-day med changes need to be logged in the eMAR before end of shift.', 'offset_min' => 90, 'priority' => 'urgent'],
        ],
        'therapies' => [
            ['text' => 'PT progress notes for this week\'s cohort are ready for co-sign. Nine participants completed gait reassessment.', 'offset_min' => 2160],
            ['text' => 'OT has two new home safety assessments scheduled for Friday — transport confirmed.', 'offset_min' => 1200],
            ['text' => 'Speech therapy is trialing a new AAC app with two participants — will report results at IDT.', 'offset_min' => 840],
            ['text' => 'Fall risk scores updated after last week\'s PT evaluations. Three participants moved to high-risk tier.', 'offset_min' => 480],
            ['text' => 'Reminder to document exercise adherence in the participant ADL record after each session.', 'offset_min' => 60],
        ],
        'social_work' => [
            ['text' => 'Caregiver respite request received for WEST-00005 — coordinating with home care for coverage next week.', 'offset_min' => 3000],
            ['text' => 'APS referral submitted for EAST-00008 — please do not contact family directly until clearance.', 'offset_min' => 1500],
            ['text' => 'Housing instability flag added to three participants following today\'s social assessment rounds.', 'offset_min' => 900],
            ['text' => 'Food insecurity screening results compiled — six participants identified, community resource packets mailed.', 'offset_min' => 240],
            ['text' => 'Family meeting for WEST-00014 scheduled for Thursday 10am — IDT attendance requested.', 'offset_min' => 45],
        ],
        'behavioral_health' => [
            ['text' => 'PHQ-9 reassessments completed for this quarter\'s cohort — results filed in participant charts.', 'offset_min' => 2400],
            ['text' => 'Group therapy starting new grief support session Tuesdays 2pm — please refer appropriate participants.', 'offset_min' => 1320],
            ['text' => 'EAST-00009 declined individual counseling today. Plan to re-approach next visit with different framing.', 'offset_min' => 660],
            ['text' => 'Crisis protocol reminder: if you observe a participant in acute distress, page BH immediately, do not wait for next visit.', 'offset_min' => 300, 'priority' => 'urgent'],
            ['text' => 'Monthly BH utilization report sent to QA — 94% of flagged participants received follow-up within 72h.', 'offset_min' => 30],
        ],
        'dietary' => [
            ['text' => 'Menu updated for next week — low-sodium options increased per IDT feedback. Copies posted in dining room.', 'offset_min' => 2520],
            ['text' => 'Six participants need calorie-count monitoring this week per dietitian order — aides have been notified.', 'offset_min' => 1080],
            ['text' => 'Food allergy audit complete — all allergen flags in EMR match kitchen posted lists. No discrepancies found.', 'offset_min' => 540],
            ['text' => 'WEST-00011 lost 4 lbs this month — flagged for dietary consult. Can someone check in today?', 'offset_min' => 180],
            ['text' => 'Supplements order for this week is in. Ensure documentation of supplement administration in eMAR.', 'offset_min' => 20],
        ],
        'activities' => [
            ['text' => 'Music therapy session Thursday had 18 participants — highest attendance this quarter. Great engagement!', 'offset_min' => 2700],
            ['text' => 'Art exhibition prep underway — participants working on pieces for the family showcase next month.', 'offset_min' => 1260],
            ['text' => 'Bingo prize budget approved for Q2. Order has been placed — estimated delivery Friday.', 'offset_min' => 720],
            ['text' => 'Outdoor garden activity postponed due to air quality index. Indoor alternatives posted on activity board.', 'offset_min' => 210],
            ['text' => 'New participant EAST-00021 completed activity interest inventory — preferences logged in chart.', 'offset_min' => 15],
        ],
        'home_care' => [
            ['text' => 'AM care rounds complete. Two participants reported increased fatigue — flagged to primary care.', 'offset_min' => 480],
            ['text' => 'Home visit for WEST-00007 rescheduled to Thursday — caregiver unavailable Wednesday.', 'offset_min' => 1440],
            ['text' => 'PPE restock request submitted — gloves and masks running low at West site.', 'offset_min' => 900],
            ['text' => 'Monthly aide competency check-ins scheduled for next week. Please confirm availability with scheduler.', 'offset_min' => 300],
            ['text' => 'Urgent: EAST-00016 home safety visit shows new fall hazard (loose rug). OT referral submitted.', 'offset_min' => 30, 'priority' => 'urgent'],
        ],
        'transportation' => [
            ['text' => 'Today\'s run sheet is posted. Nine trips, two accessible van required. Dispatch by 7:30am.', 'offset_min' => 420],
            ['text' => 'WEST-00003 cancelled Friday trip — please update manifest and notify scheduler.', 'offset_min' => 1200],
            ['text' => 'Vehicle V-04 returned from maintenance — cleared for service starting Monday.', 'offset_min' => 2160],
            ['text' => 'Reminder: pre-trip inspection forms must be submitted before first pickup of the day.', 'offset_min' => 360],
            ['text' => 'Traffic advisory for I-405 northbound — adjust morning pickup windows by 15 minutes.', 'offset_min' => 60, 'priority' => 'urgent'],
        ],
        'pharmacy' => [
            ['text' => 'Warfarin INR alerts: three participants with values out of range. Notifying primary care now.', 'offset_min' => 300, 'priority' => 'urgent'],
            ['text' => 'Monthly medication reconciliation reports exported and sent to QA. Twelve participants reviewed.', 'offset_min' => 1500],
            ['text' => 'Drug interaction alert acknowledged for EAST-00007 (Warfarin + Aspirin). Attending reviewed and accepted.', 'offset_min' => 840],
            ['text' => 'Controlled substance counts completed this morning — all tallies match. Log signed and filed.', 'offset_min' => 360],
            ['text' => 'New formulary update effective April 1 — Metformin ER added to preferred tier. Details in email.', 'offset_min' => 120],
        ],
        'idt' => [
            ['text' => 'Thursday IDT agenda posted — 14 participants on review queue. Please submit pre-IDT summaries by Wednesday noon.', 'offset_min' => 2880],
            ['text' => 'Reminder: all SDRs assigned to IDT must be updated before the meeting or they will be escalated.', 'offset_min' => 1440, 'priority' => 'urgent'],
            ['text' => 'Meeting minutes from last Thursday\'s IDT uploaded to shared drive. Action items highlighted.', 'offset_min' => 720],
            ['text' => 'Three participants admitted to hospital this week — SDRs auto-generated. Please review and assign.', 'offset_min' => 240],
            ['text' => 'CMS audit prep: all care plans must have active goals in every domain before the site visit.', 'offset_min' => 60],
        ],
        'enrollment' => [
            ['text' => 'New referral received from St. Mary\'s Hospital — PACE candidate, 78F, CHF + DM2. Intake scheduled.', 'offset_min' => 1440],
            ['text' => 'Eligibility documentation for two pending referrals uploaded to state portal this morning.', 'offset_min' => 900],
            ['text' => 'Outreach event at Sunrise Senior Center Thursday — please bring enrollment brochures and FAQ sheets.', 'offset_min' => 360],
            ['text' => 'WEST site has two open enrollment slots next quarter — flagging for outreach team.', 'offset_min' => 120],
            ['text' => 'Reminder: disenrollment paperwork for EAST-00024 must be submitted to CMS within 30 days.', 'offset_min' => 30],
        ],
        'finance' => [
            ['text' => 'April capitation reports ready for QA review — all 30 participants reconciled. No discrepancies.', 'offset_min' => 2160],
            ['text' => 'Prior auth for EAST-00013 specialist visit submitted to Medicare — awaiting response.', 'offset_min' => 1200],
            ['text' => 'Reminder: encounter logs for March must be finalized by end of week for monthly billing cycle.', 'offset_min' => 600],
            ['text' => 'New Medicare rates effective April 1 — capitation amounts updated in system. Finance dashboard reflects new values.', 'offset_min' => 180],
            ['text' => 'Two authorizations expiring this week — flagged in finance dashboard. Please renew or request extension.', 'offset_min' => 45, 'priority' => 'urgent'],
        ],
        'qa_compliance' => [
            ['text' => 'Monthly compliance report distributed — documentation rate at 91%, target is 95%. Improvement plan attached.', 'offset_min' => 2520],
            ['text' => 'Fall incident RCA for EAST-00002 must be completed within 72h per CMS 42 CFR 460.136. Assigned to QA admin.', 'offset_min' => 1440, 'priority' => 'urgent'],
            ['text' => 'Annual mock audit scheduled for April 15 — all departments must have binder ready for review.', 'offset_min' => 720],
            ['text' => 'Reminder: any CMS-reportable incident must be entered in the EMR within 24h of occurrence.', 'offset_min' => 240],
            ['text' => 'Incident trend analysis Q1 complete — falls increased 18%. Fall prevention workgroup meeting scheduled.', 'offset_min' => 60],
        ],
        'it_admin' => [
            ['text' => 'Scheduled maintenance window Sunday 2–4am — EMR will be unavailable. Downtime procedures posted.', 'offset_min' => 4320],
            ['text' => 'New user accounts provisioned for two rotating staff members — credentials sent via Mailpit.', 'offset_min' => 1440],
            ['text' => 'HL7 ADT integration test successful — 12 admit messages processed correctly this morning.', 'offset_min' => 720],
            ['text' => 'MFA reminder: all admin accounts must have OTP verified monthly. Check audit log for compliance.', 'offset_min' => 240],
            ['text' => 'Backup verification complete — last nightly backup restored successfully to test environment.', 'offset_min' => 30],
        ],
    ];

    // ── Referral scenario data ─────────────────────────────────────────────────
    private const REFERRALS = [
        [
            'referred_by_name'  => 'Dr. Sandra Chen',
            'referred_by_org'   => 'Harbor Community Hospital',
            'referral_source'   => 'hospital',
            'status'            => 'pending_enrollment',
            'notes'             => 'Patient with CHF, DM2, and early-stage dementia. Lives alone, caregiver daughter involved. Pending final Medicaid eligibility confirmation.',
            'days_ago'          => 8,
        ],
        [
            'referred_by_name'  => 'Dr. Marcus Williams',
            'referred_by_org'   => 'Westside Family Medicine',
            'referral_source'   => 'physician',
            'status'            => 'eligibility_pending',
            'notes'             => 'Patient post-hip replacement, high fall risk. Family requesting PACE evaluation. Awaiting Medicare/Medicaid dual-eligibility confirmation from state.',
            'days_ago'          => 5,
        ],
        [
            'referred_by_name'  => 'Elena Vega',
            'referred_by_org'   => 'Self-referral via Family',
            'referral_source'   => 'family',
            'status'            => 'intake_complete',
            'notes'             => 'Daughter contacted enrollment team after seeing PACE brochure at senior center. Father is 84, insulin-dependent, lives with family. Intake completed. Awaiting IDT assessment.',
            'days_ago'          => 12,
        ],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();

        $this->command->info('  Phase 7D: Adding demo polish data...');

        $this->seedUnsignedNotes($tenant);
        $this->seedCarePlansDueSoon($tenant);
        $this->seedFallIncident($tenant);
        $this->seedReferrals($tenant);
        $this->seedChatMessages($tenant);
        $this->seedGuaranteedNoShow($tenant);

        $this->command->line('  Phase 7D seeder complete.');
    }

    // ── 1. Unsigned notes older than 24h ─────────────────────────────────────

    /**
     * Create 3 draft (unsigned) clinical notes with created_at set to > 26h ago.
     * These trigger the QA "unsigned notes >24h" compliance KPI on the dashboard.
     * Guard: skips if ≥ 3 unsigned notes already exist older than 24h.
     */
    private function seedUnsignedNotes(object $tenant): void
    {
        $existing = ClinicalNote::where('tenant_id', $tenant->id)
            ->where('status', 'draft')
            ->whereNull('signed_at')
            ->where('created_at', '<', now()->subHours(24))
            ->count();

        if ($existing >= 3) {
            $this->command->line('  Unsigned notes: already seeded, skipping.');
            return;
        }

        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->inRandomOrder()
            ->limit(3)
            ->get();

        $author = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')
            ->first();

        if (! $author || $participants->isEmpty()) {
            $this->command->warn('  Unsigned notes: missing author or participants, skipping.');
            return;
        }

        $site = $author->site_id;

        foreach ($participants as $i => $participant) {
            $hoursAgo = 26 + ($i * 4); // 26h, 30h, 34h ago
            $visitDate = now()->subHours($hoursAgo);

            $note = ClinicalNote::create([
                'participant_id'      => $participant->id,
                'tenant_id'           => $tenant->id,
                'site_id'             => $site,
                'note_type'           => 'soap',
                'authored_by_user_id' => $author->id,
                'department'          => 'primary_care',
                'status'              => 'draft',
                'visit_type'          => 'in_center',
                'visit_date'          => $visitDate->toDateString(),
                'visit_time'          => $visitDate->format('H:i:s'),
                'subjective'          => 'Participant reports mild fatigue and increased joint discomfort over the past week. Denies chest pain or shortness of breath.',
                'objective'           => 'BP: 142/88 mmHg. HR: 76 bpm. SpO2: 97%. Weight stable. Mild pitting edema bilateral ankles.',
                'assessment'          => 'Hypertension — suboptimally controlled. CHF — stable. Arthritis — mildly symptomatic.',
                'plan'                => 'Increase Lisinopril to 20mg daily. Follow-up in 2 weeks. Lab order for BMP placed.',
                'content'             => null,
                'signed_at'           => null,
                'signed_by_user_id'   => null,
                'is_late_entry'       => false,
            ]);

            // Force created_at to the past — cannot be done via fillable, use direct update
            DB::table('emr_clinical_notes')
                ->where('id', $note->id)
                ->update(['created_at' => now()->subHours($hoursAgo)]);
        }

        $this->command->line("  Unsigned notes: created {$participants->count()} draft notes >24h old.");
    }

    // ── 2. Care plans due within 30 days ─────────────────────────────────────

    /**
     * Ensure at least 3 active care plans have review_due_date within the next
     * 7–25 days. This surfaces them in the "care plans due soon" QA widget.
     * Guard: skips if ≥ 3 plans already have review_due_date ≤ 30 days from now.
     */
    private function seedCarePlansDueSoon(object $tenant): void
    {
        $alreadyDueSoon = CarePlan::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereBetween('review_due_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();

        $needed = 3 - $alreadyDueSoon;

        if ($needed <= 0) {
            $this->command->line('  Care plans due soon: already seeded, skipping.');
            return;
        }

        $plans = CarePlan::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('review_due_date', '>', now()->addDays(30)->toDateString())
            ->inRandomOrder()
            ->limit($needed)
            ->get();

        $dueDays = [7, 14, 23]; // Days from today

        foreach ($plans as $i => $plan) {
            $plan->update([
                'review_due_date' => now()->addDays($dueDays[$i] ?? 20)->toDateString(),
            ]);
        }

        $this->command->line("  Care plans due soon: adjusted {$plans->count()} plan(s) to be due within 30 days.");
    }

    // ── 3. Fall incident requiring RCA ───────────────────────────────────────

    /**
     * Seed 1 fall incident with rca_required=true, status=under_review.
     * Per CMS 42 CFR 460.136, falls are in the RCA_REQUIRED_TYPES set,
     * so rca_required is set directly here (bypassing IncidentService which would
     * also set it). Guard: skips if ≥ 1 fall incident already exists.
     */
    private function seedFallIncident(object $tenant): void
    {
        $existingFall = Incident::where('tenant_id', $tenant->id)
            ->where('incident_type', 'fall')
            ->count();

        if ($existingFall >= 1) {
            $this->command->line('  Fall incident: already seeded, skipping.');
            return;
        }

        $participant = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->inRandomOrder()
            ->first();

        $reporter = User::where('tenant_id', $tenant->id)
            ->where('department', 'qa_compliance')
            ->first()
            ?? User::where('tenant_id', $tenant->id)->where('is_active', true)->first();

        if (! $participant || ! $reporter) {
            $this->command->warn('  Fall incident: missing participant or reporter, skipping.');
            return;
        }

        Incident::create([
            'tenant_id'              => $tenant->id,
            'participant_id'         => $participant->id,
            'incident_type'          => 'fall',
            'occurred_at'            => now()->subDays(2)->setHour(14)->setMinute(32),
            'location_of_incident'   => 'PACE Center — Dining Room',
            'reported_by_user_id'    => $reporter->id,
            'reported_at'            => now()->subDays(2)->setHour(15)->setMinute(10),
            'description'            => 'Participant was ambulating to dining table unassisted when right foot caught chair leg. '
                . 'Lost balance and fell to left side. Landed on left hip and elbow. Denies head strike. '
                . 'Witnessed by dietary aide and two other participants.',
            'immediate_actions_taken'=> 'Participant assisted to upright position. RN assessed immediately — no visible deformity, no LOC. '
                . 'Ice applied to left elbow contusion. Vital signs stable. Family notified at 15:45. '
                . 'Physician notified. X-ray ordered.',
            'injuries_sustained'     => true,
            'injury_description'     => 'Left elbow contusion. Left hip tenderness without deformity. X-ray negative for fracture.',
            'witnesses'              => 'Maria Lopez (dietary aide), two participants (names in incident log).',
            'rca_required'           => true,   // falls are always RCA per CMS 42 CFR 460.136
            'rca_completed'          => false,
            'rca_text'               => null,
            'cms_reportable'         => false,
            'status'                 => 'under_review',
        ]);

        $this->command->line('  Fall incident: created 1 fall incident with RCA required.');
    }

    // ── 4. Enrollment referrals ───────────────────────────────────────────────

    /**
     * Seed 3 referrals in active pipeline stages.
     * Guard: skips if ≥ 2 referrals already exist for the tenant.
     */
    private function seedReferrals(object $tenant): void
    {
        $existingCount = Referral::where('tenant_id', $tenant->id)->count();

        if ($existingCount >= 2) {
            $this->command->line('  Referrals: already seeded, skipping.');
            return;
        }

        $enrollmentUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'enrollment')
            ->first()
            ?? User::where('tenant_id', $tenant->id)->where('is_active', true)->first();

        $site = \App\Models\Site::where('tenant_id', $tenant->id)->first();

        if (! $enrollmentUser || ! $site) {
            $this->command->warn('  Referrals: missing enrollment user or site, skipping.');
            return;
        }

        foreach (self::REFERRALS as $data) {
            Referral::create([
                'tenant_id'          => $tenant->id,
                'site_id'            => $site->id,
                'referred_by_name'   => $data['referred_by_name'],
                'referred_by_org'    => $data['referred_by_org'],
                'referral_date'      => now()->subDays($data['days_ago'])->toDateString(),
                'referral_source'    => $data['referral_source'],
                'participant_id'     => null,
                'assigned_to_user_id'=> $enrollmentUser->id,
                'status'             => $data['status'],
                'decline_reason'     => null,
                'withdrawn_reason'   => null,
                'notes'              => $data['notes'],
                'created_by_user_id' => $enrollmentUser->id,
            ]);
        }

        $this->command->line('  Referrals: created ' . count(self::REFERRALS) . ' referrals in active pipeline stages.');
    }

    // ── 5. Chat seed messages ─────────────────────────────────────────────────

    /**
     * Seed 3–5 realistic messages per department channel.
     * Uses the actual department members as senders (message authors).
     * Guard: skips any channel that already has messages.
     */
    private function seedChatMessages(object $tenant): void
    {
        $channels = ChatChannel::where('tenant_id', $tenant->id)
            ->where('channel_type', 'department')
            ->where('is_active', true)
            ->get();

        if ($channels->isEmpty()) {
            $this->command->warn('  Chat messages: no department channels found, skipping.');
            return;
        }

        $seeded = 0;

        foreach ($channels as $channel) {
            // Skip if this channel already has messages
            $existingCount = ChatMessage::where('channel_id', $channel->id)->count();
            if ($existingCount > 0) {
                continue;
            }

            // Infer department from channel name (channels named e.g. "Primary Care / Nursing")
            $dept = $this->channelNameToDept($channel->name);
            $messages = self::DEPT_MESSAGES[$dept] ?? null;

            if (! $messages) {
                continue;
            }

            // Get member user IDs for this channel (cycle through them as senders)
            $memberUserIds = ChatMembership::where('channel_id', $channel->id)
                ->pluck('user_id')
                ->toArray();

            if (empty($memberUserIds)) {
                continue;
            }

            foreach ($messages as $idx => $msgData) {
                $senderId = $memberUserIds[$idx % count($memberUserIds)];
                $sentAt   = now()->subMinutes($msgData['offset_min']);

                // ChatMessage has no created_at/updated_at — use sent_at field
                DB::table('emr_chat_messages')->insert([
                    'channel_id'      => $channel->id,
                    'sender_user_id'  => $senderId,
                    'message_text'    => $msgData['text'],
                    'priority'        => $msgData['priority'] ?? 'standard',
                    'sent_at'         => $sentAt,
                    'edited_at'       => null,
                    'deleted_at'      => null,
                ]);
            }

            $seeded++;
        }

        // Also seed 3 messages in the broadcast channel
        $broadcast = ChatChannel::where('tenant_id', $tenant->id)
            ->where('channel_type', 'broadcast')
            ->where('is_active', true)
            ->first();

        if ($broadcast) {
            $existingCount = ChatMessage::where('channel_id', $broadcast->id)->count();

            if ($existingCount === 0) {
                $adminUser = User::where('tenant_id', $tenant->id)
                    ->where('role', 'super_admin')
                    ->orWhere(function ($q) use ($tenant) {
                        $q->where('tenant_id', $tenant->id)->where('department', 'it_admin');
                    })
                    ->first();

                if ($adminUser) {
                    $broadcastMessages = [
                        ['text' => 'Welcome to NostosEMR! This broadcast channel reaches all staff. Use it for org-wide announcements only.', 'offset_min' => 10080],
                        ['text' => 'Reminder: Annual HIPAA training is due for all staff by April 30. Link in your email inbox.', 'offset_min' => 2880, 'priority' => 'urgent'],
                        ['text' => 'EMR system will be updated tonight at 2am. Please log out before midnight. Maintenance window: 2–4am.', 'offset_min' => 360],
                    ];

                    foreach ($broadcastMessages as $msgData) {
                        DB::table('emr_chat_messages')->insert([
                            'channel_id'     => $broadcast->id,
                            'sender_user_id' => $adminUser->id,
                            'message_text'   => $msgData['text'],
                            'priority'       => $msgData['priority'] ?? 'standard',
                            'sent_at'        => now()->subMinutes($msgData['offset_min']),
                            'edited_at'      => null,
                            'deleted_at'     => null,
                        ]);
                    }
                }
            }
        }

        $this->command->line("  Chat messages: seeded messages in {$seeded} department channel(s).");
    }

    // ── 6. Guaranteed no-show trip for today ─────────────────────────────────

    /**
     * Ensure today's manifest always has at least one no_show trip.
     * Guard: skips if a no_show transport request for today already exists.
     */
    private function seedGuaranteedNoShow(object $tenant): void
    {
        $existingNoShow = TransportRequest::where('tenant_id', $tenant->id)
            ->where('status', 'no_show')
            ->whereDate('scheduled_pickup_time', today())
            ->count();

        if ($existingNoShow >= 1) {
            $this->command->line('  No-show trip: already seeded, skipping.');
            return;
        }

        $participant = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->inRandomOrder()
            ->first();

        $transportUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'transportation')
            ->first()
            ?? User::where('tenant_id', $tenant->id)->where('is_active', true)->first();

        $paceCenter = Location::where('tenant_id', $tenant->id)
            ->where('location_type', 'pace_center')
            ->first();

        $participantHome = Location::where('tenant_id', $tenant->id)
            ->where('location_type', 'participant_home')
            ->first();

        if (! $participant || ! $transportUser || ! $paceCenter) {
            $this->command->warn('  No-show trip: missing required models, skipping.');
            return;
        }

        $pickupTime = today()->setHour(8)->setMinute(30);

        TransportRequest::create([
            'tenant_id'              => $tenant->id,
            'participant_id'         => $participant->id,
            'requesting_user_id'     => $transportUser->id,
            'requesting_department'  => 'transportation',
            'trip_type'              => 'to_center',
            'pickup_location_id'     => $participantHome?->id ?? $paceCenter->id,
            'dropoff_location_id'    => $paceCenter->id,
            'requested_pickup_time'  => $pickupTime,
            'scheduled_pickup_time'  => $pickupTime,
            'actual_pickup_time'     => null,
            'actual_dropoff_time'    => null,
            'special_instructions'   => 'Participant requires lift-equipped vehicle.',
            'mobility_flags_snapshot'=> [],
            'status'                 => 'no_show',
            'transport_trip_id'      => random_int(5000, 9999),
            'driver_notes'           => 'Knocked twice — no answer. Neighbor confirmed participant was home. Notified dispatch.',
            'last_synced_at'         => now()->subHours(2),
        ]);

        $this->command->line('  No-show trip: created 1 no_show trip for today\'s manifest.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Map a channel name back to its department key for looking up message templates.
     * Channel names come from the DemoEnvironmentSeeder dept label constants.
     */
    private function channelNameToDept(string $channelName): string
    {
        $map = [
            'Primary Care'       => 'primary_care',
            'Therapies'          => 'therapies',
            'Social Work'        => 'social_work',
            'Behavioral Health'  => 'behavioral_health',
            'Dietary'            => 'dietary',
            'Activities'         => 'activities',
            'Home Care'          => 'home_care',
            'Transportation'     => 'transportation',
            'Pharmacy'           => 'pharmacy',
            'IDT'                => 'idt',
            'Enrollment'         => 'enrollment',
            'Finance'            => 'finance',
            'QA'                 => 'qa_compliance',
            'IT'                 => 'it_admin',
            'Compliance'         => 'qa_compliance',
            'Admin'              => 'it_admin',
        ];

        foreach ($map as $fragment => $dept) {
            if (str_contains($channelName, $fragment)) {
                return $dept;
            }
        }

        return '';
    }
}
