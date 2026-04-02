<?php

// ─── TherapiesDashboardController ─────────────────────────────────────────────
// JSON widget endpoints for the Therapies (PT/OT/ST) dashboard.
// All endpoints are tenant-scoped and require the therapies department
// (or super_admin).
//
// Routes (GET, all under /dashboards/therapies/):
//   schedule   — Today's therapy appointments (PT/OT/ST)
//   goals      — Active therapy care plan goals (therapy_pt/ot/st domains)
//   sdrs       — Open/overdue SDRs assigned to therapies
//   docs       — Unsigned therapy notes (documentation queue)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CarePlanGoal;
use App\Models\ClinicalNote;
use App\Models\ClinicalOrder;
use App\Models\Sdr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TherapiesDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'therapies') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Today's PT, OT, and ST therapy sessions.
     * Returns up to 20 appointments ordered by start time.
     */
    public function schedule(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $appointments = Appointment::where('tenant_id', $tenantId)
            ->whereIn('appointment_type', ['therapy_pt', 'therapy_ot', 'therapy_st', 'telehealth'])
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
     * Active care plan goals in therapy domains (PT, OT, ST).
     * Linked to the most recent active care plan per participant.
     */
    public function goals(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $goals = CarePlanGoal::whereHas('carePlan', fn ($q) => $q
                ->where('tenant_id', $tenantId)
                ->where('status', '!=', 'archived')
            )
            ->whereIn('domain', ['therapy_pt', 'therapy_ot', 'therapy_st'])
            ->active()
            ->with([
                'carePlan.participant:id,first_name,last_name',
                'authoredBy:id,first_name,last_name',
            ])
            ->orderBy('target_date', 'asc')
            ->limit(20)
            ->get()
            ->map(fn (CarePlanGoal $g) => [
                'id'               => $g->id,
                'domain'           => $g->domain,
                'domain_label'     => $g->domainLabel(),
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
     * Open and overdue SDRs assigned to the therapies department.
     * Overdue SDRs (past 72h window) appear first.
     */
    public function sdrs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $sdrs = Sdr::where('tenant_id', $tenantId)
            ->forDepartment('therapies')
            ->open()
            ->with(['participant:id,first_name,last_name'])
            ->orderByRaw('due_at ASC')
            ->limit(15)
            ->get()
            ->map(fn (Sdr $s) => [
                'id'               => $s->id,
                'participant'      => $s->participant ? [
                    'id'   => $s->participant->id,
                    'name' => $s->participant->first_name . ' ' . $s->participant->last_name,
                ] : null,
                'request_type'     => $s->request_type,
                'type_label'       => $s->typeLabel(),
                'priority'         => $s->priority,
                'status'           => $s->status,
                'is_overdue'       => $s->isOverdue(),
                'hours_remaining'  => round($s->hoursRemaining(), 1),
                'due_at'           => $s->due_at?->toDateTimeString(),
                'href'             => '/sdrs',
            ]);

        return response()->json([
            'sdrs'          => $sdrs,
            'overdue_count' => Sdr::where('tenant_id', $tenantId)->forDepartment('therapies')->overdue()->count(),
            'open_count'    => Sdr::where('tenant_id', $tenantId)->forDepartment('therapies')->open()->count(),
        ]);
    }

    /**
     * Unsigned therapy notes pending sign-off (documentation queue).
     * Returns up to 10 draft notes for PT/OT/ST note types.
     */
    public function docs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $notes = ClinicalNote::where('tenant_id', $tenantId)
            ->whereIn('note_type', ['therapy_pt', 'therapy_ot', 'therapy_st'])
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
            'notes'         => $notes,
            'unsigned_count'=> ClinicalNote::where('tenant_id', $tenantId)->whereIn('note_type', ['therapy_pt', 'therapy_ot', 'therapy_st'])->unsigned()->count(),
        ]);
    }

    /**
     * GET /dashboards/therapies/orders
     * W4-7: Active therapy orders (PT/OT/ST/Speech) for therapies department.
     */
    public function orders(Request $request): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $therapyTypes = ['therapy_pt', 'therapy_ot', 'therapy_st', 'therapy_speech'];

        $pendingOrders = ClinicalOrder::forTenant($tenantId)
            ->whereIn('order_type', $therapyTypes)
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
            'pending_count' => ClinicalOrder::forTenant($tenantId)->whereIn('order_type', $therapyTypes)->pending()->count(),
        ]);
    }
}
