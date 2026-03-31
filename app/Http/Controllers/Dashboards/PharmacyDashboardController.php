<?php

// ─── PharmacyDashboardController ──────────────────────────────────────────────
// JSON widget endpoints for the Pharmacy department dashboard.
// All endpoints require the pharmacy department (or super_admin).
//
// Routes (GET, all under /dashboards/pharmacy/):
//   med-changes    — medications created or discontinued today (grouped)
//   interactions   — unacknowledged drug interaction alerts across all participants
//   controlled     — eMAR records for controlled substances administered today
//   refills        — medications requiring refill (0 remaining or last filled >28 days)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\DrugInteractionAlert;
use App\Models\EmarRecord;
use App\Models\Medication;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PharmacyDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    /** Abort 403 if the authenticated user is not pharmacy or super_admin. */
    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'pharmacy') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Medications created or discontinued today, grouped into new orders and discontinued.
     * Provides a same-day medication change summary for pharmacist review.
     */
    public function medChanges(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        // New orders: medications created today
        $newOrders = Medication::where('tenant_id', $tenantId)
            ->withTrashed()
            ->whereDate('created_at', today())
            ->whereIn('status', ['active', 'prn', 'on_hold'])
            ->with(['participant:id,first_name,last_name,mrn', 'prescribingProvider:id,first_name,last_name'])
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get()
            ->map(fn (Medication $m) => [
                'id'          => $m->id,
                'participant' => $m->participant ? [
                    'id'   => $m->participant->id,
                    'name' => $m->participant->first_name . ' ' . $m->participant->last_name,
                    'mrn'  => $m->participant->mrn,
                ] : null,
                'drug_name'   => $m->drug_name,
                'dose_label'  => $m->doseLabel(),
                'prescriber'  => $m->prescribingProvider
                    ? $m->prescribingProvider->first_name . ' ' . $m->prescribingProvider->last_name
                    : null,
                'is_controlled' => $m->is_controlled,
                'href'          => $m->participant
                    ? "/participants/{$m->participant->id}?tab=medications"
                    : '/participants',
            ]);

        // Discontinued today: medications where status changed to discontinued
        $discontinued = Medication::where('tenant_id', $tenantId)
            ->withTrashed()
            ->where('status', 'discontinued')
            ->whereDate('updated_at', today())
            ->with(['participant:id,first_name,last_name,mrn'])
            ->orderBy('updated_at', 'desc')
            ->limit(15)
            ->get()
            ->map(fn (Medication $m) => [
                'id'                  => $m->id,
                'participant'         => $m->participant ? [
                    'id'   => $m->participant->id,
                    'name' => $m->participant->first_name . ' ' . $m->participant->last_name,
                    'mrn'  => $m->participant->mrn,
                ] : null,
                'drug_name'           => $m->drug_name,
                'discontinued_reason' => $m->discontinued_reason,
                'href'                => $m->participant
                    ? "/participants/{$m->participant->id}?tab=medications"
                    : '/participants',
            ]);

        return response()->json([
            'new_orders'       => $newOrders,
            'new_orders_count' => $newOrders->count(),
            'discontinued'     => $discontinued,
            'discontinued_count' => $discontinued->count(),
        ]);
    }

    /**
     * All unacknowledged drug-drug interaction alerts across the tenant.
     * Ordered by severity (contraindicated → major → moderate → minor).
     * Pharmacy reviews and acknowledges these alerts to confirm clinical acceptance.
     */
    public function interactions(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $alerts = DrugInteractionAlert::where('tenant_id', $tenantId)
            ->unacknowledged()
            ->with(['participant:id,first_name,last_name,mrn'])
            ->orderByRaw("CASE severity
                WHEN 'contraindicated' THEN 0
                WHEN 'major' THEN 1
                WHEN 'moderate' THEN 2
                ELSE 3 END")
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn (DrugInteractionAlert $a) => [
                'id'             => $a->id,
                'participant'    => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                    'mrn'  => $a->participant->mrn,
                ] : null,
                'drug_name_1'    => $a->drug_name_1,
                'drug_name_2'    => $a->drug_name_2,
                'severity'       => $a->severity,
                'severity_color' => $a->severityColor(),
                'description'    => $a->description,
                'created_at'     => $a->created_at?->diffForHumans(),
                'href'           => $a->participant
                    ? "/participants/{$a->participant->id}?tab=medications"
                    : '/participants',
            ]);

        return response()->json([
            'alerts'       => $alerts,
            'total_count'  => DrugInteractionAlert::where('tenant_id', $tenantId)->unacknowledged()->count(),
        ]);
    }

    /**
     * Controlled substance eMAR records administered today.
     * DEA Schedule II/III meds require witness co-signature — flagged here if missing.
     * Used for pharmacy compliance monitoring.
     */
    public function controlled(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        // Controlled medication IDs for this tenant
        $controlledMedIds = Medication::where('tenant_id', $tenantId)
            ->where('is_controlled', true)
            ->pluck('id');

        $records = EmarRecord::where('tenant_id', $tenantId)
            ->whereIn('medication_id', $controlledMedIds)
            ->whereDate('scheduled_time', today())
            ->with([
                'participant:id,first_name,last_name,mrn',
                'medication:id,drug_name,controlled_schedule,dose,dose_unit',
                'administeredBy:id,first_name,last_name',
                'witness:id,first_name,last_name',
            ])
            ->orderBy('scheduled_time')
            ->limit(30)
            ->get()
            ->map(fn (EmarRecord $r) => [
                'id'                  => $r->id,
                'participant'         => $r->participant ? [
                    'id'   => $r->participant->id,
                    'name' => $r->participant->first_name . ' ' . $r->participant->last_name,
                    'mrn'  => $r->participant->mrn,
                ] : null,
                'drug_name'           => $r->medication?->drug_name,
                'controlled_schedule' => $r->medication?->controlled_schedule,
                'dose_label'          => $r->medication
                    ? "{$r->medication->dose} {$r->medication->dose_unit}"
                    : null,
                'status'              => $r->status,
                'scheduled_time'      => $r->scheduled_time?->toTimeString('minute'),
                'administered_by'     => $r->administeredBy
                    ? $r->administeredBy->first_name . ' ' . $r->administeredBy->last_name
                    : null,
                'witness'             => $r->witness
                    ? $r->witness->first_name . ' ' . $r->witness->last_name
                    : null,
                'needs_witness'       => $r->medication?->requiresWitness() && $r->status === 'given' && is_null($r->witness_user_id),
                'href'                => $r->participant
                    ? "/participants/{$r->participant->id}?tab=medications"
                    : '/participants',
            ]);

        return response()->json([
            'records' => $records,
            'count'   => $records->count(),
        ]);
    }

    /**
     * Medications requiring refill attention:
     *   - refills_remaining = 0 (no remaining refills on file)
     *   - last_filled_date > 28 days ago (overdue for refill pickup)
     * Active medications only. Pharmacy follows up to prevent supply gaps.
     */
    public function refills(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $needsRefill = Medication::where('tenant_id', $tenantId)
            ->active()
            ->where(function ($q) {
                $q->where('refills_remaining', 0)
                  ->orWhere(fn ($q2) => $q2
                      ->whereNotNull('last_filled_date')
                      ->where('last_filled_date', '<=', now()->subDays(28)->toDateString())
                  );
            })
            ->with(['participant:id,first_name,last_name,mrn', 'prescribingProvider:id,first_name,last_name'])
            ->orderBy('last_filled_date')
            ->limit(25)
            ->get()
            ->map(fn (Medication $m) => [
                'id'                => $m->id,
                'participant'       => $m->participant ? [
                    'id'   => $m->participant->id,
                    'name' => $m->participant->first_name . ' ' . $m->participant->last_name,
                    'mrn'  => $m->participant->mrn,
                ] : null,
                'drug_name'         => $m->drug_name,
                'dose_label'        => $m->doseLabel(),
                'refills_remaining' => $m->refills_remaining,
                'last_filled_date'  => $m->last_filled_date?->toDateString(),
                'days_since_filled' => $m->last_filled_date
                    ? abs((int) now()->diffInDays($m->last_filled_date))
                    : null,
                'reason'            => $m->refills_remaining === 0
                    ? 'no_refills'
                    : 'overdue_refill',
                'href'              => $m->participant
                    ? "/participants/{$m->participant->id}?tab=medications"
                    : '/participants',
            ]);

        return response()->json([
            'medications' => $needsRefill,
            'count'       => $needsRefill->count(),
        ]);
    }
}
