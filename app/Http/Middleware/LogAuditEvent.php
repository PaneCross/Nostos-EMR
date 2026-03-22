<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LogAuditEvent
{
    /**
     * Log a PHI access event for routes that touch participant data.
     *
     * Usage: ->middleware('audit.log:phi_read') or ->middleware('audit.log:phi_write')
     */
    public function handle(Request $request, Closure $next, string $action = 'phi_read'): Response
    {
        $response = $next($request);

        // Only log successful reads/writes (2xx)
        if ($response->isSuccessful() && Auth::check()) {
            $user = Auth::user();

            AuditLog::record(
                action: $action,
                tenantId: $user->tenant_id,
                userId: $user->id,
                resource_type: $this->resolveResourceType($request),
                resource_id: $this->resolveResourceId($request),
                description: "{$request->method()} {$request->path()}",
            );
        }

        return $response;
    }

    private function resolveResourceType(Request $request): ?string
    {
        // Extract resource type from the route name or URL segment
        $segments = $request->segments();

        return $segments[0] ?? null;
    }

    private function resolveResourceId(Request $request): ?int
    {
        // Try to extract a numeric ID from the route parameters
        $params = $request->route()?->parameters() ?? [];

        foreach ($params as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
