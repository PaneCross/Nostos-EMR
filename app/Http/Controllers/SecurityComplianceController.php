<?php

// ─── SecurityComplianceController ────────────────────────────────────────────
// HIPAA Security Rule compliance management: BAA tracking + SRA records +
// runtime encryption status checklist.
//
// Resolves BLOCKER-01 (encryption at rest) and BLOCKER-03 (SRA / BAA tracking)
// from the 2026-03-31 compliance audit.
//
// Route list:
//   GET  /it-admin/security          → index()     (Inertia — 3-tab page)
//   POST /it-admin/baa               → baaStore()  (create BAA record)
//   PUT  /it-admin/baa/{baa}         → baaUpdate() (update BAA record)
//   POST /it-admin/sra               → sraStore()  (create SRA record)
//   PUT  /it-admin/sra/{sra}         → sraUpdate() (update SRA record)
//
// Authorization: all endpoints require department='it_admin'.
// Tenant isolation: baaUpdate/sraUpdate abort 403 on cross-tenant access.
//
// Encryption status is computed at runtime from live config() values —
// no DB storage needed. Each check returns a pass/warn/fail status so the
// UI can render appropriate badge colors.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\BaaRecord;
use App\Models\SraRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class SecurityComplianceController extends Controller
{
    // ── Guard ─────────────────────────────────────────────────────────────────

    /** All endpoints in this controller require IT Admin department */
    private function requireItAdmin(Request $request): void
    {
        abort_if(
            $request->user()->department !== 'it_admin',
            403,
            'Security & Compliance management requires IT Admin access.'
        );
    }

    // ── Dashboard page ────────────────────────────────────────────────────────

    /**
     * Render the Security & Compliance Inertia page.
     * Pre-loads all 3 tabs' data to avoid any lazy-load flicker.
     *
     * GET /it-admin/security
     */
    public function index(Request $request): InertiaResponse
    {
        $this->requireItAdmin($request);

        $tenantId = $request->user()->tenant_id;

        $baaRecords = BaaRecord::forTenant($tenantId)
            ->orderByDesc('baa_signed_date')
            ->get()
            ->map->toApiArray();

        $sraRecords = SraRecord::forTenant($tenantId)
            ->orderByDesc('sra_date')
            ->get()
            ->map->toApiArray();

        // Runtime encryption checklist — re-evaluated on every page load
        $encryptionStatus = $this->buildEncryptionStatus();

        // Compliance posture summary for the page header chips
        $expiredBaaCount   = BaaRecord::forTenant($tenantId)->expired()->count();
        $expiringSoonCount = BaaRecord::forTenant($tenantId)->expiringSoon()->count();
        $latestSra         = SraRecord::forTenant($tenantId)->completed()->orderByDesc('sra_date')->first();
        $sraOverdue        = $latestSra ? $latestSra->isOverdue() : true; // no SRA = overdue

        return Inertia::render('ItAdmin/Security', [
            'baaRecords'       => $baaRecords,
            'sraRecords'       => $sraRecords,
            'encryptionStatus' => $encryptionStatus,
            'vendorTypes'      => BaaRecord::VENDOR_TYPE_LABELS,
            'baaStatuses'      => BaaRecord::STATUS_LABELS,
            'sraRiskLevels'    => SraRecord::RISK_LEVEL_LABELS,
            'sraStatuses'      => SraRecord::STATUS_LABELS,
            'posture' => [
                'expired_baa_count'   => $expiredBaaCount,
                'expiring_soon_count' => $expiringSoonCount,
                'sra_overdue'         => $sraOverdue,
                'session_encrypted'   => (bool) config('session.encrypt'),
                'db_ssl_required'     => config('database.connections.pgsql.sslmode', 'prefer') === 'require',
            ],
        ]);
    }

    // ── BAA endpoints ─────────────────────────────────────────────────────────

    /**
     * Create a new BAA record for the current tenant.
     *
     * POST /it-admin/baa
     */
    public function baaStore(Request $request): JsonResponse
    {
        $this->requireItAdmin($request);

        $validated = $request->validate([
            'vendor_name'         => 'required|string|max:255',
            'vendor_type'         => 'required|in:' . implode(',', BaaRecord::VENDOR_TYPES),
            'phi_accessed'        => 'required|boolean',
            'baa_signed_date'     => 'nullable|date',
            'baa_expiration_date' => 'nullable|date',
            'status'              => 'required|in:' . implode(',', BaaRecord::STATUSES),
            'contact_name'        => 'nullable|string|max:255',
            'contact_email'       => 'nullable|email|max:255',
            'contact_phone'       => 'nullable|string|max:30',
            'notes'               => 'nullable|string',
        ]);

        $baa = BaaRecord::create(array_merge($validated, [
            'tenant_id' => $request->user()->tenant_id,
        ]));

        return response()->json(['message' => 'BAA record created.', 'baa' => $baa->toApiArray()], 201);
    }

    /**
     * Update an existing BAA record. Enforces tenant isolation.
     *
     * PUT /it-admin/baa/{baa}
     */
    public function baaUpdate(Request $request, BaaRecord $baa): JsonResponse
    {
        $this->requireItAdmin($request);
        abort_if($baa->tenant_id !== $request->user()->tenant_id, 403, 'Cross-tenant BAA access denied.');

        $validated = $request->validate([
            'vendor_name'         => 'required|string|max:255',
            'vendor_type'         => 'required|in:' . implode(',', BaaRecord::VENDOR_TYPES),
            'phi_accessed'        => 'required|boolean',
            'baa_signed_date'     => 'nullable|date',
            'baa_expiration_date' => 'nullable|date',
            'status'              => 'required|in:' . implode(',', BaaRecord::STATUSES),
            'contact_name'        => 'nullable|string|max:255',
            'contact_email'       => 'nullable|email|max:255',
            'contact_phone'       => 'nullable|string|max:30',
            'notes'               => 'nullable|string',
        ]);

        $baa->update($validated);

        return response()->json(['message' => 'BAA record updated.', 'baa' => $baa->fresh()->toApiArray()]);
    }

    // ── SRA endpoints ─────────────────────────────────────────────────────────

    /**
     * Create a new SRA record for the current tenant.
     *
     * POST /it-admin/sra
     */
    public function sraStore(Request $request): JsonResponse
    {
        $this->requireItAdmin($request);

        $validated = $request->validate([
            'sra_date'            => 'required|date',
            'conducted_by'        => 'required|string|max:255',
            'scope_description'   => 'required|string',
            'risk_level'          => 'required|in:' . implode(',', SraRecord::RISK_LEVELS),
            'findings_summary'    => 'nullable|string',
            'next_sra_due'        => 'nullable|date|after:sra_date',
            'status'              => 'required|in:' . implode(',', SraRecord::STATUSES),
            'reviewed_by_user_id' => 'nullable|integer|exists:shared_users,id',
        ]);

        $sra = SraRecord::create(array_merge($validated, [
            'tenant_id' => $request->user()->tenant_id,
        ]));

        return response()->json(['message' => 'SRA record created.', 'sra' => $sra->toApiArray()], 201);
    }

    /**
     * Update an existing SRA record. Enforces tenant isolation.
     *
     * PUT /it-admin/sra/{sra}
     */
    public function sraUpdate(Request $request, SraRecord $sra): JsonResponse
    {
        $this->requireItAdmin($request);
        abort_if($sra->tenant_id !== $request->user()->tenant_id, 403, 'Cross-tenant SRA access denied.');

        $validated = $request->validate([
            'sra_date'            => 'required|date',
            'conducted_by'        => 'required|string|max:255',
            'scope_description'   => 'required|string',
            'risk_level'          => 'required|in:' . implode(',', SraRecord::RISK_LEVELS),
            'findings_summary'    => 'nullable|string',
            'next_sra_due'        => 'nullable|date',
            'status'              => 'required|in:' . implode(',', SraRecord::STATUSES),
            'reviewed_by_user_id' => 'nullable|integer|exists:shared_users,id',
        ]);

        $sra->update($validated);

        return response()->json(['message' => 'SRA record updated.', 'sra' => $sra->fresh()->toApiArray()]);
    }

    // ── Encryption status helper ──────────────────────────────────────────────

    /**
     * Build the runtime encryption status checklist.
     * Checks 4 HIPAA-required controls and returns a structured array for the UI.
     *
     * Each item: { label, value, status ('pass'|'warn'|'fail'), note }
     *
     * These are computed from live config() values so the checklist reflects the
     * current environment without requiring manual updates to any DB record.
     */
    private function buildEncryptionStatus(): array
    {
        $sessionEncrypt = config('session.encrypt', false);
        $sslMode        = config('database.connections.pgsql.sslmode', 'prefer');
        $filesystemDisk = config('filesystems.default', 'local');

        // Check if Participant model has 'encrypted' cast for PHI fields
        $participantCasts = (new \App\Models\Participant())->getCasts();
        $fieldEncryption  = ($participantCasts['medicare_id'] ?? null) === 'encrypted';

        return [
            'session' => [
                'label'  => 'Session Encryption',
                'value'  => (bool) $sessionEncrypt,
                'status' => $sessionEncrypt ? 'pass' : 'fail',
                'note'   => $sessionEncrypt
                    ? 'SESSION_ENCRYPT=true — session data encrypted with APP_KEY (AES-256-CBC).'
                    : 'SESSION_ENCRYPT=false — session data stored as plaintext. '
                      . 'Set SESSION_ENCRYPT=true in production .env (HIPAA §164.312(a)(2)(iv)).',
            ],
            'db_ssl' => [
                'label'  => 'Database SSL/TLS',
                'value'  => $sslMode,
                'status' => match ($sslMode) {
                    'require', 'verify-ca', 'verify-full' => 'pass',
                    'prefer'                               => 'warn',
                    default                                => 'fail',
                },
                'note'   => match ($sslMode) {
                    'require', 'verify-ca', 'verify-full'
                        => "DB_SSLMODE={$sslMode} — all PostgreSQL connections use TLS.",
                    'prefer'
                        => 'DB_SSLMODE=prefer — connection may fall back to plaintext. '
                           . 'Set DB_SSLMODE=require in production (HIPAA §164.312(e)(1)).',
                    default
                        => "DB_SSLMODE={$sslMode} — plaintext connections allowed. "
                           . 'Set DB_SSLMODE=require in production immediately.',
                },
            ],
            'field_encryption' => [
                'label'  => 'PHI Field Encryption',
                'value'  => $fieldEncryption,
                'status' => $fieldEncryption ? 'pass' : 'warn',
                'note'   => $fieldEncryption
                    ? 'Sensitive PHI fields (medicare_id, medicaid_id, ssn_last_four, member_id) '
                      . 'encrypted at rest using APP_KEY. Rotation requires re-seeding.'
                    : 'PHI field encryption is not active. '
                      . 'Ensure Participant and InsuranceCoverage models have encrypted casts '
                      . 'for medicare_id, medicaid_id, ssn_last_four, member_id (HIPAA §164.312(a)(2)(iv)).',
            ],
            'storage' => [
                'label'  => 'Document Storage',
                'value'  => $filesystemDisk,
                'status' => $filesystemDisk === 's3' ? 'pass' : 'warn',
                'note'   => $filesystemDisk === 's3'
                    ? 'Documents stored on S3 — ensure SSE-KMS is enabled on the bucket for encryption at rest.'
                    : "Documents stored on '{$filesystemDisk}' disk (local storage, unencrypted). "
                      . 'Set FILESYSTEM_DISK=s3 with SSE-KMS enabled in production (DEBT-021).',
            ],
        ];
    }
}
