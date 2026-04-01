<?php

// ─── VitalController ──────────────────────────────────────────────────────────
// Manages vital sign recordings for a participant.
// Append-only: vitals cannot be edited or deleted after recording.
// The /trends endpoint returns daily aggregates for charting (30/60/90 days).
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Events\VitalsRecordedEvent;
use App\Http\Requests\StoreVitalRequest;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\Vital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VitalController extends Controller
{
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    /**
     * GET /participants/{participant}/vitals
     * Returns the latest 100 vitals records, newest first.
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $vitals = $participant->vitals()
            ->with('recordedBy:id,first_name,last_name')
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get();

        return response()->json($vitals);
    }

    /**
     * POST /participants/{participant}/vitals
     * Records a new vitals entry. Append-only — no update or delete.
     * Only clinical departments that perform direct patient care may record vitals.
     */
    public function store(StoreVitalRequest $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        // Restrict vitals recording to clinical care departments.
        // Non-clinical departments (finance, dietary, transportation, enrollment, etc.) cannot record vitals.
        $vitalsAllowedDepts = ['primary_care', 'therapies', 'home_care', 'social_work', 'it_admin'];
        abort_unless(
            in_array($user->department, $vitalsAllowedDepts, true),
            403,
            'Your department is not authorized to record vitals.'
        );

        $vital = Vital::create(array_merge($request->validated(), [
            'participant_id'      => $participant->id,
            'tenant_id'           => $user->tenant_id,
            'recorded_by_user_id' => $user->id,
            'recorded_at'         => $request->input('recorded_at', now()),
        ]));

        // Flag out-of-range values for audit trail
        $outOfRange = $vital->isOutOfRange();

        AuditLog::record(
            action: 'participant.vitals.recorded',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Vitals recorded for {$participant->mrn}"
                . (! empty($outOfRange) ? ' [Out-of-range: ' . implode(', ', array_keys($outOfRange)) . ']' : ''),
            newValues: array_merge($request->validated(), ['out_of_range' => $outOfRange]),
        );

        // Phase 4: broadcast for real-time chart tab refresh
        broadcast(new VitalsRecordedEvent($vital->load('recordedBy:id,first_name,last_name')))->toOthers();

        return response()->json(
            $vital->load('recordedBy:id,first_name,last_name'),
            201
        );
    }

    /**
     * GET /participants/{participant}/vitals/trends
     * Returns daily aggregated averages for charting.
     *
     * Query param: ?days=30 (default) | 60 | 90
     *
     * Response shape:
     * [{ day: '2024-03-01', bp_systolic: 138, bp_diastolic: 82, weight_lbs: 156.2, o2_saturation: 97 }, ...]
     */
    public function trends(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $days = (int) $request->input('days', 30);
        abort_unless(in_array($days, [30, 60, 90], true), 422, 'days must be 30, 60, or 90.');

        $since = now()->subDays($days)->startOfDay();

        // PostgreSQL date_trunc aggregation for daily averages
        $trends = DB::select(
            "SELECT
                date_trunc('day', recorded_at) AS day,
                ROUND(AVG(bp_systolic))    AS bp_systolic,
                ROUND(AVG(bp_diastolic))   AS bp_diastolic,
                ROUND(AVG(pulse))          AS pulse,
                ROUND(AVG(o2_saturation))  AS o2_saturation,
                ROUND(AVG(weight_lbs)::numeric, 1)    AS weight_lbs,
                ROUND(AVG(pain_score)::numeric, 1)    AS pain_score,
                ROUND(AVG(blood_glucose)::numeric, 0) AS blood_glucose
             FROM emr_vitals
             WHERE participant_id = ? AND recorded_at >= ?
             GROUP BY date_trunc('day', recorded_at)
             ORDER BY day ASC",
            [$participant->id, $since]
        );

        return response()->json($trends);
    }
}
