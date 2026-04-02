<?php

// ─── ProcessLabResultJob ──────────────────────────────────────────────────────
// Processes an inbound lab result message asynchronously.
//
// Flow:
//   1. Resolve participant by MRN (scoped to tenant)
//   2. Parse payload — extract header fields + component array
//   3. Create LabResult record (emr_lab_results)
//   4. Create LabResultComponent records (emr_lab_result_components) — one per OBX
//   5. Create EncounterLog for billing/tracking (service_type='other')
//   6. If abnormal_flag is true: create alert for primary_care
//      - severity='critical' if any component has critical_low or critical_high
//      - severity='warning' for other abnormal results
//   7. Mark integration_log as processed
//
// Unknown MRN: mark integration_log as failed (graceful — log warning, don't throw)
// Parse failure: gracefully degrade — store result without components, log warning
//
// Expected payload structure (from LabResultConnector / IntegrationController):
//   patient_mrn    — MRN to resolve participant
//   test_name      — Panel/test name
//   test_code      — LOINC or local code (optional)
//   value          — Top-level result value (for single-component labs)
//   unit           — Unit of measure
//   abnormal_flag  — Boolean overall flag
//   result_date    — Date of collection (YYYY-MM-DD)
//   collected_at   — ISO datetime of collection (optional, falls back to result_date)
//   resulted_at    — ISO datetime of result availability (optional)
//   ordering_provider — Provider name (optional)
//   performing_facility — Lab facility name (optional)
//   overall_status — final/preliminary/corrected/cancelled (optional, default: final)
//   components     — Array of OBX segment objects (optional, for panel results)
//     Each component: { name, code?, value, unit?, reference_range?, abnormal_flag? }
//     abnormal_flag values: normal, low, high, critical_low, critical_high, abnormal
//
// Queue: 'integrations' (same as ProcessHl7AdtJob)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\EncounterLog;
use App\Models\IntegrationLog;
use App\Models\LabResult;
use App\Models\LabResultComponent;
use App\Models\Participant;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessLabResultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int   $integrationLogId,
        public readonly array $payload,
        public readonly int   $tenantId,
    ) {
        $this->onQueue('integrations');
    }

    public function handle(AlertService $alertService): void
    {
        $logEntry = IntegrationLog::findOrFail($this->integrationLogId);
        $mrn      = $this->payload['patient_mrn'] ?? '';

        Log::info('[ProcessLabResultJob] Processing lab result', [
            'integration_log_id' => $this->integrationLogId,
            'patient_mrn'        => $mrn,
            'test_code'          => $this->payload['test_code'] ?? null,
            'abnormal_flag'      => $this->payload['abnormal_flag'] ?? false,
        ]);

        // ── 1. Resolve participant ─────────────────────────────────────────────

        $participant = Participant::where('tenant_id', $this->tenantId)
            ->where('mrn', $mrn)
            ->first();

        if (! $participant) {
            Log::warning('[ProcessLabResultJob] Unknown MRN — participant not found', [
                'mrn'       => $mrn,
                'tenant_id' => $this->tenantId,
            ]);
            $logEntry->markFailed("Participant not found for MRN: {$mrn}");
            return;
        }

        $testName    = $this->payload['test_name']  ?? $this->payload['test_code'] ?? 'Lab Test';
        $value       = $this->payload['value']       ?? null;
        $unit        = $this->payload['unit']        ?? '';
        $abnormal    = (bool) ($this->payload['abnormal_flag'] ?? false);
        $resultDate  = $this->payload['result_date'] ?? now()->toDateString();
        $collectedAt = $this->payload['collected_at'] ?? $resultDate;
        $resultedAt  = $this->payload['resulted_at']  ?? null;

        // ── 2. Create structured LabResult record ─────────────────────────────

        $labResult = null;
        $hasCritical = false;

        try {
            DB::transaction(function () use (
                $participant, $testName, $value, $unit, $abnormal,
                $collectedAt, $resultedAt, &$labResult, &$hasCritical
            ) {
                $labResult = LabResult::create([
                    'participant_id'         => $participant->id,
                    'tenant_id'              => $this->tenantId,
                    'integration_log_id'     => $this->integrationLogId,
                    'test_name'              => $testName,
                    'test_code'              => $this->payload['test_code'] ?? null,
                    'collected_at'           => $collectedAt,
                    'resulted_at'            => $resultedAt,
                    'ordering_provider_name' => $this->payload['ordering_provider'] ?? null,
                    'performing_facility'    => $this->payload['performing_facility'] ?? null,
                    'source'                 => 'hl7_inbound',
                    'overall_status'         => $this->payload['overall_status'] ?? 'final',
                    'abnormal_flag'          => $abnormal,
                    'notes'                  => null,
                ]);

                // ── 3. Create component records ───────────────────────────────

                $rawComponents = $this->payload['components'] ?? null;

                if (is_array($rawComponents) && count($rawComponents) > 0) {
                    // Multi-component panel (HL7 OBX segments)
                    foreach ($rawComponents as $comp) {
                        if (empty($comp['name']) || !isset($comp['value'])) {
                            continue;
                        }
                        $compFlag = $this->normalizeAbnormalFlag($comp['abnormal_flag'] ?? null);

                        LabResultComponent::create([
                            'lab_result_id'   => $labResult->id,
                            'component_name'  => $comp['name'],
                            'component_code'  => $comp['code'] ?? null,
                            'value'           => (string) $comp['value'],
                            'unit'            => $comp['unit'] ?? null,
                            'reference_range' => $comp['reference_range'] ?? null,
                            'abnormal_flag'   => $compFlag,
                        ]);

                        if (in_array($compFlag, LabResultComponent::CRITICAL_FLAGS, true)) {
                            $hasCritical = true;
                        }
                    }

                    // Re-evaluate overall abnormal_flag based on components if not already set
                    if (! $abnormal) {
                        $hasAbnormalComp = $labResult->components()
                            ->where('abnormal_flag', '!=', 'normal')
                            ->whereNotNull('abnormal_flag')
                            ->exists();
                        if ($hasAbnormalComp) {
                            $labResult->update(['abnormal_flag' => true]);
                            $abnormal = true;
                        }
                    }

                    if (! $hasCritical) {
                        $hasCritical = $labResult->components()
                            ->whereIn('abnormal_flag', LabResultComponent::CRITICAL_FLAGS)
                            ->exists();
                    }

                } elseif ($value !== null) {
                    // Single-value result — create one component from top-level fields
                    $compFlag = $abnormal ? 'abnormal' : 'normal';

                    LabResultComponent::create([
                        'lab_result_id'   => $labResult->id,
                        'component_name'  => $testName,
                        'component_code'  => $this->payload['test_code'] ?? null,
                        'value'           => (string) $value,
                        'unit'            => $unit ?: null,
                        'reference_range' => $this->payload['reference_range'] ?? null,
                        'abnormal_flag'   => $compFlag,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Graceful degradation: log the failure but continue with billing + alert
            Log::warning('[ProcessLabResultJob] Failed to create structured lab records', [
                'integration_log_id' => $this->integrationLogId,
                'error'              => $e->getMessage(),
            ]);
            // $labResult may be null or partial — only proceed with billing/alerts
        }

        // ── 4. Always create an encounter log for billing/Finance tracking ────

        EncounterLog::create([
            'tenant_id'      => $this->tenantId,
            'participant_id' => $participant->id,
            'service_date'   => $resultDate,
            'service_type'   => 'other',
            'notes'          => "Lab result received: {$testName} = {$value} {$unit}" . ($abnormal ? ' [ABNORMAL]' : ''),
        ]);

        // ── 5. Abnormal flag: create primary_care alert for clinical review ───

        if ($abnormal) {
            // Critical values (life-threatening) get severity=critical; others get warning
            $severity = $hasCritical ? 'critical' : 'warning';

            $alertService->create([
                'tenant_id'          => $this->tenantId,
                'participant_id'     => $participant->id,
                'source_module'      => 'integration',
                'alert_type'         => 'abnormal_lab',
                'title'              => ($hasCritical ? 'Critical' : 'Abnormal') . " Lab Result: {$participant->first_name} {$participant->last_name}",
                'message'            => "{$testName}: {$value} {$unit} — flagged as " . ($hasCritical ? 'CRITICAL' : 'abnormal') . ". Review required.",
                'severity'           => $severity,
                'target_departments' => ['primary_care'],
                'created_by_system'  => true,
                'metadata'           => $labResult ? ['lab_result_id' => $labResult->id] : null,
            ]);
        }

        // ── 6. Audit log ──────────────────────────────────────────────────────

        AuditLog::record(
            action:       'integration.lab.result',
            resourceType: 'Participant',
            resourceId:   $participant->id,
            tenantId:     $this->tenantId,
            newValues:    [
                'test_code'      => $this->payload['test_code'] ?? null,
                'test_name'      => $testName,
                'abnormal_flag'  => $abnormal,
                'is_critical'    => $hasCritical,
                'lab_result_id'  => $labResult?->id,
            ],
        );

        $logEntry->markProcessed();
    }

    /**
     * Normalize an incoming abnormal_flag string to a valid DB enum value or null.
     * Handles HL7 interpretation codes like 'H', 'L', 'HH', 'LL', 'N' and our own enum values.
     */
    private function normalizeAbnormalFlag(mixed $flag): ?string
    {
        if ($flag === null || $flag === '') {
            return null;
        }

        $map = [
            'N'   => 'normal',
            'H'   => 'high',
            'L'   => 'low',
            'HH'  => 'critical_high',
            'LL'  => 'critical_low',
            'A'   => 'abnormal',
            // Already-normalized values pass through
            'normal'       => 'normal',
            'high'         => 'high',
            'low'          => 'low',
            'critical_high'=> 'critical_high',
            'critical_low' => 'critical_low',
            'abnormal'     => 'abnormal',
        ];

        return $map[(string) $flag] ?? 'abnormal';
    }
}
