<?php

// ─── WoundController ─────────────────────────────────────────────────────────
// Manages wound records and periodic assessments for PACE participants.
// All endpoints are nested under /participants/{participant}/wounds/.
//
// Write access: nursing departments (home_care, primary_care) + it_admin.
// Read access: all authenticated users with participant access.
//
// Routes:
//   GET    /participants/{p}/wounds                  → index()
//   POST   /participants/{p}/wounds                  → store()
//   GET    /participants/{p}/wounds/{w}              → show()
//   PUT    /participants/{p}/wounds/{w}              → update()
//   POST   /participants/{p}/wounds/{w}/assess       → addAssessment()
//   POST   /participants/{p}/wounds/{w}/close        → close()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Models\WoundRecord;
use App\Services\WoundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WoundController extends Controller
{
    // Departments authorized to document wounds (nursing + clinical leads)
    private const WRITE_DEPARTMENTS = ['home_care', 'primary_care', 'therapies', 'it_admin'];

    public function __construct(
        private readonly WoundService $service,
    ) {}

    // ── Auth helpers ──────────────────────────────────────────────────────────

    private function authorizeTenant(Participant $participant): void
    {
        abort_if($participant->tenant_id !== Auth::user()->tenant_id, 403);
    }

    private function authorizeWrite(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && ! in_array($user->department, self::WRITE_DEPARTMENTS, true)) {
            abort(403, 'Only nursing and clinical departments may document wounds.');
        }
    }

    private function authorizeWound(WoundRecord $wound, Participant $participant): void
    {
        abort_if($wound->participant_id !== $participant->id, 403);
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * List all wound records for a participant (open + healed).
     * Open wounds first, then healed/closed sorted by date.
     *
     * GET /participants/{participant}/wounds
     */
    public function index(Participant $participant): JsonResponse
    {
        $this->authorizeTenant($participant);

        $open   = WoundRecord::forParticipant($participant->id)->open()
            ->with(['documentedBy:id,first_name,last_name', 'assessments.assessedBy:id,first_name,last_name'])
            ->orderBy('first_identified_date', 'asc')
            ->get()
            ->map(fn ($w) => $w->load(['documentedBy', 'assessments.assessedBy'])->toApiArray());

        $healed = WoundRecord::forParticipant($participant->id)->where('status', 'healed')
            ->with(['documentedBy:id,first_name,last_name', 'assessments.assessedBy:id,first_name,last_name'])
            ->orderByDesc('healed_date')
            ->get()
            ->map(fn ($w) => $w->toApiArray());

        return response()->json([
            'open'   => $open,
            'healed' => $healed,
        ]);
    }

    /**
     * Open a new wound record.
     *
     * POST /participants/{participant}/wounds
     */
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeWrite();

        $data = $request->validate([
            'wound_type'               => 'required|in:' . implode(',', WoundRecord::WOUND_TYPES),
            'location'                 => 'required|string|max:255',
            'pressure_injury_stage'    => 'nullable|in:' . implode(',', WoundRecord::PRESSURE_STAGES),
            'length_cm'                => 'nullable|numeric|min:0|max:999.9',
            'width_cm'                 => 'nullable|numeric|min:0|max:999.9',
            'depth_cm'                 => 'nullable|numeric|min:0|max:999.9',
            'wound_bed'                => 'nullable|in:granulation,slough,eschar,epithelialization,mixed,not_visible',
            'exudate_amount'           => 'nullable|in:none,scant,light,moderate,heavy',
            'exudate_type'             => 'nullable|in:serous,serosanguineous,sanguineous,purulent',
            'periwound_skin'           => 'nullable|in:intact,macerated,erythema,callus,other',
            'odor'                     => 'boolean',
            'pain_score'               => 'nullable|integer|min:0|max:10',
            'treatment_description'    => 'nullable|string',
            'dressing_type'            => 'nullable|string|max:255',
            'dressing_change_frequency'=> 'nullable|string|max:100',
            'goal'                     => 'nullable|in:healing,maintenance,palliative',
            'first_identified_date'    => 'required|date',
            'photo_taken'              => 'boolean',
            'notes'                    => 'nullable|string',
        ]);

        $data['documented_by_user_id'] = Auth::id();
        $data['site_id']               = Auth::user()->site_id ?? $participant->site_id;

        $wound = $this->service->open($participant, $data);
        $wound->load(['documentedBy:id,first_name,last_name', 'assessments']);

        return response()->json($wound->toApiArray(), 201);
    }

    /**
     * Get wound detail with full assessment history.
     *
     * GET /participants/{participant}/wounds/{wound}
     */
    public function show(Participant $participant, WoundRecord $wound): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeWound($wound, $participant);

        $wound->load(['documentedBy:id,first_name,last_name', 'assessments.assessedBy:id,first_name,last_name']);

        return response()->json(array_merge($wound->toApiArray(), [
            'assessments' => $wound->assessments->map(fn ($a) => $a->toApiArray())->values(),
        ]));
    }

    /**
     * Update wound record metadata (not an assessment — use addAssessment for measurements).
     *
     * PUT /participants/{participant}/wounds/{wound}
     */
    public function update(Request $request, Participant $participant, WoundRecord $wound): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeWrite();
        $this->authorizeWound($wound, $participant);

        $data = $request->validate([
            'location'                 => 'sometimes|string|max:255',
            'pressure_injury_stage'    => 'nullable|in:' . implode(',', WoundRecord::PRESSURE_STAGES),
            'wound_bed'                => 'nullable|in:granulation,slough,eschar,epithelialization,mixed,not_visible',
            'exudate_amount'           => 'nullable|in:none,scant,light,moderate,heavy',
            'exudate_type'             => 'nullable|in:serous,serosanguineous,sanguineous,purulent',
            'periwound_skin'           => 'nullable|in:intact,macerated,erythema,callus,other',
            'odor'                     => 'boolean',
            'pain_score'               => 'nullable|integer|min:0|max:10',
            'treatment_description'    => 'nullable|string',
            'dressing_type'            => 'nullable|string|max:255',
            'dressing_change_frequency'=> 'nullable|string|max:100',
            'goal'                     => 'nullable|in:healing,maintenance,palliative',
            'status'                   => 'sometimes|in:' . implode(',', WoundRecord::STATUSES),
            'photo_taken'              => 'boolean',
            'notes'                    => 'nullable|string',
        ]);

        $wound->update($data);
        $wound->load(['documentedBy:id,first_name,last_name', 'assessments']);

        return response()->json($wound->toApiArray());
    }

    /**
     * Add a periodic assessment (re-measurement) to an existing wound.
     * If status_change=healed, the wound record is closed automatically.
     *
     * POST /participants/{participant}/wounds/{wound}/assess
     */
    public function addAssessment(Request $request, Participant $participant, WoundRecord $wound): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeWrite();
        $this->authorizeWound($wound, $participant);

        abort_if($wound->status === 'healed', 409, 'Cannot add assessment to a healed wound.');

        $data = $request->validate([
            'assessed_at'          => 'nullable|date',
            'length_cm'            => 'nullable|numeric|min:0|max:999.9',
            'width_cm'             => 'nullable|numeric|min:0|max:999.9',
            'depth_cm'             => 'nullable|numeric|min:0|max:999.9',
            'wound_bed'            => 'nullable|in:granulation,slough,eschar,epithelialization,mixed,not_visible',
            'exudate_amount'       => 'nullable|in:none,scant,light,moderate,heavy',
            'exudate_type'         => 'nullable|in:serous,serosanguineous,sanguineous,purulent',
            'periwound_skin'       => 'nullable|in:intact,macerated,erythema,callus,other',
            'odor'                 => 'boolean',
            'pain_score'           => 'nullable|integer|min:0|max:10',
            'treatment_description'=> 'nullable|string',
            'status_change'        => 'nullable|in:improved,unchanged,deteriorated,healed',
            'notes'                => 'nullable|string',
        ]);

        $data['assessed_by_user_id'] = Auth::id();

        $assessment = $this->service->addAssessment($wound, $data);

        // Reload wound to reflect potential status update
        $wound->refresh()->load(['documentedBy:id,first_name,last_name', 'assessments']);

        return response()->json([
            'assessment' => $assessment->load('assessedBy:id,first_name,last_name')->toApiArray(),
            'wound'      => $wound->toApiArray(),
        ], 201);
    }

    /**
     * Mark a wound as healed/closed.
     *
     * POST /participants/{participant}/wounds/{wound}/close
     */
    public function close(Request $request, Participant $participant, WoundRecord $wound): JsonResponse
    {
        $this->authorizeTenant($participant);
        $this->authorizeWrite();
        $this->authorizeWound($wound, $participant);

        abort_if($wound->status === 'healed', 409, 'Wound is already healed.');

        $data = $request->validate([
            'healed_date' => 'nullable|date',
            'notes'       => 'nullable|string',
        ]);

        $wound->update([
            'status'      => 'healed',
            'healed_date' => $data['healed_date'] ?? now()->toDateString(),
            'notes'       => $data['notes'] ?? $wound->notes,
        ]);

        return response()->json($wound->fresh()->toApiArray());
    }
}
