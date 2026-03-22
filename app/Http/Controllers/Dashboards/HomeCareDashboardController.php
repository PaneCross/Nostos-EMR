<?php

// ─── HomeCareDashboardController ──────────────────────────────────────────────
// JSON widget endpoints for the Home Care dashboard.
// All endpoints are tenant-scoped and require the home_care department
// (or super_admin).
//
// Routes (GET, all under /dashboards/home-care/):
//   schedule    — Today's home visits
//   adl-alerts  — Active ADL decline alerts (threshold breaches) for home_care
//   goals       — Active care plan goals in the home_care domain
//   sdrs        — Open/overdue SDRs assigned to home_care
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Appointment;
use App\Models\CarePlanGoal;
use App\Models\Sdr;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class HomeCareDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'home_care') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Today's home visits scheduled across the tenant.
     */
    public function schedule(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $appointments = Appointment::where('tenant_id', $tenantId)
            ->where('appointment_type', 'home_visit')
            ->whereDate('scheduled_start', today())
            ->whereNotIn('status', ['cancelled'])
            ->with(['participant:id,first_name,last_name,mrn', 'provider:id,first_name,last_name'])
            ->orderBy('scheduled_start')
            ->limit(20)
            ->get()
            ->map(fn (Appointment $a) => [
                'id'              => $a->id,
                'participant'     => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                    'mrn'  => $a->participant->mrn,
                ] : null,
                'type_label'      => $a->typeLabel(),
                'scheduled_start' => $a->scheduled_start?->toTimeString('minute'),
                'scheduled_end'   => $a->scheduled_end?->toTimeString('minute'),
                'status'          => $a->status,
                'provider_name'   => $a->provider
                    ? $a->provider->first_name . ' ' . $a->provider->last_name
                    : null,
                'transport_required' => $a->transport_required,
            ]);

        return response()->json(['appointments' => $appointments]);
    }

    /**
     * Active ADL decline alerts targeting the home_care department.
     * These fire from AdlRecordObserver → AdlThresholdService when a
     * participant's independence level drops below their configured threshold.
     */
    public function adlAlerts(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $alerts = Alert::where('tenant_id', $tenantId)
            ->whereJsonContains('target_departments', 'home_care')
            ->where('is_active', true)
            ->with(['participant:id,first_name,last_name'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (Alert $a) => [
                'id'           => $a->id,
                'title'        => $a->title,
                'message'      => $a->message,
                'severity'     => $a->severity,
                'alert_type'   => $a->alert_type,
                'type_label'   => $a->typeLabel(),
                'acknowledged' => $a->isAcknowledged(),
                'participant'  => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                ] : null,
                'created_at'   => $a->created_at?->diffForHumans(),
            ]);

        $unacknowledgedCount = Alert::where('tenant_id', $tenantId)
            ->whereJsonContains('target_departments', 'home_care')
            ->where('is_active', true)
            ->whereNull('acknowledged_at')
            ->count();

        return response()->json([
            'alerts'               => $alerts,
            'unacknowledged_count' => $unacknowledgedCount,
        ]);
    }

    /**
     * Active care plan goals in the home_care domain.
     */
    public function goals(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $goals = CarePlanGoal::whereHas('carePlan', fn ($q) => $q
                ->where('tenant_id', $tenantId)
                ->where('status', '!=', 'archived')
            )
            ->where('domain', 'home_care')
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
            ]);

        return response()->json(['goals' => $goals]);
    }

    /**
     * Open and overdue SDRs assigned to the home_care department.
     */
    public function sdrs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $sdrs = Sdr::where('tenant_id', $tenantId)
            ->forDepartment('home_care')
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
            ]);

        return response()->json([
            'sdrs'          => $sdrs,
            'overdue_count' => Sdr::where('tenant_id', $tenantId)->forDepartment('home_care')->overdue()->count(),
            'open_count'    => Sdr::where('tenant_id', $tenantId)->forDepartment('home_care')->open()->count(),
        ]);
    }
}
