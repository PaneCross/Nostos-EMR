<?php

// ─── PrimaryCareDashboardController ───────────────────────────────────────────
// JSON widget endpoints for the Primary Care / Nursing dashboard.
// All endpoints are tenant-scoped and require the primary_care department
// (or super_admin).
//
// Routes (GET, all under /dashboards/primary-care/):
//   schedule   — Today's clinic/lab/specialist/telehealth appointments
//   alerts     — Active alerts targeting primary_care
//   docs       — Unsigned notes + overdue assessments (documentation queue)
//   vitals     — 5 most recent vitals records across the tenant
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Appointment;
use App\Models\Assessment;
use App\Models\ClinicalNote;
use App\Models\ClinicalOrder;
use App\Models\Vital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PrimaryCareDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    /** Abort 403 if the authenticated user is not primary_care or super_admin. */
    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'primary_care') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Today's appointments for primary-care-relevant types.
     * Returns up to 20 appointments ordered by start time.
     */
    public function schedule(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $types = [
            'clinic_visit', 'telehealth', 'lab', 'imaging',
            'specialist', 'external_referral',
        ];

        $appointments = Appointment::where('tenant_id', $tenantId)
            ->whereIn('appointment_type', $types)
            ->whereDate('scheduled_start', today())
            ->whereNotIn('status', ['cancelled'])
            ->with(['participant:id,first_name,last_name,mrn', 'provider:id,first_name,last_name'])
            ->orderBy('scheduled_start')
            ->limit(20)
            ->get()
            ->map(fn (Appointment $a) => [
                'id'               => $a->id,
                'participant'      => $a->participant ? [
                    'id'         => $a->participant->id,
                    'name'       => $a->participant->first_name . ' ' . $a->participant->last_name,
                    'mrn'        => $a->participant->mrn,
                ] : null,
                'appointment_type' => $a->appointment_type,
                'type_label'       => $a->typeLabel(),
                'scheduled_start'  => $a->scheduled_start?->toTimeString('minute'),
                'scheduled_end'    => $a->scheduled_end?->toTimeString('minute'),
                'status'           => $a->status,
                'provider_name'    => $a->provider
                    ? $a->provider->first_name . ' ' . $a->provider->last_name
                    : null,
                'href'             => $a->participant
                    ? "/participants/{$a->participant->id}"
                    : '/schedule',
            ]);

        return response()->json(['appointments' => $appointments]);
    }

    /**
     * Active alerts targeting the primary_care department.
     * Returns up to 10 unacknowledged critical/warning alerts first.
     */
    public function alerts(): JsonResponse
    {
        $this->requireDept();
        $user = Auth::user();

        // Fake a user object scoped to primary_care for the forUser() scope
        $scopeUser = (object) [
            'tenant_id'  => $user->tenant_id,
            'department' => 'primary_care',
        ];

        $alerts = Alert::where('tenant_id', $user->tenant_id)
            ->whereJsonContains('target_departments', 'primary_care')
            ->where('is_active', true)
            ->with(['participant:id,first_name,last_name'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (Alert $a) => [
                'id'             => $a->id,
                'title'          => $a->title,
                'message'        => $a->message,
                'severity'       => $a->severity,
                'alert_type'     => $a->alert_type,
                'type_label'     => $a->typeLabel(),
                'acknowledged'   => $a->isAcknowledged(),
                'participant'    => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                ] : null,
                'created_at'     => $a->created_at?->diffForHumans(),
                'href'           => $a->participant
                    ? "/participants/{$a->participant->id}"
                    : '/alerts',
            ]);

        return response()->json(['alerts' => $alerts]);
    }

    /**
     * Documentation queue: unsigned notes + overdue assessments for primary_care.
     * Returns counts + up to 10 items of each type.
     */
    public function docs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        // Unsigned draft notes for this department
        $unsignedNotes = ClinicalNote::where('tenant_id', $tenantId)
            ->forDepartment('primary_care')
            ->unsigned()
            ->with(['participant:id,first_name,last_name', 'author:id,first_name,last_name'])
            ->orderBy('created_at', 'asc')
            ->limit(10)
            ->get()
            ->map(fn (ClinicalNote $n) => [
                'id'            => $n->id,
                'participant'   => $n->participant ? [
                    'id'   => $n->participant->id,
                    'name' => $n->participant->first_name . ' ' . $n->participant->last_name,
                ] : null,
                'note_type'     => $n->note_type,
                'type_label'    => $n->noteTypeLabel(),
                'author'        => $n->author
                    ? $n->author->first_name . ' ' . $n->author->last_name
                    : null,
                'visit_date'    => $n->visit_date?->toDateString(),
                'created_at'    => $n->created_at?->diffForHumans(),
                'href'          => $n->participant
                    ? "/participants/{$n->participant->id}?tab=chart"
                    : '/clinical/notes',
            ]);

        // Overdue assessments in nursing/medical domain types
        $overdueAssessments = Assessment::where('tenant_id', $tenantId)
            ->whereIn('assessment_type', [
                'initial_comprehensive', 'annual_reassessment',
                'fall_risk_morse', 'pain_scale', 'mmse_cognitive',
            ])
            ->overdue()
            ->with(['participant:id,first_name,last_name'])
            ->orderBy('next_due_date', 'asc')
            ->limit(10)
            ->get()
            ->map(fn (Assessment $a) => [
                'id'              => $a->id,
                'participant'     => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                ] : null,
                'assessment_type' => $a->assessment_type,
                'type_label'      => $a->typeLabel(),
                'next_due_date'   => $a->next_due_date?->toDateString(),
                'days_overdue'    => $a->next_due_date
                    ? abs((int) now()->diffInDays($a->next_due_date))
                    : null,
                'href'            => $a->participant
                    ? "/participants/{$a->participant->id}?tab=assessments"
                    : '/clinical/assessments',
            ]);

        return response()->json([
            'unsigned_notes'      => $unsignedNotes,
            'unsigned_count'      => ClinicalNote::where('tenant_id', $tenantId)->forDepartment('primary_care')->unsigned()->count(),
            'overdue_assessments' => $overdueAssessments,
            'overdue_count'       => Assessment::where('tenant_id', $tenantId)->whereIn('assessment_type', ['initial_comprehensive', 'annual_reassessment', 'fall_risk_morse', 'pain_scale', 'mmse_cognitive'])->overdue()->count(),
        ]);
    }

    /**
     * 5 most recent vitals records across the tenant.
     * Used as a "quick glance" vitals widget on the nursing dashboard.
     */
    public function vitals(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $vitals = Vital::where('tenant_id', $tenantId)
            ->with(['participant:id,first_name,last_name', 'recordedBy:id,first_name,last_name'])
            ->orderByDesc('recorded_at')
            ->limit(5)
            ->get()
            ->map(fn (Vital $v) => [
                'id'           => $v->id,
                'participant'  => $v->participant ? [
                    'id'   => $v->participant->id,
                    'name' => $v->participant->first_name . ' ' . $v->participant->last_name,
                ] : null,
                'bp'           => ($v->bp_systolic && $v->bp_diastolic)
                    ? "{$v->bp_systolic}/{$v->bp_diastolic}"
                    : null,
                'pulse'        => $v->pulse,
                'o2_saturation'=> $v->o2_saturation,
                'temperature_f'=> $v->temperature_f,
                'weight_lbs'   => $v->weight_lbs,
                'out_of_range' => $v->isOutOfRange(),
                'recorded_at'  => $v->recorded_at?->diffForHumans(),
                'recorded_by'  => $v->recordedBy
                    ? $v->recordedBy->first_name . ' ' . $v->recordedBy->last_name
                    : null,
                'href'         => $v->participant
                    ? "/participants/{$v->participant->id}?tab=chart"
                    : '/participants',
            ]);

        return response()->json(['vitals' => $vitals]);
    }

    /**
     * GET /dashboards/primary-care/orders
     * W4-7: Active clinical orders for primary_care department.
     * Returns stat/urgent pending orders + recently resulted orders.
     */
    public function orders(Request $request): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $pendingOrders = ClinicalOrder::forTenant($tenantId)
            ->forDepartment('primary_care')
            ->whereNotIn('status', ClinicalOrder::TERMINAL_STATUSES)
            ->with(['participant:id,first_name,last_name,mrn'])
            ->orderByRaw("CASE priority WHEN 'stat' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END")
            ->orderBy('ordered_at')
            ->limit(10)
            ->get()
            ->map(fn ($o) => [
                'id'           => $o->id,
                'participant'  => $o->participant->first_name . ' ' . $o->participant->last_name,
                'mrn'          => $o->participant->mrn,
                'order_type'   => $o->orderTypeLabel(),
                'priority'     => $o->priority,
                'status'       => $o->status,
                'instructions' => \Illuminate\Support\Str::limit($o->instructions, 80),
                'ordered_at'   => $o->ordered_at?->toIso8601String(),
                'is_overdue'   => $o->isOverdue(),
                'href'         => '/participants/' . $o->participant_id . '?tab=orders',
            ]);

        return response()->json([
            'orders'        => $pendingOrders,
            'pending_count' => ClinicalOrder::forTenant($tenantId)->forDepartment('primary_care')->pending()->count(),
            'stat_count'    => ClinicalOrder::forTenant($tenantId)->forDepartment('primary_care')->where('priority', 'stat')->where('status', 'pending')->count(),
        ]);
    }
}
