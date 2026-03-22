<?php

// ─── HosMSurveyController ─────────────────────────────────────────────────────
// REST API for Health Outcomes Survey for Medicare (HOS-M) annual surveys.
//
// Route list:
//   GET  /billing/hos-m               → index()   — list surveys with filters
//   POST /billing/hos-m               → store()   — create survey record
//   PUT  /billing/hos-m/{survey}      → update()  — update survey responses
//   POST /billing/hos-m/{survey}/submit → submit() — mark as submitted to CMS
//
// Department access: finance + primary_care (administrators of the survey)
//   + super_admin + it_admin.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\HosMSurvey;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class HosMSurveyController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeAccess(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && !in_array($user->department, ['finance', 'primary_care', 'it_admin']),
            403
        );
    }

    // ── Survey Index ──────────────────────────────────────────────────────────

    /**
     * List HOS-M surveys for the tenant.
     * Filters: ?participant_id= ?survey_year= ?completed= ?submitted_to_cms=
     *
     * GET /billing/hos-m
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeAccess($request);
        $tenantId = $request->user()->tenant_id;
        $year     = now()->year;

        $surveys = HosMSurvey::forTenant($tenantId)
            ->with([
                'participant:id,mrn,first_name,last_name',
                'administeredBy:id,first_name,last_name',
            ])
            ->orderBy('survey_year', 'desc')
            ->orderBy('administered_at', 'desc')
            ->get();

        $enrolled = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();

        $stats = [
            'total_enrolled'      => $enrolled,
            'surveyed_this_year'  => HosMSurvey::forTenant($tenantId)->forYear($year)->count(),
            'completed_this_year' => HosMSurvey::forTenant($tenantId)->forYear($year)->where('completed', true)->count(),
            'submitted_to_cms'    => HosMSurvey::forTenant($tenantId)->forYear($year)->where('submitted_to_cms', true)->count(),
        ];

        return Inertia::render('Finance/HosMSurvey', [
            'surveys'     => $surveys,
            'stats'       => $stats,
            'currentYear' => $year,
        ]);
    }

    // ── Survey Store ──────────────────────────────────────────────────────────

    /**
     * Create an HOS-M survey record for a participant.
     * Enforces one survey per participant per year (DB unique constraint).
     *
     * POST /billing/hos-m
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'participant_id'   => ['required', 'integer', 'exists:emr_participants,id'],
            'survey_year'      => ['required', 'integer', 'min:2020', 'max:2035'],
            'administered_at'  => ['required', 'date'],
            'completed'        => ['boolean'],
            'responses'        => ['nullable', 'array'],
            'responses.physical_health'  => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.mental_health'    => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.pain'             => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.falls_past_year'  => ['nullable', 'in:0,1'],
            'responses.fall_injuries'    => ['nullable', 'in:0,1'],
        ]);

        abort_if(
            !Participant::where('id', $data['participant_id'])
                ->where('tenant_id', $tenantId)
                ->exists(),
            403
        );

        try {
            $survey = HosMSurvey::create(array_merge($data, [
                'tenant_id'               => $tenantId,
                'administered_by_user_id' => $request->user()->id,
            ]));
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique constraint violation — survey already exists for this year
            return response()->json([
                'error' => "A HOS-M survey already exists for this participant in {$data['survey_year']}.",
            ], 409);
        }

        AuditLog::record(
            action: 'billing.hosm.create',
            resourceType: 'HosMSurvey',
            resourceId: $survey->id,
            tenantId: $tenantId,
            userId: $request->user()->id,
            newValues: $data
        );

        return response()->json($survey->load([
            'participant:id,mrn,first_name,last_name',
            'administeredBy:id,first_name,last_name',
        ]), 201);
    }

    // ── Survey Update ─────────────────────────────────────────────────────────

    /**
     * Update survey responses or completion status.
     * Cannot update a survey that has already been submitted to CMS.
     *
     * PUT /billing/hos-m/{survey}
     */
    public function update(Request $request, HosMSurvey $survey): JsonResponse
    {
        $this->authorizeAccess($request);
        abort_if($survey->tenant_id !== $request->user()->tenant_id, 403);
        abort_if($survey->submitted_to_cms, 409, 'Cannot update a survey that has been submitted to CMS.');

        $old = $survey->only(['completed', 'responses']);

        $data = $request->validate([
            'completed'                  => ['boolean'],
            'responses'                  => ['nullable', 'array'],
            'responses.physical_health'  => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.mental_health'    => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.pain'             => ['nullable', 'integer', 'min:1', 'max:5'],
            'responses.falls_past_year'  => ['nullable', 'in:0,1'],
            'responses.fall_injuries'    => ['nullable', 'in:0,1'],
        ]);

        $survey->update($data);

        AuditLog::record(
            action: 'billing.hosm.update',
            resourceType: 'HosMSurvey',
            resourceId: $survey->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            oldValues: $old,
            newValues: $data
        );

        return response()->json($survey->fresh()->load([
            'participant:id,mrn,first_name,last_name',
            'administeredBy:id,first_name,last_name',
        ]));
    }

    // ── Submit to CMS ─────────────────────────────────────────────────────────

    /**
     * Mark a completed survey as submitted to CMS.
     * Requires the survey to be completed first.
     *
     * POST /billing/hos-m/{survey}/submit
     */
    public function submit(Request $request, HosMSurvey $survey): JsonResponse
    {
        $this->authorizeAccess($request);
        abort_if($survey->tenant_id !== $request->user()->tenant_id, 403);
        abort_if(!$survey->completed, 422, 'Survey must be completed before submitting to CMS.');
        abort_if($survey->submitted_to_cms, 409, 'Survey has already been submitted to CMS.');

        $survey->update([
            'submitted_to_cms' => true,
            'submitted_at'     => now(),
        ]);

        AuditLog::record(
            action: 'billing.hosm.submit_to_cms',
            resourceType: 'HosMSurvey',
            resourceId: $survey->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            newValues: ['submitted_to_cms' => true]
        );

        return response()->json($survey->fresh()->load([
            'participant:id,mrn,first_name,last_name',
        ]));
    }
}
