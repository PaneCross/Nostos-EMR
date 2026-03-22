<?php

// ─── ClinicalDashboardController ─────────────────────────────────────────────
// Cross-participant clinical module landing pages.
// All four methods power the Clinical nav items that previously 404'd:
//   GET /clinical/notes       → recent notes across all participants
//   GET /clinical/vitals      → latest vitals per participant with OOR flags
//   GET /clinical/assessments → due / overdue assessment worklist
//   GET /clinical/care-plans  → care plan status per participant
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\CarePlan;
use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Models\Vital;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClinicalDashboardController extends Controller
{
    /**
     * GET /clinical/notes
     * Recent clinical notes across all participants, newest first.
     * Supports ?department=, ?note_type=, ?status= filters.
     */
    public function notes(Request $request): Response
    {
        $user = $request->user();

        $query = ClinicalNote::with(
            'participant:id,mrn,first_name,last_name',
            'author:id,first_name,last_name,department'
        )
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('visit_date')
            ->orderByDesc('created_at');

        if ($dept = $request->input('department')) {
            $query->where('department', $dept);
        }
        if ($type = $request->input('note_type')) {
            $query->where('note_type', $type);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $notes = $query->paginate(50)->through(fn ($n) => [
            'id'          => $n->id,
            'note_type'   => $n->note_type,
            'status'      => $n->status,
            'visit_date'  => $n->visit_date?->toDateString(),
            'visit_type'  => $n->visit_type,
            'department'  => $n->department,
            'author'      => $n->author ? [
                'id'         => $n->author->id,
                'first_name' => $n->author->first_name,
                'last_name'  => $n->author->last_name,
                'department' => $n->author->department,
            ] : null,
            'participant' => $n->participant ? [
                'id'         => $n->participant->id,
                'mrn'        => $n->participant->mrn,
                'first_name' => $n->participant->first_name,
                'last_name'  => $n->participant->last_name,
            ] : null,
        ]);

        return Inertia::render('Clinical/Notes', [
            'notes'     => $notes,
            'filters'   => $request->only('department', 'note_type', 'status'),
            'noteTypes' => array_keys(config('emr_note_templates', [])),
        ]);
    }

    /**
     * GET /clinical/vitals
     * Most recent vital signs per participant, with out-of-range flags.
     */
    public function vitals(Request $request): Response
    {
        $user = $request->user();

        // Fetch recent vitals and deduplicate to one per participant (newest first)
        $latestVitals = Vital::with('participant:id,mrn,first_name,last_name')
            ->where('tenant_id', $user->tenant_id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->unique('participant_id')
            ->values()
            ->map(fn ($v) => [
                'id'            => $v->id,
                'recorded_at'   => $v->recorded_at?->toIso8601String(),
                'bp_systolic'   => $v->bp_systolic,
                'bp_diastolic'  => $v->bp_diastolic,
                'pulse'         => $v->pulse,
                'o2_saturation' => $v->o2_saturation,
                'weight_lbs'    => $v->weight_lbs,
                'temperature_f' => $v->temperature_f,
                'pain_score'    => $v->pain_score,
                'blood_glucose' => $v->blood_glucose,
                'participant'   => $v->participant ? [
                    'id'         => $v->participant->id,
                    'mrn'        => $v->participant->mrn,
                    'first_name' => $v->participant->first_name,
                    'last_name'  => $v->participant->last_name,
                ] : null,
            ]);

        // Participants with vitals older than 7 days (or no vitals) get a "due" flag
        $participantsWithFreshVitals = $latestVitals
            ->filter(fn ($v) => $v['recorded_at'] && now()->diffInDays($v['recorded_at']) <= 7)
            ->pluck('participant.id')
            ->filter()
            ->values()
            ->all();

        return Inertia::render('Clinical/Vitals', [
            'vitals'                      => $latestVitals,
            'participantsWithFreshVitals' => $participantsWithFreshVitals,
        ]);
    }

    /**
     * GET /clinical/assessments
     * Worklist: overdue, due within 14 days, recently completed.
     */
    public function assessments(Request $request): Response
    {
        $user = $request->user();
        $now  = now();
        $soon = now()->addDays(14);

        $overdue = Assessment::with('participant:id,mrn,first_name,last_name')
            ->where('tenant_id', $user->tenant_id)
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<', $now)
            ->orderBy('next_due_date')
            ->get();

        $dueSoon = Assessment::with('participant:id,mrn,first_name,last_name')
            ->where('tenant_id', $user->tenant_id)
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [$now, $soon])
            ->orderBy('next_due_date')
            ->get();

        $recent = Assessment::with('participant:id,mrn,first_name,last_name')
            ->where('tenant_id', $user->tenant_id)
            ->where('completed_at', '>=', now()->subDays(30))
            ->orderByDesc('completed_at')
            ->limit(30)
            ->get();

        return Inertia::render('Clinical/Assessments', [
            'overdue' => $overdue,
            'dueSoon' => $dueSoon,
            'recent'  => $recent,
        ]);
    }

    /**
     * GET /clinical/care-plans
     * Current care plan status for every active participant.
     */
    public function carePlans(Request $request): Response
    {
        $user = $request->user();

        // Most recent non-archived care plan per participant, keyed by participant_id
        $carePlansMap = CarePlan::with('goals')
            ->where('tenant_id', $user->tenant_id)
            ->whereIn('status', ['active', 'draft', 'under_review'])
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('participant_id')
            ->map(fn ($plans) => $plans->first());

        $participants = Participant::where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function ($p) use ($carePlansMap) {
                $plan = $carePlansMap->get($p->id);

                return [
                    'id'         => $p->id,
                    'mrn'        => $p->mrn,
                    'first_name' => $p->first_name,
                    'last_name'  => $p->last_name,
                    'care_plan'  => $plan ? [
                        'id'              => $plan->id,
                        'status'          => $plan->status,
                        'version'         => $plan->version,
                        'goal_count'      => $plan->goals->count(),
                        'effective_date'  => $plan->effective_date?->toDateString(),
                        'review_due_date' => $plan->review_due_date?->toDateString(),
                    ] : null,
                ];
            });

        return Inertia::render('Clinical/CarePlans', [
            'participants' => $participants,
        ]);
    }
}
