<?php

// ─── ActivitiesDashboardController ────────────────────────────────────────────
// JSON widget endpoints for the Activities / Recreation Therapy dashboard.
// All endpoints are tenant-scoped and require the activities department
// (or super_admin).
//
// Routes (GET, all under /dashboards/activities/):
//   schedule  — Today's activities sessions and day center attendance
//   goals     — Active care plan goals in the activities domain
//   sdrs      — Open/overdue SDRs assigned to activities
//   docs      — Unsigned activity notes (documentation queue)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CarePlanGoal;
use App\Models\ClinicalNote;
use App\Models\Sdr;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ActivitiesDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'activities') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Today's activity sessions and day center attendance blocks.
     */
    public function schedule(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $appointments = Appointment::where('tenant_id', $tenantId)
            ->whereIn('appointment_type', ['activities', 'day_center_attendance'])
            ->whereDate('scheduled_start', today())
            ->whereNotIn('status', ['cancelled'])
            ->with(['participant:id,first_name,last_name,mrn'])
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

        // Day center attendance count
        $dayCount = Appointment::where('tenant_id', $tenantId)
            ->where('appointment_type', 'day_center_attendance')
            ->whereDate('scheduled_start', today())
            ->whereNotIn('status', ['cancelled'])
            ->count();

        return response()->json([
            'appointments'       => $appointments,
            'day_center_count'   => $dayCount,
        ]);
    }

    /**
     * Active care plan goals in the activities domain.
     */
    public function goals(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $goals = CarePlanGoal::whereHas('carePlan', fn ($q) => $q
                ->where('tenant_id', $tenantId)
                ->where('status', '!=', 'archived')
            )
            ->where('domain', 'activities')
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

    /**
     * Open and overdue SDRs assigned to the activities department.
     */
    public function sdrs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $sdrs = Sdr::where('tenant_id', $tenantId)
            ->forDepartment('activities')
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
            'overdue_count' => Sdr::where('tenant_id', $tenantId)->forDepartment('activities')->overdue()->count(),
            'open_count'    => Sdr::where('tenant_id', $tenantId)->forDepartment('activities')->open()->count(),
        ]);
    }

    /**
     * Unsigned activity notes pending documentation sign-off.
     */
    public function docs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        // Activity notes use note_type='activity_notes' — map to department scope
        $notes = ClinicalNote::where('tenant_id', $tenantId)
            ->forDepartment('activities')
            ->unsigned()
            ->with(['participant:id,first_name,last_name', 'author:id,first_name,last_name'])
            ->orderBy('created_at', 'asc')
            ->limit(10)
            ->get()
            ->map(fn (ClinicalNote $n) => [
                'id'         => $n->id,
                'participant'=> $n->participant ? [
                    'id'   => $n->participant->id,
                    'name' => $n->participant->first_name . ' ' . $n->participant->last_name,
                ] : null,
                'note_type'  => $n->note_type,
                'type_label' => $n->noteTypeLabel(),
                'author'     => $n->author
                    ? $n->author->first_name . ' ' . $n->author->last_name
                    : null,
                'visit_date' => $n->visit_date?->toDateString(),
                'created_at' => $n->created_at?->diffForHumans(),
                'href'       => $n->participant
                    ? "/participants/{$n->participant->id}?tab=chart"
                    : '/clinical/notes',
            ]);

        return response()->json([
            'notes'          => $notes,
            'unsigned_count' => ClinicalNote::where('tenant_id', $tenantId)->forDepartment('activities')->unsigned()->count(),
        ]);
    }
}
