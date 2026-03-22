<?php

// ─── AssessmentController ─────────────────────────────────────────────────────
// Manages clinical assessments for a participant (PHQ-9, MMSE, fall risk, etc.).
// The /due endpoint returns overdue + due-within-14-days assessments for dashboard alerts.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssessmentRequest;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    /**
     * GET /participants/{participant}/assessments
     * Returns all assessments, newest first.
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $assessments = $participant->assessments()
            ->with('author:id,first_name,last_name,department')
            ->orderByDesc('completed_at')
            ->get();

        return response()->json($assessments);
    }

    /**
     * POST /participants/{participant}/assessments
     * Records a completed assessment.
     */
    public function store(StoreAssessmentRequest $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $assessment = Assessment::create(array_merge($request->validated(), [
            'participant_id'      => $participant->id,
            'tenant_id'           => $user->tenant_id,
            'authored_by_user_id' => $user->id,
            'department'          => $user->department,
        ]));

        AuditLog::record(
            action: 'participant.assessment.created',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "{$assessment->typeLabel()} assessment completed for {$participant->mrn}"
                . ($assessment->score !== null ? " (score: {$assessment->score})" : ''),
            newValues: ['assessment_id' => $assessment->id, 'type' => $assessment->assessment_type, 'score' => $assessment->score],
        );

        return response()->json($assessment->load('author:id,first_name,last_name'), 201);
    }

    /**
     * GET /participants/{participant}/assessments/due
     * Returns overdue and due-within-14-days assessments, ordered by next_due_date ASC.
     * Used by dashboard alerts and the Assessments tab status badges.
     */
    public function due(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $overdue = $participant->assessments()
            ->overdue()
            ->with('author:id,first_name,last_name')
            ->orderBy('next_due_date')
            ->get()
            ->each(fn ($a) => $a->status_label = 'overdue');

        $dueSoon = $participant->assessments()
            ->dueSoon(14)
            ->with('author:id,first_name,last_name')
            ->orderBy('next_due_date')
            ->get()
            ->each(fn ($a) => $a->status_label = 'due_soon');

        return response()->json([
            'overdue'  => $overdue,
            'due_soon' => $dueSoon,
        ]);
    }
}
