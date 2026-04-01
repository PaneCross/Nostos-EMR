<?php

// ─── W46DataSeeder ────────────────────────────────────────────────────────────
// Seeds demo data for Wave 4, Phase 6 (Incident Regulatory Tracking + QAPI).
//
// Creates:
//   1. 2 QAPI projects for the demo tenant — one active Safety domain project
//      and one active Clinical Outcomes domain project. Both count toward the
//      CMS minimum of 2 active projects (42 CFR §460.136).
//   2. 1 pending SignificantChangeEvent from a recent hospitalization, due
//      in 18 days — demonstrates the IDT significant change widget.
//   3. 1 overdue SignificantChangeEvent from a fall with injury — demonstrates
//      the overdue alert state.
//
// Called from DemoEnvironmentSeeder after W42DataSeeder.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Participant;
use App\Models\QapiProject;
use App\Models\SignificantChangeEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class W46DataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->first();
        if (! $tenant) {
            $this->command->warn('  W46DataSeeder: Demo tenant not found — skipping.');
            return;
        }

        $qaUser = User::where('tenant_id', $tenant->id)
            ->where('department', 'qa_compliance')
            ->first();

        // ── 1. QAPI Projects ─────────────────────────────────────────────────
        // Two active projects satisfies CMS minimum per 42 CFR §460.136.
        if (QapiProject::where('tenant_id', $tenant->id)->count() === 0) {

            QapiProject::create([
                'tenant_id'              => $tenant->id,
                'title'                  => 'Fall Prevention Quality Improvement Initiative',
                'description'            => 'Systematic review and improvement of fall prevention protocols across all PACE sites to reduce fall rate among high-risk participants.',
                'aim_statement'          => 'Reduce participant fall rate from 42% to below 30% within 6 months through targeted interventions.',
                'domain'                 => 'safety',
                'status'                 => 'active',
                'start_date'             => now()->subMonths(2)->toDateString(),
                'target_completion_date' => now()->addMonths(4)->toDateString(),
                'actual_completion_date' => null,
                'baseline_metric'        => '42% of participants experienced at least one fall in Q3.',
                'target_metric'          => 'Reduce fall rate to <30% by end of project.',
                'current_metric'         => '38% fall rate as of last month — improvement noted.',
                'interventions'          => "1. Standardized Morse Fall Risk assessment on enrollment and at each IDT meeting\n2. Non-slip footwear provision for all high-risk participants (Morse score ≥45)\n3. Environmental safety audit of day center walkways\n4. Staff education refresher on fall prevention protocols\n5. Home safety evaluation and modification recommendations",
                'findings'               => null,
                'project_lead_user_id'   => $qaUser?->id,
                'team_member_ids'        => [],
                'created_by_user_id'     => $qaUser?->id,
            ]);

            QapiProject::create([
                'tenant_id'              => $tenant->id,
                'title'                  => 'Medication Reconciliation Process Improvement',
                'description'            => 'Improving the accuracy and timeliness of medication reconciliation for newly enrolled participants and post-hospitalization transitions.',
                'aim_statement'          => 'Achieve 100% timely medication reconciliation (within 72h of enrollment or hospital discharge) by end of year.',
                'domain'                 => 'clinical_outcomes',
                'status'                 => 'active',
                'start_date'             => now()->subWeeks(6)->toDateString(),
                'target_completion_date' => now()->addMonths(6)->toDateString(),
                'actual_completion_date' => null,
                'baseline_metric'        => 'Baseline: 68% reconciliation completed within 72h of enrollment or discharge.',
                'target_metric'          => '100% timely reconciliation within 72h.',
                'current_metric'         => null,
                'interventions'          => "1. Pharmacy team assigned to all new enrollments and hospital discharges\n2. EMR alert fired at 48h if reconciliation not yet started\n3. IDT care plan includes medication reconciliation status as standing agenda item\n4. Provider sign-off workflow streamlined (24h turnaround goal)",
                'findings'               => null,
                'project_lead_user_id'   => $qaUser?->id,
                'team_member_ids'        => [],
                'created_by_user_id'     => $qaUser?->id,
            ]);

            $this->command->line("  W4-6: Created 2 active QAPI projects.");
        }

        // ── 2. Significant Change Events ─────────────────────────────────────
        // Seed demo SCEs to make the IDT dashboard widget visible.
        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->inRandomOrder()
            ->limit(4)
            ->get();

        if ($participants->count() >= 2 && SignificantChangeEvent::where('tenant_id', $tenant->id)->count() === 0) {

            // Pending (due in 18 days) — hospitalization via ADT
            SignificantChangeEvent::create([
                'tenant_id'          => $tenant->id,
                'participant_id'     => $participants->get(0)->id,
                'trigger_type'       => 'hospitalization',
                'trigger_date'       => now()->subDays(12)->toDateString(),
                'trigger_source'     => 'adt_connector',
                'idt_review_due_date'=> now()->addDays(18)->toDateString(),
                'status'             => 'pending',
                'notes'              => 'Admitted to General Hospital for pneumonia. ADT A01 received.',
                'created_by_user_id' => null,
            ]);

            // Overdue (due 5 days ago) — fall with injury
            SignificantChangeEvent::create([
                'tenant_id'          => $tenant->id,
                'participant_id'     => $participants->get(1)->id,
                'trigger_type'       => 'fall_with_injury',
                'trigger_date'       => now()->subDays(35)->toDateString(),
                'trigger_source'     => 'incident_service',
                'idt_review_due_date'=> now()->subDays(5)->toDateString(),
                'status'             => 'pending',
                'notes'              => 'Fall with laceration to right forearm. IDT reassessment overdue.',
                'created_by_user_id' => null,
            ]);

            $this->command->line("  W4-6: Created 2 significant change events (1 pending, 1 overdue).");
        }
    }
}
