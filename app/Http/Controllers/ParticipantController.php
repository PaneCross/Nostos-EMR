<?php

// ─── ParticipantController ─────────────────────────────────────────────────────
// Manages the PACE participant directory and individual participant profiles.
//
// Routes (all behind auth + CheckDepartmentAccess middleware):
//   GET    /participants                → index()   Inertia: directory with search/filter
//   POST   /participants                → store()   Create new participant (enrollment only)
//   GET    /participants/{id}           → show()    Inertia: full profile (12-tab layout)
//   PUT    /participants/{id}           → update()  Update demographics/enrollment fields
//   DELETE /participants/{id}           → destroy() Soft-delete (enrollment admin or it_admin)
//   GET    /participants/search?q=...   → search()  JSON: global search widget (header)
//
// Permission model:
//   canCreate    → enrollment department only (enforced by CheckDepartmentAccess)
//   canEdit      → admin role OR enrollment/it_admin department
//   canDelete    → enrollment admin OR it_admin
//   canViewAudit → it_admin OR qa_compliance (PHI access audit trail)
//
// Data loading strategy for show():
//   Pre-loaded: addresses, contacts, flags, insurances, problems, allergies, vitals (≤100)
//   Lazy-loaded (per tab, via JSON endpoints): notes, assessments, ADL records
//   Reason: problems and allergies are small datasets (~2-10 rows) needed immediately to
//   render the life-threatening allergy banner and problems summary; vitals pre-load enables
//   the Vitals tab to render without a second request.
//
// All mutations write to shared_audit_logs (append-only, HIPAA requirement).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreParticipantRequest;
use App\Http\Requests\UpdateParticipantRequest;
use App\Models\AuditLog;
use App\Models\Icd10Lookup;
use App\Models\Participant;
use App\Models\ParticipantAddress;
use App\Models\Site;
use App\Services\NoteTemplateService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ParticipantController extends Controller
{
    public function __construct(private NoteTemplateService $noteTemplateService) {}

    // ─── Directory ─────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $user     = $request->user();
        $tenantId = $user->tenant_id;

        $query = Participant::forTenant($tenantId)
            ->with(['site', 'activeFlags'])
            ->orderBy('last_name')
            ->orderBy('first_name');

        // Search
        if ($search = $request->input('search')) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $search)) {
                $query->searchByDob($search);
            } else {
                $query->search($search);
            }
        }

        // Filters
        if ($status = $request->input('status')) {
            $query->where('enrollment_status', $status);
        }
        if ($siteId = $request->input('site_id')) {
            $query->where('site_id', $siteId);
        }
        if ($request->boolean('has_flags')) {
            $query->whereHas('activeFlags');
        }

        $participants = $query->paginate(50)->withQueryString();

        // Log the search to audit trail
        AuditLog::record(
            action:       'participant.directory.viewed',
            tenantId:     $tenantId,
            userId:       $user->id,
            resourceType: 'participant',
            description:  'Participant directory viewed' . ($search ? " (search: {$search})" : ''),
        );

        return Inertia::render('Participants/Index', [
            'participants' => $participants,
            'sites'        => Site::where('tenant_id', $tenantId)->get(['id', 'name']),
            'filters'      => $request->only(['search', 'status', 'site_id', 'has_flags']),
            'canCreate'    => $user->department === 'enrollment',
        ]);
    }

    // ─── Create ────────────────────────────────────────────────────────────────

    public function store(StoreParticipantRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        $addressData = $data['address'] ?? null;
        unset($data['address']);

        $participant = Participant::create(array_merge($data, [
            'tenant_id'          => $user->tenant_id,
            'created_by_user_id' => $user->id,
        ]));

        if ($addressData && isset($addressData['street'])) {
            ParticipantAddress::create(array_merge($addressData, [
                'participant_id' => $participant->id,
                'address_type'   => 'home',
                'is_primary'     => true,
            ]));
        }

        AuditLog::record(
            action:       'participant.created',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Created participant {$participant->mrn}: {$participant->fullName()}",
            newValues:    $data,
        );

        return redirect()->route('participants.show', $participant->id)
            ->with('success', "Participant {$participant->mrn} created.");
    }

    // ─── Profile ───────────────────────────────────────────────────────────────

    public function show(Request $request, Participant $participant): Response
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $participant->load(['site', 'tenant', 'createdBy']);

        // HIPAA: audit trail access restricted to IT and QA only — clinical staff
        // should not see who else has accessed a participant's chart.
        $canViewAudit = in_array($user->department, ['it_admin', 'qa_compliance']);

        AuditLog::record(
            action:       'participant.profile.viewed',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Profile viewed for {$participant->mrn}",
        );

        // ── Phase 3: pre-load small clinical datasets + ICD-10 for typeahead ──────
        // Vitals, notes, assessments, ADL are loaded lazily per tab via JSON endpoints.
        // Problems (~2-4 per participant) and allergies (~0-3) are small enough to pre-load.
        $lifeThreateningAllergyCount = $participant->activeLifeThreateningAllergies()->count();

        return Inertia::render('Participants/Show', [
            'participant'  => $participant,
            'addresses'    => $participant->addresses()->orderBy('is_primary', 'desc')->get(),
            'contacts'     => $participant->contacts()->orderBy('priority_order')->get(),
            'flags'        => $participant->flags()->with('createdBy', 'resolvedBy')->latest()->get(),
            'insurances'   => $participant->insuranceCoverages()->orderBy('payer_type')->get(),

            // ── Phase 3: clinical props ────────────────────────────────────────
            'problems'                     => $participant->problems()
                ->with('addedBy:id,first_name,last_name')
                ->orderBy('is_primary_diagnosis', 'desc')
                ->orderBy('status')
                ->get(),
            'allergies'                    => $participant->allergies()
                ->with('verifiedBy:id,first_name,last_name')
                ->where('is_active', true)
                ->get(),
            'lifeThreateningAllergyCount'  => $lifeThreateningAllergyCount,
            'vitals'                       => $participant->vitals()
                ->with('recordedBy:id,first_name,last_name')
                ->orderByDesc('recorded_at')
                ->limit(100)
                ->get(),
            'icd10Codes'                   => Icd10Lookup::orderBy('code')
                ->get(['code', 'description', 'category']),
            'noteTemplates'                => $this->noteTemplateService->all(),
            // ──────────────────────────────────────────────────────────────────

            'auditLogs'    => $canViewAudit
                ? AuditLog::where('resource_type', 'participant')
                    ->where('resource_id', $participant->id)
                    ->latest()
                    ->limit(100)
                    ->get()
                : [],
            'canEdit'      => $user->isAdmin() || in_array($user->department, ['enrollment', 'it_admin']),
            'canDelete'    => $user->department === 'enrollment' && $user->isAdmin()
                          || $user->department === 'it_admin',
            'canViewAudit' => $canViewAudit,
        ]);
    }

    // ─── Update ────────────────────────────────────────────────────────────────

    public function update(UpdateParticipantRequest $request, Participant $participant)
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $old  = $participant->only(array_keys($request->validated()));
        $participant->update($request->validated());

        AuditLog::record(
            action:       'participant.updated',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Participant {$participant->mrn} updated",
            oldValues:    $old,
            newValues:    $request->validated(),
        );

        return back()->with('success', 'Participant record updated.');
    }

    // ─── Soft Delete ───────────────────────────────────────────────────────────

    public function destroy(Request $request, Participant $participant)
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $canDelete = ($user->department === 'enrollment' && $user->isAdmin())
                  || $user->department === 'it_admin';

        abort_unless($canDelete, 403, 'Only Enrollment Admin or IT Admin can deactivate participants.');

        $participant->delete();

        AuditLog::record(
            action:       'participant.deleted',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Participant {$participant->mrn} soft-deleted",
        );

        return redirect()->route('participants.index')
            ->with('success', "Participant {$participant->mrn} deactivated.");
    }

    // ─── Global Search (JSON) ──────────────────────────────────────────────────

    public function search(Request $request)
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        $user     = $request->user();
        $term     = $request->input('q');
        $tenantId = $user->tenant_id;

        $query = Participant::forTenant($tenantId)
            ->with('activeFlags')
            ->limit(15);

        // DOB search
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $term)) {
            $query->searchByDob($term);
        } else {
            $query->search($term);
        }

        $results = $query->get()->map(fn ($p) => [
            'id'                => $p->id,
            'mrn'               => $p->mrn,
            'name'              => $p->fullName(),
            'dob'               => $p->dob->format('Y-m-d'),
            'age'               => $p->age(),
            'enrollment_status' => $p->enrollment_status,
            'flags'             => $p->activeFlags->pluck('flag_type')->toArray(),
        ]);

        AuditLog::record(
            action:       'participant.searched',
            tenantId:     $tenantId,
            userId:       $user->id,
            resourceType: 'participant',
            description:  "Global search: \"{$term}\" ({$results->count()} results)",
        );

        return response()->json($results);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }
}
