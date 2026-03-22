<?php

// ─── TransferAdminController ──────────────────────────────────────────────────
// Powers the /enrollment/transfers admin page.
// Lists all transfers for the tenant with filtering by status.
//
// Allowed: enrollment, it_admin, super_admin.
//
// Phase 10A
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\ParticipantSiteTransfer;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TransferAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();

        if (!$user->isSuperAdmin() && !in_array($user->department, ['enrollment', 'it_admin'], true)) {
            abort(403);
        }

        $query = ParticipantSiteTransfer::forTenant($user->tenant_id)
            ->with([
                'participant:id,first_name,last_name,mrn',
                'fromSite:id,name',
                'toSite:id,name',
                'requestedBy:id,first_name,last_name',
                'approvedBy:id,first_name,last_name',
            ])
            ->orderByDesc('requested_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transfers = $query->paginate(25)->through(fn ($t) => [
            'id'                    => $t->id,
            'participant'           => [
                'id'   => $t->participant->id,
                'name' => $t->participant->first_name . ' ' . $t->participant->last_name,
                'mrn'  => $t->participant->mrn,
            ],
            'from_site'             => $t->fromSite ? ['id' => $t->fromSite->id, 'name' => $t->fromSite->name] : null,
            'to_site'               => $t->toSite   ? ['id' => $t->toSite->id,   'name' => $t->toSite->name]   : null,
            'transfer_reason_label' => ParticipantSiteTransfer::TRANSFER_REASON_LABELS[$t->transfer_reason] ?? $t->transfer_reason,
            'requested_by'          => $t->requestedBy ? $t->requestedBy->first_name . ' ' . $t->requestedBy->last_name : null,
            'approved_by'           => $t->approvedBy  ? $t->approvedBy->first_name  . ' ' . $t->approvedBy->last_name  : null,
            'requested_at'          => $t->requested_at?->format('Y-m-d'),
            'effective_date'        => $t->effective_date?->format('Y-m-d'),
            'status'                => $t->status,
        ]);

        $sites = Site::where('tenant_id', $user->tenant_id)->get(['id', 'name']);

        return Inertia::render('Enrollment/Transfers', [
            'transfers'      => $transfers,
            'sites'          => $sites,
            'transferReasons'=> ParticipantSiteTransfer::TRANSFER_REASON_LABELS,
            'filters'        => ['status' => $request->status],
        ]);
    }
}
