<?php

// ─── TransportRequestController ───────────────────────────────────────────────
// Handles transport request creation (Add-On flow) and dispatch management.
//
// Routes:
//   POST   /transport/add-ons              → store()        (any dept; creates add-on request)
//   GET    /transport/add-ons/pending      → pending()      (Transportation Team only)
//   PUT    /transport/add-ons/{request}    → update()       (Transportation Team only)
//   POST   /transport/add-ons/{request}/cancel → cancel()  (Transportation Team or requesting dept)
//   GET    /transport/manifest             → manifest()     (Inertia page)
//   GET    /transport/manifest/runs        → runs()         (JSON; run-sheet data for a date)
//
// The Add-On flow:
//   Any staff member can submit a same-day transport request via the Add-On Modal.
//   → Creates a TransportRequest with trip_type='add_on', status='requested'.
//   → Fires an alert to the Transportation Team for review.
//   → Transportation Team approves (PUT), which bridges to transport app.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransportRequestRequest;
use App\Http\Requests\UpdateTransportRequestRequest;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Participant;
use App\Models\ParticipantFlag;
use App\Models\TransportRequest;
use App\Services\AlertService;
use App\Services\TransportBridgeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransportRequestController extends Controller
{
    public function __construct(
        private readonly TransportBridgeService $bridge,
        private readonly AlertService $alertService,
    ) {}

    // ── Add-On queue: manifest page ───────────────────────────────────────────

    /**
     * Render the Transport Manifest Inertia page.
     * Appointment/run data is loaded client-side via runs().
     */
    public function manifest(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Transport/Manifest', [
            'sites' => \App\Models\Site::where('tenant_id', $user->tenant_id)
                ->where('is_active', true)
                ->get(['id', 'name']),
        ]);
    }

    /**
     * JSON: transport run-sheet data for a specific date + site.
     * Transportation Team sees all runs; other departments see only their participants.
     *
     * Returns: array of TransportRequest records with participant, flags, locations.
     */
    public function runs(Request $request): JsonResponse
    {
        $user = $request->user();
        $date = $request->input('date', today()->toDateString());

        $query = TransportRequest::forTenant($user->tenant_id)
            ->forDate($date)
            ->whereNotIn('status', ['cancelled'])   // Cancelled trips are excluded from the run sheet
            ->with([
                'participant:id,first_name,last_name,mrn',
                'pickupLocation:id,name,label,street,city',
                'dropoffLocation:id,name,label,street,city',
                'requestingUser:id,first_name,last_name',
            ])
            ->orderBy('requested_pickup_time');

        if ($request->filled('site_id')) {
            // Filter to participants at the given site
            $query->whereHas('participant', fn ($q) => $q->where('site_id', $request->input('site_id')));
        }

        $runs = $query->get()->map(function (TransportRequest $r) {
            // Attach mobility flag snapshot so the run sheet shows flags as-requested
            return [
                'id'                   => $r->id,
                'participant'          => $r->participant,
                // Remap snapshot keys to canonical flag_type for frontend consistency
                'mobility_flags'       => array_map(fn ($f) => [
                    'flag_type'   => $f['type'] ?? $f['flag_type'] ?? null,
                    'severity'    => $f['severity'] ?? null,
                    'description' => $f['description'] ?? null,
                ], $r->mobility_flags_snapshot ?? []),
                'pickup_time'          => $r->requested_pickup_time?->toIso8601String(),
                'scheduled_time'       => $r->scheduled_pickup_time?->toIso8601String(),
                'actual_pickup_time'   => $r->actual_pickup_time?->toIso8601String(),
                'actual_dropoff_time'  => $r->actual_dropoff_time?->toIso8601String(),
                'pickup_location'      => $r->pickupLocation,
                'dropoff_location'     => $r->dropoffLocation,
                'trip_type'            => $r->trip_type,
                'status'               => $r->status,
                'driver_notes'         => $r->driver_notes,
                'requesting_user'      => $r->requestingUser,
                'requesting_department'=> $r->requesting_department,
                'special_instructions' => $r->special_instructions,
            ];
        });

        return response()->json($runs);
    }

    // ── Add-On submission ─────────────────────────────────────────────────────

    /**
     * Submit an add-on transport request (same-day unscheduled trip).
     * Any authenticated user can submit; Transportation Team reviews and approves.
     *
     * Captures mobility_flags_snapshot at time of request (historical accuracy).
     * Fires an alert to the Transportation Team for immediate review.
     */
    public function store(StoreTransportRequestRequest $request): JsonResponse
    {
        $user        = $request->user();
        $participant = Participant::where('tenant_id', $user->tenant_id)
            ->findOrFail($request->input('participant_id'));

        // Capture active transport flags at time of request
        $flagsSnapshot = $participant->activeFlags()
            ->whereIn('flag_type', ParticipantFlag::TRANSPORT_FLAGS)
            ->get()
            ->map(fn ($f) => ['type' => $f->flag_type, 'severity' => $f->severity])
            ->values()
            ->toArray();

        $transportRequest = TransportRequest::create([
            'tenant_id'              => $user->tenant_id,
            'participant_id'         => $participant->id,
            'appointment_id'         => $request->input('appointment_id'),
            'requesting_user_id'     => $user->id,
            'requesting_department'  => $user->department,
            'trip_type'              => $request->input('trip_type', 'add_on'),
            'pickup_location_id'     => $request->input('pickup_location_id'),
            'dropoff_location_id'    => $request->input('dropoff_location_id'),
            'requested_pickup_time'  => $request->input('requested_pickup_time'),
            'special_instructions'   => $request->input('special_instructions'),
            'mobility_flags_snapshot'=> $flagsSnapshot,
            'status'                 => 'requested',
        ]);

        AuditLog::record(
            action:       'transport_request.created',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'transport_request',
            resourceId:   $transportRequest->id,
            description:  "Transport request created for participant {$participant->mrn}",
            newValues:    ['trip_type' => $transportRequest->trip_type, 'status' => 'requested'],
        );

        // Alert Transportation Team about the new add-on request
        if ($transportRequest->trip_type === 'add_on') {
            $name = trim("{$participant->first_name} {$participant->last_name}");
            $this->alertService->create([
                'tenant_id'          => $user->tenant_id,
                'participant_id'     => $participant->id,
                'source_module'      => 'transport',
                'alert_type'         => 'info',
                'title'              => "Add-On Transport Request — {$name}",
                'message'            => "{$user->first_name} {$user->last_name} ({$user->department}) submitted "
                                      . "an add-on transport request for {$name}.",
                'severity'           => 'info',
                'target_departments' => ['transportation'],
                'created_by_system'  => false,
                'created_by_user_id' => $user->id,
            ]);
        }

        return response()->json($transportRequest->load(['pickupLocation', 'dropoffLocation']), 201);
    }

    // ── Transportation Team: manage add-ons ───────────────────────────────────

    /**
     * JSON: list of pending add-on requests awaiting Transportation Team review.
     * Transportation Team only.
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->department === 'transportation', 403);

        $pending = TransportRequest::forTenant($user->tenant_id)
            ->pendingAddOns()
            ->with(['participant:id,first_name,last_name,mrn', 'pickupLocation', 'dropoffLocation', 'requestingUser:id,first_name,last_name,department'])
            ->orderBy('requested_pickup_time')
            ->get();

        return response()->json($pending);
    }

    /**
     * Approve or schedule an add-on request (Transportation Team only).
     * When status is set to 'scheduled', bridges the request to the transport app.
     */
    public function update(UpdateTransportRequestRequest $request, TransportRequest $transportRequest): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->department === 'transportation', 403);
        abort_unless($transportRequest->tenant_id === $user->tenant_id, 403);
        abort_unless($transportRequest->isEditable(), 422);

        $transportRequest->update($request->safe()->except(['cancellation_reason']));

        // When scheduling (status = 'scheduled'), bridge to the transport app
        if ($request->input('status') === 'scheduled') {
            $this->bridge->createTripRequest($transportRequest->fresh());
        }

        AuditLog::record(
            action:       'transport_request.updated',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'transport_request',
            resourceId:   $transportRequest->id,
            description:  "Transport request updated by Transportation Team",
            newValues:    $request->validated(),
        );

        return response()->json($transportRequest->fresh());
    }

    /**
     * Cancel a transport request.
     * Available to Transportation Team or the requesting department.
     */
    public function cancel(Request $request, TransportRequest $transportRequest): JsonResponse
    {
        $user = $request->user();
        abort_unless($transportRequest->tenant_id === $user->tenant_id, 403);
        abort_unless(
            $user->department === 'transportation' || $user->department === $transportRequest->requesting_department,
            403
        );
        // Completed and already-cancelled trips cannot be re-cancelled
        abort_if(in_array($transportRequest->status, ['completed', 'cancelled']), 409);

        $reason = $request->input('cancellation_reason', 'Cancelled by staff');
        $this->bridge->cancelTrip($transportRequest, $reason);

        AuditLog::record(
            action:       'transport_request.cancelled',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'transport_request',
            resourceId:   $transportRequest->id,
            description:  "Transport request cancelled: {$reason}",
            newValues:    ['status' => 'cancelled', 'reason' => $reason],
        );

        return response()->json(['status' => 'cancelled']);
    }
}
