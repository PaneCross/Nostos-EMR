<?php

// ─── MedicationController ──────────────────────────────────────────────────────
// Manages participant medication lists, eMAR administration, drug interaction
// alerts, and medication reconciliation.
//
// Routes:
//   GET    /participants/{participant}/medications              → index()
//   POST   /participants/{participant}/medications              → store()
//   PUT    /participants/{participant}/medications/{med}/discontinue → discontinue()
//   GET    /participants/{participant}/medications/interactions → interactions()
//   POST   /participants/{participant}/medications/{med}/interactions/{alert}/acknowledge → acknowledgeInteraction()
//   GET    /participants/{participant}/emar                     → emarIndex()
//   POST   /participants/{participant}/emar/{record}/administer → administer()
//   POST   /participants/{participant}/medications/{med}/prn-dose → recordPrnDose()
//   GET    /participants/{participant}/medications/reconciliations → reconciliations()
//   POST   /participants/{participant}/medications/reconciliations → storeReconciliation()
//   GET    /medications/reference/search                        → referenceSearch()
//
// Authorization:
//   - View: any authenticated user with tenant access
//   - Write: primary_care, therapies, it_admin departments only
//   - Controlled substance witness: required for DEA Schedule II/III
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\RecordEmarAdministrationRequest;
use App\Http\Requests\StoreMedReconciliationRequest;
use App\Http\Requests\StoreMedicationRequest;
use App\Models\AuditLog;
use App\Models\DrugInteractionAlert;
use App\Models\EmarRecord;
use App\Models\Medication;
use App\Models\MedReconciliation;
use App\Models\Participant;
use App\Services\AlertService;
use App\Services\DrugInteractionService;
use App\Services\MedicationScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MedicationController extends Controller
{
    /** Departments permitted to prescribe/discontinue medications. */
    private const PRESCRIBER_DEPARTMENTS = ['primary_care', 'therapies', 'it_admin'];

    public function __construct(
        private readonly DrugInteractionService    $interactionService,
        private readonly AlertService              $alertService,
    ) {}

    // ── Tenant authorization helper ───────────────────────────────────────────

    /** Abort with 403 if the participant belongs to a different tenant than the user. */
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    /** Abort with 403 if the user is not in a prescriber department. */
    private function authorizePrescriber($user): void
    {
        abort_unless(in_array($user->department, self::PRESCRIBER_DEPARTMENTS), 403);
    }

    // ── Medication CRUD ───────────────────────────────────────────────────────

    /**
     * List all medications for a participant, grouped by status.
     * Returns active + PRN medications first, then discontinued/on_hold.
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $medications = Medication::where('participant_id', $participant->id)
            ->with('prescribingProvider:id,first_name,last_name')
            ->orderByRaw("CASE status WHEN 'active' THEN 1 WHEN 'prn' THEN 2 WHEN 'on_hold' THEN 3 ELSE 4 END")
            ->orderBy('drug_name')
            ->get();

        // Attach unacknowledged interaction counts per medication
        $interactionCounts = DrugInteractionAlert::where('participant_id', $participant->id)
            ->where('is_acknowledged', false)
            ->get(['medication_id_1', 'medication_id_2', 'severity']);

        return response()->json([
            'medications'           => $medications,
            'interaction_alert_count' => $interactionCounts->count(),
        ]);
    }

    /**
     * Add a new medication to the participant's medication list.
     * Checks for drug interactions after saving.
     */
    public function store(StoreMedicationRequest $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizePrescriber($user);

        $medication = Medication::create([
            ...$request->validated(),
            'participant_id' => $participant->id,
            'tenant_id'      => $user->tenant_id,
        ]);

        // Check for drug-drug interactions with existing active medications
        $newAlerts = $this->interactionService->checkInteractions($medication, $participant);

        AuditLog::record(
            action:       'medication.added',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'medication',
            resourceId:   $medication->id,
            description:  "Medication added: {$medication->drug_name} for participant {$participant->mrn}",
            newValues:    ['drug_name' => $medication->drug_name, 'status' => 'active'],
        );

        return response()->json([
            'medication'     => $medication->load('prescribingProvider:id,first_name,last_name'),
            'new_alerts'     => $newAlerts,
        ], 201);
    }

    /**
     * Discontinue a medication. Sets status='discontinued' and records reason.
     * Prescriber departments only.
     */
    public function discontinue(Request $request, Participant $participant, Medication $medication): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizePrescriber($user);
        abort_if($medication->participant_id !== $participant->id, 404);
        abort_if($medication->status === 'discontinued', 409);

        $reason = $request->input('reason', 'Discontinued by clinician');
        $medication->discontinue($reason);

        AuditLog::record(
            action:       'medication.discontinued',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'medication',
            resourceId:   $medication->id,
            description:  "Medication discontinued: {$medication->drug_name}. Reason: {$reason}",
            newValues:    ['status' => 'discontinued', 'discontinued_reason' => $reason],
        );

        return response()->json($medication->fresh());
    }

    // ── Drug Interaction Alerts ───────────────────────────────────────────────

    /**
     * List all unacknowledged drug interaction alerts for a participant.
     * Ordered by severity (contraindicated first).
     */
    public function interactions(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $alerts = $this->interactionService->getUnacknowledgedAlerts($participant);

        return response()->json($alerts);
    }

    /**
     * Acknowledge a drug interaction alert (clinician has reviewed and accepted the risk).
     */
    public function acknowledgeInteraction(
        Request $request,
        Participant $participant,
        DrugInteractionAlert $alert
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($alert->participant_id !== $participant->id, 404);
        abort_if($alert->is_acknowledged, 409);

        $note = $request->input('acknowledgement_note');
        $alert->acknowledge($user, $note);

        AuditLog::record(
            action:       'drug_interaction.acknowledged',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'drug_interaction_alert',
            resourceId:   $alert->id,
            description:  "Drug interaction acknowledged: {$alert->drug_name_1} + {$alert->drug_name_2}",
            newValues:    ['acknowledged_by' => $user->id, 'note' => $note],
        );

        return response()->json($alert->fresh());
    }

    // ── eMAR ──────────────────────────────────────────────────────────────────

    /**
     * Retrieve eMAR records for a participant on a specific date.
     * Returns records grouped by medication for the eMAR grid view.
     *
     * Query param: date (Y-m-d, defaults to today)
     */
    public function emarIndex(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $date = $request->input('date', today()->toDateString());

        $records = EmarRecord::where('participant_id', $participant->id)
            ->whereDate('scheduled_time', $date)
            ->with([
                'medication:id,drug_name,dose,dose_unit,route,frequency,is_controlled,controlled_schedule',
                'administeredBy:id,first_name,last_name',
                'witness:id,first_name,last_name',
            ])
            ->orderBy('scheduled_time')
            ->get();

        return response()->json($records);
    }

    /**
     * Record medication administration (or refusal/hold) against a scheduled eMAR record.
     * Controlled substances (DEA Schedule II/III) require witness_user_id.
     */
    public function administer(
        RecordEmarAdministrationRequest $request,
        Participant $participant,
        EmarRecord $record
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($record->participant_id !== $participant->id, 404);

        // Validate controlled substance witness requirement
        if ($record->medication?->requiresWitness() && $request->input('status') === 'given') {
            abort_unless($request->filled('witness_user_id'), 422);
        }

        // eMAR records are append-only — update via direct DB to avoid triggering events
        EmarRecord::where('id', $record->id)->update([
            'status'                 => $request->input('status'),
            'administered_at'        => $request->input('administered_at'),
            'administered_by_user_id'=> $user->id,
            'dose_given'             => $request->input('dose_given'),
            'route_given'            => $request->input('route_given'),
            'reason_not_given'       => $request->input('reason_not_given'),
            'witness_user_id'        => $request->input('witness_user_id'),
            'notes'                  => $request->input('notes'),
        ]);

        AuditLog::record(
            action:       'emar.administered',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'emar_record',
            resourceId:   $record->id,
            description:  "eMAR dose recorded: {$record->medication?->drug_name} — {$request->input('status')}",
            newValues:    ['status' => $request->input('status'), 'administered_by' => $user->id],
        );

        return response()->json($record->fresh(['administeredBy', 'witness', 'medication']));
    }

    /**
     * Record a PRN (as-needed) dose — creates a new eMAR record with status='given'.
     * Only available for medications with is_prn=true.
     */
    public function recordPrnDose(Request $request, Participant $participant, Medication $medication): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($medication->participant_id !== $participant->id, 404);
        abort_unless($medication->is_prn, 422);

        $request->validate([
            'administered_at'  => ['required', 'date'],
            'dose_given'       => ['nullable', 'string', 'max:50'],
            'reason'           => ['nullable', 'string', 'max:300'],
            'witness_user_id'  => ['nullable', 'integer', 'exists:shared_users,id'],
            'notes'            => ['nullable', 'string', 'max:1000'],
        ]);

        $record = EmarRecord::create([
            'participant_id'          => $participant->id,
            'medication_id'           => $medication->id,
            'tenant_id'               => $user->tenant_id,
            'scheduled_time'          => $request->input('administered_at'),  // PRN: scheduled = actual
            'administered_at'         => $request->input('administered_at'),
            'administered_by_user_id' => $user->id,
            'status'                  => 'given',
            'dose_given'              => $request->input('dose_given'),
            'reason_not_given'        => $request->input('reason'),
            'witness_user_id'         => $request->input('witness_user_id'),
            'notes'                   => $request->input('notes'),
        ]);

        AuditLog::record(
            action:       'emar.prn_dose',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'emar_record',
            resourceId:   $record->id,
            description:  "PRN dose recorded: {$medication->drug_name}",
        );

        return response()->json($record->load('medication:id,drug_name'), 201);
    }

    // ── Medication Reconciliation ─────────────────────────────────────────────

    /**
     * List all medication reconciliations for a participant, newest first.
     */
    public function reconciliations(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeForTenant($participant, $request->user());

        $reconciliations = MedReconciliation::where('participant_id', $participant->id)
            ->with('reconciledBy:id,first_name,last_name,department')
            ->orderByDesc('reconciled_at')
            ->get();

        return response()->json($reconciliations);
    }

    /**
     * Create a new medication reconciliation record.
     * Prescriber departments only.
     */
    public function storeReconciliation(
        StoreMedReconciliationRequest $request,
        Participant $participant
    ): JsonResponse {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizePrescriber($user);

        $reconciliation = MedReconciliation::create([
            ...$request->validated(),
            'participant_id'          => $participant->id,
            'tenant_id'               => $user->tenant_id,
            'reconciled_by_user_id'   => $user->id,
            'reconciling_department'  => $user->department,
        ]);

        AuditLog::record(
            action:       'med_reconciliation.completed',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'med_reconciliation',
            resourceId:   $reconciliation->id,
            description:  "Medication reconciliation ({$reconciliation->reconciliation_type}) completed for {$participant->mrn}",
            newValues:    [
                'type'             => $reconciliation->reconciliation_type,
                'has_discrepancies'=> $reconciliation->has_discrepancies,
                'med_count'        => count($reconciliation->reconciled_medications ?? []),
            ],
        );

        return response()->json($reconciliation->load('reconciledBy:id,first_name,last_name'), 201);
    }

    // ── Reference search ──────────────────────────────────────────────────────

    /**
     * Typeahead search against the medication reference table.
     * Matches on drug_name, rxnorm_code, or brand_names.
     * Returns up to 20 results for the "Add Medication" modal.
     *
     * Query param: q (search string, min 2 chars)
     */
    public function referenceSearch(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $results = DB::table('emr_medications_reference')
            ->where('drug_name', 'ilike', "%{$q}%")
            ->orWhere('brand_names', 'ilike', "%{$q}%")
            ->orWhere('rxnorm_code', 'ilike', "%{$q}%")
            ->orderBy('drug_name')
            ->limit(20)
            ->get([
                'drug_name', 'rxnorm_code', 'drug_class', 'common_dose',
                'dose_unit', 'route', 'frequency', 'is_controlled', 'controlled_schedule',
            ]);

        return response()->json($results);
    }
}
