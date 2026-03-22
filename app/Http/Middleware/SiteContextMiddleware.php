<?php

// ─── SiteContextMiddleware ────────────────────────────────────────────────────
// Phase 10B — Multi-site context resolution for executive and Nostos SA dept users.
//
// Resolves the "active site" for the current authenticated request:
//   - Regular users: always their own site_id from shared_users
//   - Executive users: session('active_site_id') with fallback to their default site_id
//   - Nostos SA dept (department='super_admin'): session('active_site_id')
//   - Role-based SA (role='super_admin'): session('active_site_id')
//
// Sets request attribute 'active_site_id' for use by controllers that need to
// scope queries to the active site rather than the user's own site.
//
// The site switcher (SiteContextController) validates tenant ownership before
// writing to session, so this middleware can trust the session value.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SiteContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user) {
            $request->attributes->set('active_site_id', $this->resolveActiveSiteId($user));
        }

        return $next($request);
    }

    /**
     * Determine the active site for this request.
     *
     * Super-admin users (role or dept) and executives can switch site context.
     * Regular users are always scoped to their own site.
     */
    private function resolveActiveSiteId($user): ?int
    {
        $canSwitch = $user->isSuperAdmin() || $user->isDeptSuperAdmin() || $user->isExecutive();

        if ($canSwitch) {
            return session('active_site_id') ?? $user->site_id;
        }

        // Regular users: always their own site_id
        return $user->site_id;
    }
}
