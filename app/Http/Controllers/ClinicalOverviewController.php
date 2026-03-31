<?php

// ─── ClinicalOverviewController ───────────────────────────────────────────────
// Cross-participant clinical overview pages.
//
// These pages aggregate clinical data across ALL enrolled participants for the
// current tenant — useful for clinical supervisors and IT Admin reviewing the
// full population's medication and care plan status.
//
// Routes:
//   GET /clinical/medications  — Inertia: medication population overview
//   GET /clinical/orders       — Inertia: active care plan goals worklist
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\DrugInteractionAlert;
use App\Models\Medication;
use App\Models\Participant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClinicalOverviewController extends Controller
{
    /**
     * GET /clinical/medications
     * Cross-participant medication population overview.
     * Shows KPIs + per-participant active medication summary.
     */
    public function medications(Request $request): Response
    {
        $user = $request->user();
        $tid  = $user->tenant_id;

        // ── KPIs ──────────────────────────────────────────────────────────────
        $totalActive = Medication::where('tenant_id', $tid)
            ->where('status', 'active')
            ->count();

        $totalPrn = Medication::where('tenant_id', $tid)
            ->where('status', 'active')
            ->where('is_prn', true)
            ->count();

        $activeInteractionAlerts = DrugInteractionAlert::where('tenant_id', $tid)
            ->whereNull('acknowledged_at')
            ->count();

        $participantsWithMeds = Medication::where('tenant_id', $tid)
            ->where('status', 'active')
            ->distinct('participant_id')
            ->count('participant_id');

        // ── Per-participant summary ────────────────────────────────────────────
        // Get enrolled participants and their active medication counts
        $participants = Participant::where('tenant_id', $tid)
            ->where('enrollment_status', 'enrolled')
            ->with([
                'medications' => fn ($q) => $q->where('status', 'active'),
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function ($p) use ($tid) {
                $activeCount      = $p->medications->count();
                $prnCount         = $p->medications->where('is_prn', true)->count();
                $controlledCount  = $p->medications->where('is_controlled', true)->count();

                $openAlerts = DrugInteractionAlert::where('tenant_id', $tid)
                    ->where('participant_id', $p->id)
                    ->whereNull('acknowledged_at')
                    ->count();

                return [
                    'id'               => $p->id,
                    'name'             => $p->first_name . ' ' . $p->last_name,
                    'mrn'              => $p->mrn,
                    'active_count'     => $activeCount,
                    'prn_count'        => $prnCount,
                    'controlled_count' => $controlledCount,
                    'open_alerts'      => $openAlerts,
                ];
            })
            ->filter(fn ($row) => $row['active_count'] > 0) // only participants with meds
            ->values();

        return Inertia::render('Clinical/Medications', [
            'kpis' => [
                'total_active'           => $totalActive,
                'total_prn'              => $totalPrn,
                'active_interaction_alerts' => $activeInteractionAlerts,
                'participants_with_meds' => $participantsWithMeds,
            ],
            'participants' => $participants,
        ]);
    }

    /**
     * GET /clinical/orders
     * Active care plan goals worklist — functions as a clinical orders overview
     * until a full CPOE module is built (DEBT-CPOE).
     * Shows active goals across all participants ordered by target date (soonest first).
     */
    public function orders(Request $request): Response
    {
        $user = $request->user();
        $tid  = $user->tenant_id;

        // ── KPIs ──────────────────────────────────────────────────────────────
        $activeGoals = CarePlanGoal::whereHas(
            'carePlan',
            fn ($q) => $q->where('tenant_id', $tid)->where('status', 'active')
        )->where('status', 'active')->count();

        $overdueGoals = CarePlanGoal::whereHas(
            'carePlan',
            fn ($q) => $q->where('tenant_id', $tid)->where('status', 'active')
        )->where('status', 'active')
         ->whereNotNull('target_date')
         ->where('target_date', '<', now()->toDateString())
         ->count();

        $metThisMonth = CarePlanGoal::whereHas(
            'carePlan',
            fn ($q) => $q->where('tenant_id', $tid)
        )->where('status', 'met')
         ->whereMonth('updated_at', now()->month)
         ->whereYear('updated_at', now()->year)
         ->count();

        // ── Goals list ────────────────────────────────────────────────────────
        $goals = CarePlanGoal::whereHas(
            'carePlan',
            fn ($q) => $q->where('tenant_id', $tid)->where('status', 'active')
        )->where('status', 'active')
         ->with([
             'carePlan' => fn ($q) => $q->with('participant:id,first_name,last_name,mrn'),
         ])
         ->orderByRaw("CASE WHEN target_date IS NULL THEN 1 ELSE 0 END")  // nulls last
         ->orderBy('target_date')
         ->get()
         ->map(fn ($goal) => [
             'id'               => $goal->id,
             'participant_id'   => $goal->carePlan?->participant_id,
             'participant_name' => $goal->carePlan?->participant
                 ? $goal->carePlan->participant->first_name . ' ' . $goal->carePlan->participant->last_name
                 : '-',
             'mrn'              => $goal->carePlan?->participant?->mrn ?? '-',
             'domain'           => $goal->domain,
             'goal_description' => $goal->goal_description,
             'target_date'      => $goal->target_date?->format('Y-m-d'),
             'is_overdue'       => $goal->target_date && $goal->target_date->isPast(),
             'progress_notes'   => $goal->progress_notes,
         ]);

        return Inertia::render('Clinical/Orders', [
            'kpis' => [
                'active_goals'    => $activeGoals,
                'overdue_goals'   => $overdueGoals,
                'met_this_month'  => $metThisMonth,
            ],
            'goals' => $goals,
        ]);
    }
}
