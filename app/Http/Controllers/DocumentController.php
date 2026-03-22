<?php

// ─── DocumentController ───────────────────────────────────────────────────────
// Manages participant-level document uploads, listing, streaming, and deletion.
//
// Routes (all nested under /participants/{participant}):
//   GET    /documents                  → index()    — paginated list, optional ?category= filter
//   POST   /documents                  → store()    — upload file (multipart/form-data)
//   GET    /documents/{document}       → download() — stream file to browser
//   DELETE /documents/{document}       → destroy()  — soft-delete (HIPAA: never hard-delete)
//
// Security:
//   - All routes are inside the 'auth' middleware group.
//   - Tenant isolation: participant must belong to auth user's tenant.
//   - File path is NEVER exposed in API responses — download goes through controller.
//   - Soft-delete only. Hard delete is prohibited.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    // ── List ──────────────────────────────────────────────────────────────────

    /**
     * Return a paginated JSON list of documents for a participant.
     *
     * Query params:
     *   category — optional filter (must match Document::VALID_CATEGORIES)
     *   per_page — optional page size (default 20, max 100)
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->participantForTenant($participant);

        $query = Document::where('participant_id', $participant->id)
            ->forTenant(auth()->user()->tenant_id)
            ->with('uploader:id,first_name,last_name')
            ->orderBy('uploaded_at', 'desc');

        if ($request->filled('category') && in_array($request->category, Document::VALID_CATEGORIES)) {
            $query->byCategory($request->category);
        }

        $perPage = min((int) ($request->per_page ?? 20), 100);
        $docs    = $query->paginate($perPage);

        return response()->json([
            'data'       => $docs->map(fn (Document $d) => $d->toApiArray()),
            'total'      => $docs->total(),
            'per_page'   => $docs->perPage(),
            'current_page' => $docs->currentPage(),
            'last_page'  => $docs->lastPage(),
            'categories' => Document::CATEGORY_LABELS,
        ]);
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    /**
     * Upload a document for a participant.
     *
     * Stores the file to storage/app/participants/{participant_id}/
     * and creates a Document record. File path is never returned to the client.
     */
    public function store(StoreDocumentRequest $request, Participant $participant): JsonResponse
    {
        $this->participantForTenant($participant);

        $file       = $request->file('file');
        $ext        = strtolower($file->getClientOriginalExtension());
        $fileType   = $ext === 'jpg' ? 'jpeg' : $ext;
        $storagePath = "participants/{$participant->id}/" . uniqid('doc_', true) . ".{$ext}";

        // Store file on local disk — path is relative to storage/app/
        Storage::disk('local')->put($storagePath, file_get_contents($file->getRealPath()));

        $document = Document::create([
            'participant_id'      => $participant->id,
            'tenant_id'           => auth()->user()->tenant_id,
            'site_id'             => auth()->user()->site_id ?? null,
            'file_name'           => $file->getClientOriginalName(),
            'file_path'           => $storagePath,
            'file_type'           => $fileType,
            'file_size_bytes'     => $file->getSize(),
            'description'         => $request->description,
            'document_category'   => $request->document_category,
            'uploaded_by_user_id' => auth()->id(),
            // uploaded_at is set automatically via CREATED_AT = 'uploaded_at'
        ]);

        AuditLog::record(
            action: 'document.upload',
            userId: auth()->id(),
            tenantId: auth()->user()->tenant_id,
            resourceType: 'document',
            resourceId: $document->id,
            newValues: [
                'participant_id'    => $participant->id,
                'file_name'         => $document->file_name,
                'document_category' => $document->document_category,
                'file_size_bytes'   => $document->file_size_bytes,
            ]
        );

        $document->load('uploader:id,first_name,last_name');

        return response()->json(['document' => $document->toApiArray()], 201);
    }

    // ── Stream Download ───────────────────────────────────────────────────────

    /**
     * Stream a document file to the browser.
     *
     * Sets Content-Disposition: inline for PDFs and images (browser preview),
     * attachment for DOCX (force download).
     *
     * The file path is NEVER exposed — client only knows the document ID.
     */
    public function download(Participant $participant, Document $document): StreamedResponse
    {
        $this->participantForTenant($participant);
        $this->documentBelongsToParticipant($document, $participant);

        abort_unless(Storage::disk('local')->exists($document->file_path), 404, 'File not found on storage.');

        AuditLog::record(
            action: 'document.download',
            userId: auth()->id(),
            tenantId: auth()->user()->tenant_id,
            resourceType: 'document',
            resourceId: $document->id,
            newValues: ['file_name' => $document->file_name]
        );

        $inline     = in_array($document->file_type, ['pdf', 'jpeg', 'png']);
        $disposition = $inline ? 'inline' : 'attachment';
        $mimeTypes  = [
            'pdf'  => 'application/pdf',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $mime = $mimeTypes[$document->file_type] ?? 'application/octet-stream';

        return Storage::disk('local')->download(
            $document->file_path,
            $document->file_name,
            [
                'Content-Type'        => $mime,
                'Content-Disposition' => "{$disposition}; filename=\"{$document->file_name}\"",
            ]
        );
    }

    // ── Soft Delete ───────────────────────────────────────────────────────────

    /**
     * Soft-delete a document record (file bytes are retained per HIPAA).
     *
     * Only the uploader or an it_admin/super_admin user may delete.
     */
    public function destroy(Participant $participant, Document $document): JsonResponse
    {
        $this->participantForTenant($participant);
        $this->documentBelongsToParticipant($document, $participant);

        $user = auth()->user();
        $canDelete = $document->uploaded_by_user_id === $user->id
            || in_array($user->department, ['it_admin'])
            || $user->isSuperAdmin();

        abort_unless($canDelete, 403, 'Only the uploader or an admin may delete documents.');

        AuditLog::record(
            action: 'document.delete',
            userId: auth()->id(),
            tenantId: auth()->user()->tenant_id,
            resourceType: 'document',
            resourceId: $document->id,
            newValues: ['file_name' => $document->file_name]
        );

        $document->delete(); // Soft delete — file bytes retained on disk

        return response()->json(['message' => 'Document removed.']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Confirm the participant belongs to the authenticated user's tenant.
     * Aborts 403 if not — prevents cross-tenant access.
     */
    private function participantForTenant(Participant $participant): void
    {
        abort_unless(
            $participant->tenant_id === auth()->user()->tenant_id,
            403,
            'Access denied.'
        );
    }

    /**
     * Confirm the document belongs to the given participant.
     * Aborts 404 if not — prevents probing other participants' documents.
     */
    private function documentBelongsToParticipant(Document $document, Participant $participant): void
    {
        abort_unless(
            $document->participant_id === $participant->id,
            404,
            'Document not found.'
        );
    }
}
