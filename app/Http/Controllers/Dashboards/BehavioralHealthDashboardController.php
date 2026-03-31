<?php

// ─── BehavioralHealthDashboardController ──────────────────────────────────────
// JSON widget endpoints for the Behavioral Health dashboard.
// All endpoints are tenant-scoped and require the behavioral_health department
// (or super_admin).
//
// Routes (GET, all under /dashboards/behavioral-health/):
//   schedule      — Today's behavioral health appointments
//   assessments   — PHQ-9 depression + GAD-7 anxiety screens overdue or due soon
//   sdrs          — Open/overdue SDRs assigned to behavioral_health
//   goals         — Active care plan goals in the behavioral domain
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Assessment;
use App\Models\CarePlanGoal;
use App\Models\Sdr;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class BehavioralHealthDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'behavioral_health') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Today's behavioral health sessions and telehealth visits.
     */
    public function schedule(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $appointments = Appointment::where('tenant_id', $tenantId)
            ->whereIn('appointment_type', ['behavioral_health', 'telehealth'])
            ->whereDate('scheduled_start', today())
            ->whereNotIn('status', ['cancelled'])
            ->with(['participant:id,first_name,last_name,mrn', 'provider:id,first_name,last_name'])
            ->orderBy('scheduled_start')
            ->limit(20)
            ->get()
            ->map(fn (Appointment $a) => [
                'id'               => $a->id,
                'participant'      => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                    'mrn'  => $a->participant->mrn,
                ] : null,
                'appointment_type' => $a->appointment_type,
                'type_label'       => $a->typeLabel(),
                'scheduled_start'  => $a->scheduled_start?->toTimeString('minute'),
                'scheduled_end'    => $a->scheduled_end?->toTimeString('minute'),
                'status'           => $a->status,
                'href'             => $a->participant
                    ? "/participants/{$a->participant->id}"
                    : '/schedule',
            ]);

        return response()->json(['appointments' => $appointments]);
    }

    /**
     * PHQ-9 and GAD-7 assessments that are overdue or due within 14 days.
     * These are the primary BH screening tools used in PACE programs.
     */
    public function assessments(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $bhTypes = ['phq9_depression', 'gad7_anxiety'];

        $overdue = Assessment::where('tenant_id', $tenantId)
            ->whereIn('assessment_type', $bhTypes)
            ->overdue()
            ->with(['participant:id,first_name,last_name'])
            ->orderBy('next_due_date', 'asc')
            ->limit(10)
            ->get();

        $dueSoon = Assessment::where('tenant_id', $tenantId)
            ->whereIn('assessment_type', $bhTypes)
            ->dueSoon(14)
            ->with(['participant:id,first_name,last_name'])
            ->orderBy('next_due_date', 'asc')
            ->limit(10)
            ->get();

        $mapAssessment = fn (Assessment $a) => [
            'id'              => $a->id,
            'assessment_type' => $a->assessment_type,
            'type_label'      => $a->typeLabel(),
            'score'           => $a->score,
            'scored_label'    => $a->scoredLabel(),
            'next_due_date'   => $a->next_due_date?->toDateString(),
            'participant'     => $a->participant ? [
                'id'   => $a->participant->id,
                'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
            ] : null,
            'href'            => $a->participant
                ? "/participants/{$a->participant->id}?tab=assessments"
                : '/participants',
        ];

        return response()->json([
            'overdue'        => $overdue->map($mapAssessment),
            'due_soon'       => $dueSoon->map($mapAssessment),
            'overdue_count'  => Assessment::where('tenant_id', $tenantId)->whereIn('assessment_type', $bhTypes)->overdue()->count(),
            'due_soon_count' => Assessment::where('tenant_id', $tenantId)->whereIn('assessment_type', $bhTypes)->dueSoon(14)->count(),
        ]);
    }

    /**
     * Open and overdue SDRs assigned to the behavioral_health department.
     */
    public function sdrs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $sdrs = Sdr::where('tenant_id', $tenantId)
            ->forDepartment('behavioral_health')
            ->open()
            ->with(['participant:id,first_name,last_name'])
            ->orderByRaw('due_at ASC')
            ->limit(15)
            ->get()
            ->map(fn (Sdr $s) => [
                'id'              => $s->id,
                'participant'     => $s->participant ? [
                    'id'   => $s->participant->id,
                    'name' => $s->participant->first_name . ' ' . $s->participant->last_name,
                ] : null,
                'request_type'    => $s->request_type,
                'type_label'      => $s->typeLabel(),
                'priority'        => $s->priority,
                'status'          => $s->status,
                'is_overdue'      => $s->isOverdue(),
                'hours_remaining' => round($s->hoursRemaining(), 1),
                'due_at'          => $s->due_at?->toDateTimeString(),
                'href'            => '/sdrs',
            ]);

        return response()->json([
            'sdrs'          => $sdrs,
            'overdue_count' => Sdr::where('tenant_id', $tenantId)->forDepartment('behavioral_health')->overdue()->count(),
            'open_count'    => Sdr::where('tenant_id', $tenantId)->forDepartment('behavioral_health')->open()->count(),
        ]);
    }

    /**
     * Active care plan goals in the behavioral health domain.
     */
    public function goals(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $goals = CarePlanGoal::whereHas('carePlan', fn ($q) => $q
                ->where('tenant_id', $tenantId)
                ->where('status', '!=', 'archived')
            )
            ->where('domain', 'behavioral')
            ->active()
            ->with(['carePlan.participant:id,first_name,last_name'])
            ->orderBy('target_date', 'asc')
            ->limit(20)
            ->get()
            ->map(fn (CarePlanGoal $g) => [
                'id'               => $g->id,
                'goal_description' => $g->goal_description,
                'target_date'      => $g->target_date?->toDateString(),
                'status'           => $g->status,
                'participant'      => $g->carePlan?->participant ? [
                    'id'   => $g->carePlan->participant->id,
                    'name' => $g->carePlan->participant->first_name . ' ' . $g->carePlan->participant->last_name,
                ] : null,
                'href'             => $g->carePlan?->participant?->id
                    ? "/participants/{$g->carePlan->participant->id}?tab=careplan"
                    : '/participants',
            ]);

        return response()->json(['goals' => $goals]);
    }
}
