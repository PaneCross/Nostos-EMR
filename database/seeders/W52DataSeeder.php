<?php

// ─── W52DataSeeder ─────────────────────────────────────────────────────────
// Demo data for W5-2: Lab Results Viewer.
//
// Lab results seeded per enrolled participant (first 10 participants):
//   - 5-8 lab results per participant (mix of panels)
//   - ~20% abnormal results (warning severity alerts)
//   - ~5% critical results (critical_low / critical_high components)
//   - Some results reviewed, some unreviewed
//   - Mix of HL7-sourced and manual_entry sources
//
// Called from DemoEnvironmentSeeder after W51DataSeeder.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\LabResult;
use App\Models\LabResultComponent;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Database\Seeder;

class W52DataSeeder extends Seeder
{
    // Common lab panels with representative components
    private array $panels = [
        [
            'test_name' => 'CBC with Differential',
            'test_code' => '58410-2',
            'components' => [
                ['name' => 'WBC',        'code' => '6690-2',  'unit' => 'K/uL',  'ref' => '4.5-11.0'],
                ['name' => 'RBC',        'code' => '789-8',   'unit' => 'M/uL',  'ref' => '4.2-5.4'],
                ['name' => 'Hemoglobin', 'code' => '718-7',   'unit' => 'g/dL',  'ref' => '12.0-16.0'],
                ['name' => 'Hematocrit', 'code' => '4544-3',  'unit' => '%',     'ref' => '37.0-47.0'],
                ['name' => 'Platelets',  'code' => '777-3',   'unit' => 'K/uL',  'ref' => '150-400'],
            ],
        ],
        [
            'test_name' => 'Comprehensive Metabolic Panel',
            'test_code' => '24323-8',
            'components' => [
                ['name' => 'Sodium',     'code' => '2951-2', 'unit' => 'mEq/L', 'ref' => '136-145'],
                ['name' => 'Potassium',  'code' => '2823-3', 'unit' => 'mEq/L', 'ref' => '3.5-5.1'],
                ['name' => 'Glucose',    'code' => '2345-7', 'unit' => 'mg/dL', 'ref' => '70-100'],
                ['name' => 'Creatinine', 'code' => '2160-0', 'unit' => 'mg/dL', 'ref' => '0.6-1.3'],
                ['name' => 'BUN',        'code' => '3094-0', 'unit' => 'mg/dL', 'ref' => '7-25'],
                ['name' => 'ALT',        'code' => '1742-6', 'unit' => 'U/L',   'ref' => '7-56'],
            ],
        ],
        [
            'test_name' => 'Hemoglobin A1c',
            'test_code' => '4548-4',
            'components' => [
                ['name' => 'HbA1c', 'code' => '4548-4', 'unit' => '%', 'ref' => '<5.7'],
            ],
        ],
        [
            'test_name' => 'Lipid Panel',
            'test_code' => '57698-3',
            'components' => [
                ['name' => 'Total Cholesterol', 'code' => '2093-3', 'unit' => 'mg/dL', 'ref' => '<200'],
                ['name' => 'LDL',               'code' => '2089-1', 'unit' => 'mg/dL', 'ref' => '<100'],
                ['name' => 'HDL',               'code' => '2085-9', 'unit' => 'mg/dL', 'ref' => '>40'],
                ['name' => 'Triglycerides',     'code' => '2571-8', 'unit' => 'mg/dL', 'ref' => '<150'],
            ],
        ],
        [
            'test_name' => 'TSH',
            'test_code' => '3016-3',
            'components' => [
                ['name' => 'TSH', 'code' => '3016-3', 'unit' => 'mIU/L', 'ref' => '0.4-4.0'],
            ],
        ],
        [
            'test_name' => 'PT/INR',
            'test_code' => '5902-2',
            'components' => [
                ['name' => 'PT',  'code' => '5902-2', 'unit' => 'seconds', 'ref' => '11.0-13.5'],
                ['name' => 'INR', 'code' => '6301-6', 'unit' => 'ratio',   'ref' => '0.8-1.1 (therapeutic 2.0-3.0)'],
            ],
        ],
        [
            'test_name' => 'Vitamin D, 25-Hydroxy',
            'test_code' => '1989-3',
            'components' => [
                ['name' => 'Vitamin D (25-OH)', 'code' => '1989-3', 'unit' => 'ng/mL', 'ref' => '30-100'],
            ],
        ],
    ];

