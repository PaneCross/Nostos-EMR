<?php

// ─── SiteContextController ───────────────────────────────────────────────────
// Phase 10B — Site context switcher for executive and Nostos SA dept users.
//
// Allows users who can switch site context to select an active site for their
// current session. The active site filters participant/clinical data shown on
// dashboards and executive views.
//
// Routes:
//   POST /site-context/switch  — set session active_site_id (JSON)
//   DELETE /site-context       — clear session active_site_id, revert to own site (JSON)
//
// Access control:
//   - Executive users: may only switch to sites within their own tenant
//   - role='super_admin': may switch to any site across all tenants
//   - department='super_admin': may switch to any site across all tenants
//   - Regular users: 403 — they have no concept of site switching
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SiteContextController extends Controller
{
    /**
     * Switch the session's active site context.
     * Executive users are restricted to their own tenant's sites.
     * Super-admin users (role or dept) may switch to any site.
     */
    public function switch(Request $request): JsonResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'site_id' => 'required|integer|exists:shared_sites,id',
        ]);

        $site = Site::findOrFail($data['site_id']);

        // Executives can only switch within their own tenant
        if ($user->isExecutive() && $site->tenant_id !== $user->tenant_id) {
            return response()->json(['message' => 'Cannot switch to a site outside your organisation.'], 403);
        }

        // Regular users have no site-switching capability
        if (! $user->isSuperAdmin() && ! $user->isDeptSuperAdmin() && ! $user->isExecutive()) {
            return response()->json(['message' => 'Site switching is not available for your role.'], 403);
        }

        session(['active_site_id' => $site->id]);

        return response()->json([
            'site' => [
                'id'   => $site->id,
                'name' => $site->name,
            ],
        ]);
    }

    /**
     * Clear the active site context, reverting to the user's own site.
     */
    public function clear(): JsonResponse
    {
        $user = Auth::user();

        if (! $user->isSuperAdmin() && ! $user->isDeptSuperAdmin() && ! $user->isExecutive()) {
            return response()->json(['message' => 'Site switching is not available for your role.'], 403);
        }

        session()->forget('active_site_id');

        return response()->json(['site' => null]);
    }
}
