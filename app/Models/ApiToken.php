<?php

// ─── ApiToken ─────────────────────────────────────────────────────────────────
// FHIR R4 API bearer tokens for external system access.
//
// Tokens are stored as SHA-256 hashes. The plaintext token is only shown once
// at creation — NostosEMR cannot recover it after that.
//
// Used by FhirAuthMiddleware to authenticate incoming /fhir/R4/* requests.
// All reads via FHIR API are logged to shared_audit_logs with source='fhir_api'.
//
// Scope format: e.g. ["patient.read", "observation.read"]
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiToken extends Model
{
    use HasFactory;

    protected $table = 'emr_api_tokens';

    /** All supported FHIR resource scopes. */
    public const SCOPES = [
        'patient.read',
        'observation.read',
        'medication.read',
        'condition.read',
        'allergy.read',
        'careplan.read',
        'appointment.read',
        'immunization.read',
        'procedure.read',
        'encounter.read',
        'diagnosticreport.read',
        'practitioner.read',
        'organization.read',
    ];

    protected $fillable = [
        'user_id',
        'tenant_id',
        'token',
        'scopes',
        'name',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'scopes'      => 'array',
        'last_used_at'=> 'datetime',
        'expires_at'  => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Hash a plaintext token for storage/lookup.
     * Uses SHA-256 — fast enough for internal lookup, not suitable for passwords.
     */
    public static function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Find a token record by its plaintext value.
     * Returns null if not found or expired.
     */
    public static function findByToken(string $plaintext): ?self
    {
        $hashed = self::hashToken($plaintext);

        return self::where('token', $hashed)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    /** True if the token grants the requested scope. */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }

    /** Mark this token as used right now. Avoid overriding Model::touch() — use markUsed() instead. */
    public function markUsed(): bool
    {
        return $this->update(['last_used_at' => now()]);
    }
}
