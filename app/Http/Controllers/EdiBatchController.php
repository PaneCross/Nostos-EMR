<?php

// ─── EdiBatchController ───────────────────────────────────────────────────────
// Manages EDI 837P batch files for CMS Encounter Data submission.
//
// Route list:
//   GET  /billing/batches                        → index()       — paginated list
//   GET  /billing/batches/{batch}/download       → download()    — X12 file attachment
//   POST /billing/batches/{batch}/acknowledge    → acknowledge() — process 277CA response
//
// Department access: finance only (+ super_admin, it_admin).
// file_content is NEVER returned in index() — only via download().
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EdiBatch;
use App\Services\Edi837PBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EdiBatchController extends Controller
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

    // ── Batch Index ───────────────────────────────────────────────────────────

    /**
     * List EDI batches for the tenant, paginated.
     * file_content is explicitly excluded from the response.
     *
     * GET /billing/batches
     */
    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $this->authorizeFinance($request);

        // Browser navigation (direct URL or Inertia SPA nav): Accept header is text/html.
        // Axios data-fetch from the mounted component: Accept is application/json.
        if (!$request->wantsJson() || $request->header('X-Inertia')) {
            return Inertia::render('Finance/EdiBatch');
        }

        // Axios data-fetch from the mounted React component → return JSON list.
        $tenantId = $request->user()->tenant_id;

        $batches = EdiBatch::forTenant($tenantId)
            ->with('createdBy:id,first_name,last_name')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // Strip file_content from all items — download goes through download()
        $batches->getCollection()->transform(fn ($b) => $b->makeHidden('file_content'));

        return response()->json($batches);
    }

    // ── Batch Download ────────────────────────────────────────────────────────

    /**
     * Stream the EDI X12 batch file as an attachment.
     * Content-Type: application/edi-x12
     *
     * GET /billing/batches/{batch}/download
     */
    public function download(Request $request, EdiBatch $batch): Response
    {
        $this->authorizeFinance($request);
        abort_if($batch->tenant_id !== $request->user()->tenant_id, 403);

        AuditLog::record(
            action: 'edi_batch.download',
            resourceType: 'EdiBatch',
            resourceId: $batch->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id
        );

        $fileName = $batch->file_name ?? ('batch_' . $batch->id . '.edi');

        return response($batch->file_content ?? '', 200, [
            'Content-Type'        => 'application/edi-x12',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    // ── 277CA Acknowledgement ─────────────────────────────────────────────────

    /**
     * Process a CMS 277CA acknowledgement file.
     * Accepts the raw X12 content in the request body (text/plain or form field).
     *
     * POST /billing/batches/{batch}/acknowledge
     * Body: { edi_content: "ISA*00*..." } OR raw X12 as request body
     */
    public function acknowledge(Request $request, EdiBatch $batch): JsonResponse
    {
        $this->authorizeFinance($request);
        abort_if($batch->tenant_id !== $request->user()->tenant_id, 403);

        $ediContent = $request->input('edi_content') ?? $request->getContent();

        if (empty($ediContent)) {
            return response()->json(['error' => '277CA EDI content is required.'], 422);
        }

        $service = new Edi837PBuilderService();
        $service->parseAcknowledgement($ediContent, $batch);

        AuditLog::record(
            action: 'edi_batch.acknowledge',
            resourceType: 'EdiBatch',
            resourceId: $batch->id,
            tenantId: $request->user()->tenant_id,
            userId: $request->user()->id,
            newValues: ['new_status' => $batch->fresh()->status]
        );

        return response()->json($batch->fresh()->makeHidden('file_content'));
    }
}
