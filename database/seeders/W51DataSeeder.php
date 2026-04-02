<?php

// ─── W51DataSeeder ─────────────────────────────────────────────────────────
// Demo data for W5-1: Wound Care + Break-the-Glass emergency access.
//
// Wound records seeded:
//   - 4 open wound records across 4 enrolled participants
//     (1 Stage 3 pressure injury [critical], 2 non-critical open wounds,
//      1 diabetic foot ulcer with assessments)
//   - 2 assessments on the diabetic foot ulcer for trend demo
//
// Break-the-glass events seeded:
//   - 2 unreviewed events (today and yesterday) — shown in red on IT Admin dashboard
//   - 1 acknowledged event (3 days ago) — shown in amber
//
// Called from DemoEnvironmentSeeder after W46DataSeeder.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\BreakGlassEvent;
use App\Models\Participant;
use App\Models\User;
use App\Models\WoundAssessment;
use App\Models\WoundRecord;
use Illuminate\Database\Seeder;

class W51DataSeeder extends Seeder
{
    public function run(): void
    {
        // ── Resolve tenant context ────────────────────────────────────────────

        // Use an enrolled participant to get the tenant + site context
        $enrolledParticipants = Participant::where('enrollment_status', 'enrolled')
            ->with(['site'])
            ->limit(10)
            ->get();

        if ($enrolledParticipants->isEmpty()) {
            $this->command->warn('  W51DataSeeder: No enrolled participants found — skipping wound/BTG seed.');
            return;
        }

        $tenantId = $enrolledParticipants->first()->tenant_id;

        // Get a home_care or primary_care user to act as documenting clinician
        $nursingUser = User::where('tenant_id', $tenantId)
            ->whereIn('department', ['home_care', 'primary_care'])
            ->first();

        if (! $nursingUser) {
            $this->command->warn('  W51DataSeeder: No nursing user found — skipping wound seed.');
            return;
        }

        // ── Wound Records ─────────────────────────────────────────────────────

        $this->command->line('  Wound records:');

        // 1. Stage 3 pressure injury on participant #1 (critical — CMS QAPI reportable)
        $p1 = $enrolledParticipants->get(0);
        $wound1 = WoundRecord::create([
            'participant_id'         => $p1->id,
            'tenant_id'              => $tenantId,
            'site_id'                => $p1->site_id,
            'wound_type'             => 'pressure_injury',
            'location'               => 'Sacrum',
            'pressure_injury_stage'  => 'stage_3',
            'length_cm'              => 4.2,
            'width_cm'               => 3.1,
            'depth_cm'               => 0.8,
            'wound_bed'              => 'slough',
            'exudate_amount'         => 'moderate',
            'exudate_type'           => 'serosanguineous',
            'periwound_skin'         => 'erythema',
            'odor'                   => false,
            'pain_score'             => 4,
            'treatment_description'  => 'Alginate dressing with bordered foam overlay. Reposition every 2 hours.',
            'dressing_type'          => 'Alginate',
            'dressing_change_frequency' => 'Every 2 days',
            'goal'                   => 'healing',
            'status'                 => 'open',
            'first_identified_date'  => now()->subDays(21)->toDateString(),
            'healed_date'            => null,
            'documented_by_user_id'  => $nursingUser->id,
            'photo_taken'            => true,
            'notes'                  => 'CMS QAPI reportable pressure injury. Wound care team notified. Off-loading protocol initiated.',
        ]);
        $this->command->line("    <comment>Stage 3 pressure injury (CRITICAL)</comment> — {$p1->first_name} {$p1->last_name}");

        // 2. Diabetic foot ulcer on participant #2 — non-critical, with assessments
        $p2 = $enrolledParticipants->get(1);
        $wound2 = WoundRecord::create([
            'participant_id'         => $p2->id,
            'tenant_id'              => $tenantId,
            'site_id'                => $p2->site_id,
            'wound_type'             => 'diabetic_foot_ulcer',
            'location'               => 'Right heel',
            'pressure_injury_stage'  => null,
            'length_cm'              => 2.5,
            'width_cm'               => 1.8,
            'depth_cm'               => 0.4,
            'wound_bed'              => 'granulation',
            'exudate_amount'         => 'light',
            'exudate_type'           => 'serous',
            'periwound_skin'         => 'intact',
            'odor'                   => false,
            'pain_score'             => 2,
            'treatment_description'  => 'Hydrocolloid dressing. Offloading boot in use. Blood glucose monitoring daily.',
            'dressing_type'          => 'Hydrocolloid',
            'dressing_change_frequency' => 'Every 3 days',
            'goal'                   => 'healing',
            'status'                 => 'healing',
            'first_identified_date'  => now()->subDays(35)->toDateString(),
            'healed_date'            => null,
            'documented_by_user_id'  => $nursingUser->id,
            'photo_taken'            => true,
            'notes'                  => 'Wound showing good granulation tissue. Continue current treatment protocol.',
        ]);
        $this->command->line("    <comment>Diabetic foot ulcer (healing)</comment> — {$p2->first_name} {$p2->last_name}");

        // Add 2 assessments to the diabetic foot ulcer for trend demo
        WoundAssessment::create([
            'wound_record_id'       => $wound2->id,
            'assessed_by_user_id'   => $nursingUser->id,
            'assessed_at'           => now()->subDays(14),
            'length_cm'             => 3.2,
            'width_cm'              => 2.4,
            'depth_cm'              => 0.6,
            'wound_bed'             => 'slough',
            'exudate_amount'        => 'moderate',
            'exudate_type'          => 'serosanguineous',
            'periwound_skin'        => 'erythema',
            'odor'                  => false,
            'pain_score'            => 3,
            'treatment_description' => 'Hydrocolloid dressing applied.',
            'status_change'         => 'unchanged',
            'notes'                 => 'Initial assessment at wound care visit.',
        ]);

        WoundAssessment::create([
            'wound_record_id'       => $wound2->id,
            'assessed_by_user_id'   => $nursingUser->id,
            'assessed_at'           => now()->subDays(7),
            'length_cm'             => 2.8,
            'width_cm'              => 2.0,
            'depth_cm'              => 0.5,
            'wound_bed'             => 'granulation',
            'exudate_amount'        => 'light',
            'exudate_type'          => 'serous',
            'periwound_skin'        => 'intact',
            'odor'                  => false,
            'pain_score'            => 2,
            'treatment_description' => 'Hydrocolloid dressing, changed to every 3 days.',
            'status_change'         => 'improved',
            'notes'                 => 'Wound reducing in size. Granulation tissue present. Good healing response.',
        ]);
        $this->command->line('    <comment>2 assessments seeded</comment> on diabetic foot ulcer');

        // 3. Stage 2 pressure injury on participant #3 — non-critical
        $p3 = $enrolledParticipants->get(2);
        WoundRecord::create([
            'participant_id'         => $p3->id,
            'tenant_id'              => $tenantId,
            'site_id'                => $p3->site_id,
            'wound_type'             => 'pressure_injury',
            'location'               => 'Coccyx',
            'pressure_injury_stage'  => 'stage_2',
            'length_cm'              => 1.5,
            'width_cm'               => 1.0,
            'depth_cm'               => 0.2,
            'wound_bed'              => 'granulation',
            'exudate_amount'         => 'scant',
            'exudate_type'           => 'serous',
            'periwound_skin'         => 'intact',
            'odor'                   => false,
            'pain_score'             => 1,
            'treatment_description'  => 'Foam dressing. Reposition every 2 hours. Moisture barrier cream applied.',
            'dressing_type'          => 'Foam',
            'dressing_change_frequency' => 'Daily',
            'goal'                   => 'healing',
            'status'                 => 'healing',
            'first_identified_date'  => now()->subDays(10)->toDateString(),
            'healed_date'            => null,
            'documented_by_user_id'  => $nursingUser->id,
            'photo_taken'            => false,
            'notes'                  => 'Stage 2 identified during home visit. Moisture protocol initiated.',
        ]);
        $this->command->line("    <comment>Stage 2 pressure injury</comment> — {$p3->first_name} {$p3->last_name}");

        // 4. Venous ulcer on participant #4
        if ($enrolledParticipants->count() >= 4) {
            $p4 = $enrolledParticipants->get(3);
            WoundRecord::create([
                'participant_id'         => $p4->id,
                'tenant_id'              => $tenantId,
                'site_id'                => $p4->site_id,
                'wound_type'             => 'venous_ulcer',
                'location'               => 'Left shin',
                'pressure_injury_stage'  => null,
                'length_cm'              => 5.0,
                'width_cm'              => 3.5,
                'depth_cm'               => 0.3,
                'wound_bed'              => 'mixed',
                'exudate_amount'         => 'moderate',
                'exudate_type'           => 'serous',
                'periwound_skin'         => 'macerated',
                'odor'                   => false,
                'pain_score'             => 3,
                'treatment_description'  => 'Compression bandaging. Leg elevation when seated. Antimicrobial dressing.',
                'dressing_type'          => 'Foam',
                'dressing_change_frequency' => 'Every 2 days',
                'goal'                   => 'healing',
                'status'                 => 'stable',
                'first_identified_date'  => now()->subDays(60)->toDateString(),
                'healed_date'            => null,
                'documented_by_user_id'  => $nursingUser->id,
                'photo_taken'            => true,
                'notes'                  => 'Long-standing venous insufficiency ulcer. Compression therapy ongoing.',
            ]);
            $this->command->line("    <comment>Venous ulcer (stable)</comment> — {$p4->first_name} {$p4->last_name}");
        }

        // ── Break-the-Glass Events ────────────────────────────────────────────

        $this->command->line('  Break-the-glass events:');

        // Get any non-IT-Admin clinical user to simulate BTG requester
        $clinicalUser = User::where('tenant_id', $tenantId)
            ->whereIn('department', ['primary_care', 'social_work', 'home_care'])
            ->first();

        $itAdminUser = User::where('tenant_id', $tenantId)
            ->where('department', 'it_admin')
            ->first();

        if ($clinicalUser && $enrolledParticipants->count() >= 2) {
            // Event 1: Today — unreviewed (shows in red on dashboard)
            BreakGlassEvent::create([
                'user_id'           => $clinicalUser->id,
                'tenant_id'         => $tenantId,
                'participant_id'    => $enrolledParticipants->get(0)->id,
                'justification'     => 'Emergency home visit — participant unresponsive. Unable to contact primary care physician. Needed medication history and allergy information immediately.',
                'access_granted_at' => now()->subHours(2),
                'access_expires_at' => now()->subHours(2)->addHours(BreakGlassEvent::ACCESS_DURATION_HOURS),
                'ip_address'        => '192.168.1.105',
                'acknowledged_by_supervisor_user_id' => null,
                'acknowledged_at'   => null,
            ]);
            $this->command->line('    <comment>Unreviewed BTG event (today)</comment>');

            // Event 2: Yesterday — unreviewed (shows in red on dashboard)
            BreakGlassEvent::create([
                'user_id'           => $clinicalUser->id,
                'tenant_id'         => $tenantId,
                'participant_id'    => $enrolledParticipants->get(1)->id,
                'justification'     => 'Participant transferred to ER after fall. ER team requested medication list and advance directive status. Day center closed and normal chart access unavailable.',
                'access_granted_at' => now()->subDay()->subHour(),
                'access_expires_at' => now()->subDay()->subHour()->addHours(BreakGlassEvent::ACCESS_DURATION_HOURS),
                'ip_address'        => '192.168.1.107',
                'acknowledged_by_supervisor_user_id' => null,
                'acknowledged_at'   => null,
            ]);
            $this->command->line('    <comment>Unreviewed BTG event (yesterday)</comment>');

            // Event 3: 3 days ago — acknowledged (shows in amber on dashboard)
            $ackUserId = $itAdminUser?->id ?? $clinicalUser->id;
            BreakGlassEvent::create([
                'user_id'           => $clinicalUser->id,
                'tenant_id'         => $tenantId,
                'participant_id'    => $enrolledParticipants->get(0)->id,
                'justification'     => 'Weekend on-call — participant had acute pain episode. Needed to verify current medication orders before contacting on-call physician.',
                'access_granted_at' => now()->subDays(3)->subHours(5),
                'access_expires_at' => now()->subDays(3)->subHour(),
                'ip_address'        => '192.168.1.105',
                'acknowledged_by_supervisor_user_id' => $ackUserId,
                'acknowledged_at'   => now()->subDays(2),
            ]);
            $this->command->line('    <comment>Acknowledged BTG event (3 days ago)</comment>');
        } else {
            $this->command->warn('  W51DataSeeder: Insufficient users/participants for BTG events — skipping.');
        }
    }
}
