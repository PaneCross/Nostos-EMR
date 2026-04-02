<?php

// ─── DiagnosticReportMapper ───────────────────────────────────────────────────
// Maps a NostosEMR IntegrationLog (lab_results connector) to a FHIR R4
// DiagnosticReport resource.
//
// FHIR R4 spec: https://hl7.org/fhir/R4/diagnosticreport.html
//
// Source: emr_integration_log rows where connector_type='lab_results' and
//         status='processed'. Lab results are treated as final once stored.
//
// Expected raw_payload structure (from ProcessLabResultJob / LabResultConnector):
//   patient_mrn    — MRN to resolve participant identity
//   test_name      — Human-readable test name (e.g. "CBC with Differential")
//   result_value   — Numeric or string result (e.g. "12.5")
//   result_unit    — Unit of measure (e.g. "g/dL")
//   abnormal_flag  — Boolean; true if result is outside reference range
//   collected_at   — ISO datetime when specimen was collected
//
// Conclusion format: "{result_value} {result_unit} [ABNORMAL]"
//
// W4-9 — GAP-13: FHIR R4 DiagnosticReport resource.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Fhir\Mappers;

use App\Models\IntegrationLog;
use Carbon\Carbon;

class DiagnosticReportMapper
{
    /**
     * Map an IntegrationLog lab result to a FHIR R4 DiagnosticReport resource.
     *
     * @param  IntegrationLog  $log            The processed lab result integration log entry
     * @param  int             $participantId  Resolved participant ID (from MRN lookup)
     */
    public static function toFhir(IntegrationLog $log, int $participantId): array
    {
        $payload  = $log->raw_payload ?? [];
        $abnormal = (bool) ($payload['abnormal_flag'] ?? false);

        // Conclusion: value + unit + ABNORMAL marker if flagged
        $conclusionParts = array_filter([
            $payload['result_value'] ?? null,
            $payload['result_unit']  ?? null,
            $abnormal ? 'ABNORMAL' : null,
        ]);
        $conclusion = $conclusionParts ? implode(' ', $conclusionParts) : null;

        return [
            'resourceType' => 'DiagnosticReport',
            'id'           => (string) $log->id,

            // All stored lab results are final — they are only written after
            // ProcessLabResultJob completes successfully.
            'status' => 'final',

            // ── Category ──────────────────────────────────────────────────────
            'category' => [
                [
                    'coding' => [
                        [
                            'system'  => 'http://terminology.hl7.org/CodeSystem/v2-0074',
                            'code'    => 'LAB',
                            'display' => 'Laboratory',
                        ],
                    ],
                ],
            ],

            // ── Code (test name) ──────────────────────────────────────────────
            'code' => [
                'coding' => [
                    ['display' => $payload['test_name'] ?? 'Lab Result'],
                ],
                'text' => $payload['test_name'] ?? 'Lab Result',
            ],

            // ── Subject ───────────────────────────────────────────────────────
            'subject' => ['reference' => "Patient/{$participantId}"],

            // ── Effective date (specimen collection) ──────────────────────────
            'effectiveDateTime' => isset($payload['collected_at'])
                ? Carbon::parse($payload['collected_at'])->toIso8601String()
                : null,

            // ── Issued date (when result was logged) ──────────────────────────
            'issued' => $log->created_at
                ? Carbon::parse($log->created_at)->toIso8601String()
                : null,

            // ── Conclusion ────────────────────────────────────────────────────
            'conclusion' => $conclusion,
        ];
    }
}
