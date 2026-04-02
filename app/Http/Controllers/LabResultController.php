<?php

// ─── LabResultController ──────────────────────────────────────────────────────
// REST endpoints for participant lab results (emr_lab_results).
//
// W5-2: Lab Results Viewer — surfaces structured HL7-sourced and manually-entered
// lab results in the participant chart.
//
// Auth: standard RBAC (any authenticated user with participant access may read;
// write restricted to clinical departments).
//
// WRITE departments (store): primary_care, home_care, therapies, it_admin
// REVIEW departments (review): primary_care, it_admin
// All authenticated users may read.
//
// Tenant isolation enforced on every query.
// Cross-participant access blocked (404, not 403, to avoid information leakage).
//
// Routes:
//   GET  /participants/{participant}/lab-results           → index()
//   POST /participants/{participant}/lab-results           → store()
//   GET  /participants/{participant}/lab-results/{lab}     → show()
//   POST /participants/{participant}/lab-results/{lab}/review → review()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LabResult;
use App\Models\LabResultComponent;
use App\Models\Participant;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class LabResultController extends Controller
{
    /** Departments that may create lab results manually. */
    private const WRITE_DEPARTMENTS = ['primary_care', 'home_care', 'therapies', 'it_admin'];

    /** Departments that may mark results as reviewed. */
    private const REVIEW_DEPARTMENTS = ['primary_care', 'it_admin'];

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resolve the participant, enforce tenant isolation, and return 404 on mismatch.
     */
    private function resolveParticipant(int $participantId): Participant
    {
        $tenantId = Auth::user()->tenant_id;

        $participant = Participant::where('id', $participantId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return $participant;
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    /**
     * GET /participants/{participant}/lab-results
     *
     * Paginated list of lab results for a participant.
     * Filters: abnormal_only, unreviewed, date_from, date_to
     * Returns newest-first (collected_at desc), 25 per page.
     */
    public function index(Request $request, int $participantId): JsonResponse
    {
        $participant = $this->resolveParticipant($participantId);

        $query = LabResult::forParticipant($participant->id)
            ->forTenant($participant->tenant_id)
            ->with(['reviewedBy:id,first_name,last_name']);

        if ($request->boolean('abnormal_only')) {
            $query->abnormal();
        }

        if ($request->boolean('unreviewed')) {
            $query->unreviewed();
        }

        if ($request->filled('date_from')) {
            $query->where('collected_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('collected_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        $results = $query->orderByDesc('collected_at')->paginate(25);

        return response()->json([
            'data' => $results->map(fn (LabResult $r) => $r->toApiArray())->values(),
            'meta' => [
                'current_page'  => $results->currentPage(),
                'last_page'     => $results->lastPage(),
                'per_page'      => $results->perPage(),
                'total'         => $results->total(),
                'unreviewed_count' => LabResult::forParticipant($participant->id)
                    ->forTenant($participant->tenant_id)
                    ->abnormal()
                    ->unreviewed()
                    ->count(),
            ],
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    /**
     * GET /participants/{participant}/lab-results/{lab}
     *
     * Single lab result with all components.
     */
    public function show(int $participantId, int $labId): JsonResponse
    {
        $participant = $this->resolveParticipant($participantId);

        $lab = LabResult::with(['components', 'reviewedBy:id,first_name,last_name'])
            ->where('id', $labId)
            ->where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->firstOrFail();

        return response()->json($lab->toDetailArray());
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    /**
     * POST /participants/{participant}/lab-results
     *
     * Manual lab result entry with optional components array.
     * Restricted to clinical departments.
     * Triggers alert to primary_care if any component is abnormal or critical.
     */
    public function store(Request $request, int $participantId, AlertService $alertService): JsonResponse
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && ! in_array($user->department, self::WRITE_DEPARTMENTS, true)) {
            abort(403);
        }

        $participant = $this->resolveParticipant($participantId);

        $data = $request->validate([
            'test_name'              => 'required|string|max:200',
            'test_code'              => 'nullable|string|max:50',
            'collected_at'           => 'required|date',
            'resulted_at'            => 'nullable|date|after_or_equal:collected_at',
            'ordering_provider_name' => 'nullable|string|max:200',
            'performing_facility'    => 'nullable|string|max:200',
            'overall_status'         => ['nullable', Rule::in(LabResult::STATUSES)],
            'notes'                  => 'nullable|string|max:2000',
            'components'             => 'nullable|array|min:1',
            'components.*.component_name' => 'required_with:components|string|max:200',
            'components.*.component_code' => 'nullable|string|max:50',
            'components.*.value'          => 'required_with:components|string|max:100',
            'components.*.unit'           => 'nullable|string|max:50',
            'components.*.reference_range'=> 'nullable|string|max:100',
            'components.*.abnormal_flag'  => ['nullable', Rule::in(LabResultComponent::ABNORMAL_FLAGS)],
        ]);

        // Determine overall abnormal flag from components
        $hasCritical = false;
        $hasAbnormal = false;

        if (! empty($data['components'])) {
            foreach ($data['components'] as $comp) {
                $flag = $comp['abnormal_flag'] ?? null;
                if (in_array($flag, LabResultComponent::CRITICAL_FLAGS, true)) {
                    $hasCritical = true;
                    $hasAbnormal = true;
                } elseif ($flag !== null && $flag !== 'normal') {
                    $hasAbnormal = true;
                }
            }
        }

        $lab = LabResult::create([
            'participant_id'         => $participant->id,
            'tenant_id'              => $participant->tenant_id,
            'integration_log_id'     => null,
            'test_name'              => $data['test_name'],
            'test_code'              => $data['test_code'] ?? null,
            'collected_at'           => $data['collected_at'],
            'resulted_at'            => $data['resulted_at'] ?? null,
            'ordering_provider_name' => $data['ordering_provider_name'] ?? null,
            'performing_facility'    => $data['performing_facility'] ?? null,
            'source'                 => 'manual_entry',
            'overall_status'         => $data['overall_status'] ?? 'final',
            'abnormal_flag'          => $hasAbnormal,
            'notes'                  => $data['notes'] ?? null,
        ]);

        if (! empty($data['components'])) {
            foreach ($data['components'] as $comp) {
                LabResultComponent::create([
                    'lab_result_id'   => $lab->id,
                    'component_name'  => $comp['component_name'],
                    'component_code'  => $comp['component_code'] ?? null,
                    'value'           => $comp['value'],
                    'unit'            => $comp['unit'] ?? null,
                    'reference_range' => $comp['reference_range'] ?? null,
                    'abnormal_flag'   => $comp['abnormal_flag'] ?? null,
                ]);
            }
        }

        // Alert primary_care on any abnormal result
        if ($hasAbnormal) {
            $severity = $hasCritical ? 'critical' : 'warning';
            $alertService->create([
                'tenant_id'          => $participant->tenant_id,
                'participant_id'     => $participant->id,
                'source_module'      => 'lab_results',
                'alert_type'         => 'abnormal_lab',
                'title'              => ($hasCritical ? 'Critical' : 'Abnormal') . " Lab Result: {$participant->first_name} {$participant->last_name}",
                'message'            => "{$data['test_name']} — " . ($hasCritical ? 'critical value' : 'abnormal result') . " entered by {$user->first_name} {$user->last_name}. Review required.",
                'severity'           => $severity,
                'target_departments' => ['primary_care'],
                'created_by_system'  => false,
                'created_by_user_id' => $user->id,
                'metadata'           => ['lab_result_id' => $lab->id],
            ]);
        }

        AuditLog::record(
            action:       'lab_result.store',
            resourceType: 'LabResult',
            resourceId:   $lab->id,
            tenantId:     $participant->tenant_id,
            userId:       $user->id,
            newValues:    ['test_name' => $lab->test_name, 'abnormal' => $hasAbnormal],
        );

        $lab->load('components');
        return response()->json($lab->toDetailArray(), 201);
    }

    // ── Review ────────────────────────────────────────────────────────────────

    /**
     * POST /participants/{participant}/lab-results/{lab}/review
     *
     * Mark a lab result as reviewed by the authenticated clinician.
     * Returns 409 if already reviewed.
     * Restricted to primary_care and it_admin.
     */
    public function review(Request $request, int $participantId, int $labId): JsonResponse
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && ! in_array($user->department, self::REVIEW_DEPARTMENTS, true)) {
            abort(403);
        }

        $participant = $this->resolveParticipant($participantId);

        $lab = LabResult::where('id', $labId)
            ->where('participant_id', $participant->id)
            ->where('tenant_id', $participant->tenant_id)
            ->firstOrFail();

        if ($lab->isReviewed()) {
            return response()->json([
                'message' => 'Lab result has already been reviewed.',
            ], 409);
        }

        $lab->update([
            'reviewed_by_user_id' => $user->id,
            'reviewed_at'         => now(),
        ]);

        AuditLog::record(
            action:       'lab_result.reviewed',
            resourceType: 'LabResult',
            resourceId:   $lab->id,
            tenantId:     $participant->tenant_id,
            userId:       $user->id,
        );

        $lab->load(['components', 'reviewedBy:id,first_name,last_name']);
        return response()->json($lab->toDetailArray());
    }
}
