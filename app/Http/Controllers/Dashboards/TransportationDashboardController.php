<?php

// ─── TransportationDashboardController ────────────────────────────────────────
// JSON widget endpoints for the Transportation department dashboard.
// All endpoints require the transportation department (or super_admin).
// Transport mode awareness: broker mode shows vendor assignment data;
// direct mode hides vendor-related content.
//
// Routes (GET, all under /dashboards/transportation/):
//   manifest-summary — today's trip counts grouped by status
//   add-ons          — pending add-on requests awaiting scheduling
//   flag-alerts      — new participant mobility flags added today
//   config           — transport_mode + broker vendor assignment count
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\ParticipantFlag;
use App\Models\Tenant;
use App\Models\TransportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TransportationDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    /** Abort 403 if the authenticated user is not transportation or super_admin. */
    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'transportation') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Today's transport trip counts grouped by status.
     * Returns counts per status + a link to the full manifest.
     */
    public function manifestSummary(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        // Aggregate today's trip statuses
        $counts = TransportRequest::where('tenant_id', $tenantId)
            ->whereDate('requested_pickup_time', today())
            ->whereNotIn('status', ['cancelled'])
            ->get(['status'])
            ->groupBy('status')
            ->map(fn ($group) => $group->count());

        // Ensure all expected statuses are present (even if zero)
        $summary = [];
        foreach (['scheduled', 'dispatched', 'en_route', 'arrived', 'completed', 'no_show'] as $status) {
            $summary[$status] = $counts[$status] ?? 0;
        }

        $total = TransportRequest::where('tenant_id', $tenantId)
            ->whereDate('requested_pickup_time', today())
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $cancelled = TransportRequest::where('tenant_id', $tenantId)
            ->whereDate('requested_pickup_time', today())
            ->where('status', 'cancelled')
            ->count();

        return response()->json([
            'summary'        => $summary,
            'total'          => $total,
            'cancelled_count'=> $cancelled,
        ]);
    }

    /**
     * Pending add-on requests: unscheduled same-day trips awaiting transport team approval.
     * Returns up to 20 add-on requests ordered by requested pickup time.
     */
    public function addOns(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $addOns = TransportRequest::where('tenant_id', $tenantId)
            ->pendingAddOns()
            ->whereDate('requested_pickup_time', today())
            ->with([
                'participant:id,first_name,last_name,mrn',
                'requestingUser:id,first_name,last_name,department',
                'pickupLocation:id,label',
                'dropoffLocation:id,label',
            ])
            ->orderBy('requested_pickup_time')
            ->limit(20)
            ->get()
            ->map(fn (TransportRequest $t) => [
                'id'                   => $t->id,
                'participant'          => $t->participant ? [
                    'id'   => $t->participant->id,
                    'name' => $t->participant->first_name . ' ' . $t->participant->last_name,
                    'mrn'  => $t->participant->mrn,
                ] : null,
                'requested_by_dept'    => $t->requesting_department,
                'requested_by_name'    => $t->requestingUser
                    ? $t->requestingUser->first_name . ' ' . $t->requestingUser->last_name
                    : null,
                'requested_pickup_time'=> $t->requested_pickup_time?->toTimeString('minute'),
                'pickup'               => $t->pickupLocation?->label,
                'dropoff'              => $t->dropoffLocation?->label,
                'status'               => $t->status,
                'special_instructions' => $t->special_instructions,
                'mobility_flags'       => $t->mobility_flags_snapshot ?? [],
            ]);

        return response()->json([
            'add_ons' => $addOns,
            'count'   => $addOns->count(),
        ]);
    }

    /**
     * New participant mobility flags added today — relevant to transport operations.
     * TRANSPORT_FLAGS: wheelchair, stretcher, oxygen, behavioral.
     */
    public function flagAlerts(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $flags = ParticipantFlag::where('tenant_id', $tenantId)
            ->whereIn('flag_type', ParticipantFlag::TRANSPORT_FLAGS)
            ->where('is_active', true)
            ->whereDate('created_at', today())
            ->with(['participant:id,first_name,last_name,mrn', 'createdBy:id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (ParticipantFlag $f) => [
                'id'          => $f->id,
                'participant' => $f->participant ? [
                    'id'   => $f->participant->id,
                    'name' => $f->participant->first_name . ' ' . $f->participant->last_name,
                    'mrn'  => $f->participant->mrn,
                ] : null,
                'flag_type'   => $f->flag_type,
                'flag_label'  => $f->label(),
                'severity'    => $f->severity,
                'description' => $f->description,
                'added_by'    => $f->createdBy
                    ? $f->createdBy->first_name . ' ' . $f->createdBy->last_name
                    : null,
                'created_at'  => $f->created_at?->diffForHumans(),
            ]);

        return response()->json([
            'flags' => $flags,
            'count' => $flags->count(),
        ]);
    }

    /**
     * Tenant transport configuration panel.
     * Shows transport_mode (direct|broker) and, if broker, pending vendor assignments.
     * Direct mode: no vendor data returned (vendor fields hidden in UI).
     */
    public function config(): JsonResponse
    {
        $this->requireDept();
        $user   = Auth::user();
        $tenant = Tenant::find($user->tenant_id);

        $isBroker = $tenant?->isBrokerMode() ?? false;

        // Pending add-ons without vendor assignment (only meaningful in broker mode).
        // In broker mode, trips without a transport_trip_id assigned are "unassigned".
        $pendingVendorAssignment = $isBroker
            ? TransportRequest::where('tenant_id', $user->tenant_id)
                ->whereIn('status', ['requested', 'scheduled'])
                ->whereNull('transport_trip_id')
                ->whereDate('requested_pickup_time', today())
                ->count()
            : null;

        return response()->json([
            'transport_mode'            => $tenant?->transport_mode ?? 'direct',
            'is_broker_mode'            => $isBroker,
            'pending_vendor_assignment' => $pendingVendorAssignment,
            'auto_logout_minutes'       => $tenant?->auto_logout_minutes,
        ]);
    }
}
