<?php

// ─── BillingComplianceController ──────────────────────────────────────────────
// Powers the Billing Compliance Checklist for the Finance department.
//
// Route list:
//   GET /billing/compliance-checklist       → index() — Inertia page
//   GET /billing/compliance-checklist/data  → data()  — JSON checklist (live refresh)
//
// Uses BillingComplianceService to compute 5 checklist categories:
//   1. Encounter Data (completeness, diagnosis codes, rejections)
//   2. Risk Adjustment (RAF coverage, stale scores, low-RAF alerts)
//   3. Capitation (monthly record coverage, RAF populated)
//   4. HPMS (enrollment file submission status)
//   5. Part D / PDE (PDE records, pending submissions, TrOOP alerts)
//
// Department access: finance only (+ super_admin, it_admin).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Services\BillingComplianceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BillingComplianceController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeFinance(Request $request): void
    {
        $user = $request->user();
        abort_if(
            !$user->isSuperAdmin()
            && !in_array($user->department, ['finance', 'it_admin']),
            403
        );
    }

    // ── Inertia Page ─────────────────────────────────────────────────────────

    /**
     * Render the Billing Compliance Checklist Inertia page.
     * Full checklist is pre-loaded for initial render.
     *
     * GET /billing/compliance-checklist
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        $checklist = (new BillingComplianceService())->getChecklist($tenantId);

        return Inertia::render('Finance/ComplianceChecklist', [
            'checklist' => $checklist,
        ]);
    }

    // ── JSON Data ─────────────────────────────────────────────────────────────

    /**
     * Return the full compliance checklist as JSON.
     * Used by the page for on-demand refresh without a full page reload.
     *
     * GET /billing/compliance-checklist/data
     */
    public function data(Request $request): JsonResponse
    {
        $this->authorizeFinance($request);
        $tenantId = $request->user()->tenant_id;

        $checklist = (new BillingComplianceService())->getChecklist($tenantId);

        return response()->json($checklist);
    }
}
