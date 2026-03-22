<?php

// ─── FhirController ───────────────────────────────────────────────────────────
// Serves FHIR R4 resources for external EHR/HIE integration.
//
// Authentication: FhirAuthMiddleware (Bearer token + per-route scope check).
// Tenant isolation: all queries are scoped to fhir_tenant_id from the token.
// Cross-tenant access returns 404 (not 403) per FHIR conventions to avoid
// information leakage about whether a resource exists in another tenant.
//
// All reads are logged to shared_audit_logs with source_type='fhir_api'.
// Responses use Content-Type: application/fhir+json per FHIR R4 specification.
//
// Route list (all under /fhir/R4):
//   GET /Patient/{id}           → patient()              scope: patient.read
//   GET /Observation            → observations()         scope: observation.read
//   GET /MedicationRequest      → medicationRequests()   scope: medication.read
//   GET /Condition              → conditions()           scope: condition.read
//   GET /AllergyIntolerance     → allergyIntolerances()  scope: allergy.read
//   GET /CarePlan               → carePlans()            scope: careplan.read
//   GET /Appointment            → appointments()         scope: appointment.read
//   GET /Immunization           → immunizations()        scope: immunization.read   (Phase 11B)
//   GET /Procedure              → procedures()           scope: procedure.read      (Phase 11B)
//
// All search endpoints require ?patient={participantId} query param.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Fhir\Mappers\AllergyIntoleranceMapper;
use App\Fhir\Mappers\AppointmentMapper;
use App\Fhir\Mappers\CarePlanMapper;
use App\Fhir\Mappers\ConditionMapper;
use App\Fhir\Mappers\ImmunizationMapper;
use App\Fhir\Mappers\MedicationRequestMapper;
use App\Fhir\Mappers\ObservationMapper;
use App\Fhir\Mappers\PatientMapper;
use App\Fhir\Mappers\ProcedureMapper;
use App\Fhir\Mappers\SdohObservationMapper;
use App\Models\Allergy;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\CarePlan;
use App\Models\Immunization;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Procedure;
use App\Models\SocialDeterminant;
use App\Models\Vital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FhirController extends Controller
{
    // ── Patient ───────────────────────────────────────────────────────────────

    /**
     * Return a single FHIR Patient resource by participant ID.
     * Scope: patient.read
     *
     * GET /fhir/R4/Patient/{id}
     */
    public function patient(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->attributes->get('fhir_tenant_id');

        $participant = Participant::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        // 404 on cross-tenant access (FHIR convention: do not reveal resource existence)
        if (! $participant) {
            return $this->fhirNotFound("Patient/{$id}");
        }

        $this->logFhirRead($request, 'Patient', $id, $tenantId);

        return $this->fhirResponse(PatientMapper::toFhir($participant));
    }

    // ── Observation (Vitals) ──────────────────────────────────────────────────

    /**
     * Return a FHIR Bundle of Observation resources for a participant's vitals.
     * Scope: observation.read
     * Required: ?patient={participantId}
     * Optional: ?category=vital-signs (ignored — always vital-signs for this system)
     *
     * GET /fhir/R4/Observation?patient={id}
     */
    public function observations(Request $request): JsonResponse
    {
        $tenantId      = $request->attributes->get('fhir_tenant_id');
        $participantId = (int) $request->query('patient');

        if (! $participantId || ! $this->participantBelongsToTenant($participantId, $tenantId)) {
            return ! $participantId
                ? $this->fhirError(400, 'Required search parameter: patient')
                : $this->fhirNotFound("Patient/{$participantId}");
        }

        $vitals = Vital::where('participant_id', $participantId)
            ->orderBy('recorded_at', 'desc')
            ->limit(200)
            ->get();

        // Each Vital row may produce multiple Observation resources (one per measurement)
        $observations = [];
        foreach ($vitals as $vital) {
            foreach (ObservationMapper::toFhirCollection($vital) as $obs) {
                $observations[] = ['resource' => $obs];
            }
        }

        $this->logFhirRead($request, 'Observation', $participantId, $tenantId, count($observations));

        return $this->fhirBundle('searchset', $observations);
    }

    // ── MedicationRequest ─────────────────────────────────────────────────────

    /**
     * Return a FHIR Bundle of MedicationRequest resources for active medications.
     * Scope: medication.read
     * Required: ?patient={participantId}
     * Optional: ?status=active (only active returned by default)
     *
     * GET /fhir/R4/MedicationRequest?patient={id}
     */
    public function medicationRequests(Request $request): JsonResponse
    {
        $tenantId      = $request->attributes->get('fhir_tenant_id');
        $participantId = (int) $request->query('patient');

        if (! $participantId || ! $this->participantBelongsToTenant($participantId, $tenantId)) {
            return ! $participantId
                ? $this->fhirError(400, 'Required search parameter: patient')
                : $this->fhirNotFound("Patient/{$participantId}");
        }

        $query = Medication::where('participant_id', $participantId)
            ->where('tenant_id', $tenantId)
            ->orderBy('prescribed_date', 'desc');

        // Default to active only; ?status=all returns everything
        if ($request->query('status') !== 'all') {
            $query->where('status', '!=', 'discontinued');
        }

        $meds = $query->get();

        $entries = $meds->map(fn ($med) => ['resource' => MedicationRequestMapper::toFhir($med)])->values()->all();

        $this->logFhirRead($request, 'MedicationRequest', $participantId, $tenantId, count($entries));

        return $this->fhirBundle('searchset', $entries);
    }

    // ── Condition (Problem List) ──────────────────────────────────────────────

    /**
     * Return a FHIR Bundle of Condition resources from the problem list.
     * Scope: condition.read
     * Required: ?patient={participantId}
     *
     * GET /fhir/R4/Condition?patient={id}
     */
    public function conditions(Request $request): JsonResponse
    {
        $tenantId      = $request->attributes->get('fhir_tenant_id');
        $participantId = (int) $request->query('patient');

        if (! $participantId || ! $this->participantBelongsToTenant($participantId, $tenantId)) {
            return ! $participantId
                ? $this->fhirError(400, 'Required search parameter: patient')
                : $this->fhirNotFound("Patient/{$participantId}");
        }

        $problems = Problem::where('participant_id', $participantId)
            ->where('tenant_id', $tenantId)
            ->orderBy('onset_date', 'desc')
            ->get();

        $entries = $problems->map(fn ($p) => ['resource' => ConditionMapper::toFhir($p)])->values()->all();

        $this->logFhirRead($request, 'Condition', $participantId, $tenantId, count($entries));

        return $this->fhirBundle('searchset', $entries);
    }

    // ── AllergyIntolerance ────────────────────────────────────────────────────

    /**
     * Return a FHIR Bundle of AllergyIntolerance resources.
     * Scope: allergy.read
     * Required: ?patient={participantId}
     *
     * GET /fhir/R4/AllergyIntolerance?patient={id}
     */
    public function allergyIntolerances(Request $request): JsonResponse
    {
        $tenantId      = $request->attributes->get('fhir_tenant_id');
        $participantId = (int) $request->query('patient');

        if (! $participantId || ! $this->participantBelongsToTenant($participantId, $tenantId)) {
            return ! $participantId
                ? $this->fhirError(400, 'Required search parameter: patient')
                : $this->fhirNotFound("Patient/{$participantId}");
        }

        $allergies = Allergy::where('participant_id', $participantId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('severity', 'desc')
            ->get();

        $entries = $allergies->map(fn ($a) => ['resource' => AllergyIntoleranceMapper::toFhir($a)])->values()->all();

        $this->logFhirRead($request, 'AllergyIntolerance', $participantId, $tenantId, count($entries));

        return $this->fhirBundle('searchset', $entries);
    }

    // ── CarePlan ──────────────────────────────────────────────────────────────

    /**
     * Return a FHIR Bundle of CarePlan resources (active/draft, not archived).
     * Scope: careplan.read
     * Required: ?patient={participantId}
     *
     * GET /fhir/R4/CarePlan?patient={id}
     */
    public function carePlans(Request $request): JsonResponse
    {
        $tenantId      = $request->attributes->get('fhir_tenant_id');
        $participantId = (int) $request->query('patient');

        if (! $participantId || ! $this->participantBelongsToTenant($participantId, $tenantId)) {
            return ! $participantId
                ? $this->fhirError(400, 'Required search parameter: patient')
                : $this->fhirNotFound("Patient/{$participantId}");
        }

        $carePlans = CarePlan::where('participant_id', $participantId)
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['archived'])
            ->with('goals')
            ->orderBy('effective_date', 'desc')
            ->get();

        $entries = $carePlans->map(fn ($cp) => ['resource' => CarePlanMapper::toFhir($cp)])->values()->all();

        $this->logFhirRead($request, 'CarePlan', $participantId, $tenantId, count($entries));

        return $this->fhirBundle('searchset', $entries);
    }

    // ── Appointment ───────────────────────────────────────────────────────────

    /**
     * Return a FHIR Bundle of Appointment resources.
     * Scope: appointment.read
     * Required: ?patient={participantId}
     * Optional: ?date=YYYY-MM-DD (filter to specific day), ?status=booked|fulfilled|cancelled
     *
     * GET /fhir/R4/Appointment?patient={id}
     */
    public function appointments(Request $request): JsonResponse
    {
        $tenantId      = $request->attributes->get('fhir_tenant_id');
        $participantId = (int) $request->query('patient');

        if (! $participantId || ! $this->participantBelongsToTenant($participantId, $tenantId)) {
            return ! $participantId
                ? $this->fhirError(400, 'Required search parameter: patient')
                : $this->fhirNotFound("Patient/{$participantId}");
        }

        $query = Appointment::where('participant_id', $participantId)
            ->where('tenant_id', $tenantId)
            ->orderBy('scheduled_start', 'desc');

        if ($date = $request->query('date')) {
            $query->whereDate('scheduled_start', $date);
        }

        $appts = $query->limit(200)->get();

        $entries = $appts->map(fn ($a) => ['resource' => AppointmentMapper::toFhir($a)])->values()->all();

        $this->logFhirRead($request, 'Appointment', $participantId, $tenantId, count($entries));

        return $this->fhirBundle('searchset', $entries);
    }

    // ── Immunization ─────────────────────────────────────────────────────────

    /**
     * Return a FHIR Bundle of Immunization resources.
     * Scope: immunization.read
     * Required: ?patient={participantId}
     *
     * GET /fhir/R4/Immunization?patient={id}
     */
    public function immunizations(Request $request): JsonResponse
    {
        $tenantId      = $request->attributes->get('fhir_tenant_id');
        $participantId = (int) $request->query('patient');

        if (! $participantId || ! $this->participantBelongsToTenant($participantId, $tenantId)) {
            return ! $participantId
                ? $this->fhirError(400, 'Required search parameter: patient')
                : $this->fhirNotFound("Patient/{$participantId}");
        }

        $immunizations = Immunization::where('participant_id', $participantId)
            ->where('tenant_id', $tenantId)
            ->orderBy('administered_date', 'desc')
            ->get();

        $entries = $immunizations->map(fn ($i) => ['resource' => ImmunizationMapper::toFhir($i)])->values()->all();

        $this->logFhirRead($request, 'Immunization', $participantId, $tenantId, count($entries));

        return $this->fhirBundle('searchset', $entries);
    }

    // ── Procedure ─────────────────────────────────────────────────────────────

    /**
     * Return a FHIR Bundle of Procedure resources from procedure history.
     * Scope: procedure.read
     * Required: ?patient={participantId}
     *
     * GET /fhir/R4/Procedure?patient={id}
     */
    public function procedures(Request $request): JsonResponse
    {
        $tenantId      = $request->attributes->get('fhir_tenant_id');
        $participantId = (int) $request->query('patient');

        if (! $participantId || ! $this->participantBelongsToTenant($participantId, $tenantId)) {
            return ! $participantId
                ? $this->fhirError(400, 'Required search parameter: patient')
                : $this->fhirNotFound("Patient/{$participantId}");
        }

        $procedures = Procedure::where('participant_id', $participantId)
            ->where('tenant_id', $tenantId)
            ->orderBy('performed_date', 'desc')
            ->get();

        $entries = $procedures->map(fn ($p) => ['resource' => ProcedureMapper::toFhir($p)])->values()->all();

        $this->logFhirRead($request, 'Procedure', $participantId, $tenantId, count($entries));

        return $this->fhirBundle('searchset', $entries);
    }

    // ── SDOH Observation ─────────────────────────────────────────────────────

    /**
     * Return a FHIR Bundle of Observation resources for SDOH screenings.
     * Each screening produces multiple Observations (one per SDOH domain).
     * Scope: observation.read (reuses existing observation scope)
     * Required: ?patient={participantId}&category=social-history
     *
     * GET /fhir/R4/Observation?patient={id}&category=social-history
     */
    public function sdohObservations(Request $request): JsonResponse
    {
        $tenantId      = $request->attributes->get('fhir_tenant_id');
        $participantId = (int) $request->query('patient');

        if (! $participantId || ! $this->participantBelongsToTenant($participantId, $tenantId)) {
            return ! $participantId
                ? $this->fhirError(400, 'Required search parameter: patient')
                : $this->fhirNotFound("Patient/{$participantId}");
        }

        $screenings = SocialDeterminant::where('participant_id', $participantId)
            ->where('tenant_id', $tenantId)
            ->orderBy('assessed_at', 'desc')
            ->get();

        $observations = [];
        foreach ($screenings as $screening) {
            foreach (SdohObservationMapper::toFhirCollection($screening) as $obs) {
                $observations[] = ['resource' => $obs];
            }
        }

        $this->logFhirRead($request, 'Observation', $participantId, $tenantId, count($observations));

        return $this->fhirBundle('searchset', $observations);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Check that the participant exists in the given tenant. */
    private function participantBelongsToTenant(int $participantId, int $tenantId): bool
    {
        return Participant::where('id', $participantId)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    /** Log a FHIR read to audit_log with source_type='fhir_api'. */
    private function logFhirRead(Request $request, string $resourceType, int $resourceId, int $tenantId, ?int $count = null): void
    {
        AuditLog::record(
            action: 'fhir.read.' . strtolower($resourceType),
            resourceType: $resourceType,
            resourceId: $resourceId,
            tenantId: $tenantId,
            userId: $request->attributes->get('fhir_user_id'),
            newValues: array_filter([
                'source_type'     => 'fhir_api',
                'result_count'    => $count,
                'remote_ip'       => $request->ip(),
                'token_name'      => $request->attributes->get('fhir_token')?->name,
            ])
        );
    }

    /** Return a single FHIR resource as JSON with correct Content-Type. */
    private function fhirResponse(array $resource): JsonResponse
    {
        return response()->json($resource, 200, ['Content-Type' => 'application/fhir+json']);
    }

    /** Wrap entries in a FHIR Bundle. */
    private function fhirBundle(string $type, array $entries): JsonResponse
    {
        return response()->json([
            'resourceType' => 'Bundle',
            'type'         => $type,
            'total'        => count($entries),
            'entry'        => $entries,
        ], 200, ['Content-Type' => 'application/fhir+json']);
    }

    /** Return a FHIR OperationOutcome 404 response. */
    private function fhirNotFound(string $reference): JsonResponse
    {
        return $this->fhirError(404, "Resource not found: {$reference}");
    }

    /** Return a FHIR OperationOutcome error response. */
    private function fhirError(int $statusCode, string $message): JsonResponse
    {
        return response()->json([
            'resourceType' => 'OperationOutcome',
            'issue' => [
                [
                    'severity'    => $statusCode >= 500 ? 'fatal' : 'error',
                    'code'        => match (true) {
                        $statusCode === 401 => 'security',
                        $statusCode === 403 => 'forbidden',
                        $statusCode === 404 => 'not-found',
                        default             => 'processing',
                    },
                    'diagnostics' => $message,
                ],
            ],
        ], $statusCode, ['Content-Type' => 'application/fhir+json']);
    }
}
