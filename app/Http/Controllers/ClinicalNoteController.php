<?php

// ─── ClinicalNoteController ───────────────────────────────────────────────────
// Manages clinical notes for a participant.
// Notes start as 'draft' and become immutable once signed.
// Addenda create a new child note (parent_note_id) against a signed note.
// All routes are nested under /participants/{participant}/notes.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Events\ClinicalNoteSignedEvent;
use App\Http\Requests\StoreClinicalNoteRequest;
use App\Http\Requests\UpdateClinicalNoteRequest;
use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicalNoteController extends Controller
{
    // ── Tenant isolation ──────────────────────────────────────────────────────

    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    private function authorizeNoteForParticipant(ClinicalNote $note, Participant $participant): void
    {
        abort_if($note->participant_id !== $participant->id, 404);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * GET /participants/{participant}/notes
     * Returns paginated notes, newest first.
     * Supports ?status=draft|signed, ?note_type=soap, ?department=primary_care
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $query = $participant->clinicalNotes()
            ->with(['author:id,first_name,last_name,department', 'site:id,name'])
            ->orderByDesc('visit_date')
            ->orderByDesc('created_at');

        // Cross-department viewers (clinical, IDT, QA, IT Admin) see all notes.
        // Departments outside this list see only their own department's notes.
        $crossDeptViewers = [
            'primary_care', 'therapies', 'social_work', 'behavioral_health',
            'home_care', 'idt', 'qa_compliance', 'it_admin',
        ];
        if (! in_array($user->department, $crossDeptViewers, true)) {
            $query->where('department', $user->department);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->input('note_type')) {
            $query->where('note_type', $type);
        }
        if ($dept = $request->input('department')) {
            $query->where('department', $dept);
        }

        AuditLog::record(
            action: 'participant.notes.viewed',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Clinical notes viewed for {$participant->mrn}",
        );

        return response()->json($query->paginate(50));
    }

    /**
     * POST /participants/{participant}/notes
     * Creates a new draft clinical note.
     */
    public function store(StoreClinicalNoteRequest $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $note = ClinicalNote::create(array_merge($request->validated(), [
            'participant_id'      => $participant->id,
            'tenant_id'           => $user->tenant_id,
            'site_id'             => $participant->site_id,
            'authored_by_user_id' => $user->id,
            'status'              => ClinicalNote::STATUS_DRAFT,
        ]));

        AuditLog::record(
            action: 'participant.note.created',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Draft {$note->noteTypeLabel()} note created for {$participant->mrn}",
            newValues: ['note_id' => $note->id, 'note_type' => $note->note_type],
        );

        return response()->json($note->load('author:id,first_name,last_name'), 201);
    }

    /**
     * GET /participants/{participant}/notes/{note}
     * Returns full note with author and signer.
     */
    public function show(Request $request, Participant $participant, ClinicalNote $note): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeNoteForParticipant($note, $participant);

        return response()->json(
            $note->load('author:id,first_name,last_name,department', 'signedBy:id,first_name,last_name', 'addenda.author:id,first_name,last_name')
        );
    }

    /**
     * PUT /participants/{participant}/notes/{note}
     * Updates a draft note. Only the author may edit; signed notes cannot be changed.
     */
    public function update(UpdateClinicalNoteRequest $request, Participant $participant, ClinicalNote $note): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeNoteForParticipant($note, $participant);

        // Belt-and-suspenders: controller enforces editability even if FormRequest passes
        abort_unless($note->canEdit($user), 403, 'Only the note author can edit a draft note.');

        $note->update($request->validated());

        AuditLog::record(
            action: 'participant.note.updated',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Draft note updated for {$participant->mrn}",
            newValues: ['note_id' => $note->id],
        );

        return response()->json($note->fresh('author:id,first_name,last_name'));
    }

    /**
     * POST /participants/{participant}/notes/{note}/sign
     * Signs a draft note. Only the author (same department) may sign.
     * Once signed the note is permanently immutable.
     */
    public function sign(Request $request, Participant $participant, ClinicalNote $note): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeNoteForParticipant($note, $participant);

        abort_unless($note->isDraft(), 403, 'Only draft notes can be signed.');
        abort_unless(
            $note->authored_by_user_id === $user->id,
            403,
            'Only the original author may sign this note.'
        );

        $note->sign($user);

        AuditLog::record(
            action: 'participant.note.signed',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "{$note->noteTypeLabel()} note signed for {$participant->mrn}",
            newValues: ['note_id' => $note->id, 'signed_at' => $note->signed_at],
        );

        // Phase 4: broadcast for real-time cross-dept chart tab refresh
        broadcast(new ClinicalNoteSignedEvent($note->load('author:id,first_name,last_name,department')))->toOthers();

        return response()->json($note->fresh('author:id,first_name,last_name', 'signedBy:id,first_name,last_name'));
    }

    /**
     * POST /participants/{participant}/notes/{note}/addendum
     * Creates a new addendum note linked to the parent note.
     * Can be added to any signed note by any clinician with access.
     */
    public function addendum(StoreClinicalNoteRequest $request, Participant $participant, ClinicalNote $note): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeNoteForParticipant($note, $participant);

        abort_unless($note->isSigned(), 422, 'Addenda can only be added to signed notes.');

        $addendum = ClinicalNote::create(array_merge($request->validated(), [
            'participant_id'      => $participant->id,
            'tenant_id'           => $user->tenant_id,
            'site_id'             => $participant->site_id,
            'authored_by_user_id' => $user->id,
            'note_type'           => 'addendum',
            'status'              => ClinicalNote::STATUS_DRAFT,
            'parent_note_id'      => $note->id,
        ]));

        AuditLog::record(
            action: 'participant.note.addendum_created',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Addendum created for note #{$note->id} on {$participant->mrn}",
            newValues: ['addendum_id' => $addendum->id, 'parent_note_id' => $note->id],
        );

        return response()->json($addendum->load('author:id,first_name,last_name'), 201);
    }
}
