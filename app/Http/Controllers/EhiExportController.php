<?php

// ─── EhiExportController ──────────────────────────────────────────────────────
// 21st Century Cures Act — Electronic Health Information (EHI) export.
// Generates a ZIP of all FHIR R4 resources + non-FHIR clinical data for a participant.
//
// POST /participants/{id}/ehi-export                            → request()
//   Dispatches EhiExportService synchronously (demo env — no queue needed).
//   Returns 202 with download URL. Logs ehi_export_generated.
//
// GET  /participants/{id}/ehi-export/{token}/download           → download()
//   Validates token (not expired, not already downloaded), streams ZIP.
//   Marks downloaded_at. Returns 410 Gone if expired.
//
// Access control: it_admin, enrollment admin, or primary_care admin.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EhiExport;
use App\Models\Participant;
use App\Services\EhiExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EhiExportController extends Controller
{
    public function __construct(private EhiExportService $ehiExportService) {}

    private function authorizeExport(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);

        $canExport = $user->department === 'it_admin'
            || ($user->department === 'enrollment' && $user->isAdmin())
            || ($user->department === 'primary_care' && $user->isAdmin());

        abort_if(! $canExport, 403, 'EHI export requires it_admin, enrollment admin, or primary care admin.');
    }

    /**
     * POST /participants/{participant}/ehi-export
     * Initiates an EHI export and returns a time-limited download URL.
     */
    public function request(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeExport($participant, $user);

        $export = $this->ehiExportService->generate($participant, $user);

        AuditLog::record(
            action:       'ehi_export_generated',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "EHI export generated for {$participant->mrn}",
            newValues: ['export_id' => $export->id, 'expires_at' => $export->expires_at->toIso8601String()],
        );

        return response()->json([
            'export_id'    => $export->id,
            'status'       => $export->status,
            'download_url' => url("/participants/{$participant->id}/ehi-export/{$export->token}/download"),
            'expires_at'   => $export->expires_at->toIso8601String(),
        ], 202);
    }

    /**
     * GET /participants/{participant}/ehi-export/{token}/download
     * Streams the export ZIP file if token is valid and not expired.
     */
    public function download(Request $request, Participant $participant, string $token): BinaryFileResponse
    {
        $user   = $request->user();
        abort_if($participant->tenant_id !== $user->tenant_id, 403);

        $export = EhiExport::where('token', $token)
            ->where('participant_id', $participant->id)
            ->first();

        if (! $export) {
            abort(404);
        }

        if ($export->isExpired()) {
            $export->update(['status' => 'expired']);
            abort(410, 'EHI export has expired. Please generate a new export.');
        }

        if (! $export->isDownloadable()) {
            abort(404, 'Export is not ready.');
        }

        $export->update(['downloaded_at' => now()]);

        AuditLog::record(
            action:       'ehi_export_downloaded',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "EHI export downloaded for {$participant->mrn}",
        );

        $path = Storage::disk('local')->path($export->file_path);

        return response()->download(
            $path,
            "ehi_export_{$participant->mrn}_{$export->created_at->format('Ymd')}.zip",
            ['Content-Type' => 'application/zip']
        );
    }
}
