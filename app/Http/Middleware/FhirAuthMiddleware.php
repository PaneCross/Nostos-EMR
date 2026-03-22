<?php

// ─── FhirAuthMiddleware ───────────────────────────────────────────────────────
// Authenticates incoming FHIR R4 API requests via Bearer token.
//
// Token lookup:
//   1. Extract Bearer token from Authorization header
//   2. SHA-256 hash the plaintext and query emr_api_tokens
//   3. Reject if token not found, expired, or missing required scope
//   4. Set request attributes: fhir_token, fhir_tenant_id, fhir_user_id
//   5. Update last_used_at on the token record (non-blocking)
//
// Error responses are FHIR OperationOutcome JSON with Content-Type: application/fhir+json
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FhirAuthMiddleware
{
    /**
     * Handle an incoming FHIR API request.
     *
     * @param string|null $scope Optional scope required for this route (e.g. 'patient.read').
     *                           If not provided, any valid token is accepted.
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        // Extract Bearer token from Authorization header
        $authHeader = $request->header('Authorization', '');
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return $this->fhirError(401, 'No bearer token provided.');
        }

        $plaintext = substr($authHeader, 7);

        // Look up token (automatically excludes expired tokens)
        $token = ApiToken::findByToken($plaintext);

        if (! $token) {
            return $this->fhirError(401, 'Invalid or expired token.');
        }

        // Check scope if required
        if ($scope !== null && ! $token->hasScope($scope)) {
            return $this->fhirError(403, "Token does not have required scope: {$scope}");
        }

        // Attach token data to request for downstream use
        $request->attributes->set('fhir_token',     $token);
        $request->attributes->set('fhir_tenant_id', $token->tenant_id);
        $request->attributes->set('fhir_user_id',   $token->user_id);

        // Update last_used_at without blocking the response
        $token->update(['last_used_at' => now()]);

        return $next($request);
    }

    /** Build a FHIR OperationOutcome error response. */
    private function fhirError(int $statusCode, string $message): Response
    {
        return response()->json([
            'resourceType' => 'OperationOutcome',
            'issue' => [
                [
                    'severity'    => $statusCode >= 500 ? 'fatal' : 'error',
                    'code'        => $statusCode === 401 ? 'security' : 'forbidden',
                    'diagnostics' => $message,
                ],
            ],
        ], $statusCode, ['Content-Type' => 'application/fhir+json']);
    }
}
