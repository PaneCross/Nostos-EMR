<?php

// ─── ProcessLabResultJob ──────────────────────────────────────────────────────
// Processes an inbound lab result message asynchronously.
//
// Flow:
//   1. Resolve participant by MRN (scoped to tenant)
//   2. Create EncounterLog for billing/tracking (service_type='other')
//   3. If abnormal_flag is true: create high-priority alert for primary_care
//   4. Mark integration_log as processed
//
// Unknown MRN: mark integration_log as failed (graceful — log warning, don't throw)
//
// Queue: 'integrations' (same as ProcessHl7AdtJob)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\EncounterLog;
use App\Models\IntegrationLog;
use App\Models\Participant;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

        // Resolve participant by MRN
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

        // Always create an encounter log for billing/Finance tracking
        EncounterLog::create([
            'tenant_id'      => $this->tenantId,
            'participant_id' => $participant->id,
            'service_date'   => $resultDate,
            'service_type'   => 'other',
            'notes'          => "Lab result received: {$testName} = {$value} {$unit}" . ($abnormal ? ' [ABNORMAL]' : ''),
        ]);

        // Abnormal flag: create primary_care alert for clinical review
        if ($abnormal) {
            $alertService->create([
                'tenant_id'          => $this->tenantId,
                'participant_id'     => $participant->id,
                'source_module'      => 'integration',
                'alert_type'         => 'abnormal_lab',
                'title'              => "Abnormal Lab Result: {$participant->full_name}",
                'message'            => "{$testName}: {$value} {$unit} — flagged as abnormal. Review required.",
                'severity'           => 'warning', // valid values: info, warning, critical
                'target_departments' => ['primary_care'],
                'created_by_system'  => true,
            ]);
        }

        AuditLog::record(
            action:       'integration.lab.result',
            resourceType: 'Participant',
            resourceId:   $participant->id,
            tenantId:     $this->tenantId,
            newValues:    [
                'test_code'     => $this->payload['test_code'] ?? null,
                'test_name'     => $testName,
                'abnormal_flag' => $abnormal,
            ],
        );

        $logEntry->markProcessed();
    }
}
