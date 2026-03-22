<?php

// ─── HpmsController ───────────────────────────────────────────────────────────
// Manages HPMS (Health Plan Management System) file submissions for PACE.
//
// Route list:
//   GET   /billing/hpms                          → index()         — Inertia page
//   POST  /billing/hpms/generate                 → generate()      — generate file
//   GET   /billing/hpms/{submission}/download    → download()      — stream file
//   PATCH /billing/hpms/{submission}/submit      → markSubmitted() — mark as submitted
//
// Department access: finance only (+ super_admin, it_admin).
// file_content NEVER returned in API responses — only through download().
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\HpmsSubmission;
use App\Services\HpmsFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class HpmsController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeFinance(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && !in_array($user->department, ['finance', 'it_admin']),
            403
        );
    }

    // ── Inertia Page ─────────────────────────────────────────────────────────

    /**
     * Render the HPMS Submissions Inertia page.
     *
     * GET /billing/hpms
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeFinance($request);
        $tenantId    = $request->user()->tenant_id;

        $submissions = HpmsSubmission::forTenant($tenantId)
            ->with('createdBy:id,first_name,last_name')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->makeHidden('file_content');

        return Inertia::render('Finance/Hpms', [
            'submissions'     => $submissions,
            'submissionTypes' => HpmsSubmission::SUBMISSION_TYPES,
        ]);
    }

    // ── Generate File ─────────────────────────────────────────────────────────

    /**
     * Generate an HPMS submission file.
     * Body: { type: 'enrollment'|'disenrollment'|'quality_data'|'hos_m', month: 'YYYY-MM', year: int, quarter: int }
     *
     * POST /billing/hpms/generate
     */
    public function generate(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'type'    => ['required', Rule::in(array_keys(HpmsSubmission::SUBMISSION_TYPES))],
            'month'   => ['required_if:type,enrollment,disenrollment', 'nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'year'    => ['required_if:type,quality_data,hos_m', 'nullable', 'integer', 'min:2020', 'max:2035'],
            'quarter' => ['required_if:type,quality_data', 'nullable', 'integer', 'min:1', 'max:4'],
        ]);

        $service    = new HpmsFileService();
        $userId     = $request->user()->id;
        $submission = null;

        switch ($data['type']) {
            case 'enrollment':
                $submission = $service->generateEnrollmentFile($tenantId, $data['month'], $userId);
                break;
            case 'disenrollment':
                $submission = $service->generateDisenrollmentFile($tenantId, $data['month'], $userId);
                break;
            case 'quality_data':
                $submission = $service->generateQualityDataFile($tenantId, (int) $data['year'], (int) $data['quarter'], $userId);
                break;
            case 'hos_m':
                $submission = $service->generateHosMFile($tenantId, (int) $data['year'], $userId);
                break;
        }

        AuditLog::record(
            action: 'billing.hpms.generate',
            resourceType: 'HpmsSubmission',
            resourceId: $submission->id,
            tenantId: $tenantId,
            userId: $userId,
            newValues: ['type' => $data['type'], 'record_count' => $submission->record_count]
        );

        return response()->json($submission->makeHidden('file_content'), 201);
    }

    // ── Download ──────────────────────────────────────────────────────────────

    /**
     * Stream the HPMS file as a plain-text attachment.
     *
     * GET /billing/hpms/{submission}/download
     */
    public function download(Request $request, HpmsSubmission $submission): Response
    {
        $this->authorizeFinance($request);
        abort_if($submission->tenant_id !== $request->user()->tenant_id, 403);

        AuditLog::record(
            action: 'billing.hpms.download',
            resourceType: 'HpmsSubmission',
            resourceId: $submission->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id
        );

        $fileName = 'hpms_' . $submission->submission_type . '_'
            . $submission->period_start->format('Y-m-d') . '.txt';

        return response($submission->file_content ?? '', 200, [
            'Content-Type'        => 'text/plain',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    // ── Mark Submitted ────────────────────────────────────────────────────────

    /**
     * Mark an HPMS submission as submitted to CMS (status = 'submitted').
     *
     * PATCH /billing/hpms/{submission}/submit
     */
    public function markSubmitted(Request $request, HpmsSubmission $submission): JsonResponse
    {
        $this->authorizeFinance($request);
        abort_if($submission->tenant_id !== $request->user()->tenant_id, 403);

        abort_if($submission->status === 'submitted', 409, 'Submission is already marked as submitted.');

        $submission->update([
            'status'       => 'submitted',
            'submitted_at' => now(),
        ]);

        AuditLog::record(
            action: 'billing.hpms.submitted',
            resourceType: 'HpmsSubmission',
            resourceId: $submission->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            newValues: ['status' => 'submitted']
        );

        return response()->json($submission->fresh()->makeHidden('file_content'));
    }
}
