<?php

// ─── GrievanceController ──────────────────────────────────────────────────────
// Manages the grievance workflow per 42 CFR §460.120–§460.121.
//
// Authorization:
//   - Any authenticated user may file a grievance (POST store)
//   - QA Admin (qa_compliance | it_admin) may update/resolve/escalate/notify
//   - All authenticated users may view grievances for their own site
//   - QA Admin may view all sites within their tenant
//
// Routes:
//   GET  /grievances              → index (Inertia page)
//   POST /grievances              → store
//   GET  /grievances/overdue      → overdue (JSON feed for QA dashboard)
//   GET  /grievances/{id}         → show (Inertia page)
//   PUT  /grievances/{id}         → update
//   POST /grievances/{id}/resolve         → resolve
//   POST /grievances/{id}/escalate        → escalate
//   POST /grievances/{id}/notify-participant → notifyParticipant
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\EscalateGrievanceRequest;
use App\Http\Requests\NotifyParticipantGrievanceRequest;
use App\Http\Requests\ResolveGrievanceRequest;
use App\Http\Requests\StoreGrievanceRequest;
use App\Http\Requests\UpdateGrievanceRequest;
use App\Models\AuditLog;
use App\Models\Grievance;
use App\Models\Participant;
use App\Models\User;
use App\Services\GrievanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class GrievanceController extends Controller
{
    public function __construct(private readonly GrievanceService $service) {}

    // ── Authorization helpers ─────────────────────────────────────────────────

    /** Abort 403 if grievance does not belong to the user's tenant */
    private function authorizeTenant(Grievance $grievance): void
    {
        abort_if($grievance->tenant_id !== Auth::user()->tenant_id, 403);
    }

    /** Abort 403 if user is not qa_compliance or it_admin */
    private function authorizeQaAdmin(): void
    {
        $dept = Auth::user()->department;
        abort_unless(
            in_array($dept, ['qa_compliance', 'it_admin']) || Auth::user()->isSuperAdmin(),
            403
        );
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * Grievance list page.
     * QA Admin sees all grievances for their tenant.
     * Other roles see only grievances for their own site.
     */
    public function index(): Response
    {
        $user     = Auth::user();
        $tenantId = $user->tenant_id;
        $isQaAdmin = in_array($user->department, ['qa_compliance', 'it_admin']) || $user->isSuperAdmin();

        $query = Grievance::forTenant($tenantId)
            ->with(['participant:id,mrn,first_name,last_name', 'assignedTo:id,first_name,last_name'])
            ->orderBy('filed_at', 'desc');

        // Non-QA users see only their own site's grievances
        if (!$isQaAdmin) {
            $query->where('site_id', $user->site_id);
        }

        $all         = $query->get();
        $open        = $all->whereIn('status', ['open', 'under_review', 'escalated'])->values();
        $resolved    = $all->whereIn('status', ['resolved', 'withdrawn'])->values();
        $cmsReportable = $all->where('cms_reportable', true)->values();

        return Inertia::render('Grievances/Index', [
            'openGrievances'      => $open->map->toApiArray()->values(),
            'resolvedGrievances'  => $resolved->map->toApiArray()->values(),
            'cmsGrievances'       => $cmsReportable->map->toApiArray()->values(),
            'categories'          => Grievance::CATEGORY_LABELS,
            'statuses'            => Grievance::STATUS_LABELS,
            'priorities'          => Grievance::PRIORITIES,
            'isQaAdmin'           => $isQaAdmin,
        ]);
    }

    /**
     * File a new grievance. Any authenticated user may file.
     * Participant must belong to the same tenant.
     */
    public function store(StoreGrievanceRequest $request): RedirectResponse
    {
        $participant = Participant::findOrFail($request->participant_id);
        abort_if($participant->tenant_id !== Auth::user()->tenant_id, 403);

        $grievance = $this->service->open($participant, $request->validated(), Auth::user());

        return redirect()->route('grievances.show', $grievance->id)
            ->with('success', 'Grievance filed successfully.');
    }

    /**
     * JSON endpoint returning users with escalation-relevant designations.
     * Used to populate the "Escalate To" dropdown in Grievances/Show.tsx.
     *
     * Returns users holding 'compliance_officer' or 'medical_director' designations
     * within the current tenant. QA admin role required.
     *
     * GET /grievances/escalation-staff
     */
    public function escalationStaff(): JsonResponse
    {
        $this->authorizeQaAdmin();
        $tenantId = Auth::user()->tenant_id;

        $staff = User::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereJsonContains('designations', 'compliance_officer')
                      ->orWhereJsonContains('designations', 'medical_director')
                      ->orWhereJsonContains('designations', 'program_director');
            })
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'department', 'designations']);

        return response()->json([
            'staff' => $staff->map(fn($u) => [
                'id'           => $u->id,
                'name'         => "{$u->first_name} {$u->last_name}",
                'department'   => $u->department,
                'designations' => $u->designations ?? [],
                'label'        => collect($u->designations ?? [])
                    ->map(fn($d) => User::DESIGNATION_LABELS[$d] ?? $d)
                    ->join(', '),
            ])->values(),
        ]);
    }

    /**
     * JSON endpoint for QA dashboard "overdue grievances" feed.
     * Requires QA admin role.
     */
    public function overdue(): JsonResponse
    {
        $this->authorizeQaAdmin();
        $tenantId = Auth::user()->tenant_id;

        $urgentOverdue   = Grievance::forTenant($tenantId)->urgentOverdue()
            ->with('participant:id,mrn,first_name,last_name')->get();
        $standardOverdue = Grievance::forTenant($tenantId)->standardOverdue()
            ->with('participant:id,mrn,first_name,last_name')->get();

        return response()->json([
            'urgent_overdue'   => $urgentOverdue->map->toApiArray()->values(),
            'standard_overdue' => $standardOverdue->map->toApiArray()->values(),
            'total'            => $urgentOverdue->count() + $standardOverdue->count(),
        ]);
    }

    /**
     * Grievance detail page.
     */
    public function show(Grievance $grievance): Response
    {
        $this->authorizeTenant($grievance);

        $grievance->load([
            'participant:id,mrn,first_name,last_name',
            'receivedBy:id,first_name,last_name',
            'assignedTo:id,first_name,last_name',
            'escalatedTo:id,first_name,last_name,department,designations',
        ]);

        AuditLog::record(
            action:       'grievance.viewed',
            tenantId:     $grievance->tenant_id,
            userId:       Auth::id(),
            resourceType: 'grievance',
            resourceId:   $grievance->id,
            description:  "Grievance #{$grievance->id} viewed.",
        );

        // ── Activity timeline ─────────────────────────────────────────────────
        // Pull all meaningful audit events for this grievance, ordered oldest→newest.
        // For status_changed, new_values['status'] is set by the service for new
        // records; for older records we fall back to parsing the description string.
        $deptLabels = [
            'primary_care'     => 'Primary Care',
            'therapies'        => 'Therapies',
            'social_work'      => 'Social Work',
            'behavioral_health'=> 'Behavioral Health',
            'dietary'          => 'Dietary',
            'activities'       => 'Activities',
            'home_care'        => 'Home Care',
            'transportation'   => 'Transportation',
            'pharmacy'         => 'Pharmacy',
            'idt'              => 'IDT',
            'enrollment'       => 'Enrollment',
            'finance'          => 'Finance',
            'qa_compliance'    => 'QA Compliance',
            'it_admin'         => 'IT Admin',
            'executive'        => 'Executive',
            'super_admin'      => 'Nostos Admin',
        ];
        $statusLabelMap = [
            'under_review' => 'Marked Under Review',
            'resolved'     => 'Resolved',
            'escalated'    => 'Escalated',
            'withdrawn'    => 'Withdrawn',
        ];

        $activity = AuditLog::where('resource_type', 'grievance')
            ->where('resource_id', $grievance->id)
            ->whereIn('action', [
                'grievance.opened',
                'grievance.status_changed',
                'grievance.participant_notified',
            ])
            ->with('user:id,first_name,last_name,department')
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($log) use ($statusLabelMap, $deptLabels) {
                if ($log->action === 'grievance.status_changed') {
                    // new_values['status'] set for records created after W4-1 add-on;
                    // fall back to regex parse of description for older records.
                    $newStatus = $log->new_values['status'] ?? null;
                    if (!$newStatus) {
                        preg_match("/to '(\w+)'/", $log->description ?? '', $m);
                        $newStatus = $m[1] ?? null;
                    }
                    $label = $statusLabelMap[$newStatus] ?? 'Status Changed';
                    $status = $newStatus;
                } elseif ($log->action === 'grievance.opened') {
                    $label  = 'Grievance Filed';
                    $status = 'open';
                } else {
                    $label  = 'Participant Notified';
                    $status = 'notified';
                }

                $dept      = $log->user?->department;
                $deptLabel = $dept ? ($deptLabels[$dept] ?? ucfirst(str_replace('_', ' ', $dept))) : null;

                return [
                    'action'          => $log->action,
                    'label'           => $label,
                    'status'          => $status,
                    'user_name'       => $log->user
                        ? $log->user->first_name . ' ' . $log->user->last_name
                        : 'System',
                    'department'      => $dept,
                    'department_label'=> $deptLabel,
                    'timestamp'       => $log->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->toArray();

        $user      = Auth::user();
        $isQaAdmin = in_array($user->department, ['qa_compliance', 'it_admin']) || $user->isSuperAdmin();

        return Inertia::render('Grievances/Show', [
            'grievance'   => array_merge($grievance->toApiArray(), [
                'description'         => $grievance->description,
                'investigation_notes' => $grievance->investigation_notes,
                'escalation_reason'   => $grievance->escalation_reason,
                'notification_method' => $grievance->notification_method,
                'received_by'         => $grievance->receivedBy
                    ? $grievance->receivedBy->first_name . ' ' . $grievance->receivedBy->last_name
                    : null,
            ]),
            'activity'    => $activity,
            'categories'  => Grievance::CATEGORY_LABELS,
            'statuses'    => Grievance::STATUS_LABELS,
            'isQaAdmin'   => $isQaAdmin,
            'notificationMethods' => Grievance::NOTIFICATION_METHODS,
        ]);
    }

    /**
     * Update investigation notes or assigned staff.
     * Requires the assigned user or QA admin.
     */
    public function update(UpdateGrievanceRequest $request, Grievance $grievance): JsonResponse
    {
        $this->authorizeTenant($grievance);
        $this->authorizeQaAdmin();

        if ($grievance->isClosed()) {
            return response()->json(['message' => 'Cannot update a closed grievance.'], 409);
        }

        $grievance->update($request->validated());

        return response()->json(['grievance' => $grievance->fresh()->toApiArray()]);
    }

    /**
     * Transition a grievance from 'open' → 'under_review'.
     * No extra data required — QA admin clicking "Start Investigation".
     */
    public function startReview(Grievance $grievance): JsonResponse
    {
        $this->authorizeTenant($grievance);
        $this->authorizeQaAdmin();

        try {
            $this->service->updateStatus($grievance, 'under_review', [], Auth::user());
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['grievance' => $grievance->fresh()->toApiArray()]);
    }

    /**
     * Resolve a grievance with required resolution text and date.
     */
    public function resolve(ResolveGrievanceRequest $request, Grievance $grievance): JsonResponse
    {
        $this->authorizeTenant($grievance);
        $this->authorizeQaAdmin();

        try {
            $this->service->updateStatus($grievance, 'resolved', $request->validated(), Auth::user());
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['grievance' => $grievance->fresh()->toApiArray()]);
    }

    /**
     * Escalate a grievance (unresolved, requires escalation_reason).
     * Optionally targets a specific staff member via escalated_to_user_id.
     * That user must be active and belong to the same tenant.
     */
    public function escalate(EscalateGrievanceRequest $request, Grievance $grievance): JsonResponse
    {
        $this->authorizeTenant($grievance);
        $this->authorizeQaAdmin();

        $data = $request->validated();

        // Validate escalated_to_user_id for tenant isolation — cannot target cross-tenant user
        if (!empty($data['escalated_to_user_id'])) {
            $targetUser = User::where('id', $data['escalated_to_user_id'])
                ->where('tenant_id', Auth::user()->tenant_id)
                ->where('is_active', true)
                ->first();

            if (!$targetUser) {
                return response()->json(['message' => 'Selected user not found or not in your organization.'], 422);
            }
        }

        try {
            $this->service->updateStatus($grievance, 'escalated', $data, Auth::user());
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['grievance' => $grievance->fresh()->load('escalatedTo')->toApiArray()]);
    }

    /**
     * Withdraw a grievance (terminal state).
     * Valid from any active status (open, under_review, escalated).
     * Optional withdrawal_reason is stored in investigation_notes.
     */
    public function withdraw(\Illuminate\Http\Request $request, Grievance $grievance): JsonResponse
    {
        $this->authorizeTenant($grievance);
        $this->authorizeQaAdmin();

        $data = [];
        if ($request->filled('withdrawal_reason')) {
            // Append to investigation notes so prior notes are preserved
            $existing = $grievance->investigation_notes ? $grievance->investigation_notes . "\n\n" : '';
            $data['investigation_notes'] = $existing . 'Withdrawal reason: ' . trim($request->withdrawal_reason);
        }

        try {
            $this->service->updateStatus($grievance, 'withdrawn', $data, Auth::user());
        } catch (LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['grievance' => $grievance->fresh()->toApiArray()]);
    }

    /**
     * Record that the participant was notified of the grievance outcome.
     * CMS §460.120(d): participants must be notified of the resolution.
     */
    public function notifyParticipant(NotifyParticipantGrievanceRequest $request, Grievance $grievance): JsonResponse
    {
        $this->authorizeTenant($grievance);
        $this->authorizeQaAdmin();

        $this->service->notifyParticipant($grievance, $request->notification_method, Auth::user());

        return response()->json(['grievance' => $grievance->fresh()->toApiArray()]);
    }
}
