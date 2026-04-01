<?php

// ─── QapiController ───────────────────────────────────────────────────────────
// CRUD for QAPI (Quality Assessment and Performance Improvement) projects.
//
// 42 CFR §460.136–§460.140: PACE organizations must maintain at least 2 active
// QI projects at any time of the year. This controller tracks the full project
// lifecycle: planning → active → remeasuring → completed.
//
// Access: qa_compliance + it_admin (write), all authenticated (read).
// Remeasure endpoint: qa_compliance + it_admin only.
//
// Routes:
//   GET    /qapi/projects           → index (Inertia page)
//   POST   /qapi/projects           → store
//   GET    /qapi/projects/{id}      → show (JSON)
//   PATCH  /qapi/projects/{id}      → update
//   POST   /qapi/projects/{id}/remeasure → advance status to remeasuring
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\QapiProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QapiController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Abort 403 unless user is qa_compliance or it_admin department (or super_admin). */
    private function requireQaOrAdmin(): void
    {
        $user = auth()->user();
        if (
            $user->isSuperAdmin() ||
            in_array($user->department, ['qa_compliance', 'it_admin'], true)
        ) {
            return;
        }
        abort(403, 'Access restricted to QA Compliance and IT Admin departments.');
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * GET /qapi/projects
     * Inertia page with QAPI project board.
     */
    public function index(Request $request): Response
    {
        $user = auth()->user();
        $projects = QapiProject::forTenant($user->tenant_id)
            ->with('projectLead:id,first_name,last_name')
            ->orderByRaw("CASE status
                WHEN 'active' THEN 1
                WHEN 'remeasuring' THEN 2
                WHEN 'planning' THEN 3
                WHEN 'suspended' THEN 4
                WHEN 'completed' THEN 5
                ELSE 6 END")
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(fn($p) => $this->toApiArray($p));

        $activeCount = QapiProject::forTenant($user->tenant_id)->active()->count();
        $meetsMinimum = $activeCount >= QapiProject::MIN_ACTIVE_PROJECTS;

        return Inertia::render('Qapi/Projects', [
            'projects'          => $projects,
            'active_count'      => $activeCount,
            'meets_minimum'     => $meetsMinimum,
            'min_required'      => QapiProject::MIN_ACTIVE_PROJECTS,
            'statuses'          => QapiProject::STATUS_LABELS,
            'domains'           => QapiProject::DOMAIN_LABELS,
        ]);
    }

    /**
     * POST /qapi/projects
     * Create a new QAPI quality improvement project.
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireQaOrAdmin();

        $validated = $request->validate([
            'title'                  => 'required|string|max:200',
            'description'            => 'nullable|string',
            'aim_statement'          => 'nullable|string|max:500',
            'domain'                 => 'required|in:' . implode(',', QapiProject::DOMAINS),
            'start_date'             => 'required|date',
            'target_completion_date' => 'nullable|date|after_or_equal:start_date',
            'baseline_metric'        => 'nullable|string|max:200',
            'target_metric'          => 'nullable|string|max:200',
            'project_lead_user_id'   => 'nullable|integer|exists:shared_users,id',
            'interventions'          => 'nullable|string',
        ]);

        $user = auth()->user();
        $project = QapiProject::create([
            ...$validated,
            'tenant_id'           => $user->tenant_id,
            'status'              => 'planning',
            'team_member_ids'     => [],
            'created_by_user_id'  => $user->id,
        ]);

        AuditLog::record(
            action: 'qapi.project.created',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'qapi_project',
            resourceId: $project->id,
            description: "QAPI project created: {$project->title}",
        );

        return response()->json($this->toApiArray($project), 201);
    }

    /**
     * GET /qapi/projects/{id}
     * Return a single QAPI project as JSON.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $project = QapiProject::forTenant($user->tenant_id)
            ->with('projectLead:id,first_name,last_name')
            ->findOrFail($id);

        return response()->json($this->toApiArray($project));
    }

    /**
     * PATCH /qapi/projects/{id}
     * Update a QAPI project (status, metrics, team, notes).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireQaOrAdmin();

        $user = auth()->user();
        $project = QapiProject::forTenant($user->tenant_id)->findOrFail($id);

        $validated = $request->validate([
            'title'                   => 'sometimes|string|max:200',
            'description'             => 'nullable|string',
            'aim_statement'           => 'nullable|string|max:500',
            'domain'                  => 'sometimes|in:' . implode(',', QapiProject::DOMAINS),
            'status'                  => 'sometimes|in:' . implode(',', QapiProject::STATUSES),
            'start_date'              => 'sometimes|date',
            'target_completion_date'  => 'nullable|date',
            'actual_completion_date'  => 'nullable|date',
            'baseline_metric'         => 'nullable|string|max:200',
            'target_metric'           => 'nullable|string|max:200',
            'current_metric'          => 'nullable|string|max:200',
            'project_lead_user_id'    => 'nullable|integer|exists:shared_users,id',
            'team_member_ids'         => 'nullable|array',
            'interventions'           => 'nullable|string',
            'findings'                => 'nullable|string',
        ]);

        // Auto-set actual_completion_date when marking completed
        if (($validated['status'] ?? null) === 'completed' && empty($validated['actual_completion_date'])) {
            $validated['actual_completion_date'] = now()->toDateString();
        }

        $project->update($validated);

        AuditLog::record(
            action: 'qapi.project.updated',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'qapi_project',
            resourceId: $project->id,
            description: "QAPI project updated: {$project->title}",
        );

        return response()->json($this->toApiArray($project->fresh()));
    }

    /**
     * POST /qapi/projects/{id}/remeasure
     * Advance a project to 'remeasuring' status for outcome measurement cycle.
     */
    public function remeasure(Request $request, int $id): JsonResponse
    {
        $this->requireQaOrAdmin();

        $user = auth()->user();
        $project = QapiProject::forTenant($user->tenant_id)->findOrFail($id);

        if ($project->status !== 'active') {
            return response()->json([
                'message' => 'Only active projects can be advanced to remeasuring.',
            ], 422);
        }

        $validated = $request->validate([
            'current_metric' => 'nullable|string|max:200',
            'findings'       => 'nullable|string',
        ]);

        $project->update([
            ...$validated,
            'status' => 'remeasuring',
        ]);

        AuditLog::record(
            action: 'qapi.project.remeasuring',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'qapi_project',
            resourceId: $project->id,
            description: "QAPI project advanced to remeasuring: {$project->title}",
        );

        return response()->json($this->toApiArray($project->fresh()));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Serialize a QapiProject to API-safe array. */
    private function toApiArray(QapiProject $project): array
    {
        return [
            'id'                     => $project->id,
            'title'                  => $project->title,
            'description'            => $project->description,
            'aim_statement'          => $project->aim_statement,
            'domain'                 => $project->domain,
            'domain_label'           => $project->domainLabel(),
            'status'                 => $project->status,
            'status_label'           => $project->statusLabel(),
            'is_active'              => $project->isActive(),
            'start_date'             => $project->start_date?->toDateString(),
            'target_completion_date' => $project->target_completion_date?->toDateString(),
            'actual_completion_date' => $project->actual_completion_date?->toDateString(),
            'baseline_metric'        => $project->baseline_metric,
            'target_metric'          => $project->target_metric,
            'current_metric'         => $project->current_metric,
            'project_lead'           => $project->projectLead ? [
                'id'   => $project->projectLead->id,
                'name' => $project->projectLead->first_name . ' ' . $project->projectLead->last_name,
            ] : null,
            'team_member_ids'        => $project->team_member_ids,
            'interventions'          => $project->interventions,
            'findings'               => $project->findings,
            'created_at'             => $project->created_at?->toIso8601String(),
        ];
    }
}
