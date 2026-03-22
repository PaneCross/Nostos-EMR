<?php

// ─── Phase4DataSeeder ─────────────────────────────────────────────────────────
// Seeds Phase 4 demo data: care plans (with domain goals), IDT meetings (past
// completed + upcoming), SDRs (mix of statuses including overdue), and alerts
// (mix of severities, some unacknowledged per department).
//
// Per enrolled participant:
//   1 active care plan with goals for all 12 domains (some due for review soon)
//   Included in 1–2 IDT meeting participant review queues
//
// Shared per tenant:
//   3 completed IDT meetings (past 30 days)
//   1 in-progress meeting (today)
//   1 upcoming meeting (next week)
//   8–12 SDRs: mix of submitted / in_progress / completed / overdue
//   6–10 alerts: mix of critical / warning / info, some unacknowledged
//
// Safe to re-run on empty Phase 4 tables (does not guard against dupes).
// ──────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\IdtMeeting;
use App\Models\IdtParticipantReview;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class Phase4DataSeeder extends Seeder
{
    // ── Goal templates per domain (realistic PACE content) ────────────────────

    private const GOAL_TEMPLATES = [
        'medical' => [
            'description'  => 'Maintain stable blood pressure below 140/90 mmHg. Monitor HbA1c quarterly; target < 7.5%.',
            'outcomes'     => 'BP readings ≤ 140/90 on ≥ 80% of recorded visits.',
            'interventions'=> 'Monthly BP checks at PACE center. Titrate antihypertensives per protocol. Educate participant on sodium restriction.',
        ],
        'nursing' => [
            'description'  => 'Participant will adhere to medication regimen with >90% compliance and no missed doses.',
            'outcomes'     => 'Pill counts and refill records confirm ≥90% adherence each month.',
            'interventions'=> 'Blister pack dispensing. Daily medication log. Nursing review at each center visit.',
        ],
        'social' => [
            'description'  => 'Participant will maintain social engagement via PACE center attendance 3×/week and monthly family meetings.',
            'outcomes'     => 'Attendance log shows ≥ 3 center days per week for 3 consecutive months.',
            'interventions'=> 'Transportation coordination. Family caregiver education within 30 days of enrollment.',
        ],
        'behavioral' => [
            'description'  => 'PHQ-9 score reduced by ≥5 points within 90 days. Participant will practice 2 coping strategies independently.',
            'outcomes'     => 'PHQ-9 reassessment at 90 days shows clinically significant reduction.',
            'interventions'=> 'Weekly behavioral health check-ins. Refer to group therapy if PHQ-9 ≥ 10.',
        ],
        'therapy_pt' => [
            'description'  => 'Improve 6-minute walk test by 20% within 60 days. Independent ambulation on all surfaces with walker.',
            'outcomes'     => '6MWT distance documented at baseline and 60-day follow-up showing ≥20% improvement.',
            'interventions'=> 'PT 2×/week at center. Home exercise program. Monthly balance reassessment.',
        ],
        'therapy_ot' => [
            'description'  => 'Independent with upper body dressing using adaptive equipment. Meal preparation with supervision within 45 days.',
            'outcomes'     => 'OT functional assessment shows independence in targeted IADLs at 45-day review.',
            'interventions'=> 'OT 1×/week. Adaptive equipment fitting and training. Home environment assessment.',
        ],
        'therapy_st' => [
            'description'  => 'Maintain safe oral diet with thin liquids without aspiration. Use of AAC strategies for 5+ communications/session.',
            'outcomes'     => 'Modified barium swallow study confirms safe swallow function. Communication log maintained.',
            'interventions'=> 'ST 1×/week. Dysphagia precautions documented and communicated to dietary. AAC device trial.',
        ],
        'dietary' => [
            'description'  => 'Achieve and maintain BMI 22–27 within 90 days. Protein intake 1.0–1.2 g/kg/day documented weekly.',
            'outcomes'     => 'Weekly weight stable or trending toward target BMI. 3-day food recall shows adequate protein.',
            'interventions'=> 'Dietitian assessment monthly. Meal modifications per dietary restrictions. Appetite stimulant review.',
        ],
        'activities' => [
            'description'  => 'Participate in 2+ structured activities per week. Demonstrate engagement with creative arts program 2×/month.',
            'outcomes'     => 'Activities attendance log shows consistent participation. Staff observation notes positive engagement.',
            'interventions'=> 'Individualized activity interest assessment. Peer group matching. Adaptive activities as needed.',
        ],
        'home_care' => [
            'description'  => 'Home environment safety assessment completed and modifications in place. Participant satisfaction with personal care ≥8/10.',
            'outcomes'     => 'Home safety checklist completed. Grab bars, ramp, or other modifications installed within 30 days.',
            'interventions'=> 'Home care aide 5×/week for AM care. Monthly supervisory visit by RN. Fall risk mitigation plan.',
        ],
        'transportation' => [
            'description'  => 'Attend PACE center on all scheduled days without transport incident. Caregiver trained on accessible vehicle boarding.',
            'outcomes'     => 'Transport log shows zero missed trips due to access issues over 60 days.',
            'interventions'=> 'Accessible vehicle assignment. Boarding protocol training for caregiver. Emergency contact verified.',
        ],
        'pharmacy' => [
            'description'  => 'Medication reconciliation completed and all discrepancies resolved. Participant understands purpose of all medications.',
            'outcomes'     => 'Medication list reconciled with PCP records. Teach-back completed for top 3 medications.',
            'interventions'=> 'Pharmacist review monthly. Deprescribing consult if polypharmacy ≥ 10 medications. Patient education handout.',
        ],
    ];

    // ── SDR templates ─────────────────────────────────────────────────────────

    private const SDR_TEMPLATES = [
        [
            'type'     => 'lab_order',
            'from'     => 'primary_care',
            'to'       => 'pharmacy',
            'desc'     => 'CBC, BMP, HbA1c, and lipid panel ordered. Please coordinate with participant and ensure specimen collection before next center visit.',
            'priority' => 'routine',
        ],
        [
            'type'     => 'referral',
            'from'     => 'primary_care',
            'to'       => 'therapies',
            'desc'     => 'Participant reports increased fall risk and difficulty with transfers. Referral to PT/OT for functional assessment and home safety evaluation.',
            'priority' => 'urgent',
        ],
        [
            'type'     => 'transport_request',
            'from'     => 'social_work',
            'to'       => 'transportation',
            'desc'     => 'Participant requires accessible van transport to specialist appointment. Caregiver will accompany. Confirm vehicle availability.',
            'priority' => 'routine',
        ],
        [
            'type'     => 'home_care_visit',
            'from'     => 'social_work',
            'to'       => 'home_care',
            'desc'     => 'Home care aide hours need to be increased to 5×/week following recent hospitalization. Please update schedule and confirm availability.',
            'priority' => 'urgent',
        ],
        [
            'type'     => 'pharmacy_change',
            'from'     => 'primary_care',
            'to'       => 'pharmacy',
            'desc'     => 'Metformin dose adjustment from 500mg BID to 1000mg BID per recent labs. Please update Rx and counsel participant on side effects.',
            'priority' => 'routine',
        ],
        [
            'type'     => 'assessment_request',
            'from'     => 'idt',
            'to'       => 'primary_care',
            'desc'     => 'Annual comprehensive assessment overdue. Please schedule and complete within 5 business days per CMS compliance requirement.',
            'priority' => 'urgent',
        ],
        [
            'type'     => 'care_plan_update',
            'from'     => 'enrollment',
            'to'       => 'idt',
            'desc'     => 'Participant reported new diagnosis from outside specialist visit. Care plan goals for behavioral domain need IDT review and update.',
            'priority' => 'routine',
        ],
        [
            'type'     => 'equipment_dme',
            'from'     => 'therapies',
            'to'       => 'home_care',
            'desc'     => 'PT has determined participant requires rollator walker with seat for home use. DME order placed — please coordinate delivery and setup training.',
            'priority' => 'routine',
        ],
        [
            'type'     => 'referral',
            'from'     => 'primary_care',
            'to'       => 'social_work',
            'desc'     => 'Participant expressed caregiver stress concerns during last visit. Referral for caregiver support resources and respite planning.',
            'priority' => 'routine',
        ],
        [
            'type'     => 'lab_order',
            'from'     => 'primary_care',
            'to'       => 'pharmacy',
            'desc'     => 'Warfarin INR monitoring due. Target range 2.0–3.0. If out of range, please notify MD immediately for dose adjustment.',
            'priority' => 'emergent',
        ],
    ];

    // ── Alert templates ───────────────────────────────────────────────────────

    private const ALERT_TEMPLATES = [
        [
            'source'   => 'assessment',
            'type'     => 'assessment_overdue',
            'severity' => 'warning',
            'title'    => 'Annual Assessment Overdue',
            'message'  => 'Participant annual comprehensive assessment is overdue. CMS compliance requires completion within the review window.',
            'depts'    => ['primary_care'],
        ],
        [
            'source'   => 'adl',
            'type'     => 'adl_decline',
            'severity' => 'warning',
            'title'    => 'ADL Functional Decline Detected',
            'message'  => 'Participant independence level in bathing dropped below the established threshold. Primary care and social work review recommended.',
            'depts'    => ['primary_care', 'social_work'],
        ],
        [
            'source'   => 'sdr',
            'type'     => 'sdr_overdue',
            'severity' => 'critical',
            'title'    => 'SDR Past 72-Hour Window',
            'message'  => 'A Service Delivery Request has exceeded the 72-hour completion window and has been escalated. Immediate action required.',
            'depts'    => ['primary_care', 'qa_compliance'],
        ],
        [
            'source'   => 'allergy',
            'type'     => 'allergy_critical',
            'severity' => 'critical',
            'title'    => 'Life-Threatening Allergy on File',
            'message'  => 'Participant has a documented life-threatening allergy. Ensure all care providers are aware before administering any medications.',
            'depts'    => ['primary_care', 'pharmacy'],
        ],
        [
            'source'   => 'sdr',
            'type'     => 'sdr_warning_24h',
            'severity' => 'info',
            'title'    => 'SDR Due Within 24 Hours',
            'message'  => 'A Service Delivery Request assigned to your department is due within 24 hours. Please review and update status.',
            'depts'    => ['social_work'],
        ],
        [
            'source'   => 'manual',
            'type'     => 'manual',
            'severity' => 'info',
            'title'    => 'IDT Meeting Scheduled for Today',
            'message'  => 'Weekly IDT meeting is scheduled for 10:00 AM today. Participant reviews have been queued for discussion.',
            'depts'    => ['idt'],
        ],
    ];

    // ─────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->firstOrFail();

        $idtAdmin = User::where('tenant_id', $tenant->id)
            ->where('department', 'idt')
            ->where('role', 'admin')
            ->first();

        $pcAdmin = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')
            ->where('role', 'admin')
            ->first();

        $swUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'social_work')
            ->first();

        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->get();

        $authorId    = $pcAdmin?->id ?? 1;
        $idtAdminId  = $idtAdmin?->id ?? $authorId;

        $this->command->info("  Seeding Phase 4 data for {$participants->count()} enrolled participants...");

        // ── Care Plans ────────────────────────────────────────────────────────
        $this->seedCarePlans($participants, $tenant, $authorId, $idtAdminId);

        // ── IDT Meetings ──────────────────────────────────────────────────────
        $meetings = $this->seedIdtMeetings($tenant, $participants, $idtAdmin, $pcAdmin);

        // ── SDRs ──────────────────────────────────────────────────────────────
        $this->seedSdrs($participants, $tenant, $authorId, $swUser);

        // ── Alerts ────────────────────────────────────────────────────────────
        $this->seedAlerts($participants, $tenant, $authorId);
    }

    // ── Care Plans ─────────────────────────────────────────────────────────────

    private function seedCarePlans(
        $participants,
        $tenant,
        int $authorId,
        int $idtAdminId
    ): void {
        $carePlanCount = 0;
        $goalCount     = 0;

        foreach ($participants as $idx => $participant) {
            // Some participants have a plan due for review soon (within 30 days)
            $isDueSoon = ($idx % 4 === 0);

            $effectiveDate  = $isDueSoon
                ? Carbon::now()->subMonths(5)->subWeeks(2)
                : Carbon::now()->subMonths(rand(1, 5));
            $reviewDueDate  = $effectiveDate->copy()->addMonths(6);

            $carePlan = CarePlan::create([
                'participant_id'       => $participant->id,
                'tenant_id'            => $tenant->id,
                'version'              => 1,
                'status'               => 'active',
                'effective_date'       => $effectiveDate->format('Y-m-d'),
                'review_due_date'      => $reviewDueDate->format('Y-m-d'),
                'approved_by_user_id'  => $idtAdminId,
                'approved_at'          => $effectiveDate,
                'overall_goals_text'   => 'Maintain participant quality of life, functional independence, and community engagement through coordinated interdisciplinary care. '
                    . 'Focus areas include chronic disease management, fall prevention, psychosocial support, and caregiver education.',
            ]);
            $carePlanCount++;

            // Seed goals for all 12 domains
            foreach (CarePlanGoal::DOMAINS as $domainIdx => $domain) {
                $template = self::GOAL_TEMPLATES[$domain];

                // A couple of goals are 'met' to show lifecycle variation
                $status = ($domainIdx % 7 === 0) ? 'met' : 'active';

                $targetDate = Carbon::now()->addMonths(rand(2, 5));

                CarePlanGoal::create([
                    'care_plan_id'            => $carePlan->id,
                    'domain'                  => $domain,
                    'goal_description'        => $template['description'],
                    'target_date'             => $targetDate->format('Y-m-d'),
                    'measurable_outcomes'     => $template['outcomes'],
                    'interventions'           => $template['interventions'],
                    'status'                  => $status,
                    'authored_by_user_id'     => $authorId,
                    'last_updated_by_user_id' => $authorId,
                ]);
                $goalCount++;
            }
        }

        $this->command->line(
            "  Care plans seeded: <comment>{$carePlanCount}</comment> plans · <comment>{$goalCount}</comment> domain goals"
        );
    }

    // ── IDT Meetings ───────────────────────────────────────────────────────────

    private function seedIdtMeetings(
        $tenant,
        $participants,
        ?User $idtAdmin,
        ?User $pcAdmin
    ): array {
        $meetings   = [];
        $reviewCount = 0;

        $facilitatorId = $idtAdmin?->id ?? 1;

        // 3 completed past meetings (over last 30 days)
        for ($i = 3; $i >= 1; $i--) {
            $meetingDate = Carbon::now()->subWeeks($i)->startOfWeek()->addDays(1); // Tuesdays

            $meeting = IdtMeeting::create([
                'tenant_id'            => $tenant->id,
                'site_id'              => null,
                'meeting_date'         => $meetingDate->format('Y-m-d'),
                'meeting_time'         => '10:00',
                'meeting_type'         => 'weekly',
                'facilitator_user_id'  => $facilitatorId,
                'attendees'            => $this->buildAttendeeList($tenant),
                'minutes_text'         => $this->meetingMinutes($i),
                'decisions'            => $this->meetingDecisions(),
                'status'               => 'completed',
            ]);

            // Queue 3–5 participants for review in each completed meeting
            $reviewParticipants = $participants->random(min(rand(3, 5), $participants->count()));
            foreach ($reviewParticipants as $order => $participant) {
                $reviewedAt = $meetingDate->copy()->addHours(10)->addMinutes($order * 12);

                IdtParticipantReview::create([
                    'meeting_id'    => $meeting->id,
                    'participant_id'=> $participant->id,
                    'summary_text'  => 'Participant status reviewed. Care plan goals on track. '
                        . 'No acute concerns identified. Continue current plan.',
                    'action_items'  => [
                        ['description' => 'Follow up on labs', 'assigned_to_dept' => 'primary_care', 'due_date' => $meetingDate->copy()->addWeek()->format('Y-m-d')],
                        ['description' => 'Caregiver check-in call', 'assigned_to_dept' => 'social_work', 'due_date' => $meetingDate->copy()->addDays(5)->format('Y-m-d')],
                    ],
                    'reviewed_at'   => $reviewedAt,
                    'queue_order'   => $order + 1,
                ]);
                $reviewCount++;
            }

            $meetings[] = $meeting;
        }

        // 1 in-progress meeting (today)
        $todayMeeting = IdtMeeting::create([
            'tenant_id'           => $tenant->id,
            'site_id'             => null,
            'meeting_date'        => now()->format('Y-m-d'),
            'meeting_time'        => '10:00',
            'meeting_type'        => 'weekly',
            'facilitator_user_id' => $facilitatorId,
            'attendees'           => $this->buildAttendeeList($tenant),
            'minutes_text'        => null,
            'decisions'           => [],
            'status'              => 'in_progress',
        ]);

        // Queue all enrolled participants for today's meeting (not yet reviewed)
        foreach ($participants->take(min(6, $participants->count())) as $order => $participant) {
            IdtParticipantReview::create([
                'meeting_id'    => $todayMeeting->id,
                'participant_id'=> $participant->id,
                'summary_text'  => null,
                'action_items'  => [],
                'reviewed_at'   => null,
                'queue_order'   => $order + 1,
            ]);
        }

        $meetings[] = $todayMeeting;

        // 1 upcoming meeting (next week)
        $nextWeek = Carbon::now()->addWeek()->startOfWeek()->addDays(1); // Next Tuesday
        $upcomingMeeting = IdtMeeting::create([
            'tenant_id'           => $tenant->id,
            'site_id'             => null,
            'meeting_date'        => $nextWeek->format('Y-m-d'),
            'meeting_time'        => '10:00',
            'meeting_type'        => 'weekly',
            'facilitator_user_id' => $facilitatorId,
            'attendees'           => [],
            'minutes_text'        => null,
            'decisions'           => [],
            'status'              => 'scheduled',
        ]);
        $meetings[] = $upcomingMeeting;

        $meetingCount = count($meetings);
        $this->command->line(
            "  IDT meetings seeded: <comment>{$meetingCount}</comment> meetings · <comment>{$reviewCount}</comment> participant reviews"
        );

        return $meetings;
    }

    // ── SDRs ───────────────────────────────────────────────────────────────────

    private function seedSdrs(
        $participants,
        $tenant,
        int $authorId,
        ?User $swUser
    ): void {
        $sdrCount = 0;

        // Collect a handful of participants for variety
        $sampleParticipants = $participants->values();

        foreach (self::SDR_TEMPLATES as $idx => $template) {
            $participant = $sampleParticipants[$idx % $sampleParticipants->count()];

            // Vary the age of the SDR for testing different states
            $submittedAt = match (true) {
                // Overdue (>72h ago, not completed)
                $idx === 2 => Carbon::now()->subHours(80),
                $idx === 9 => Carbon::now()->subHours(100),
                // Warning zone (24–48h ago)
                $idx === 4 => Carbon::now()->subHours(26),
                // Recent (within 24h)
                default    => Carbon::now()->subHours(rand(1, 18)),
            };

            $status = match (true) {
                $idx === 0 => 'completed',
                $idx === 1 => 'in_progress',
                $idx === 2 => 'in_progress',   // overdue + in_progress
                $idx === 9 => 'in_progress',   // overdue + in_progress (emergent)
                $idx === 7 => 'acknowledged',
                default    => 'submitted',
            };

            $sdrData = [
                'participant_id'      => $participant->id,
                'tenant_id'           => $tenant->id,
                'requesting_user_id'  => $authorId,
                'requesting_department' => $template['from'],
                'assigned_department' => $template['to'],
                'assigned_to_user_id' => null,
                'request_type'        => $template['type'],
                'description'         => $template['desc'],
                'priority'            => $template['priority'],
                'status'              => $status,
                'submitted_at'        => $submittedAt,
                // due_at auto-set by Sdr::boot() to submitted_at + 72h
            ];

            if ($status === 'completed') {
                $sdrData['completed_at']      = $submittedAt->copy()->addHours(rand(6, 20));
                $sdrData['completion_notes']  = 'Request fulfilled. Participant notified. All actions documented in chart.';
            }

            // Mark overdue SDRs as escalated
            if (in_array($idx, [2, 9])) {
                $sdrData['escalated']         = true;
                $sdrData['escalation_reason'] = 'SDR not completed within 72-hour window. Escalated automatically by system.';
                $sdrData['escalated_at']      = $submittedAt->copy()->addHours(72)->addMinutes(15);
            }

            Sdr::create($sdrData);
            $sdrCount++;
        }

        $this->command->line("  SDRs seeded: <comment>{$sdrCount}</comment> requests (including 2 overdue/escalated)");
    }

    // ── Alerts ─────────────────────────────────────────────────────────────────

    private function seedAlerts(
        $participants,
        $tenant,
        int $authorId
    ): void {
        $alertCount = 0;

        $sampleParticipants = $participants->values();

        foreach (self::ALERT_TEMPLATES as $idx => $template) {
            $participant = $sampleParticipants[$idx % $sampleParticipants->count()];

            // Vary acknowledged state: 1st and last are acknowledged, rest are active/unread
            $isAcknowledged = in_array($idx, [0, 5]);
            $isResolved     = ($idx === 0);

            Alert::create([
                'tenant_id'                => $tenant->id,
                'participant_id'           => $participant->id,
                'source_module'            => $template['source'],
                'alert_type'               => $template['type'],
                'title'                    => $template['title'],
                'message'                  => $template['message'],
                'severity'                 => $template['severity'],
                'target_departments'       => $template['depts'],
                'created_by_system'        => $template['source'] !== 'manual',
                'created_by_user_id'       => $template['source'] === 'manual' ? $authorId : null,
                'is_active'                => ! $isResolved,
                'acknowledged_at'          => $isAcknowledged ? now()->subMinutes(rand(5, 60)) : null,
                'acknowledged_by_user_id'  => $isAcknowledged ? $authorId : null,
                'resolved_at'              => $isResolved ? now()->subMinutes(rand(2, 30)) : null,
            ]);
            $alertCount++;
        }

        $this->command->line(
            "  Alerts seeded: <comment>{$alertCount}</comment> alerts "
            . "(2 acknowledged · 1 resolved · " . ($alertCount - 3) . " active/unread)"
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Build a realistic attendee list from actual demo users. */
    private function buildAttendeeList(Tenant $tenant): array
    {
        $depts = ['primary_care', 'social_work', 'therapies', 'behavioral_health', 'dietary', 'idt'];
        $attendees = [];

        foreach ($depts as $dept) {
            $user = User::where('tenant_id', $tenant->id)
                ->where('department', $dept)
                ->where('role', 'admin')
                ->first();

            if ($user) {
                $attendees[] = [
                    'user_id'    => $user->id,
                    'name'       => $user->first_name . ' ' . $user->last_name,
                    'department' => $dept,
                ];
            }
        }

        return $attendees;
    }

    /** Generate realistic meeting minutes text. */
    private function meetingMinutes(int $weeksAgo): string
    {
        return "Weekly IDT Meeting — {$weeksAgo} week(s) ago\n\n"
            . "ATTENDANCE: Primary Care, Social Work, Therapies, Behavioral Health, Dietary, IDT Coordinator.\n\n"
            . "PARTICIPANT REVIEWS COMPLETED: All queued participants reviewed. "
            . "No acute clinical concerns requiring immediate escalation identified.\n\n"
            . "KEY DECISIONS: (1) Care plan updates for participants with goal review dates within 30 days to be initiated by primary care. "
            . "(2) Social work to follow up on 2 participants with caregiver stress concerns. "
            . "(3) PT to complete updated fall risk assessments for 3 participants before next meeting.\n\n"
            . "ACTION ITEMS: Assigned and tracked per participant review records. "
            . "Follow-up items to be confirmed complete at next weekly meeting.\n\n"
            . "NEXT MEETING: Scheduled for same time next week. Facilitator: IDT Coordinator.";
    }

    /** Generate sample decisions array. */
    private function meetingDecisions(): array
    {
        return [
            [
                'decision'   => 'Update care plan goals for participants with review dates within 30 days.',
                'owner'      => 'primary_care',
                'due_date'   => Carbon::now()->addDays(7)->format('Y-m-d'),
            ],
            [
                'decision'   => 'Social work to complete caregiver stress assessment for 2 flagged participants.',
                'owner'      => 'social_work',
                'due_date'   => Carbon::now()->addDays(5)->format('Y-m-d'),
            ],
            [
                'decision'   => 'PT to complete fall risk reassessments before next meeting.',
                'owner'      => 'therapies',
                'due_date'   => Carbon::now()->addDays(7)->format('Y-m-d'),
            ],
        ];
    }
}
