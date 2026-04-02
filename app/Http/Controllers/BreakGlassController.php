<?php

// ─── BreakGlassController ─────────────────────────────────────────────────────
// HIPAA Emergency Access Override (break-the-glass) endpoints.
//
// Participant-facing:
//   POST /participants/{participant}/break-glass  → requestAccess()
//     Rate-limited: 3 requests per user per 24 hours.
//     Returns event_id + access_expires_at on success.
//
// IT Admin management:
//   GET  /it-admin/break-glass                         → adminIndex()
//   POST /it-admin/break-glass/{event}/acknowledge     → acknowledge()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\BreakGlassEvent;
use App\Models\Participant;
use App\Services\BreakGlassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BreakGlassController extends Controller
{
    public function __construct(
        private readonly BreakGlassService $service,
    ) {}

    // ── Participant-facing ────────────────────────────────────────────────────

    /**
     * Request emergency read-only access to a participant's chart.
     * Creates an audit log entry + critical alert for IT Admin / QA.
     * Rate-limited: max 3 per user per 24 hours.
     *
     * POST /participants/{participant}/break-glass
     */
    public function requestAccess(Request $request, Participant $participant): JsonResponse
    {
        $user = Auth::user();

        // Tenant isolation check
        abort_if($participant->tenant_id !== $user->tenant_id, 403);

        $validated = $request->validate([
            'justification' => 'required|string',
        ]);

        // Justification length and rate-limit validation happen inside the service
        $event = $this->service->requestAccess(
            user:          $user,
            participant:   $participant,
            justification: $validated['justification'],
            ipAddress:     $request->ip(),
        );

        return response()->json([
            'event_id'         => $event->id,
            'access_expires_at'=> $event->access_expires_at->toIso8601String(),
        ], 201);
    }

    // ── IT Admin management ────────────────────────────────────────────────────

    /**
     * IT Admin break-glass event log with filtering.
     * Requires it_admin department or super_admin.
     *
     * GET /it-admin/break-glass
     */
    public function adminIndex(Request $request): InertiaResponse
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'it_admin') {
            abort(403);
        }

        $tenantId = $user->tenant_id;

        $query = BreakGlassEvent::forTenant($tenantId)
            ->with([
                'user:id,first_name,last_name,department',
                'participant:id,first_name,last_name,mrn',
                'acknowledgedBy:id,first_name,last_name',
            ])
            ->orderByDesc('created_at');

        // Optional filters
        if ($request->filled('unacknowledged')) {
            $query->unacknowledged();
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        $events = $query->limit(100)->get()->map(fn ($e) => $e->toApiArray());

        $unacknowledgedCount = BreakGlassEvent::forTenant($tenantId)->unacknowledged()->count();

        return Inertia::render('ItAdmin/BreakGlass', [
            'events'               => $events,
            'unacknowledged_count' => $unacknowledgedCount,
        ]);
    }

    /**
     * Supervisor acknowledges they have reviewed a break-glass event.
     *
     * POST /it-admin/break-glass/{event}/acknowledge
     */
    public function acknowledge(BreakGlassEvent $event): JsonResponse
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'it_admin') {
            abort(403);
        }

        // Tenant isolation
        abort_if($event->tenant_id !== $user->tenant_id, 403);

        abort_if($event->isAcknowledged(), 409, 'Event already acknowledged.');

        $this->service->acknowledge($event, $user);

        return response()->json(['message' => 'Acknowledged.']);
    }
}
