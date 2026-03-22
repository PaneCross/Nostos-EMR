<?php

// ─── Transport Dashboard ───────────────────────────────────────────────────────
// Shows participant transport needs across the entire census:
// mobility equipment flags, behavioral flags, and address information.
// This is a read-only operational view — trip scheduling lives in a future phase.
// Route: GET /transport → Inertia::render('Transport/Dashboard')
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Models\ParticipantFlag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TransportController extends Controller
{
    /**
     * Cross-participant transport needs dashboard.
     *
     * Shows all active participants with their transport-relevant flags
     * (wheelchair, stretcher, oxygen, behavioral) and primary address.
     * Grouped stats provide a quick census of mobility/equipment needs.
     */
    public function dashboard(Request $request): Response
    {
        $tenantId = Auth::user()->tenant_id;

        // Load all active participants with their active transport-relevant flags
        // and primary address (for route planning context).
        $participants = Participant::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with([
                'flags' => fn ($q) => $q
                    ->where('is_active', true)
                    ->whereIn('flag_type', ParticipantFlag::TRANSPORT_FLAGS)
                    ->select(['id', 'participant_id', 'flag_type', 'severity', 'description']),
                'addresses' => fn ($q) => $q
                    ->where('is_primary', true)
                    ->select(['id', 'participant_id', 'street', 'city', 'state', 'zip']),
            ])
            ->select(['id', 'tenant_id', 'mrn', 'first_name', 'last_name', 'preferred_name', 'is_active'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function (Participant $p) {
                $homeAddress = $p->addresses->first();
                return [
                    'id'         => $p->id,
                    'mrn'        => $p->mrn,
                    'first_name' => $p->first_name,
                    'last_name'  => $p->last_name,
                    'flags'      => $p->flags->map(fn ($f) => [
                        'flag_type'   => $f->flag_type,
                        'severity'    => $f->severity,
                        'description' => $f->description,
                    ])->values()->all(),
                    'address'    => $homeAddress ? [
                        'line'  => $homeAddress->street,
                        'city'  => $homeAddress->city,
                        'state' => $homeAddress->state,
                        'zip'   => $homeAddress->zip,
                    ] : null,
                ];
            });

        // Aggregate stats for the summary chips
        $stats = [
            'total_active'     => $participants->count(),
            'needs_wheelchair' => $participants->filter(fn ($p) => collect($p['flags'])->contains('flag_type', 'wheelchair'))->count(),
            'needs_stretcher'  => $participants->filter(fn ($p) => collect($p['flags'])->contains('flag_type', 'stretcher'))->count(),
            'needs_oxygen'     => $participants->filter(fn ($p) => collect($p['flags'])->contains('flag_type', 'oxygen'))->count(),
            'has_behavioral'   => $participants->filter(fn ($p) => collect($p['flags'])->contains('flag_type', 'behavioral'))->count(),
            'no_flags'         => $participants->filter(fn ($p) => empty($p['flags']))->count(),
        ];

        return Inertia::render('Transport/Dashboard', [
            'participants' => $participants->values()->all(),
            'stats'        => $stats,
        ]);
    }
}
