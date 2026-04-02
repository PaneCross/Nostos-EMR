<?php

// ─── ClinicalOrdersSeeder ─────────────────────────────────────────────────────
// W4-7: Seeds demo clinical orders for the CPOE feature.
// Creates 2-4 orders per enrolled participant (mix of lab, therapy, consult)
// plus 1 stat order and 1 urgent order for the first 3 participants
// to demonstrate the stat/urgent alert workflow.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\ClinicalOrder;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class ClinicalOrdersSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->first();
        if (!$tenant) return;

        $prescriber = User::where('tenant_id', $tenant->id)
            ->where('department', 'primary_care')
            ->where('role', 'admin')
            ->first();
        if (!$prescriber) return;

        $participants = Participant::where('tenant_id', $tenant->id)
            ->where('enrollment_status', 'enrolled')
            ->take(30)
            ->get();

        $routineOrders = [
            ['order_type' => 'lab',         'priority' => 'routine', 'instructions' => 'Comprehensive metabolic panel. Fasting specimen preferred.', 'clinical_indication' => 'Annual metabolic monitoring.'],
            ['order_type' => 'therapy_pt',   'priority' => 'routine', 'instructions' => 'PT evaluation and treatment for balance and gait training. 2x/week x 6 weeks.', 'clinical_indication' => 'Fall risk reduction.'],
            ['order_type' => 'therapy_ot',   'priority' => 'routine', 'instructions' => 'OT evaluation for ADL retraining and home safety assessment.', 'clinical_indication' => 'Decreased ADL independence.'],
            ['order_type' => 'consult',      'priority' => 'routine', 'instructions' => 'Cardiology consult requested for CHF management optimization.', 'clinical_indication' => 'Worsening peripheral edema.'],
            ['order_type' => 'imaging',      'priority' => 'routine', 'instructions' => 'Chest X-ray PA and lateral.', 'clinical_indication' => 'New onset dyspnea, r/o pneumonia.'],
            ['order_type' => 'dme',          'priority' => 'routine', 'instructions' => 'Standard rollator walker. Participant has Medicare Part B.', 'clinical_indication' => 'Gait instability, fall risk.'],
            ['order_type' => 'home_health',  'priority' => 'routine', 'instructions' => 'Home health aide 3x/week for personal care and medication management support.', 'clinical_indication' => 'Declining functional independence.'],
            ['order_type' => 'medication_change', 'priority' => 'routine', 'instructions' => 'Increase metoprolol succinate to 50mg daily. Monitor HR at next visit.', 'clinical_indication' => 'Persistent hypertension.'],
        ];

        foreach ($participants as $i => $participant) {
            $numOrders = rand(2, 4);
            $shuffled  = collect($routineOrders)->shuffle()->take($numOrders);

            foreach ($shuffled as $orderData) {
                $targetDept = ClinicalOrder::DEPARTMENT_ROUTING[$orderData['order_type']] ?? 'primary_care';
                $status     = $this->randomStatus();

                $data = [
                    'participant_id'      => $participant->id,
                    'tenant_id'           => $tenant->id,
                    'site_id'             => $participant->site_id,
                    'ordered_by_user_id'  => $prescriber->id,
                    'ordered_at'          => now()->subDays(rand(1, 30))->subHours(rand(0, 23)),
                    'order_type'          => $orderData['order_type'],
                    'priority'            => $orderData['priority'],
                    'status'              => $status,
                    'instructions'        => $orderData['instructions'],
                    'clinical_indication' => $orderData['clinical_indication'],
                    'target_department'   => $targetDept,
                    'due_date'            => now()->addDays(rand(3, 14))->format('Y-m-d'),
                ];

                if ($status === 'acknowledged' || $status === 'in_progress') {
                    $data['acknowledged_at']         = now()->subDays(rand(0, 2));
                    $data['acknowledged_by_user_id'] = $prescriber->id;
                }
                if ($status === 'completed') {
                    $data['acknowledged_at']         = now()->subDays(3);
                    $data['acknowledged_by_user_id'] = $prescriber->id;
                    $data['completed_at']            = now()->subDays(rand(1, 2));
                    $data['result_summary']          = 'Order completed. Results reviewed and documented.';
                }

                ClinicalOrder::create($data);
            }

            // First 3 participants get 1 stat + 1 urgent order for demo purposes
            if ($i < 3) {
                ClinicalOrder::create([
                    'participant_id'      => $participant->id,
                    'tenant_id'           => $tenant->id,
                    'site_id'             => $participant->site_id,
                    'ordered_by_user_id'  => $prescriber->id,
                    'ordered_at'          => now()->subHours(rand(1, 3)),
                    'order_type'          => 'lab',
                    'priority'            => 'stat',
                    'status'              => 'pending',
                    'instructions'        => 'STAT BMP - participant reports severe weakness and confusion. Evaluate for metabolic emergency.',
                    'clinical_indication' => 'Acute onset confusion, r/o metabolic emergency.',
                    'target_department'   => 'primary_care',
                ]);

                ClinicalOrder::create([
                    'participant_id'      => $participant->id,
                    'tenant_id'           => $tenant->id,
                    'site_id'             => $participant->site_id,
                    'ordered_by_user_id'  => $prescriber->id,
                    'ordered_at'          => now()->subHours(rand(4, 8)),
                    'order_type'          => 'imaging',
                    'priority'            => 'urgent',
                    'status'              => 'acknowledged',
                    'instructions'        => 'Urgent chest X-ray. Participant reports worsening shortness of breath.',
                    'clinical_indication' => 'Acute dyspnea, r/o CHF exacerbation.',
                    'target_department'   => 'primary_care',
                    'acknowledged_at'     => now()->subHours(2),
                    'acknowledged_by_user_id' => $prescriber->id,
                ]);
            }
        }

        $this->command?->line('  Clinical orders seeded for ' . count($participants) . ' participants.');
    }

    private function randomStatus(): string
    {
        $weights = ['pending' => 40, 'acknowledged' => 25, 'in_progress' => 20, 'completed' => 15];
        $rand = rand(1, 100);
        $cumulative = 0;
        foreach ($weights as $status => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) return $status;
        }
        return 'pending';
    }
}