    public function run(): void
    {
        // ── Resolve tenant context ─────────────────────────���───────────────────

        $participants = Participant::where('enrollment_status', 'enrolled')
            ->with('site')
            ->limit(10)
            ->get();

        if ($participants->isEmpty()) {
            $this->command->warn('  W52DataSeeder: No enrolled participants found — skipping lab result seed.');
            return;
        }

        $tenantId = $participants->first()->tenant_id;

        $reviewer = User::where('tenant_id', $tenantId)
            ->where('department', 'primary_care')
            ->first();

        if (! $reviewer) {
            $this->command->warn('  W52DataSeeder: No primary_care user found — skipping lab result seed.');
            return;
        }

        $this->command->line('  Lab results:');
        $totalLabs = 0;

        foreach ($participants as $idx => $participant) {
            $labCount = rand(5, 8);

            for ($i = 0; $i < $labCount; $i++) {
                $panel       = $this->panels[array_rand($this->panels)];
                $isAbnormal  = (rand(1, 100) <= 20);  // ~20% abnormal
                $isCritical  = $isAbnormal && (rand(1, 100) <= 25); // ~5% overall critical
                $isReviewed  = $isAbnormal ? (rand(1, 100) <= 60) : (rand(1, 100) <= 85);
                $collectedAt = now()->subDays(rand(1, 180))->subHours(rand(0, 12));
                $source      = (rand(1, 100) <= 60) ? 'hl7_inbound' : 'manual_entry';

                $lab = LabResult::create([
                    'participant_id'         => $participant->id,
                    'tenant_id'              => $tenantId,
                    'integration_log_id'     => null,
                    'test_name'              => $panel['test_name'],
                    'test_code'              => $panel['test_code'],
                    'collected_at'           => $collectedAt,
                    'resulted_at'            => $collectedAt->copy()->addHours(rand(2, 24)),
                    'ordering_provider_name' => 'Dr. ' . fake()->lastName(),
                    'performing_facility'    => fake()->randomElement([
                        'Sunrise PACE Laboratory', 'Quest Diagnostics', 'LabCorp',
                    ]),
                    'source'                 => $source,
                    'overall_status'         => 'final',
                    'abnormal_flag'          => $isAbnormal,
                    'reviewed_by_user_id'    => ($isAbnormal && $isReviewed) ? $reviewer->id : null,
                    'reviewed_at'            => ($isAbnormal && $isReviewed) ? $collectedAt->copy()->addDays(rand(1, 3)) : null,
                    'notes'                  => null,
                ]);

                // Seed components
                $componentMeta = $panel['components'];
                foreach ($componentMeta as $compIdx => $compDef) {
                    // Determine flag for this component
                    $flag = 'normal';
                    if ($isCritical && $compIdx === 0) {
                        $flag = fake()->randomElement(['critical_low', 'critical_high']);
                    } elseif ($isAbnormal && $compIdx === 0) {
                        $flag = fake()->randomElement(['low', 'high', 'abnormal']);
                    }

                    // Generate a plausible value
                    $value = $this->generateValue($compDef['name'], $flag);

                    LabResultComponent::create([
                        'lab_result_id'   => $lab->id,
                        'component_name'  => $compDef['name'],
                        'component_code'  => $compDef['code'],
                        'value'           => $value,
                        'unit'            => $compDef['unit'],
                        'reference_range' => $compDef['ref'],
                        'abnormal_flag'   => $flag,
                    ]);
                }

                $totalLabs++;
            }
        }

        $this->command->line("    - {$totalLabs} lab results seeded across {$participants->count()} participants");
        $abnormalCount = LabResult::where('tenant_id', $tenantId)->where('abnormal_flag', true)->count();
        $unreviewedCount = LabResult::where('tenant_id', $tenantId)->where('abnormal_flag', true)->whereNull('reviewed_at')->count();
        $this->command->line("    - {$abnormalCount} abnormal, {$unreviewedCount} unreviewed");
    }

    /** Generate a plausible numeric value for a given component and flag. */
    private function generateValue(string $name, string $flag): string
    {
        // Map component names to plausible value ranges
        $ranges = [
            'Hemoglobin'      => ['normal' => [12.5, 15.5], 'low' => [8.0, 11.9],    'critical_low' => [6.0, 7.9]],
            'WBC'             => ['normal' => [5.0, 10.0],  'high' => [12.0, 18.0],  'critical_high'=> [20.0, 30.0]],
            'Platelets'       => ['normal' => [180, 380],   'low'  => [80, 149],      'critical_low' => [20, 49]],
            'Potassium'       => ['normal' => [3.7, 5.0],   'low'  => [3.0, 3.4],    'critical_low' => [2.5, 2.9]],
            'Sodium'          => ['normal' => [137, 144],   'low'  => [130, 135],    'critical_low' => [118, 129]],
            'Glucose'         => ['normal' => [75, 99],     'high' => [150, 250],    'critical_high'=> [400, 600]],
            'Creatinine'      => ['normal' => [0.7, 1.2],   'high' => [1.5, 3.0],   'critical_high'=> [4.0, 7.0]],
            'INR'             => ['normal' => [1.0, 1.1],   'high' => [2.0, 3.5],   'critical_high'=> [5.0, 8.0]],
            'HbA1c'           => ['normal' => [5.0, 5.6],   'high' => [7.0, 9.0],   'critical_high'=> [10.0, 14.0]],
            'Total Cholesterol'=> ['normal' => [160, 199],  'high' => [201, 280]],
            'LDL'             => ['normal' => [80, 99],     'high' => [101, 190]],
            'TSH'             => ['normal' => [0.5, 3.8],   'low'  => [0.1, 0.39],  'high' => [4.5, 8.0]],
            'Vitamin D (25-OH)' => ['normal' => [32, 80],   'low'  => [12, 29],     'critical_low' => [5, 11]],
        ];

        $def = $ranges[$name] ?? null;
        if (! $def) {
            return (string) round(fake()->randomFloat(1, 0.5, 100.0), 1);
        }

        $useFlag = $flag === 'critical_low'  ? ($def['critical_low']  ?? $def['low']  ?? $def['normal']) : null;
        $useFlag ??= $flag === 'critical_high' ? ($def['critical_high'] ?? $def['high'] ?? $def['normal']) : null;
        $useFlag ??= $def[$flag] ?? $def['normal'];

        [$min, $max] = $useFlag;
        $val = $min + ($max - $min) * (mt_rand(0, 1000) / 1000.0);
        return (string) round($val, in_array($name, ['Platelets', 'Glucose', 'Sodium', 'BUN', 'Total Cholesterol', 'LDL', 'WBC', 'RBC'], true) ? 0 : 1);
    }
}
