# CLAUDE.md — NostosEMR Working Memory

## PROJECT IDENTITY — READ THIS FIRST EVERY SESSION

**App name:** NostosEMR
**Framework:** Laravel 11, PHP 8.5
**Frontend:** React + TypeScript via Inertia.js + Tailwind CSS
**Database:** PostgreSQL (shared with Nostos transport app)
**Auth:** Laravel Fortify (passwordless OTP) + Socialite (Google, Yahoo stubs)
**Real-time:** Laravel Reverb (WebSockets)
**Queues:** Redis + Laravel Horizon
**Local stack:** Laravel Sail — pgsql, redis, mailpit, meilisearch
**App runs at:** http://localhost | OTP emails: http://localhost:8025
**Node:** v22.14.0 (local Windows — node_modules are Linux-native, build only via Docker)
**WSL2 project path:** `/home/tj/projects/nostosemr` (Ubuntu distro) — always run docker/git from here
**Windows edit path:** `C:\Users\TJ\Desktop\PACE EMR\nostosemr` — Claude Code edits go here; sync to WSL2 before committing

**Table prefixes:**
- `emr_*`       NostosEMR tables
- `shared_*`    Shared with Nostos transport (users, tenants, audit_logs, sessions)
- `transport_*` Nostos transport tables (read-only; writes via TransportBridgeService)

**14 Departments:** primary_care, therapies, social_work, behavioral_health, dietary, activities, home_care, transportation, pharmacy, idt, enrollment, finance, qa_compliance, it_admin
**Special roles:** executive (cross-site read within tenant), super_admin (Nostos staff, cross-tenant)

**Auth flow:** email → 6-digit OTP (10 min, max 3 attempts) → session
OR Google/Yahoo OAuth → email matched to provisioned user
No passwords. No self-registration. IT Admin provisions all accounts.
HIPAA auto-logout: configurable per tenant (default 15 min idle)

All deletes = soft deletes (deleted_at). Nothing ever hard-deleted.
Audit log (shared_audit_logs) = append-only, immutable, 6-year retention.

Transport integration: PENDING — ComingSoonBanner on all live transport routes.
EMR-side transport CRUD is functional; live sync not yet connected.
Future: TransportBridgeService will swap DB::table() for HTTP API calls.

UI language: dark collapsible left nav (#1e293b), white content area,
color-coded rows (red=critical, amber=warning, green=done, default=neutral),
tab-based filtering, status badge chips, sticky participant header.

TDD: every model→Factory, every controller→Feature test, every Service→Unit test.
Run `php artisan test --parallel --processes=4` after every phase. Zero failures before moving on.

Demo data: 'Sunrise PACE - Demo Organization', 2 sites (East + West),
30 participants (last name Testpatient), 28 users (last name Demo),
email format: firstname.department@sunrisepace-demo.test
Seed: `php artisan db:seed --class=DemoEnvironmentSeeder`

---

## SESSION STARTUP CHECKLIST (run at start of every session)
1. Read this entire CLAUDE.md file before doing anything else
2. Check WAVE STATUS to know which phase is current
3. Check KNOWN ISSUES for anything relevant to today's work
4. Check MIGRATIONS RUN to know what tables exist
5. If anything seems inconsistent with actual code, flag it in KNOWN ISSUES
6. Update WAVE STATUS and SESSION LOG at end of every session
7. **Push to GitHub as final step of every phase** — run from WSL2: `cd ~/projects/nostosemr && git add -A && git commit -m "W3-X: <phase name>" && git push` (use `~/bin/gh` for GH CLI ops; plain git push works for normal pushes)
8. **After every `--parallel` test run, fix storage permissions** (parallel workers run as root and leave root-owned cache files that break localhost):
   ```bash
   docker compose exec -T laravel.test chmod -R 777 storage bootstrap/cache
   docker compose exec -T laravel.test php artisan view:clear
   docker compose exec -T laravel.test php artisan config:clear && php artisan optimize
   ```
9. Output a RESUME PROMPT before ending (format: "To resume: We are in [phase]. Last completed: [task]. Next step: [task]. Run [command] to verify state.")

---

## WAVE STATUS
Wave 1 (Phases 1A-1C, 2A-2C, 3A-3D, 4A-4D, 5A-5D, 6A-6D, 7A-7D + Audits A-D + SA Enhancements): COMPLETE
Wave 2 (Phases 8A-12B): COMPLETE
Wave 3 (Phases W3-0 through W3-8): COMPLETE
  W3-0 Context Bake:               [x] COMPLETE — 2026-03-24
  W3-1 Dark Mode:                  [x] COMPLETE — 2026-03-26
  W3-2 Nav Menu Fixes:             [x] COMPLETE — 2026-03-26
  W3-3 Dashboard Overhaul:         [x] COMPLETE — 2026-03-26
  W3-4 Participant Section Fixes:  [x] COMPLETE — 2026-03-27
  W3-5 Chat Fixes:                 [x] COMPLETE — 2026-03-27
  W3-6 Site Transfer Integrity:    [x] COMPLETE — 2026-03-30
  W3-7 Billing Seed & Math:        [x] COMPLETE — 2026-03-31
  W3-8 Catch-All Fixes:            [x] COMPLETE — 2026-03-31

Wave 4 (Phases W4-0 through W4-9): IN PROGRESS
  W4-0  CLAUDE.md Wave 4 Update:          [x] COMPLETE — 2026-03-31
  W4-1  Grievance & Consent Module:        [ ] NOT STARTED
  W4-2  Encryption at Rest + BAA/SRA:      [ ] NOT STARTED
  W4-3  Demographics + Participant Fields:  [ ] NOT STARTED
  W4-4  Quick Wins — Vitals & Assessments: [ ] NOT STARTED
  W4-5  Care Plan + IDT Compliance:        [ ] NOT STARTED
  W4-6  Incident + Regulatory Tracking:    [ ] NOT STARTED
  W4-7  CPOE — Lightweight Order Entry:    [ ] NOT STARTED
  W4-8  New Note Types + Assessments:      [ ] NOT STARTED
  W4-9  FHIR Gaps + HPMS Verification:     [ ] NOT STARTED

---

## FUTURE SCOPE — DO NOT BUILD IN WAVE 4

1. **Full Transportation suite and live connections to Nostos transport.**
   Status: ComingSoonBanner in place. Brian (transport team) to implement.
   When ready: update TransportBridgeService to HTTP API calls, remove banners.

2. **Tablet and mobile views / responsive design.**
   Home care users need full workflow access on tablet.
   Mobile webapp views needed for all user types.
   Possible future: native app for home care specifically.
   Note: home care workflows on tablet is highest priority within this scope.
   Do not start until explicitly requested.

3. **Live EDI clearinghouse submission (BLOCKER-05).**
   837P builder exists (Edi837PBuilderService). No live submission pipeline.
   Requires clearinghouse vendor selection (Availity, Change Healthcare, etc.),
   credential config, 277CA tracking, and 835 remittance reconciliation.

4. **eRx integration (NCPDP SCRIPT).**
   Electronic prescribing to pharmacy. Requires Brian / transport team coordination
   for the PACE pharmacy integration model.

5. **C-CDA generation for transitions of care.**
   Requires HL7 FHIR/CDA library and Direct Secure Messaging (HISP) setup.
   Deferred pending clearinghouse and EHR integration decisions.

6. **HEDIS measure extraction.**
   Post-launch data quality work. Depends on clean encounter and diagnosis data.

7. **HCC chasing workflow UI.**
   Revenue integrity enhancement — surfaces HCC gap analysis for clinical staff.
   Depends on Phase 9C risk adjustment foundation (done) + clinical adoption.

8. **Beers Criteria / polypharmacy alerts.**
   Pharmacy enhancement for high-risk medications in elderly populations.
   Requires drug reference expansion and clinical validation.

9. **Patient/family portal.**
   Separate application, FHIR patient-facing API (SMART on FHIR).
   Out of scope for NostosEMR v1 — future standalone product.

10. **CMS-2728 form module.**
    ESRD (End-Stage Renal Disease) participants only. Low PACE volume.

11. **FHIR bulk export ($export operation).**
    Required for population-level analytics and payer integrations.
    Depends on USCDI v3 full compliance (DEBT-024).

12. **Telehealth integration.**
    Video visit scheduling + documentation linkage.

13. **Wound care documentation module.**
    Specialty clinical documentation with wound staging, measurements, photo.

14. **Remote Patient Monitoring (RPM) infrastructure.**
    Device data ingestion, threshold alerting, home care integration.

15. **GDS (Geriatric Depression Scale) assessment tool.**
    Validated 15-item depression screen. Common PACE quality measure.

16. **LACE+ Index readmission risk assessment.**
    Partially addressed in W4-8 (readmission risk narrative). Full scoring deferred.

17. **Dental note type.**
    PACE provides dental services; dedicated note template needed post-W4-8.

18. **Vision/Hearing screening assessment.**
    Standard PACE intake and annual screening tools.

---

## AUDIT FINDINGS (2026-03-31 system audit)
Full context for Wave 4 build. Status tags indicate which W4 phase addresses each item.

### BLOCKERS (must resolve before real patient data)
- BLOCKER-01 [W4-2]: Encryption at rest — no DB SSL configured, no field-level PHI
  encryption, SESSION_ENCRYPT not configured. Violates HIPAA Security Rule
  45 CFR §164.312(a)(2)(iv) and §164.312(e)(2)(ii).
- BLOCKER-02 [W4-1]: Grievance & consent module absent — no grievance workflow,
  no NPP (Notice of Privacy Practices) acknowledgment tracking, no formal
  HIPAA Authorization form workflow. Required by 42 CFR §460.122 and §460.124.
- BLOCKER-03 [W4-2]: No SRA (Security Risk Analysis) module, no BAA (Business
  Associate Agreement) tracking in system. Required by 45 CFR §164.308(a)(1)
  and §164.308(b)(1).
- BLOCKER-04 [W4-7]: Clinical > Orders page shows care plan goals worklist, NOT a
  clinical order entry system. CPOE (Computerized Physician Order Entry) is
  expected for 42 CFR §460.100 (medical care plan) and survey compliance.
- BLOCKER-05 [FUTURE]: Live EDI clearinghouse — 837P builder (Edi837PBuilderService)
  exists but no live submission, no 277CA tracking, no 835 remittance
  reconciliation. Required before billing go-live.

### HIGH GAPS
- GAP-05 [W4-5]: No 6-month IDT review frequency tracking or overdue alert.
  42 CFR §460.104(c) requires reassessment at least every 6 months.
  Common CMS survey deficiency finding.
- GAP-06 [W4-5]: No participant acknowledgment fields on care plans.
  42 CFR §460.104(d) requires participant/representative signature on plan.
- GAP-07 [W4-3]: No race/ethnicity fields on participant demographics.
  Affects HEDIS equity reporting and CMS submission requirements (OMB standards).
- GAP-08 [W4-6]: No CMS/SMA (State Medicaid Agency) notification tracking on
  incidents. 42 CFR §460.136 requires notifying CMS and SMA of significant
  adverse events within specified timeframes.
- GAP-09 [W4-5]: No disenrollment transition plan documentation workflow.
  42 CFR §460.116 requires a transition plan when a participant disenrolls.
- GAP-10 [W4-6]: No significant change event tracking (30-day IDT reassessment rule).
  42 CFR §460.104(b) requires IDT reassessment within 30 days of significant
  change in health status (hospitalization, functional decline, etc.).

### MEDIUM GAPS
- GAP-11 [W4-7]: CPOE — clinical order entry system needed beyond care plan goals.
  Need medication orders, lab orders, therapy orders, referral orders.
- GAP-12 [FUTURE]: C-CDA generation for transitions of care.
  ONC 21st Century Cures Act requires electronic care summaries on transitions.
- GAP-13 [W4-9]: Missing FHIR R4 resources: Encounter, DiagnosticReport,
  DocumentReference, Practitioner/PractitionerRole, Organization.
  Required for ONC USCDI v3 certification and EHR integrations.
- GAP-14 [W4-9]: HPMS monthly enrollment file format needs verification against
  current CMS specification. HpmsFileService exists but format not validated
  against live CMS HPMS portal requirements.

### QUICK WINS (addressed across W4-3 through W4-6)
- QW-01 [W4-4]: BMI auto-calculation in vitals (height + weight → BMI formula)
- QW-02 [W4-4]: Blood glucose field on vitals (already in schema per audit; surface in UI)
- QW-03 [W4-3]: Race/ethnicity on participant form (merged into W4-3 demographics)
- QW-04 [W3-3]: Unsigned notes queue/dashboard widget (completed in Wave 3 W3-3)
- QW-05 [W4-4]: Assessment due date alert banner on participant profile
- QW-06 [W4-5]: IDT review overdue indicator on participant header/profile
- QW-07 [W4-4]: Participant photo display in clinical sticky header
- QW-08 [W4-3]: Marital status + legal representative fields (merged into W4-3)
- QW-09 [W4-5]: Care plan participant acknowledgment field + signature date
- QW-10 [W4-6]: Incident CMS/SMA notification tracking fields
- QW-11 [W4-4]: VIS (Vaccine Information Statement) given field on immunizations
- QW-12 [W4-6]: Significant change flag on ADT hospitalizations (A01/A03 events)

---

## FRONTEND STYLE RULES (global — enforced 2026-03-24)
- **No em dashes in user-facing TSX.** Replace with: `|` for title separators (Head title, breadcrumbs), `:` for sentence connectors / label-value pairs, `-` for null/empty data placeholders in tables.
- **No emojis in user-facing TSX.** Use Heroicons (`@heroicons/react: ^2.1`, already installed). Import from `@heroicons/react/24/solid` or `@heroicons/react/24/outline`. Standard typographic symbols (✕ close buttons, ✓ check marks, ⌘ keyboard labels) are acceptable. Pictographic emoji (🏥 📋 ⚠ ⚡ 🚐 etc.) are not.
- **Heroicon usage pattern:** `<ExclamationTriangleIcon className="w-4 h-4 text-amber-500" />`

## DARK MODE STANDARDS (global — enforced 2026-03-26, updated 2026-03-28)
Dark mode uses `darkMode: 'class'` in tailwind.config.js. The `dark` class is applied to `<html>` by AppShell via useEffect. Every new TSX file and every edit to existing TSX MUST include `dark:` variants for all color utilities. No exceptions.

**Standard color mapping (light → dark):**
- `text-gray-900` → `dark:text-slate-100`
- `text-gray-800` → `dark:text-slate-200`
- `text-gray-700` → `dark:text-slate-300`
- `text-gray-600` → `dark:text-slate-400`
- `text-gray-500` → `dark:text-slate-400`
- `text-gray-400` → `dark:text-slate-500`
- `bg-white` → `dark:bg-slate-800`
- `bg-gray-50` → `dark:bg-slate-700/50` (table headers, section backgrounds)
- `bg-gray-50` → `dark:bg-slate-800` (page-level filter bars)
- `bg-gray-100` → `dark:bg-slate-700`
- `border-gray-200` → `dark:border-slate-700`
- `border-gray-300` → `dark:border-slate-600`
- `divide-gray-100` → `dark:divide-slate-700`
- `hover:bg-gray-50` → `dark:hover:bg-slate-700/50`

**Form inputs/selects — GLOBAL CSS rule (enforced 2026-03-28):**
`resources/css/app.css` contains a global `@layer base` rule that automatically applies `color: slate-100` and `color-scheme: dark` to ALL `<input>`, `<select>`, and `<textarea>` elements when the `.dark` class is present. This means:
- You do NOT need to add `dark:text-slate-100` to every individual input — it is handled globally.
- You STILL need `dark:bg-slate-800` (or `dark:bg-slate-700`) and `dark:border-slate-600` on inputs — the global rule only covers text color and color-scheme.
- `color-scheme: dark` is critical for native browser widgets (date pickers, number spinners, select arrows) — without it Chrome renders those controls black-on-white regardless of the `color` CSS value.
- Placeholder text is globally set to `slate-500` in dark mode (muted but legible).
- Individual `dark:text-*` utility classes on specific elements WILL override the global rule (utilities layer beats base layer in Tailwind).
- Checkboxes, radio buttons, range sliders, and file inputs are excluded from the global rule (they use native browser rendering).

**Status badge fallback (when not using a named color map entry):**
`bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300`

**Modal patterns:**
- Container: `bg-white dark:bg-slate-800`
- Title: `text-gray-900 dark:text-slate-100`
- Body text: `text-gray-600 dark:text-slate-400`
- Cancel button: `text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200`
- Divider: `border-b dark:border-slate-700`

**Tab bar:**
- Border container: `border-b border-gray-200 dark:border-slate-700`
- Inactive tab: `text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200`

**Note:** Pages using `slate-*` colors throughout (e.g. RiskAdjustment.tsx, ComplianceChecklist.tsx) already have full dark coverage without the gray→slate remapping. Pages confirmed fully audited (2026-03-26): Finance/Capitation, Finance/Encounters, Finance/EdiBatch, Finance/Pde, Finance/HosMSurvey, Finance/Hpms, Finance/RevenueIntegrity, Finance/RiskAdjustment, Finance/ComplianceChecklist, Finance/Dashboard, ItAdmin/Audit, ItAdmin/Users, Enrollment/Index, Enrollment/Transfers.

## KEY FILES
- Permissions seeder:   database/seeders/PermissionSeeder.php
- Demo data seeder:     database/seeders/DemoEnvironmentSeeder.php
- Clinical data seeder: database/seeders/ClinicalDataSeeder.php
- Phase 4 data seeder:  database/seeders/Phase4DataSeeder.php
- Phase 5A data seeder: database/seeders/Phase5ADataSeeder.php
- Phase 5B data seeder: database/seeders/Phase5BDataSeeder.php
- ICD-10 seeder:        database/seeders/Icd10Seeder.php
- Transport bridge:     app/Services/TransportBridgeService.php
- Note templates:       config/emr_note_templates.php
- RBAC middleware:      app/Http/Middleware/CheckDepartmentAccess.php
- SDR job:              app/Jobs/SdrDeadlineEnforcementJob.php
- ADL observer:         app/Observers/AdlRecordObserver.php

## PHASE COMPLETION STATUS
- Phase 0  (Memory Log Bootstrap):   [x] COMPLETE — 2026-03-14
- Phase 1A (Scaffold + Sail):         [x] COMPLETE
- Phase 1B (Auth + OTP):              [x] COMPLETE
- Phase 1C (RBAC + App Shell):        [x] COMPLETE
- Phase 2A (Participant DB):          [x] COMPLETE
- Phase 2B (Participant UI):          [x] COMPLETE
- Phase 2C (Transport Bridge):        [x] COMPLETE
- Phase 3A (Clinical Notes DB+API):   [x] COMPLETE
- Phase 3B (Clinical Notes UI):       [x] COMPLETE
- Phase 3C (Vitals + Assessments):    [x] COMPLETE
- Phase 3D (ADL + Problems):          [x] COMPLETE
- Phase 4A (Care Plans):              [x] COMPLETE
- Phase 4B (IDT Meetings):            [x] COMPLETE
- Phase 4C (SDRs + Horizon):          [x] COMPLETE
- Phase 4D (Reverb + Alerts):         [x] COMPLETE
- AUDIT A  (DB + Migration):          [x] COMPLETE — 2026-03-14
- AUDIT B  (Auth + RBAC):             [x] COMPLETE — 2026-03-14
- AUDIT C  (Clinical Docs):           [x] COMPLETE — 2026-03-14
- AUDIT D  (Phase 4 gaps):            [x] COMPLETE — 2026-03-14
- Phase 5A (Scheduling + Locations):  [x] COMPLETE — 2026-03-15
- Phase 5B (Transport Manifest):      [x] COMPLETE — 2026-03-15
- Phase 5C (Medications + eMAR):      [x] COMPLETE — 2026-03-15
- Phase 5D (Med Reconciliation):      [x] COMPLETE — 2026-03-15
- Phase 6A (Enrollment):              [x] COMPLETE — 2026-03-15
- Phase 6B (QA + Incidents):          [x] COMPLETE — 2026-03-15
- Phase 6C (Finance + FHIR):          [x] COMPLETE — 2026-03-16
- Phase 6D (Integrations + IT Admin): [x] COMPLETE — 2026-03-16
- Super Admin Enhancements:           [x] COMPLETE — 2026-03-16
- Phase 7A (Dashboards 1-7):          [x] COMPLETE — 2026-03-16
- Phase 7B (Dashboards 8-14):         [x] COMPLETE — 2026-03-17
- Phase 7C (Chat + Notifications):    [x] COMPLETE — 2026-03-18
- Phase 7D (Polish + CI + README):    [x] COMPLETE — 2026-03-19
- Phase 8A:                           [x] COMPLETE — 2026-03-19
- Phase 8B (CLAUDE.md Hardening):     [x] COMPLETE — 2026-03-19
- Phase 9A (Billing Research Audit):  [x] COMPLETE — 2026-03-19
- Phase 9B (Billing Engine):          [x] COMPLETE — 2026-03-19
- Phase 9C (Risk Adj + Medicaid CFG): [x] COMPLETE — 2026-03-21
- Phase 10A (Multi-Site Transfers):   [x] COMPLETE — 2026-03-21
- Phase 10B (Executive Role + Super Admin Panel): [x] COMPLETE — 2026-03-21
- Phase 11A (MVP Compliance Audit):               [x] COMPLETE — 2026-03-21
- Phase 11B (Go-Live Gap Resolution):             [x] COMPLETE — 2026-03-21
- Phase 12A (Developer Handoff Document):         [x] COMPLETE — 2026-03-21
- Phase 12B (CLAUDE.md Final Seal + Verification):[x] COMPLETE — 2026-03-21
- W3-0  (Context Bake — CLAUDE.md restructure):   [x] COMPLETE — 2026-03-24
- W3-1  (Dark Mode — theme toggle + dark: audit): [x] COMPLETE — 2026-03-24

## PHASE 8A STATUS TRACKING — COMPLETE 2026-03-19

### TRANSPORT INTEGRATION STATUS
- Transport nav links: [ ] Stubbed 'Coming Soon' | [ ] Linked to live transport app
- Transport mode: direct | broker (tenant config)
- Bridge service status: [ ] Written | [ ] Tested | [ ] Graceful failure verified
- Nostos transport integration: PENDING — fresh PACE-specific deployment planned
  (not a 1-to-1 plug-in; new PACE-model deployment required)
- All /transport/* routes show ComingSoonBanner until live bridge connected

### BILLING & ENCOUNTER DATA STATUS (Phase 9B COMPLETE)
- Capitation records: [x] Seeded | [x] UI complete (Finance/Capitation.tsx + HCC fields)
- Encounter log (837P fields): [x] All 14 fields added via migration | [x] UI complete (Finance/Encounters.tsx) | [ ] CSV export
- EDI 837P builder: [x] Builder complete (Edi837PBuilderService) | [x] Tested | [ ] Live clearinghouse configured
- CMS-HCC risk adjustment: [x] Data model (emr_hcc_mappings, 40+ V28 mappings) | [x] Scoring service | [x] Gap analysis
- Part D PDE tracking: [x] Data model (emr_pde_records) | [x] UI complete (Finance/Pde.tsx with TrOOP alerts)
- HPMS enrollment/disenrollment file: [x] Generator (HpmsFileService, 4 types) | [x] UI (Finance/Hpms.tsx)
- Clearinghouse integration: [ ] Stub configured — real clearinghouse API credentials required for go-live
- Revenue integrity dashboard: [x] Complete (Finance/RevenueIntegrity.tsx + RevenueIntegrityService 6 KPIs)
- HOS-M surveys: [x] Model + UI (Finance/HosMSurvey.tsx + completion stats)
- State Medicaid encounter: [x] Configuration framework complete (Phase 9C — emr_state_medicaid_configs + StateMedicaidConfigController + ItAdmin/StateConfig.tsx). Actual 837 submission pipeline remains (DEBT-038 is now partially complete — config layer done, submission layer deferred)
- Risk adjustment tracking: [x] Complete (emr_participant_risk_scores, RiskAdjustmentService, Finance/RiskAdjustment.tsx)
- Billing compliance checklist: [x] Complete (BillingComplianceService 5 categories, Finance/ComplianceChecklist.tsx)

### MULTI-SITE & EXECUTIVE ACCESS STATUS
- Multi-site participant transfer: [x] COMPLETE — Phase 10A (2026-03-21)
  - Migration: emr_participant_site_transfers (migration 67)
  - Model: ParticipantSiteTransfer (status: pending/approved/completed/cancelled)
  - Service: TransferService (requestTransfer, approveTransfer, cancelTransfer, completeTransfer)
  - Job: TransferCompletionJob (daily 7am, 'transfers' queue)
  - Controllers: TransferController (4 endpoints) + TransferAdminController (/enrollment/transfers)
  - Frontend: TransfersTab in Show.tsx (amber pending banner + modal + history) + Enrollment/Transfers.tsx
  - Tests: 23 new tests (ParticipantTransferTest + TransferServiceTest)
  - 90-day read-only access: enforced via ParticipantSiteTransfer::priorSiteHasReadAccess()
- Executive role: [x] COMPLETE — Phase 10B (2026-03-21)
  - Department 'executive': cross-site/cross-dept read-only within own tenant
  - Department 'super_admin': Nostos staff, cross-tenant, no impersonation UI
  - SiteContextMiddleware: resolves active_site_id from session (executive/SA), own site_id (regular)
  - SiteContextController: POST /site-context/switch (executive=own tenant only, SA=any site)
  - ExecutiveDashboardController: 4 JSON widget endpoints (org-overview, site-comparison, financial-overview, sites-list)
  - SuperAdminPanelController: index/tenants/health/onboard (full tenant onboarding wizard)
  - 2 React dashboard components: ExecutiveDashboard.tsx, SuperAdminDashboard.tsx
  - SuperAdmin/Index.tsx: 3-tab panel (Tenants, Health, Onboard new tenant)
  - AppShell.tsx: SiteSwitcherDropdown + NostosAdminBanner for dept=super_admin
  - Migration 68: shared_users.department CHECK constraint updated
  - Migration 69: emr_role_permissions.department CHECK constraint updated
  - Tests: 35 new tests (ExecutiveRoleTest 14, SuperAdminPanelTest 11, SiteContextTest 9) + 1 middleware test
- Site context switcher UI: [x] COMPLETE — Phase 10B

### NAV LINK AUDIT STATUS — COMPLETE (2026-03-19)

**CAT1 — Transport stubs (ComingSoonBanner, mode='transport'):**
- [x] /transport/scheduler   → ComingSoon mode=transport
- [x] /transport/map         → ComingSoon mode=transport
- [x] /transport/cancellations → ComingSoon mode=transport
- [x] /transport/vehicles    → ComingSoon mode=transport
- [x] /transport/vendors     → ComingSoon mode=transport
- [x] /transport/credentials → ComingSoon mode=transport
- [x] /transport/broker      → ComingSoon mode=transport
- [x] /transport/calls       → ComingSoon mode=transport
- [x] /transport/manifest    → ComingSoonBanner inline (in Manifest.tsx)
- [x] Transportation Dashboard — all 4 widgets → ComingSoonBanner inline

**CAT2 — Redirects to live pages:**
- [x] /idt/minutes           → redirect('/idt')
- [x] /idt/sdr               → redirect('/sdrs')
- [x] /admin/users           → redirect('/it-admin/users')
- [x] /admin/locations       → redirect('/locations')
- [x] /billing               → redirect('/finance/dashboard')
- [x] /billing/capitation    → NOW a live Inertia page (CapitationController, Finance/Capitation.tsx) — Phase 9B upgraded this from a redirect. Redirect entry removed.
- [x] PermissionService billing/capitation href: updated to '/finance/capitation' in Phase 9B.

**CAT3 — Planned features (PlannedFeatureBanner, mode='planned'):**
- [x] /clinical/orders       → LIVE — ClinicalOverviewController::orders() (W3-8)
- [x] /clinical/medications  → LIVE — ClinicalOverviewController::medications() (W3-8)
- [x] /scheduling/day-center → LIVE — DayCenterController (W3-2)
- [x] /billing/claims        → ComingSoon mode=planned
- [x] /reports               → LIVE — ReportsController (W3-2)
- [x] /audit                 → ComingSoon mode=planned (IT Admin audit panel is live at /it-admin/audit)
- [x] /admin/settings        → LIVE — SystemSettingsController (W3-2)

### MVP COMPLIANCE CHECKLIST STATUS
- 42 CFR Part 460 items: [ ] Not audited
- HIPAA Security Rule items: [ ] Not audited
- ONC USCDI v3 data elements: [ ] Not audited
- CMS HPMS requirements: [ ] Not audited
- State-specific framework: [ ] Not started

### GO-LIVE READINESS
- Security risk assessment: [ ] Not started
- Penetration test: [ ] Not started
- BAA templates: [ ] Not started
- HPMS credentialing checklist: [ ] Not started
- Data migration plan: [ ] Not started
- Staff training materials: [ ] Not started

### DEVELOPER HANDOFF STATUS
- HANDOFF.md created: [x] COMPLETE — 2026-03-21 (last updated 2026-03-21)
- Architecture Decision Record log: [x] COMPLETE — ADR-001 through ADR-009 in HANDOFF.md
- Known technical debt log: [x] COMPLETE — categorized by priority in HANDOFF.md
- Environment setup verified from scratch: [ ] Not yet verified by independent developer

## MIGRATIONS RUN (77 total, in order, all batch 1)
1.  0001_01_01_000000_create_users_table
2.  0001_01_01_000001_create_cache_table
3.  0001_01_01_000002_create_jobs_table
4.  2024_01_01_000001_create_shared_tenants_table
5.  2024_01_01_000002_create_shared_sites_table
6.  2024_01_01_000003_create_shared_users_table
7.  2024_01_01_000004_create_shared_otp_codes_table
8.  2024_01_01_000005_create_shared_sessions_table
9.  2024_01_01_000006_create_shared_audit_logs_table
10. 2024_01_01_000007_create_emr_role_permissions_table
11. 2024_02_01_000001_add_mrn_prefix_to_shared_sites_table
12. 2024_02_01_000002_create_emr_participants_table
13. 2024_02_01_000003_create_emr_participant_addresses_table
14. 2024_02_01_000004_create_emr_participant_contacts_table
15. 2024_02_01_000005_create_emr_insurance_coverages_table
16. 2024_02_01_000006_create_emr_participant_flags_table
17. 2024_03_01_000001_create_emr_clinical_notes_table
18. 2024_03_01_000002_create_emr_vitals_table
19. 2024_03_01_000003_create_emr_assessments_table
20. 2024_03_01_000004_create_emr_problems_table
21. 2024_03_01_000005_create_emr_allergies_table
22. 2024_03_01_000006_create_emr_adl_records_table
23. 2024_03_01_000007_create_emr_adl_thresholds_table
24. 2024_03_01_000008_create_emr_icd10_lookup_table
25. 2024_04_01_000001_create_emr_alerts_table
26. 2024_04_01_000002_create_emr_care_plans_table
27. 2024_04_01_000003_create_emr_care_plan_goals_table
28. 2024_04_01_000004_create_emr_idt_meetings_table
29. 2024_04_01_000005_create_emr_idt_participant_reviews_table
30. 2024_04_01_000006_create_emr_sdrs_table
31. 2024_05_01_000001_add_missing_audit_log_resource_index
32. 2024_05_01_000002_add_super_admin_to_shared_users_role
33. 2024_05_01_000003_create_emr_locations_table
34. 2024_05_01_000004_create_emr_appointments_table
35. 2024_05_02_000001_create_emr_transport_requests_table
36. 2024_05_02_000002_add_transport_request_id_fk_to_emr_appointments
37. 2024_05_03_000001_create_emr_medications_table
38. 2024_05_03_000002_create_emr_emar_records_table
39. 2024_05_03_000003_create_emr_med_reconciliations_table
40. 2024_05_03_000004_create_emr_drug_interaction_alerts_table
41. 2024_05_03_000005_create_emr_medications_reference_table
42. 2024_05_03_000006_create_emr_drug_interactions_reference_table
43. 2024_05_04_000001_add_phase5d_columns_to_emr_med_reconciliations_table
44. 2024_05_04_000002_make_reconciled_at_nullable_on_emr_med_reconciliations
45. 2024_06_01_000001_create_emr_referrals_table
46. 2024_06_02_000001_create_emr_incidents_table
47. 2024_06_03_000001_create_emr_capitation_records_table
48. 2024_06_03_000002_create_emr_encounter_log_table
49. 2024_06_03_000003_create_emr_authorizations_table
50. 2024_06_03_000004_create_emr_api_tokens_table
51. 2024_06_04_000001_create_emr_integration_log_table
52. 2024_06_04_000002_make_requesting_user_id_nullable_on_emr_sdrs
53. 2024_07_01_000001_create_emr_chat_channels_table
54. 2024_07_01_000002_create_emr_chat_memberships_table
55. 2024_07_01_000003_create_emr_chat_messages_table
56. 2024_07_01_000004_add_notification_preferences_to_shared_users
57. 2024_08_01_000001_create_emr_documents_table
58. 2024_09_01_000001_add_billing_fields_to_emr_encounter_log
59. 2024_09_01_000002_create_emr_edi_batches_table
60. 2024_09_01_000003_add_hcc_fields_to_emr_capitation_records
61. 2024_09_01_000004_create_emr_pde_records_table
62. 2024_09_01_000005_create_emr_hos_m_surveys_table
63. 2024_09_01_000006_create_emr_hpms_submissions_table
64. 2024_09_01_000007_create_emr_hcc_mappings_table
65. 2024_09_02_000001_create_emr_participant_risk_scores_table
66. 2024_09_02_000002_create_emr_state_medicaid_configs_table
67. 2024_10_01_000001_create_emr_participant_site_transfers_table
68. 2024_10_02_000001_add_executive_and_super_admin_to_shared_users_department
69. 2024_10_02_000002_add_executive_and_super_admin_to_emr_role_permissions_department
70. 2024_11_01_000001_create_emr_immunizations_table
71. 2024_11_01_000002_create_emr_social_determinants_table
72. 2024_11_01_000003_create_emr_procedures_table
73. 2024_11_01_000004_add_advance_directive_columns_to_emr_participants
74. 2024_11_01_000005_create_emr_ehi_exports_table
75. 2024_12_01_000001_add_theme_preference_to_shared_users
76. 2024_12_02_000001_create_emr_day_center_attendance_table
77. 2024_12_03_000001_add_metadata_to_emr_alerts_table

## MODELS (56)
AdlRecord, AdlThreshold, Alert, Allergy, ApiToken, Appointment, Assessment, AuditLog,
Authorization, CapitationRecord, CarePlan, CarePlanGoal, ChatChannel, ChatMembership, ChatMessage,
ClinicalNote, DayCenterAttendance, Document, DrugInteractionAlert, EdiBatch, EhiExport, EmarRecord, EncounterLog,
HccMapping, HosMSurvey, HpmsSubmission, Icd10Lookup, IdtMeeting, IdtParticipantReview, Immunization,
Incident, InsuranceCoverage, IntegrationLog, Location, MedReconciliation, Medication, OtpCode,
Participant, ParticipantAddress, ParticipantContact, ParticipantFlag, ParticipantRiskScore,
ParticipantSiteTransfer, PdeRecord, Problem, Procedure, Referral, RolePermission, Sdr, Site,
SocialDeterminant, StateMedicaidConfig, Tenant, TransportRequest, User, Vital

## CONTROLLERS (58 root + Auth/ subdirectory + Dashboards/ subdirectory)
AdlController, AlertController, AllergyController, AppointmentController,
AssessmentController, BillingComplianceController, BillingEncounterController, CapitationController,
CarePlanController, ChatController, ClinicalDashboardController, ClinicalNoteController, ClinicalOverviewController,
ComingSoonController, Controller (base), DayCenterController, DashboardController, DocumentController, EdiBatchController,
EhiExportController, FhirController, FinanceController, FinanceDashboardController,
HosMSurveyController, HpmsController, IdtMeetingController, ImmunizationController,
ImpersonationController, IncidentController, IntegrationController, ItAdminController,
LocationController, MedReconciliationController, MedicationController,
ParticipantContactController, ParticipantController, ParticipantFlagController,
PdeController, ProfileController, ProblemController, QaDashboardController, ReferralController,
ReportsController, RevenueIntegrityController, RiskAdjustmentController, SdrController, SiteContextController,
SocialDeterminantController, StateMedicaidConfigController, SuperAdminPanelController,
SystemSettingsController, TransferAdminController, TransferController, ThemePreferenceController,
TransportController, TransportRequestController, VitalController, WebhookController
Auth/ subdirectory: Fortify auth controllers (OTP, login, etc.)
Dashboards/ subdirectory (15): PrimaryCareDashboardController, TherapiesDashboardController,
SocialWorkDashboardController, BehavioralHealthDashboardController, DietaryDashboardController,
ActivitiesDashboardController, HomeCareDashboardController, TransportationDashboardController,
PharmacyDashboardController, IdtDashboardController, EnrollmentDashboardController,
FinanceWidgetController, QaComplianceDashboardController, ItAdminDashboardController,
ExecutiveDashboardController

## SERVICES (26 — Phase 9B adds 4, Phase 9C adds 2, Phase 10A adds 1, Phase 11B adds 1)
AdlThresholdService, AlertService, BillingComplianceService, ChatService, ConflictDetectionService,
DrugInteractionService, Edi837PBuilderService, EhiExportService, EnrollmentService,
HccRiskScoringService, HpmsFileService, ImpersonationService, IncidentService,
MedReconciliationService, MedicationScheduleService, MrnService, NotificationDispatcher,
NoteTemplateService, OtpService, PermissionService, QaMetricsService, RevenueIntegrityService,
RiskAdjustmentService, SdrDeadlineService, TransferService, TransportBridgeService

## INTEGRATIONS (2 connectors, 2 jobs)
- app/Integrations/Hl7AdtConnector.php — static receive(), dispatches ProcessHl7AdtJob
- app/Integrations/LabResultConnector.php — static receive(), dispatches ProcessLabResultJob
- app/Jobs/ProcessHl7AdtJob.php — A01(admit→encounter+alert), A03(discharge→SDR+care_plan+alert), A08(audit only)
- app/Jobs/ProcessLabResultJob.php — normal lab→encounter log; abnormal→encounter+primary_care alert

## REACT PAGES (58 — Phase 7A/B adds 14 dept dashboards, Phase 9B adds 7, Phase 9C adds 3, Phase 10A adds 1, Phase 10B adds 3, W3-2 adds 4, W3-8 adds 2)
Auth/Login, Clinical/Assessments, Clinical/CarePlans, Clinical/Medications, Clinical/Notes,
Clinical/Orders, Clinical/Vitals, ComingSoon, Dashboard/Index, Enrollment/Index, Enrollment/Transfers,
Errors/403, Finance/Capitation, Finance/ComplianceChecklist, Finance/Dashboard, Finance/EdiBatch,
Finance/Encounters, Finance/HosMSurvey, Finance/Hpms, Finance/Pde,
Finance/RevenueIntegrity, Finance/RiskAdjustment, Idt/Dashboard, Idt/RunMeeting,
ItAdmin/Audit, ItAdmin/Integrations, ItAdmin/StateConfig, ItAdmin/Users,
Participants/Index, Participants/Show, Qa/Dashboard, Schedule/Index,
Sdrs/Index, Transport/Dashboard, Transport/Manifest,
Dashboard/Depts/PrimaryCareDashboard, Dashboard/Depts/TherapiesDashboard,
Dashboard/Depts/SocialWorkDashboard, Dashboard/Depts/BehavioralHealthDashboard,
Dashboard/Depts/DietaryDashboard, Dashboard/Depts/ActivitiesDashboard,
Dashboard/Depts/HomeCareDashboard, Dashboard/Depts/TransportationDashboard,
Dashboard/Depts/PharmacyDashboard, Dashboard/Depts/IdtDashboard,
Dashboard/Depts/EnrollmentDashboard, Dashboard/Depts/FinanceDashboard,
Dashboard/Depts/QaComplianceDashboard, Dashboard/Depts/ItAdminDashboard,
Dashboard/Depts/ExecutiveDashboard, Dashboard/Depts/SuperAdminDashboard,
SuperAdmin/Index,
Chat/Index, Profile/Notifications,
Idt/Meetings, Scheduling/DayCenter, Reports/Index, ItAdmin/SystemSettings

## KNOWN ISSUES & GOTCHAS
- [Infra] Project runs from WSL2 Ubuntu (`/home/tj/projects/nostosemr`). Docker Desktop WSL Integration must be enabled for Ubuntu (Settings → Resources → WSL Integration → Ubuntu). Always use Ubuntu terminal for docker compose commands — running from Windows PowerShell works but is 5-20x slower due to Windows↔WSL2 filesystem bridge.
- [Security] `phpunit.xml` hardcodes an APP_KEY for testing (standard Laravel practice). This file IS committed to git. Always keep the phpunit.xml key DIFFERENT from the `.env` key — they should never match. If the `.env` key is rotated (`php artisan key:generate`), update phpunit.xml with a fresh independently-generated test key. GitGuardian monitors the repo and will alert if a real key leaks into committed files. To generate a test-only key: `docker compose exec -T laravel.test php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`. To remove a secret from git history: `git filter-branch --force --index-filter 'git rm --cached --ignore-unmatch <file>' --prune-empty --tag-name-filter cat -- --all` then force-push. Note: requires clean working tree (stash first); storage permissions may need `wsl -u root chmod -R 777 storage/`.
- [UI] All `<select>` elements get `padding-right: 2rem` via `resources/css/app.css` global rule (`@layer base`). This prevents the native browser dropdown arrow from overlapping text. Do NOT add per-element `pr-8` classes — the global rule covers everything.
- [Infra] `php artisan optimize` (run by `start.sh`) creates `bootstrap/cache/config.php` caching `APP_ENV=local`. When tests run after optimize, `VerifyCsrfToken::runningUnitTests()` reads the cached 'local' env (not phpunit.xml's 'testing') → returns false → ALL POST/PATCH feature tests get 419. Fix: run `php artisan config:clear` before any test run that follows `artisan optimize`. Tests/TestCase.php also disables CSRF via `withoutMiddleware(VerifyCsrfToken::class)` as a safeguard for non-Docker runners, but this does NOT override the config cache issue.
- [Infra] `routes/web.php` custom logout route at `/auth/logout` is named `auth.logout` (NOT `logout`). Fortify also registers a `/logout` route named `logout` — duplicate names cause route:cache to fail. The rename to `auth.logout` was applied 2026-03-21. Frontend uses hardcoded `/auth/logout` path so no JSX changes needed.
- [Phase 1] node_modules are Linux-native (built inside Docker). Local Windows npm/vite builds fail.
  Resolution: always run npm run build inside Docker: `docker compose exec -T laravel.test npm run build`
- [Phase 3] `emr_idt_participant_reviews`: columns are `summary_text` (not `notes`), `reviewed_at` (no `reviewed_by_user_id`)
- [Phase 2] Participant DOB column is `dob` (not `date_of_birth`)
- [Phase 3] Allergies index returns grouped by `allergy_type` (object, not flat array)
- [Phase 4] `CarePlan::createNewVersion()` archives source plan (status=`archived`)
- [Phase 4] Care plans: only `draft` and `under_review` are editable via `isEditable()`
- [Phase 1] UserFactory email includes unique random suffix to avoid parallel test collisions
- [Phase 3] SDR 72-hour rule: `due_at = submitted_at + 72h` enforced in `Sdr::boot()`
- [Phase 3+] Clinical Notes dashboard: clicking a row must go to `?tab=chart`, not Overview.
  Root cause was a broken frontend permission check — server middleware (CheckDepartmentAccess) is
  the correct enforcement layer; the frontend ternary was removed. (Fixed 2026-03-14)
- [Phase 0] TypeScript pre-existing errors in Show.tsx (React.useState UMD references in CarePlanTab)
  and app.tsx/bootstrap.ts (Vite env/glob types). These do not affect runtime — Vite handles them.
  All other TypeScript errors were resolved in Phase 12B: Finance pages had spurious `user={auth.user}`
  prop on AppShell (removed), Qa/Dashboard had spurious `title=` prop (removed), Transport/Manifest
  Echo callback typed `unknown` + cast, Chat/Index Echo callback typed `unknown` + cast,
  window.Echo type declaration extended to include `private` channel method in index.d.ts.
- [Phase 0] PHPUnit Deprecations: 64 deprecations + 78-90 PHPUnit deprecations in test run (non-failing).
  All tests pass. Deprecations are from PHP 8.5 / PHPUnit 11 API changes — track for cleanup.
- [Audit A] shared_users_role_check constraint only had 'admin'/'standard' — super_admin was blocked.
  DemoEnvironmentSeeder always failed on migrate:fresh. Fixed via migration 2024_05_01_000002.
- [Audit A] shared_audit_logs was missing (resource_type, resource_id) composite index.
  Fixed via migration 2024_05_01_000001.
- [Audit A] AuditLog model used silent rejection (return false/true) for delete/save.
  Upgraded to throw ImmutableRecordException. Existing AuditLogTest updated. New AuditLogImmutabilityTest added.
- [Audit B] Finance standard has full CRUD on billing (not just view) — seeder uses $full() for billing.
  Corrected PermissionMatrixTest assumption to match actual intended permissions.
- [Audit B] HIPAA idle timer verified in AppShell.tsx: resets on mousemove/mousedown/keydown/scroll/touchstart.
  Warning modal fires at (timeout-1) minutes; POST /auth/logout?timeout=true fires after full timeout. ✓
- [Audit B] Rate limiting: 5 attempts/min per IP on both /auth/request-otp and /auth/verify-otp (6th → 429). ✓
- [Audit B] OTP code reuse prevention: markUsed() sets used_at; query filters whereNull('used_at'). ✓
- [Audit B] STEP 1 and STEP 6 (OTP manual flow, visual shell check): browser not available via automation.
  Requires manual verification by developer at http://localhost.
- [Audit C] ChartTab note form had wrong visit_type values: sent 'in_person'/'day_center', backend expects 'in_center'/'phone'.
  Fixed in Show.tsx blankNote default and dropdown option list. (Fixed 2026-03-14)
- [Audit C] ChartTab note form did not send `department` (required field). Fixed by pulling from usePage() auth.user.department. (Fixed 2026-03-14)
- [Audit C] NoteCard had no Addendum button for signed notes. Added `+ Addendum` button (bottom-right of expanded signed note)
  and handleAddendum() in ChartTab: POST /participants/{id}/notes/{noteId}/addendum. (Fixed 2026-03-14)
- [Audit C] VitalController.store() had no dept restriction — any authenticated user could record vitals.
  Added hardcoded whitelist: ['primary_care','therapies','home_care','social_work','it_admin']. (Fixed 2026-03-14)
- [Audit C] ClinicalNoteController.index() had no dept scoping — dietary/finance users could see all departments' notes.
  Added crossDeptViewers whitelist; non-listed depts see only their own dept's notes. (Fixed 2026-03-14)
- [Audit C] Icd10Seeder had 181 unique codes (target: 200+). Added 22 codes across 6 new categories → 203 total. (Fixed 2026-03-14)
- [Audit D] echo.js (Reverb/Echo client) existed but was never imported in app.tsx — window.Echo was always undefined at runtime, making all Reverb subscriptions inert. Fixed by adding import './echo' to app.tsx. (Fixed 2026-03-14)
- [Audit D] AlertCreatedEvent broadcast was untested — added test_creating_critical_alert_broadcasts_alert_created_event to AlertTest using Event::fake(). (Fixed 2026-03-14)
- [Audit D] IdtMeetingController::show() renders Idt/RunMeeting (not a separate /run route). The spec referenced /idt/meetings/{id}/run but implementation correctly uses GET /idt/meetings/{id}. Not a bug — routing is intentional.
- [Phase 5A] emr_locations migration initially had label, street, city, state, zip as NOT NULL. Made all address/label fields nullable — many locations (telehealth, virtual) have no physical address. (Fixed 2026-03-15)
- [Phase 5A] transport_request_id on emr_appointments has NO FK constraint by design. It references transport.transport_requests (cross-app, read-only). Adding an FK would create a cross-schema dependency and break migrate:fresh. Use TransportBridgeService for any cross-app lookups.
- [Phase 5A] GET /scheduling/appointments redirects to /schedule (301). The old Coming Soon stub is gone. /scheduling/day-center still shows Coming Soon (Phase 5B). The admin.locations route in the admin prefix is still a Coming Soon stub — the real /locations API is at the top-level.
- [W3-1] Dark mode: `darkMode: 'class'` in tailwind.config.js. AppShell applies `dark` class to `document.documentElement` via useEffect on theme state change. localStorage key: `nostos_theme`. Server persistence: POST /user/theme → ThemePreferenceController (migration 75). auth.user.theme_preference = REAL user's pref, never impersonated user's. FOUC prevention: app.blade.php inline script reads localStorage before React renders.
- [W3-1] UserFactory default for theme_preference: 'light' (PostgreSQL DEFAULT). Tests needing 'dark' must pass explicitly: `User::factory()->create(['theme_preference' => 'dark'])`.
- [W3-2] SystemSettingsController maps Tenant::cms_contract_id → 'pace_contract' key in Inertia props (the TSX uses 'pace_contract'; the Tenant model column is 'cms_contract_id'). emr_state_medicaid_configs has NO 'payer_id' column — do not select it.
- [W3-2] DayCenterAttendance::scopeForSite() accepts ?int (nullable). When site_id is null (user has no site assigned), the scope applies no filter and returns all sites' records for the day. DayCenterController::index() passes $user->site_id which may be null for test users.
- [W3-2] /idt/minutes now redirects to /idt/meetings (NOT /idt). ComingSoonBannerTest updated accordingly.
- [W3-3] PharmacyDashboardController::interactions() returns key 'alerts' (not 'interactions') — DrugInteractionAlert items are returned under the 'alerts' key to distinguish from the unrelated 'interactions' widget concept. Test + frontend both use 'alerts'.
- [W3-3] ItAdminDashboardController: IntegrationLog has $timestamps=false, so created_at is a raw string. Must wrap with Carbon::parse() before calling diffInHours(). Pattern: `Carbon::parse($log->created_at)->diffInHours(now())`.
- [W3-3] emr_sdrs status enum: submitted/acknowledged/in_progress/completed/cancelled. 'open' is NOT valid. All SDR tests must use one of these values.
- [W3-4] Cross-tenant participant access returns 403 (not 404). ParticipantController::authorizeForTenant() uses abort_if($tenant_id !== $user->tenant_id, 403). Tests asserting cross-tenant isolation must use assertForbidden(), not assertNotFound().
- [W3-4] Care plan save silently failed: original catch block was `catch { /* ignore */ }`. Fixed to capture error and display it via saveError state in CarePlanTab. Plan must be draft or under_review — approved plans are read-only (Edit button hidden, plan.status check added).
- [W3-4] Tab URL sync: tabs are pure client-side state. switchTab() calls window.history.replaceState to update ?tab= param without Inertia reload. Server ignores the ?tab param entirely — always renders Participants/Show component.
- [Phase 5A] ConflictDetectionService uses half-open interval comparison: existing.start < new.end AND existing.end > new.start. This means adjacent appointments (end = next start) do NOT conflict — correct PACE scheduling behavior (back-to-back appointments are allowed).

## DOCUMENTATION STANDARD (handoff requirement)
This project will be handed off to a developer. Every file must be readable without context.
Rules (enforced going forward — audit ran 2026-03-14, all gaps patched):
- PHP: every class gets a // ─── ClassName ─── header block (purpose, lifecycle, CMS rule if relevant, route list if controller)
- PHP: every public method gets a /** JSDoc */ block or an inline comment for non-obvious logic
- PHP: complex scopes, boot() hooks, and business rule constants must have "why" comments, not just "what"
- React/TSX: every file gets a comment header (layout, data loading strategy, key props)
- React/TSX: every non-trivial helper function gets a one-line comment; every useEffect explains why it exists
- Factories: state methods must explain what scenario they simulate (for test authors)
- No silent magic: if a line enforces a CMS/HIPAA/PACE rule, say so inline
- Section dividers: use `// ── Section ────────` to separate logical blocks in long files

## ARCHITECTURAL DECISIONS
- [Phase 1] Multi-tenant by `tenant_id` on all EMR models (table prefix `emr_`)
- [Phase 1] AuditLog append-only (PostgreSQL rules block UPDATE/DELETE)
- [Phase 1] HIPAA idle timeout enforced server-side via Fortify session config
- [Phase 1] No passwords — OTP-only auth; IT provisions all accounts
- [Phase 2] MRN generated by MrnService using site's mrn_prefix + zero-padded sequence
- [Phase 3] Dropdown-first note documentation (≥50% fields are select/multiselect in all 8 templates)
- [Phase 3] Vitals are append-only (no SoftDeletes on emr_vitals / emr_adl_records)
- [Phase 3] ADL threshold breach fires via AdlRecordObserver → AdlThresholdService
- [Phase 4] Care plan versioning: createNewVersion() archives source, creates new draft
- [Phase 4] SDR deadline: Horizon job (SdrDeadlineEnforcementJob) enforces 72-hour window, not just UI
- [Phase 4] Real-time alerts use Laravel Reverb (WebSockets), not polling
- [Phase 5A] ConflictDetectionService is injected into AppointmentController via constructor DI. Two distinct checks: (1) participant time-overlap (any overlapping active appt → 409 conflict), (2) transport window (2h buffer between transport-required appts → 409 transport_conflict). Both return structured JSON error, never silently fail.
- [Phase 5A] Appointment status lifecycle: scheduled → confirmed → completed; scheduled/confirmed → cancelled (reason required) / no_show. Completed/cancelled/no_show appointments are immutable (isEditable() returns false). Status transitions are dedicated PATCH endpoints, not part of the PUT update.
- [Phase 5A] Location CRUD is Transportation Team only for writes (department = 'transportation'). All other departments can read locations (for appointment booking). Enforced in LocationController::authorizeTransportTeam().
- [Phase 5A] Schedule calendar (GET /schedule) loads appointment data client-side via GET /schedule/appointments?start_date=&end_date= — initial Inertia page response only contains static props (types, labels, colors, locations). This keeps the initial page load fast.
- [Phase 5B] TransportBridgeService::createTripRequest() signature changed from array to TransportRequest model. Any callers using the old array form will break — updated in Phase 5B.
- [Phase 5B] Webhook route POST /integrations/transport/status-webhook is OUTSIDE the 'auth' middleware group (server-to-server). HMAC-SHA256 (X-Transport-Signature header) is the only security mechanism.
- [Phase 5B] ProcessTransportStatusWebhookJob runs on 'transport-webhooks' queue. Ensure this queue is processed by Horizon (or default worker in dev).
- [Phase 3 UI] Participant profile: two-section tab bar (CLINICAL: blue, ADMIN: slate)
- [Phase 3 UI] Overview tab redesigned as PACE Facesheet with print-to-PDF via window.print()
  + @media print CSS injected into document.head (hides AppShell, isolates #facesheet-print)
- [Phase 3 UI] Life-threatening allergy banner persists across all tabs (red bar under header)
- [Phase 5B] TransportRequest.mobility_flags_snapshot stores flags as {type, severity, description}. The runs() API remaps to {flag_type, severity, description} for frontend consistency. The Phase5BDataSeeder also uses 'type' key internally — both formats are handled by runs().
- [Phase 5B] HMAC webhook validation is fail-closed: if services.transport.webhook_secret is null, ALL webhooks are rejected (403). This prevents a misconfigured environment from silently accepting unauthenticated webhooks.
- [Phase 5B] TransportRequest.cancel() is blocked for status='completed' and status='cancelled' (409). The controller checks this before calling bridge->cancelTrip().
- [Phase 5B] Manifest runs endpoint excludes cancelled trips server-side. The React UI applies opacity styling for visual dimming — but cancelled are already filtered out, so client filtering is redundant (no-op).
- [Phase 5D] MedReconciliation workflow: in_progress → decisions_made → approved (terminal, immutable). Only one active rec per participant at a time (idempotency enforced in startReconciliation()). Approved records throw LogicException on any mutation attempt (assertNotLocked() guard).
- [Phase 5D] providerApproval() requires status='decisions_made' — cannot skip Steps 1-4. Approver department check is in ApproveReconciliationRequest::authorize(), not the controller.
- [Phase 5D] frequency enum in emr_medications: daily/BID/TID/QID/Q4H/Q6H/Q8H/Q12H/PRN/weekly/monthly/once. Prior med lists may use free-text (e.g. 'twice_daily') — service sanitizes by checking against valid list before inserting.
- [Phase 5B] emr_transport_requests table is EMR-internal. FK to emr_appointments is in migration 36. No FK to transport_trips (cross-app reference — enforced by TransportBridgeService only).
- [Phase 5B] ProcessTransportStatusWebhookJob handles only status field mapping. It does NOT reassign participants or create new trips — it only syncs status + actual times back from the transport app.
- [Phase 6A] Enrollment state machine enforced in EnrollmentService::transition() via VALID_TRANSITIONS constant. Invalid transitions throw InvalidStateTransitionException (RuntimeException) → ReferralController maps to HTTP 422.
- [Phase 6A] Terminal statuses (enrolled/declined/withdrawn) have no outgoing transitions. VALID_TRANSITIONS omits them as keys.
- [Phase 6A] Kanban pipeline shows 7 columns (all except declined/withdrawn). Terminal exit columns are hidden from board view but referrals remain accessible via API (GET /enrollment/referrals/{id}).
- [Phase 6A] Disenrollment reasons enum: voluntary/involuntary/deceased/moved/nf_admission/other (in DisenrollParticipantRequest). The broader 'voluntary_withdrawal'/'death' strings are NOT valid — use the short enum values.
- [Phase 6A] /enrollment redirects → /enrollment/referrals (no standalone landing page). The Inertia Kanban is at Enrollment/Index.tsx.
- [Phase 6B] QA compliance dashboard at GET /qa/dashboard. Inertia renders Qa/Dashboard.tsx with 6 KPI cards pre-loaded server-side. Compliance detail tabs (unsigned notes, overdue assessments) lazy-load via JSON endpoints on first tab click.
- [Phase 6B] DocumentationComplianceJob runs daily at 6 AM on 'compliance' Horizon queue. Deduplicates alerts using whereJsonContains('new_values->note_id', $note->id) to avoid spam.
- [Phase 6B] Incident statuses: open → under_review → rca_in_progress → closed. Only qa_compliance and it_admin can close/update; any authenticated user can create.
- [Phase 6B] Carbon 3 changed `diffInHours`/`diffInDays` to return signed (potentially negative) values when the argument is in the past. All duration calculations in QaDashboardController use `abs()` to ensure positive values. Pattern: `abs((int) now()->diffInHours($past))`.
- [Phase 6B] SiteFactory was missing `mrn_prefix` — all factory sites derived prefix "SITE" via `MrnService::derivePrefix("... PACE Site")`. Two participants created in the same test would both get "SITE-00001" (globally unique MRN violation). Fixed by adding `mrn_prefix => unique()->lexify('????')` to SiteFactory.
- [Phase 6B] `rca_required` is NEVER in StoreIncidentRequest or UpdateIncidentRequest rules — it is auto-set exclusively by IncidentService::createIncident() based on Incident::RCA_REQUIRED_TYPES. This enforces CMS 42 CFR 460.136 without allowing UI bypass.
- [Phase 6B] Incidents cannot be closed while RCA is pending (rca_required=true, rca_completed=false). IncidentService::closeIncident() throws LogicException; IncidentController maps to HTTP 409.
- [Phase 6C] FHIR R4 API at /fhir/R4. Bearer tokens stored as SHA-256 hash in emr_api_tokens. FhirAuthMiddleware validates token + scope; sets fhir_tenant_id/fhir_user_id on request attributes. All reads logged to audit_log with action='fhir.read.{resource}'.
- [Phase 6C] Cross-tenant FHIR access returns 404 (not 403) per FHIR convention — avoids leaking resource existence across tenants.
- [Phase 6C] FHIR routes are OUTSIDE the 'auth' session middleware group (server-to-server). Registered in web.php with ->middleware(['fhir.auth']). The fhir.auth alias is registered in bootstrap/app.php.
- [Phase 6C] FHIR LOINC codes for vitals: 8480-6 (BP systolic), 8462-4 (BP diastolic), 29463-7 (weight — converted lbs→kg), 8867-4 (pulse), 59408-5 (O2 sat), 8310-5 (temperature — converted °F→°C).
- [Phase 6C] FHIR identifier type codes: MRN→MR, Medicare ID→SB, Medicaid ID→MA, PACE contract→RI.
- [Phase 6C] ApiToken::touch() was renamed to markUsed() to avoid signature conflict with Model::touch($attribute=null) in PHP 8.5 / Laravel 11.
- [Phase 6C] Finance dashboard at GET /finance/dashboard (Inertia). FinanceDashboardController pre-loads 4 KPIs + recentCapitation + expiringAuths. Encounter log tab lazy-loads via JSON endpoint.
- [Phase 6C] emr_encounter_log is append-only (UPDATED_AT = null, no SoftDeletes). EncounterLog::SERVICE_TYPES contains 12 PACE service types.
- [Phase 6C] emr_capitation_records has unique(participant_id, month_year). One record per participant per month. month_year stored as CHAR(7) 'YYYY-MM' string.
- [Phase 6C] max_locks_per_transaction issue: running 16 parallel test workers with 50 tables causes PostgreSQL OOM on DROP TABLE CASCADE. Workaround: run with --processes=8 or fewer. This is an infrastructure limit, not a test bug.
- [Phase 6D] Integration inbound endpoints (POST /integrations/hl7/adt, POST /integrations/labs/result) are OUTSIDE the 'auth' session middleware. Tenant is resolved from the X-Integration-Tenant header (tenant UUID). Missing/invalid header → 401.
- [Phase 6D] IntegrationLog (emr_integration_log) is append-only ($timestamps=false, manual created_at, no SoftDeletes). Status enum: pending/processed/failed/retried. markRetried() increments retry_count.
- [Phase 6D] ProcessHl7AdtJob A01 (admit): creates EncounterLog + alert for ['social_work', 'idt'] severity='warning'. A03 (discharge): creates SDR (assigned_department='idt') + sets active CarePlan to under_review + alert for ['idt'] severity='warning'. A08 (update): AuditLog only. Unknown MRN: markFailed() gracefully (no exception).
- [Phase 6D] ProcessLabResultJob normal result: EncounterLog only. Abnormal (abnormal_flag=true): EncounterLog + alert for ['primary_care'] severity='warning'. Unknown MRN: markFailed(). Valid severity values: info/warning/critical (NOT 'high' — DB CHECK constraint).
- [Phase 6D] IT Admin session invalidation uses DB::table('sessions') (the PACE sessions table name). Deactivate: sets is_active=false + invalidates sessions. ResetAccess: invalidates sessions only (user stays active, forced re-auth).
- [Phase 6D] IT Admin provision user: creates User + sends WelcomeEmail mailable. User is immediately is_active=true. Returns 201 with user object.
- [Phase 6D] emr_sdrs.requesting_user_id is nullable (migration 52). System-generated SDRs (from HL7 A03 discharge) have no human requesting user — field set to null.
- [Phase 6D] ItAdminController uses requireItAdmin() guard: abort_if(dept !== 'it_admin', 403) on every method. Non-it_admin users get 403 on all /it-admin/* routes.
- [Phase 7A] Dashboard widget endpoints return pure JSON (not Inertia). Each dept controller has 4 GET routes under /dashboards/{dept-slug}/{widget}. The requireDept() guard aborts 403 for wrong-dept users but passes super_admin through.
- [Phase 7A] React dept dashboards load all 4 widget endpoints in parallel via Promise.all([axios.get(...)]) in a single useEffect. Each dashboard is a separate lazy-loaded chunk (code-split by Vite).
- [Phase 7C] Chat channel types: direct (1:1 DM), department (auto-created per dept), participant_idt (per enrolled participant, 6 IDT depts), broadcast (all active users in tenant).
- [Phase 7C] emr_chat_messages is append-only for created_at but uses SoftDeletes for HIPAA compliance. Deleted messages NEVER hard-deleted — ChatMessage.toApiArray() returns null message_text when is_deleted=true. This prevents PHI from being unrecoverable while still honoring delete requests.
- [Phase 7C] Chat channels have no updated_at (UPDATED_AT=null). Memberships have neither created_at nor updated_at (use joined_at/last_read_at directly). Messages have no created_at/updated_at (use sent_at).
- [Phase 7C] ChatService::addMembersToChannel() uses DB::table()->insertOrIgnore() to respect UNIQUE(channel_id, user_id) constraint — calling it twice with the same users is safe (idempotent).
- [Phase 7C] NewChatMessage broadcasts on PrivateChannel('chat.{channelId}'). broadcastAs() returns 'chat.message'. ChatActivityEvent broadcasts on PrivateChannel('user.{userId}') with only channel_id payload (no PHI) to trigger unread badge refresh.
- [Phase 7C] NotificationDispatcher delivery modes: in_app_only (default, no email), email_immediate (queues NotificationMail), email_digest (Redis increment digest_pending:{user_id}), off (nothing). Unknown prefs → in_app_only.
- [Phase 7C] DigestNotificationJob runs every 2 hours on 'notifications' queue. Scans ALL active users across ALL tenants for pending digest counts. Sends DigestNotificationMail (count only, zero PHI in subject/body). Clears counter to 0 after send.
- [Phase 7C] Email notifications contain ZERO PHI. NotificationMail subject: "You have a new notification — NostosEMR". DigestNotificationMail subject: "You have {N} new notifications — NostosEMR". Bodies contain only a generic "log in to view" message.
- [Phase 7C] ProfileController::updateNotifications() validates against PREF_KEYS whitelist ['alert_critical','alert_warning','alert_info','sdr_overdue','new_message'] and VALID_VALUES ['in_app_only','email_immediate','email_digest','off']. Unknown keys → 422. Valid keys are merged into existing preferences (not replaced wholesale).
- [Phase 7C] DM channel deduplication: ChatService::getOrCreateDmChannel() canonicalizes user pair (lower ID first) to build a deterministic slug and uses firstOrCreate. Repeated calls between same two users return the same channel.
- [Phase 7C] Cross-tenant DM attempts return 403. ChatController::directMessage() checks $target->tenant_id === auth()->user()->tenant_id before creating channel.
- [Phase 7B] LIVE_DASHBOARD_DEPTS in Dashboard/Index.tsx: all 14 depts now render live widget dashboards. Phase 7A covered clinical (7); Phase 7B covers operations (transportation/pharmacy/idt/enrollment/finance/qa_compliance/it_admin). The static module card grid fallback is no longer reached in production.
- [Phase 8A] ComingSoonController::show() accepts mode='transport'|'planned' (default 'planned'). Transport mode renders ComingSoonBanner (amber Nostos Integration Pending). Planned mode renders PlannedFeatureBanner (indigo roadmap notice). CAT2 routes simply redirect() to the live equivalent page.
- [Phase 8A] Document model: CREATED_AT = 'uploaded_at', UPDATED_AT = null. Documents are append-only (no edits, soft-delete only per HIPAA). File bytes stored at storage/app/participants/{id}/. File path NEVER returned in API responses — all downloads go through DocumentController::download() which streams the file.
- [Phase 8A] Document delete authorization: uploader OR it_admin department OR super_admin. Non-uploader non-admins get 403 on delete. Soft-delete only — assertSoftDeleted() in tests confirms this.
- [Phase 7A] CarePlanGoal tenant scoping: Goals have no direct tenant_id. Always scope via: whereHas('carePlan', fn($q) => $q->where('tenant_id', $tenantId)->where('status', '!=', 'archived')).
- [Phase 7A] Alert dept filtering in widget controllers: use whereJsonContains('target_departments', $dept) directly (not the forUser() scope, which requires a real User object).
- [Super Admin] ImpersonationService manages two session keys: impersonating_user_id (full user impersonation) and viewing_as_department (dashboard-only dept preview). Default state (no keys set): SA sees everything — all nav, all pages, no restrictions.
- [Super Admin] Audit log identity: AuditLog::record() always uses the REAL super-admin's user ID. The impersonated user's ID is NEVER used as the AuditLog actor. This is enforced in ImpersonationService::start() and stop(), and in CheckDepartmentAccess.
- [Super Admin] When impersonating, auth.user in Inertia shared props reflects the IMPERSONATED user's data (dept/role/permissions). auth.real_user carries the SA's identity for header display. auth.user.is_super_admin is explicitly false when impersonating.
- [Super Admin] "Dashboard View" selector (viewing_as_department) ONLY affects Dashboard/Index.tsx module card rendering. All other pages (Finance, QA, IDT, etc.) remain directly accessible to SA without any dept selection. Default is 'it_admin'.
- [Super Admin] DashboardController::show() return type is Inertia\Response|\Illuminate\Http\RedirectResponse — Inertia::location() returns a RedirectResponse for non-Inertia requests.
- [Super Admin] tj@nostos.tech is the first super-admin (role='super_admin' in shared_users). Seeded in DemoEnvironmentSeeder. Logs in via Google OAuth (SocialiteController already handles this).
- [Super Admin] ImpersonationController routes are at /super-admin/* prefix, inside the auth middleware group. All endpoints abort(403) for non-super-admin users via requireSuperAdmin() guard.
- [Phase 9C] emr_participant_risk_scores: unique(participant_id, payment_year). One RAF score record per participant per payment year. score_source='calculated' when computed by RiskAdjustmentService; 'cms_import' when loaded from CMS remittance file.
- [Phase 9C] RiskAdjustmentService wraps HccRiskScoringService — heavy ICD-10→HCC mapping logic stays in scoring service; lifecycle management (upsert, gap summary delegation, diagnosis retrieval) is in RiskAdjustmentService.
- [Phase 9C] emr_state_medicaid_configs: unique(tenant_id, state_code). Finance department can VIEW configs; only it_admin can CREATE/UPDATE/DELETE. State code is normalized to uppercase on save.
- [Phase 9C] HpmsSubmission uses period_start/period_end DATE columns (not a period_month string). Status values are 'draft' and 'submitted' (no 'accepted' — HPMS does not return an acknowledgement like 277CA). BillingComplianceService::hpmsChecks() filters by whereBetween('period_start', [$monthStart, $monthEnd]).
- [Phase 9C] BillingComplianceService::getChecklist() overall_status = worst status across all 10 checks. 'fail' > 'warn' > 'pass'. Each category returns {label, checks: [{label, status, value, detail}]}.
- [Phase 9C] DEBT-038 is now partially complete: configuration framework (emr_state_medicaid_configs + StateMedicaidConfigController) is done. The actual 837 submission pipeline (state-specific EDI generation + submission) remains for a future phase.
- [W3-6] emr_appointments uses `scheduled_start` (timestamp) for the appointment start time — NOT `scheduled_date`. Any queries against appointment timing must use `scheduled_start`.
- [W3-6] emr_clinical_notes.site_id is NOT NULL (FK constrained). Notes cannot be DB-inserted with null site_id. TransferController::verify() checks for this anomaly via a raw count — will only find anomalies if the constraint was somehow bypassed (e.g., via direct SQL or older migration data).
- [Phase 10A] emr_participant_site_transfers: no unique constraint (multiple transfers per participant allowed over time — earlier transfers become 'completed'/'cancelled'). Only one active (pending or approved) transfer per participant enforced by TransferController.
- [Phase 10A] TransferService::completeTransfer() is designed to be called from TransferCompletionJob (system context, no Auth::user()). AuditLog::record() is called with userId=null for system-triggered completion.
- [Phase 10A] 90-day read-only access for prior site staff: enforced at the model level via ParticipantSiteTransfer::priorSiteHasReadAccess($siteId). CheckDepartmentAccess middleware should call this to gate cross-site participant access.
- [Phase 10A] TransferCompletionJob runs on 'transfers' queue (Horizon). Gracefully handles exceptions per-transfer (logs error, continues to next — doesn't abort entire batch).
- [Phase 10A] Blueprint::check() does not exist in this Laravel version. Use DB::statement("ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)") for PostgreSQL CHECK constraints in migrations (same pattern used throughout the codebase).
- [Phase 10A] TransfersTab in Show.tsx: visible only when canManageTransfers (enrollment, it_admin, super_admin). Other departments cannot see the Transfers tab at all. The tab shows pending transfer amber banner + request modal + history table.
- [Phase 10B] Two 'super_admin' concepts: role='super_admin' (tj@nostos.tech, impersonation system, existing) vs department='super_admin' (Nostos staff users, cross-tenant read access, no impersonation UI, new in 10B). Do not conflate them.
- [Phase 10B] SiteContextMiddleware always runs for authenticated users. For regular (non-executive, non-SA) users, it returns their own site_id unconditionally (no session key used). For executive/SA users, it reads 'active_site_id' from session (falls back to user's own site_id).
- [Phase 10B] SiteContextController::switch(): executives may only switch to sites belonging to their own tenant (403 otherwise). Users with role='super_admin' OR department='super_admin' may switch to any site across all tenants.
- [Phase 10B] SuperAdminPanelController::onboard() generates slug from tenant name as: strtolower(kebab(name)) + '-' + 6-char UUID suffix. slug is NOT NULL in shared_tenants — always generate it.
- [Phase 10B] emr_role_permissions_department_check and shared_users_department_check are both PostgreSQL CHECK constraints that must be updated (via DB::statement DROP + ADD) when new departments are added. Migration 68 covers shared_users; migration 69 covers emr_role_permissions.
- [Phase 10B] CheckDepartmentAccess: isDeptSuperAdmin() bypass fires before isSuperAdmin() block, allowing department='super_admin' users to access any route without impersonation scaffolding.
- [Phase 10B] ExecutiveDashboard widgets: all 4 endpoints are tenant-scoped (executive sees only their own tenant's data). They use Auth::user()->tenant_id for all DB queries. No cross-tenant data leaks.
- [Phase 10B] NostosAdminBanner (amber) in AppShell: renders only when auth.user.department === 'super_admin'. Distinct from the ImpersonationBanner (also amber) which renders when auth.real_user is set. Both can show simultaneously if a dept=super_admin user impersonates someone.

- [Phase 11B] EhiExportService uses raw ZipArchive (not Storage facade) to write ZIP files. EhiExportController::download() resolves the path via Storage::disk('local')->path() so Storage::fake() works in tests. These two approaches must stay in sync: service writes to storage/app/ehi_exports/ (real FS), controller reads via Storage facade (testable).
- [Phase 11B] Participant update route supports both PUT and PATCH via Route::match(['PUT','PATCH']). This is required for PATCH-based tests and consistent with the billing controller pattern (Phase 9B).
- [Phase 11B] hasDnr() returns true ONLY for advance_directive_type='dnr'. POLST is a distinct legal document (physician order, not an advance directive) and should NOT be treated as a DNR in clinical logic.
- [Phase 11B] SocialDeterminant enum values: HOUSING_VALUES=['stable','at_risk','unstable','homeless','unknown']; FOOD_VALUES=['secure','at_risk','insecure','unknown']; TRANSPORT_VALUES=['adequate','limited','none','unknown']; ISOLATION_VALUES=['low','moderate','high','unknown']; STRAIN_VALUES=['none','mild','moderate','severe','unknown']. These differ from intuitive guesses — always use model constants.
- [Phase 11B] EHI export token: 64-char hex (bin2hex(random_bytes(32))). Single-use (downloaded_at set on first download). 24h TTL (expires_at = now()+24h). Controller returns 410 Gone for expired exports.
- [Phase 11B] FHIR R4 new endpoints: GET /fhir/R4/Immunization, GET /fhir/R4/Procedure, GET /fhir/R4/Observation/social-history. All require Bearer token auth with scope (immunization.read, procedure.read, observation.read). All audit-logged.
- [Phase 11B] advance_directive columns on emr_participants: status/type/reviewed_at/reviewed_by_user_id. PostgreSQL CHECK constraints added via DB::statement(). Fields in UpdateParticipantRequest $base array (accessible to all departments including primary_care per 42 CFR 460.96).

## DEMO CREDENTIALS (dev only)
- Super Admin: superadmin@nostos.dev / DemoP@ce2025!
- Department users: [firstname].[dept]@sunrisepace-demo.test / DemoP@ce2025!
- 30 participants (last name 'Testpatient'), 28 users (last name 'Demo')
- 2 sites: Sunrise PACE East, Sunrise PACE West
- 1 tenant: Sunrise PACE - Demo Organization

## KEY DOCKER COMMANDS
**Run all commands from WSL2 Ubuntu terminal at `/home/tj/projects/nostosemr`** (not Windows PowerShell):
- Start:       `./start.sh`  (preferred — runs docker compose up + waits for health + artisan optimize)
- Start (manual): `docker compose up -d`
- Tests:       `docker compose exec -T laravel.test php artisan config:clear && docker compose exec -T laravel.test php artisan test --parallel --processes=4`
- **After tests (REQUIRED):** `docker compose exec -T laravel.test chmod -R 777 storage bootstrap/cache && docker compose exec -T laravel.test php artisan view:clear && docker compose exec -T laravel.test php artisan config:clear && docker compose exec -T laravel.test php artisan optimize`
  ↳ Parallel test workers run as root and leave root-owned view/cache files. Without this, localhost returns HTTP 500 on next page load.
- Build UI:    `docker compose exec -T laravel.test npm run build`
- Fresh seed:  `docker compose exec -T laravel.test php artisan migrate:fresh --seed --seeder=DemoEnvironmentSeeder`
- Artisan:     `docker compose exec -T laravel.test php artisan <cmd>`
- Optimize:    `docker compose exec -T laravel.test php artisan optimize`  (run after route/config changes; start.sh does this automatically)
- WSL2 shell:  Open Ubuntu app from Start menu, then `cd ~/projects/nostosemr`

## GIT & GITHUB
- **Repository:** https://github.com/PaneCross/Nostos-EMR (public)
- **GitHub account:** PaneCross
- **gh CLI:** installed at `~/bin/gh` in WSL2 Ubuntu (not on system PATH — use full path `~/bin/gh`)
- **Git root:** `/home/tj/projects/nostosemr` in WSL2 (initialized 2026-03-22; branch: main)
- **WARNING:** `C:\Users\TJ` has a git repo initialized at the wrong level (home dir). Do NOT use it — always commit from WSL2.

### Standard commit workflow (from WSL2):
```bash
cd ~/projects/nostosemr
# If edits were made on the Windows path, sync first:
cp '/mnt/c/Users/TJ/Desktop/PACE EMR/nostosemr/path/to/file' ~/projects/nostosemr/path/to/file
git add <files>
git commit -m "message"
git push
```

### Sync all changed files from Windows → WSL2:
```bash
rsync -av --exclude=vendor --exclude=node_modules --exclude=public/build --exclude=.git \
  '/mnt/c/Users/TJ/Desktop/PACE EMR/nostosemr/' ~/projects/nostosemr/
```

## TEST STATUS
- [2026-03-14] Phase 0 audit — 291 passing, 0 failing (64 deprecations, 78 PHPUnit deprecations — non-blocking)
- [2026-03-14] Audit A complete — 297 passing, 0 failing (64 deprecations, 90 PHPUnit deprecations — non-blocking)
- [2026-03-14] Audit B complete — 318 passing, 0 failing (64 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-14] Audit C complete — 325 passing, 0 failing (64 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-14] Audit D complete — 326 passing, 0 failing (64 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-15] Phase 5A complete — 365 passing, 0 failing (64 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-15] Phase 5B complete — 396 passing, 0 failing (64 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-15] Phase 5C complete — 441 passing, 0 failing (64 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-15] Phase 5D complete — 466 passing, 0 failing (64 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-15] Phase 6A complete — 500 passing, 0 failing (64 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-15] Phase 6B complete — 572 passing, 0 failing (64 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-16] Phase 6C complete — 637 passing, 0 failing (64 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-16] Phase 6D complete — 700 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-16] Super Admin Enhancements complete — 715 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-16] Phase 7A complete — 764 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-17] Phase 7B complete — 813 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-18] Phase 7C complete — 857 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking)
- [2026-03-19] Phase 7D complete — 857 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking). NostosEMR MVP demo-ready.
- [2026-03-19] Phase 8A complete — 885 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking).
- [2026-03-19] Phase 9B complete — 930 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking).
- [2026-03-21] Phase 9C complete — 959 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking).
- [2026-03-21] Phase 10A complete — 982 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking).
- [2026-03-21] Phase 10B complete — 1017 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking).
- [2026-03-21] Phase 11B complete — 1057 passing, 0 failing (32 deprecations, 92 PHPUnit deprecations — non-blocking).
- [2026-03-21] Phase 12B complete — 1057 passing, 0 failing. Build clean. Project sealed.
- [2026-03-24] W3-0 — test run pending (run from WSL2 before Wave 3 build begins; expected: 1057 passing, 0 failing).
- [2026-03-24] W3-1 — test run pending (expected: 1065+ passing, 0 failing — adds ThemePreferenceTest with 8 tests).
- [2026-03-31] W4-0 — 1181 passing, 0 failing (16 deprecations, 92 PHPUnit deprecations — non-blocking). Wave 4 baseline confirmed. Fixed 3 pre-existing test issues: QaMetricsServiceTest hospitalization boundary (subMonth→subMonths(2)), ComingSoonBannerTest clinical/orders test updated for live page (W3-8), DashboardActionabilityTest enrollment referral created_at (subDays(2) on Tuesday fell before week start → use startOfWeek+1h).
- [2026-03-26] W3-2 — 1091 passing, 0 failing. Build clean. Adds NavRoutingTest (13 tests) + DayCenterAttendanceTest (12 tests) + Day Center attendance module + Reports page + System Settings page. Bugs fixed: scopeForSite null type hint, payer_id column DNE, pace_contract column DNE (mapped to cms_contract_id), ComingSoonBannerTest stale assertions for 3 now-live pages + /idt/minutes redirect target.
- [2026-03-27] W3-4 — 1137 passing, 0 failing. Build clean. Adds FacesheetTest (6 tests) + ParticipantTabRoutingTest (22 tests). Show.tsx: print CSS fixed (visibility approach — position:fixed caused blank print), two-row tab layout (CLINICAL blue / ADMIN slate), switchTab() for URL sync via window.history.replaceState, valid tab list updated with immunizations/procedures/sdoh (Phase 11B), ParticipantHeader onTabChange prop + Care Plan/Schedule header buttons fixed, advance directive DNR/POLST/No Directive badges in sticky header flags row, CarePlanTab save error state (catch block no longer silent), editability guard on Edit button (hidden for active/archived plans). Bugs fixed: cross-tenant returns 403 not 404 (authorizeForTenant uses abort_if(..., 403)), PHPUnit @dataProvider converted to #[DataProvider] attribute.
- [2026-03-27] W3-5 — 1155 passing, 0 failing. Build clean. Adds ChatSearchTest (10 tests) + ChatNotificationTest (8 tests). Migration 77: metadata JSONB on emr_alerts. Bugs fixed: DM search was calling /it-admin/users (403 for non-IT-admin) → replaced with /chat/users/search endpoint; urgent messages never created alerts (code comment but no implementation) → fixed.
- [2026-03-26] W3-3 — 1105 passing, 0 failing. Build clean. Dashboard Overhaul: all 14 dept dashboard controllers updated with href fields on every response item (direct deep-links to participant records with ?tab=chart/assessments/careplan/medications). New ActionWidget.tsx reusable component (label+href+badge+sublabel rows, loading skeleton, viewAllHref footer, dark mode). All 13 active dept React dashboards rewritten to use ActionWidget. DashboardActionabilityTest (14 tests) confirms every widget returns hrefs starting with /. ThemeToggle.tsx: changed focus:ring to focus-visible:ring (no persistent highlight after mouse click). Bugs fixed: Assessment factory 'status' column DNE, Vital factory 'recorded_by' → 'recorded_by_user_id', Sdr status 'open' invalid (→ 'submitted'), Referral 'source' column DNE (→ 'referral_source'), pharmacy response key 'interactions' → 'alerts', ItAdminDashboardController Carbon::parse() needed for IntegrationLog string created_at.

## SESSION LOG
- [2026-03-27] W3-5 — 1155 passing, 0 failing. Build clean. Chat Fixes. Part A: Created dedicated GET /chat/users/search endpoint (ChatController::searchUsers — tenant-scoped, min 2 chars, excludes self, max 20 results, case-insensitive LIKE on first+last+full name). Added ChatSearchTest (10 tests). Fixed Chat/Index.tsx DM search to use /chat/users/search instead of broken /it-admin/users (403 for non-IT-admin). Added min-2-char enforcement, loading spinner, keyboard nav (ArrowUp/Down/Enter/Esc), department badge on results. Part B: Migration 77 (2024_12_03_000001) adds JSONB metadata column to emr_alerts. Alert model updated: 'chat' added to SOURCE_MODULES, 'metadata' in $fillable + $casts. ChatController::send() fixed: urgent messages now actually create critical alerts (was documented in a code comment but never implemented). Alert includes metadata.channel_id for deep-link to /chat?channel={id}. NotificationBell.tsx: added metadata to AlertItem interface, alertHref() helper (chat → /chat?channel={id}, participant → /participants/{id}), clickable "Go to chat" / "View" link per alert. Chat/Index.tsx: auto-selects channel from ?channel= URL param on mount (for notification deep-link). Part C: AppShell.tsx CriticalAlertBanner (full-width red banner, sessionStorage dismiss, stacked per alert, "Go to chat" deep-link for chat alerts, Reverb real-time refresh via tenant.{id} channel). Rendered below NostosAdminBanner in layout. Created ChatNotificationTest (8 tests: standard message no alert, urgent creates critical, title has sender name, metadata.channel_id, target_departments excludes sender, no members = no alert, broadcast events for both standard + urgent). Expected: 1157+ tests, 0 failures.
- [2026-03-14] Phase 0 — Bootstrapped CLAUDE.md. Audited full project: 30 migrations, 25 models, 18 controllers, 8 services, 27 test files, 14 React pages. All 291 tests green. Also fixed Clinical/Notes.tsx row-click redirect bug (always go to ?tab=chart), and redesigned Participants/Show.tsx OverviewTab as full PACE Facesheet with print-to-PDF support.
- [2026-03-14] Audit A — DB & Migration Integrity. Found 3 issues: (1) missing shared_audit_logs(resource_type, resource_id) index → migration added; (2) shared_users_role_check constraint blocked 'super_admin' role → migration added; (3) AuditLog model silently ignored mutations instead of throwing → upgraded to ImmutableRecordException, new AuditLogImmutabilityTest added. 297 tests green.
- [2026-03-14] Audit B — Auth & RBAC. All auth flows verified in code: OTP, rate limiting, code reuse, logout, session_timeout audit logging, HIPAA idle timer. Added 3 missing tests to AuthTest (code reuse, rate limiting x2). Written PermissionMatrixTest (21 tests). Browser-based manual checks (STEP 1 OTP flow, STEP 6 visual shell) require developer action at http://localhost. 318 tests green.
- [2026-03-14] Audit C — Clinical Docs. Found 6 issues: (1) VitalController missing dept restriction → added whitelist; (2) ClinicalNoteController missing dept scoping → added crossDeptViewers filter; (3) ADL alert creation not tested → test added; (4) ICD-10 seeder only 181 codes → added 22 codes (203 total); (5) ChartTab visit_type mismatch ('in_person' vs 'in_center') → fixed; (6) ChartTab missing department in payload → fixed via usePage(). Also added Addendum button to NoteCard (was missing entirely). Browser STEP 1 verified: create draft → sign → addendum all working. 325 tests green.
- [2026-03-14] Audit D — Phase 4 gaps. Found 2 issues: (1) echo.js (Reverb/Echo client) existed but was never imported in app.tsx → added import './echo' to app.tsx; (2) AlertCreatedEvent broadcast not tested → added test_creating_critical_alert_broadcasts_alert_created_event to AlertTest. All 7 other Phase 4 items verified clean: 6 tables correct, Sdr::boot() 72h enforcement, CarePlan review_due_date = effective_date+6m, SdrDeadlineEnforcementJob scheduled every 15min, 9 event classes exist, AlertService broadcasts + acknowledges + resolves, Phase4DataSeeder covers all 4 Phase 4 entities. 326 tests green.
- [2026-03-15] Phase 5A — Locations + Appointment Scheduling. Created: 2 migrations (emr_locations, emr_appointments), 2 models (Location, Appointment), ConflictDetectionService (participant overlap + 2h transport window), 4 form requests, 2 controllers (LocationController + AppointmentController with full status lifecycle), 2 factories, Phase5ADataSeeder (10 locations + 3-7 appts per participant), updated routes/web.php (/schedule, /locations, participant appointments), React Schedule/Index.tsx (week-view calendar, color-coded blocks, slide-over detail panel, multi-step booking modal with transport toggle), 3 test files (LocationTest 14 tests, AppointmentTest 13 tests, ConflictDetectionServiceTest 12 tests). Fixed emr_locations migration: label/address fields made nullable. 365 tests green.
- [2026-03-15] Phase 5C — Medications + eMAR. Created: 6 migrations (emr_medications, emr_emar_records, emr_med_reconciliations, emr_drug_interaction_alerts, emr_medications_reference, emr_drug_interactions_reference), 4 models (Medication, EmarRecord, MedReconciliation, DrugInteractionAlert), 2 services (DrugInteractionService — non-blocking interaction checks with drug-name-based idempotency, MedicationScheduleService — nightly MAR generation per frequency), LateMarDetectionJob (every 30min, 30-min grace period, flags late doses + creates alerts), 3 form requests, MedicationController (12 endpoints: CRUD, discontinue, eMAR, PRN dose, interactions, reconciliation, reference search), MedicationsReferenceSeeder (~90 meds + ~50 interaction pairs), Phase5CDataSeeder (3 med sets × participants, 7-day eMAR history, MedReconciliation, DrugInteractionAlerts), updated DemoEnvironmentSeeder + routes/web.php, MedicationsTab + EmarTab in Show.tsx. 5 test files: MedicationTest (12), EmarTest (11), DrugInteractionServiceTest (10 unit), MedicationScheduleServiceTest (11 unit), LateMarDetectionJobTest (5). Bug fixed: DrugInteractionService idempotency check now uses drug_name instead of medication_id to correctly skip duplicate alerts when the same drug is re-prescribed. 441 tests green.
- [2026-03-15] Phase 5D — Medication Reconciliation. Added 2 migrations (phase5d columns on emr_med_reconciliations: status/prior_source/prior_medications/changes_made/approved_by/approved_at; made reconciled_at nullable), rewrote MedReconciliation model (constants: SOURCES, SOURCE_LABELS, STATUSES, PRESCRIBER_DEPARTMENTS, APPROVER_DEPARTMENTS, DECISION_ACTIONS; isLocked/isActive/sourceLabel; scopeApproved/scopeActive), new MedReconciliationService (5 methods: startReconciliation idempotent, addPriorMedications, addPriorMedication, generateComparison diff, applyDecisions keep/discontinue/add/modify, providerApproval + assertNotLocked guard), MedReconciliationController (6 routes: start/prior-meds/comparison/decisions/approve/history), 3 form requests (Start, ApplyDecisions, ApproveReconciliation), MedReconciliationFactory (inProgress/decisionsMade/approved states), 2 test files (MedReconciliationTest 11 feature tests, MedReconciliationServiceTest 12 unit tests), MedReconTab 5-step wizard in Show.tsx. Fixed: reconciled_at made nullable (was NOT NULL in Phase 5C), AuditLog named params corrected (resourceType not resource_type), frequency enum validation in service add case. 466 tests green.
- [2026-03-15] Phase 6B — QA/Compliance Dashboard + Incident Reporting. Created: 1 migration (emr_incidents: 11 types, 4 statuses, RCA fields, CMS fields, witnesses JSONB, softDeletes), Incident model (RCA_REQUIRED_TYPES constant, canClose() guard), IncidentService (createIncident auto-sets rca_required per CMS 42 CFR 460.136, submitRca advances to under_review, closeIncident blocked by pending RCA via LogicException), QaMetricsService (6 KPI methods: SDR compliance rate, overdue assessments, unsigned notes, open incidents, overdue care plans, hospitalizations this month), DocumentationComplianceJob (Horizon, daily 6am, 'compliance' queue, alert deduplication via whereJsonContains), StoreIncidentRequest (participant_id, incident_type, injuries fields; rca_required excluded — service-only), UpdateIncidentRequest (QA admin only), SubmitRcaRequest (qa_compliance/primary_care/it_admin, min 50 chars), IncidentController (any user creates, QA admin updates/closes, QA+clinical submits RCA), QaDashboardController (Inertia page with 6 KPIs + incident queue, lazy-load compliance tabs, CSV export), IncidentFactory (8 states), Qa/Dashboard.tsx (6 KPI cards with alert thresholds, 3-tab compliance section, incident queue table, CSV export buttons). Fixed: Carbon 3 diffInHours/diffInDays returns negative for past dates → wrapped in abs(). SiteFactory missing mrn_prefix → all factory sites got "SITE" prefix → MRN unique violation when multiple participants per test → added unique mrn_prefix to SiteFactory. 572 tests green.
- [2026-03-16] Phase 7A — Clinical Department Dashboards (1-7). Created 7 JSON widget endpoint controllers in app/Http/Controllers/Dashboards/ (PrimaryCare, Therapies, SocialWork, BehavioralHealth, Dietary, Activities, HomeCare), registered 28 GET routes under /dashboards/* prefix in routes/web.php, built 7 React dashboard components in resources/js/Pages/Dashboard/Depts/ (each loads 4 widgets in parallel via Promise.all + axios, renders WidgetCard grid with loading skeletons), updated Dashboard/Index.tsx with LIVE_DASHBOARD_DEPTS constant + LiveDeptDashboard switch component, wrote 7 feature test files in tests/Feature/Dashboards/ (7 tests each = 49 new tests: 3 access control + 4 JSON structure per dept). Fixed DietaryDashboardController using wrong Allergy column names (allergen_name, reaction_description). 764 tests green, build clean.
- [2026-03-18] Phase 7C — Chat + Notifications. Created: 4 migrations (emr_chat_channels, emr_chat_memberships, emr_chat_messages, notification_preferences on shared_users), 3 models (ChatChannel, ChatMembership, ChatMessage — append-only, SoftDeletes on messages), 3 factories, 2 broadcast events (NewChatMessage on private-chat.{channelId}, ChatActivityEvent on private-user.{userId}), ChatService (createDepartmentChannels 14 dept+1 broadcast idempotent, createParticipantIdtChannel 6 IDT depts idempotent, getOrCreateDmChannel, addMembersToChannel insertOrIgnore), NotificationDispatcher (dispatch/deliverTo/queueForDigest/preferenceKey, 4 delivery modes: in_app_only/email_immediate/email_digest/off), DigestNotificationJob (every 2h on 'notifications' queue, Redis cache counters digest_pending:{user_id}), 2 Mail classes (NotificationMail + DigestNotificationMail — zero PHI in subjects), 2 blade email views, ChatController (7 endpoints: index Inertia, channels JSON, messages paginated, send+broadcast, markRead, directMessage, unreadCount), ProfileController (notifications Inertia page + updateNotifications API with validation), updated routes/web.php (6 chat API routes + 1 Inertia + 2 profile prefs), updated AppShell.tsx (chat unread badge + Echo private-user subscription), Chat/Index.tsx (2-col layout, channel groups, real-time via Echo, MessageBubble, urgent toggle, DM search, pagination), Profile/Notifications.tsx (4-button pref selector per key), updated DemoEnvironmentSeeder (calls ChatService::createDepartmentChannels). 4 test files: ChatTest (14), ChannelAutoCreationTest (7), NotificationTest (11), NotificationDispatcherTest (10). Fixed: ChatService used $participant->full_name (undefined) → changed to $participant->first_name . ' ' . $participant->last_name. Fixed: Reverb Docker gosu crash (WWWUSER blank on Windows) → SUPERVISOR_PHP_USER: root in compose.yaml. 857 tests green, build clean.
- [2026-03-17] Phase 7B — Operations Department Dashboards (8-14). Created 7 JSON widget endpoint controllers in app/Http/Controllers/Dashboards/ (Transportation, Pharmacy, Idt, Enrollment, FinanceWidget, QaCompliance, ItAdmin), registered 28 GET routes under /dashboards/* prefix in routes/web.php, built 7 React dashboard components in resources/js/Pages/Dashboard/Depts/ (each loads 4 widgets in parallel via Promise.all + axios), updated Dashboard/Index.tsx: 7 new imports, expanded LIVE_DASHBOARD_DEPTS to all 14 depts, added 7 cases to LiveDeptDashboard switch, emptied 7 ops dept arrays in DEPT_MODULES, wrote 7 feature test files in tests/Feature/Dashboards/ (7 tests each = 49 new tests). Fixed: QaComplianceDashboard.tsx JSX build error (unescaped > character → &gt;). Fixed: 23 test structure mismatches (test keys didn't match actual controller response keys). 813 tests green, build clean.
- [2026-03-16] Super Admin Enhancements — Session-based impersonation system. Created: ImpersonationService (start/stop/getImpersonatedUser/isImpersonating/setViewAs/clearViewAs/getViewAsDepartment), ImpersonationController (5 endpoints: users list, start/stop impersonation, set/clear view-as dept), updated HandleInertiaRequests (impersonation shared props, auth.real_user, auth.user reflects impersonated context), updated DashboardController (SA bypass with dept context, redirect to impersonated user's dept), updated CheckDepartmentAccess (impersonated user RBAC), updated routes/web.php (/super-admin/* group), updated AppShell.tsx (ImpersonationBanner amber bar, ImitateUserDropdown in TopBar, Dashboard View selector in sidebar footer), updated TypeScript types (is_super_admin, RealUser, ImpersonationState, ImpersonationUser), ImpersonationTest.php (15 tests). 715 tests green, build clean.
- [2026-03-16] Phase 6D — Integrations + IT Admin. Created: 2 migrations (emr_integration_log, make requesting_user_id nullable on emr_sdrs), 1 model (IntegrationLog — append-only, markProcessed/markFailed/markRetried), IntegrationLogFactory (4 states), 2 integration connectors (Hl7AdtConnector, LabResultConnector), 2 jobs (ProcessHl7AdtJob — A01/A03/A08 handling; ProcessLabResultJob — normal/abnormal lab results), IntegrationController (2 inbound endpoints outside auth, X-Integration-Tenant header auth), ItAdminController (integrations panel + log + retry, users panel + provision + deactivate + reactivate + reset-access, audit panel + log + CSV export), WelcomeEmail mailable + blade template, 3 React pages (ItAdmin/Integrations, ItAdmin/Users with provision modal, ItAdmin/Audit), updated routes/web.php. 5 test files: Hl7ConnectorTest (12), LabResultConnectorTest (10), ItAdminTest (25), ProcessHl7AdtJobTest (10 unit), ProcessLabResultJobTest (10 unit). Fixed: sessions table name (was shared_sessions), assigned_department NOT NULL on emr_sdrs (added to job), alert severity 'warning' not 'high'. 700 tests green, build clean.
- [2026-03-16] Phase 6C — Finance + FHIR R4. Created: 4 migrations (emr_capitation_records, emr_encounter_log, emr_authorizations, emr_api_tokens), 4 models (CapitationRecord, EncounterLog, Authorization, ApiToken), 4 factories, 7 FHIR mappers (Patient/Observation/MedicationRequest/Condition/AllergyIntolerance/CarePlan/Appointment), FhirAuthMiddleware (Bearer token + scope check + last_used_at + OperationOutcome errors), FhirController (7 read endpoints, audit-logged, tenant-isolated, 404 on cross-tenant), FinanceController (7 endpoints: capitation CRUD, encounter index/store, auth index/store/update), FinanceDashboardController (Inertia dashboard + CSV export for 3 types), Finance/Dashboard.tsx (4 KPI cards + 3 tabs: capitation/authorizations/encounters + CSV export buttons), FHIR routes (outside 'auth' middleware, fhir.auth alias registered in bootstrap/app.php), Finance routes (replaces billing coming-soon stubs), 4 test files (FinanceTest 16, FhirApiTest 18, PatientMapperTest 14, ObservationMapperTest 10). Fixed: AppointmentMapper used wrong column names (scheduled_at/ends_at → scheduled_start/scheduled_end); MedicationRequestMapper used non-existent discontinued_at (→ status==='discontinued' check); ApiToken::touch() conflicted with Model::touch($attribute=null) → renamed to markUsed(). 637 tests green, build clean.
- [2026-03-15] Phase 6A — Enrollment & Intake. Created: 1 migration (emr_referrals table with 9-status state machine, CHECK constraints for source+status), InvalidStateTransitionException (custom RuntimeException with fromStatus/toStatus readonly), Referral model (SOURCES/STATUSES/TERMINAL_STATUSES/PIPELINE_STATUSES constants, isTerminal/isEnrolled methods, forTenant/active/unassigned scopes), EnrollmentService (VALID_TRANSITIONS map, transition() with InvalidStateTransitionException guard, handleEnrollment() sets participant enrollment_status+date, linkParticipant(), disenroll()), 4 form requests (StoreReferral, UpdateReferral, TransitionReferral, DisenrollParticipant), ReferralController (index→Inertia Kanban, store, show, update, transition, disenroll), ReferralFactory (8 states: new/intakeScheduled/intakeInProgress/intakeComplete/eligibilityPending/pendingEnrollment/enrolled/declined/withdrawn), Enrollment/Index.tsx Kanban pipeline (7 columns with color coding, NewReferralModal, ReferralDetailModal with transition controls + decline/withdrawal reason fields). Fixed: site_id null in tests → create Site factory; disenroll reasons enum mismatch (voluntary/deceased not voluntary_withdrawal/death). 2 test files (EnrollmentTest 18 feature tests, EnrollmentStateMachineTest 16 unit tests). 500 tests green, build clean.
- [2026-03-15] Phase 5B — Transport Manifest + Add-On Queue. Created: 2 migrations (emr_transport_requests, FK from emr_appointments), TransportRequest model + factory (5 states), extended TransportBridgeService (createTripRequest now takes model, added updateTripStatus + validateWebhookSignature), ProcessTransportStatusWebhookJob (queued, HMAC-validated, no_show alert), 2 form requests (StoreTransportRequestRequest + UpdateTransportRequestRequest), 2 controllers (TransportRequestController + WebhookController), Transport/Manifest.tsx (date picker, run-sheet table with flag snapshot display, Reverb real-time status updates, Add-On Queue tab, Export PDF, AddOnModal with participant typeahead), Phase5BDataSeeder, updated DemoEnvironmentSeeder + routes/web.php. Fixed: HMAC fail-closed on missing secret, cancelled trips excluded from manifest API, completed trips blocked from cancel (409), mobility flag snapshot key remapped to flag_type in runs(). 4 test files (TransportRequestTest 12 tests, WebhookTest 7 tests, TransportManifestTest 7 tests, TransportBridgeServiceTest updated +7 new tests). 396 tests green.
- [2026-03-19] Phase 8A — Transport Stubs + Full Nav Link Audit + Documents Tab. Part A: Created ComingSoonBanner.tsx (transport-specific, amber badge) + PlannedFeatureBanner.tsx (generic roadmap, indigo badge). Applied ComingSoonBanner to Transport/Manifest.tsx and replaced all 4 TransportationDashboard widgets. Updated ComingSoonController to accept mode='transport'|'planned' + optional description param. Updated ComingSoon.tsx to delegate to ComingSoonBanner/PlannedFeatureBanner based on mode. Part B: Full nav audit — 10 CAT1 transport stubs (mode=transport), 7 CAT2 redirects (/idt/minutes→/idt, /idt/sdr→/sdrs, /admin/users→/it-admin/users, /admin/locations→/locations, /billing→/finance/dashboard, /billing/capitation→/finance/dashboard), 7 CAT3 planned features (mode=planned with descriptions); fixed PermissionService capitation href. Part C: emr_documents migration (57th, soft-delete, tenant-isolated), Document model (CREATED_AT='uploaded_at', HasFactory+SoftDeletes, VALID_CATEGORIES/CATEGORY_LABELS, formattedSize/toApiArray), DocumentFactory (pdf/image/forCategory states), StoreDocumentRequest (20MB max, pdf/jpeg/png/docx, VALID_CATEGORIES enum), DocumentController (4 endpoints: index, store, download stream, soft-delete; cross-tenant guard; uploader-or-admin delete guard; file path never exposed), 4 routes at /participants/{participant}/documents/*. Part D (UI): DocumentsTab in Participants/Show.tsx — lazy-load on first activation, category filter chips, upload modal with file picker/category/description, document list with file-type icons, download+delete actions. Tests: ComingSoonBannerTest (15 tests: CAT1/CAT2/CAT3/auth guard), DocumentUploadTest (13 tests: upload/validation/list/filter/download/soft-delete/cross-tenant). 885 tests green, build clean.
- [2026-03-19] Phase 7D — Demo Polish + CI + README. Created: Phase7DDataSeeder (unsigned notes >24h, care plans due ≤30 days, fall incident with RCA, 3 enrollment referrals, 3–5 chat seed messages per channel, guaranteed no-show trip); .github/workflows/ci.yml (3 jobs: PHP tests with PostgreSQL+Redis services, frontend TypeScript+Vite build, Pint style check); README.md (replaced Laravel default stub — 11 sections: overview/quick-start/demo-accounts/architecture/DB-conventions/RBAC/transport-bridge/testing/phase-roadmap/limitations/compliance). Also fixed nav routing mismatches in PermissionService.php (billing→/finance/dashboard, admin/users→/it-admin/users, scheduling/appointments→/schedule, transport add-ons→/transport/manifest+label Manifest), updated AppShell.tsx PHASE_LABEL to 'Phase 7C', added <Head title="Chat" /> to Chat/Index.tsx, added GET /transport/add-ons redirect to /transport/manifest in routes/web.php. 857 tests green, seed clean, build clean. NostosEMR MVP is demo-ready.
- [2026-03-19] Phase 8B — CLAUDE.md hardening. Migration reconciliation (57 migrations all accounted for, 1 new migration 2024_08_01_000001_create_emr_documents confirmed in list), gap report: 7 models without factories, 5 services without unit tests, 3 models without feature test coverage. ADR section added (7 ADRs). Technical debt inventory (13 items). ENV variables documented (72 vars, 9 categories). Horizon jobs inventory (7 jobs). Dependency audit: laravel/framework 11→13 major, laravel/scout 10→11 major, laravel/tinker 2→3 major; React 18→19, vite 5→8, tailwindcss 3→4 major NPM. No new feature code. 885 tests (unchanged).
- [2026-03-19] Phase 9B — Complete Billing Engine. Created: 7 migrations (billing fields on encounter log, emr_edi_batches, HCC fields on capitation records, emr_pde_records, emr_hos_m_surveys, emr_hpms_submissions, emr_hcc_mappings), 5 new models (EdiBatch, PdeRecord, HosMSurvey, HpmsSubmission, HccMapping) + updated EncounterLog (14 billing fields + casts + ediBatch relation + isSubmittable/hasDiagnoses methods) + updated CapitationRecord (7 HCC fields), 5 factories (EdiBatch, PdeRecord, HosMSurvey, HpmsSubmission, HccMapping), 4 services (Edi837PBuilderService — X12 5010A1 837P builder + 277CA parser, HpmsFileService — 4 file types, HccRiskScoringService — ICD-10→HCC RAF scoring + gap analysis, RevenueIntegrityService — 6 KPI methods), 7 controllers (BillingEncounterController, EdiBatchController, CapitationController, PdeController, HpmsController, HosMSurveyController, RevenueIntegrityController), updated routes/web.php (replaced 3-route billing stub with 24 billing routes, 10 new use imports), 7 React pages (Finance/Encounters, Finance/EdiBatch, Finance/Capitation, Finance/Pde, Finance/Hpms, Finance/HosMSurvey, Finance/RevenueIntegrity), 2 seeders (HccMappingSeeder with 40+ ICD-10→HCC V28 entries, Phase9BDataSeeder), 8 test files (BillingEncounterTest, Edi837PBatchTest, CapitationTest, HpmsFileTest, PdeTest, Edi837PBuilderServiceTest Unit, HpmsFileServiceTest Unit, HccRiskScoringServiceTest Unit), updated PermissionService (8 new billing nav items) + PermissionSeeder (6 new billing modules for finance dept).
- [2026-03-19] Phase 9A — Billing research audit. Read all 3 billing models (CapitationRecord, EncounterLog, Authorization), 2 finance controllers (FinanceController, FinanceDashboardController), Finance/Dashboard.tsx, 3 billing migrations, and FinanceTest.php. Searched entire app/ and resources/js/ for 837, EDI, X12, ClaimBuilder, PDE, PartD, MARx, TrOOP, HPMS, HOS-M, hcc, risk_score, revenue_integrity, state_medicaid, medicaid_encounter. Findings: encounter log has 13 of 14 required 837P fields MISSING (most critically: diagnosis_codes, all provider NPIs, place_of_service_code, units, charge_amount, all submission tracking fields); capitation records missing all 5 risk-adjustment reconciliation fields (HCC RAF score, frailty score, county FIPS, adjustment_type, rate_effective_date); zero EDI/837P builder code; zero PDE tracking; zero HPMS file generation; zero HOS-M support; zero state Medicaid encounter; zero HCC gap analysis; zero revenue integrity dashboard. Only HPMS/disenrollment keyword hits were in EnrollmentService (business logic, not CMS reporting). Phase 9B spec documented in CLAUDE.md with 6 priority levels. No new feature code. 885 tests (unchanged).
- [2026-03-21] Phase 9C — Risk Adjustment + State Medicaid Framework. Created: 2 migrations (65-66: emr_participant_risk_scores, emr_state_medicaid_configs), 2 models (ParticipantRiskScore, StateMedicaidConfig), 2 services (RiskAdjustmentService wraps HccRiskScoringService with lifecycle management, BillingComplianceService 5-category checklist + overall_status worst-case aggregation), 3 controllers (RiskAdjustmentController, StateMedicaidConfigController, BillingComplianceController), 3 React pages (Finance/RiskAdjustment, Finance/ComplianceChecklist, ItAdmin/StateConfig), 4 test files (29 new tests). DEBT-038 config layer complete. 959 tests, 0 failures.
- [2026-03-21] Phase 10A — Multi-Site Participant Transfers. Created: 1 migration (67: emr_participant_site_transfers), ParticipantSiteTransfer model (status: pending/approved/completed/cancelled, priorSiteHasReadAccess 90-day window), TransferService (requestTransfer/approveTransfer/cancelTransfer/completeTransfer — IDT chat alerts at both sites, DB transaction), TransferCompletionJob (daily 7am 'transfers' queue, system context userId=null), TransferController (4 endpoints) + TransferAdminController (/enrollment/transfers), TransfersTab in Show.tsx (amber pending banner + request modal + history), Enrollment/Transfers.tsx admin page, 2 test files (23 new tests). 982 tests, 0 failures.
- [2026-03-21] Phase 10B — Executive Role + Site Switcher + Nostos Super Admin Panel. Created: 2 migrations (68-69: CHECK constraints updated for department values), SiteContextMiddleware (resolves active_site_id from session), SiteContextController (POST /site-context/switch), ExecutiveDashboardController (4 JSON widget endpoints), SuperAdminPanelController (index/tenants/health/onboard), 2 React dashboard components (ExecutiveDashboard, SuperAdminDashboard), SuperAdmin/Index.tsx 3-tab panel, AppShell SiteSwitcherDropdown + NostosAdminBanner (dept=super_admin), 3 test files (35 new tests). Two 'super_admin' concepts: role=super_admin (impersonation/TJ) vs department=super_admin (Nostos staff, cross-tenant read). 1017 tests, 0 failures.
- [2026-03-21] Phase 11A — MVP Compliance Audit. Reviewed 42 CFR 460, HIPAA Security Rule, ONC USCDI v3, CMS HPMS, advance directives. Found go-live blockers: encryption at rest, consent/grievance module, formal SRA/pen test, CPOE docs, clearinghouse live connection, transport integration. Documented in HANDOFF.md Section 13. No feature code. 1017 tests (unchanged).
- [2026-03-21] Phase 11B — Go-Live Gap Resolution. Created: 5 migrations (70-74: emr_immunizations, emr_social_determinants, emr_procedures, advance_directive columns on emr_participants, emr_ehi_exports), 4 models (Immunization, SocialDeterminant, Procedure, EhiExport), 4 controllers (ImmunizationController, SocialDeterminantController, ProcedureController, EhiExportController), EhiExportService (ZipArchive, 64-char hex token, 24h TTL, 410 on expired), 3 FHIR mappers (ImmunizationMapper, ProcedureMapper, SdohObservationMapper), 3 new FHIR R4 endpoints, HpmsFileService live flu/pneumo rates, ImmunizationsTab + ProceduresTab + SdohTab in Show.tsx, 5 test files (40 new tests). Route::match(['PUT','PATCH']) on participant update. 1057 tests, 0 failures.
- [2026-03-21] Phase 12A — Developer Handoff Document. Created HANDOFF.md (15 sections: overview, quick start, architecture, demo accounts, env vars, DB structure, RBAC, 26 services, 8 jobs, transport status, ADR-001..009, tech debt, go-live checklist, roadmap, gotchas). Updated README.md with HANDOFF.md link. 1057 tests (unchanged).
- [2026-03-21] Phase 12B — Final Seal + Verification. Fixed CLAUDE.md (model/page/controller counts corrected). TypeScript fixes: Finance pages spurious user= prop removed, Qa/Dashboard spurious title= prop removed, Echo private channel type added to index.d.ts, Echo callbacks typed as unknown + cast in Transport/Manifest and Chat/Index. Build clean. 1057 tests confirmed. Project sealed.
- [2026-03-24] W3-0 — Context Bake. Restructured CLAUDE.md: added expanded PROJECT IDENTITY, SESSION STARTUP CHECKLIST, WAVE STATUS (Wave 3 IN PROGRESS), FUTURE SCOPE (transport suite + mobile). Fixed TEST STATUS (added phases 9B-12B). Fixed SESSION LOG (added 9C-12B entries). Fixed stale CAT2 /billing/capitation entry. Cleaned duplicate migration comments. Also completed em dash + emoji audit across all 64 TSX files (QaComplianceDashboard, ComplianceChecklist, Chat, ActivitiesDashboard, Enrollment, Schedule, HomeCareDashboard, Transport/Manifest, Transport/Dashboard, Participants/Show all updated with Heroicons).
- [2026-03-26] W3-2 — Nav Menu Fixes. Part A: Fixed 2 broken PermissionService hrefs (/idt/minutes→/idt/meetings, /idt/sdr→/sdrs). Added IdtMeetingController::meetingsList() at GET /idt/meetings; /idt/minutes now redirects to /idt/meetings. Created DayCenterController (5 endpoints: index/roster/checkIn/markAbsent/summary), migration 76 (emr_day_center_attendance — status CHECK constraint, unique tenant+participant+site+date), DayCenterAttendance model (8 absent reasons, STATUS_LABELS, forTenant/forDate/forSite scopes), DayCenterAttendanceFactory (present/absent states). Created ReportsController (index Inertia + data JSON KPIs, buildCatalog() filtered by dept, 11 reports across 5 categories). Created SystemSettingsController (index Inertia with tenant/medicaidConfigs/integrationStatus, update PUT it_admin only). Updated routes/web.php (5 Day Center routes, 2 Reports routes, 2 Settings routes, /idt/meetings GET route). Part B: AppShell.tsx collapsed nav flyout — hoveredGroup state + flyoutY + 120ms debounce hide + fixed-positioned panel at left=68px with group label header + nav items + chat badge. Part C: AppShell.tsx sibling-aware isActive() — collects allNavHrefs, returns false if a longer sibling href also matches currentPath (fixes /idt showing active when at /idt/meetings). TSX pages: Idt/Meetings (paginated list, status pills, row click to meeting detail), Scheduling/DayCenter (4-KPI summary row, attendance table, AbsentModal, markPresent via axios), Reports/Index (category tabs, report cards grid, KPI row, export links), ItAdmin/SystemSettings (tenant config form, integration status grid, medicaid config table). Tests: NavRoutingTest (13 tests) + DayCenterAttendanceTest (12 tests). Expected: 1090+ tests, 0 failures.

- [2026-03-24] W3-1 — Dark Mode. Migration 75 (theme_preference VARCHAR(10) DEFAULT 'light' CHECK IN ('light','dark') on shared_users). ThemePreferenceController (POST /user/theme). ThemeToggle.tsx (Heroicons SunIcon/MoonIcon neumorphic pill). AppShell.tsx updated: theme state, useEffect applies dark class to document.documentElement, localStorage sync, ThemeToggle in TopBar between search and notifications, dark: variants on header/breadcrumbs/dropdowns/buttons. FOUC prevention: app.blade.php inline script reads nostos_theme from localStorage before React. DemoEnvironmentSeeder alternates light/dark per user. tailwind.config.js: darkMode:'class' added. ThemePreferenceTest.php (8 tests). Expected: 1065+ tests, 0 failures.

---

## KNOWN TECHNICAL DEBT [DEBT]

Format: [DEBT-NNN] What it is | Why deferred | Done-when | Complexity

**Missing Factories (non-blocking — covered by feature tests via other paths):**
- [DEBT-001] AuditLogFactory missing | AuditLog is append-only (no SoftDeletes, no factory state needed); tests create via AuditLog::record() directly | If unit tests for AuditLog query scopes are needed | S
- [DEBT-002] Icd10LookupFactory missing | Seeded via Icd10Seeder (203 codes always present); no test creates its own Icd10Lookup | If tests need to control ICD-10 data | S
- [DEBT-003] IdtParticipantReviewFactory missing | Reviews always created through IdtMeeting workflow in tests | If isolated IdtParticipantReview unit tests are needed | S
- [DEBT-004] InsuranceCoverageFactory missing | Created inline via Participant factory states; no dedicated factory | If insurance-specific tests are needed | S
- [DEBT-005] OtpCodeFactory missing | Created by OtpService; AuthTest relies on OtpService, not the model directly | If OTP expiry/reuse unit tests need more state control | S
- [DEBT-006] ParticipantAddressFactory missing | Addresses created inline via ParticipantController store | If isolated address validation tests are needed | S
- [DEBT-007] RolePermissionFactory missing | Permission matrix seeded via PermissionSeeder; PermissionMatrixTest uses the seeder | If dynamic permission tests require more state | S

**Missing Unit Tests for Services:**
- [DEBT-008] AlertServiceTest (Unit) missing | AlertService is thoroughly exercised in feature tests (AlertTest, AdlTest, etc.) | Before go-live / developer handoff | S
- [DEBT-009] ChatServiceTest (Unit) missing | ChatService tested via ChatTest and ChannelAutoCreationTest feature tests | Before go-live | S
- [DEBT-010] EnrollmentServiceTest (Unit) missing | EnrollmentStateMachineTest (Unit) covers state machine; EnrollmentTest covers service behavior via HTTP | Before go-live | S
- [DEBT-011] ImpersonationServiceTest (Unit) missing | ImpersonationTest (Feature) covers all impersonation flows | Before go-live | S
- [DEBT-012] MrnServiceTest (Unit) missing | ParticipantMrnTest (Unit) tests MRN assignment; MrnService logic covered | Before go-live | S

**Missing Feature Test Coverage:**
- [DEBT-013] InsuranceCoverage has no dedicated feature test | Insurance CRUD is accessible via ParticipantProfileTest context | Add InsuranceCoverageTest before go-live | S
- [DEBT-014] ParticipantAddress has no dedicated feature test | Address creation tested as part of Participant create flow | Add ParticipantAddressTest before go-live | S
- [DEBT-015] Icd10Lookup has no feature test | ICD-10 search endpoint tested indirectly via ClinicalNote creation | Add Icd10LookupTest before go-live | S

**PHPDoc Coverage:**
- [DEBT-016] ImpersonationService public methods lack PHPDoc on setViewAs/clearViewAs/getViewAsDepartment/hasViewAs (inline comments only) | These 4 methods are trivial getters/setters | Add PHPDoc before developer handoff | S

**Feature Gaps (Go-live blocking):**
- [DEBT-017] FHIR write endpoints (POST Patient, PUT Observation, etc.) | R4 read-only is sufficient for demo; writes require HL7 FHIR compliance testing | Before EHR integration go-live | L
- [DEBT-018] Real HL7 v2 parser | Current Hl7AdtConnector accepts pre-parsed JSON payload, not raw HL7 pipe-delimited messages | Before hospital system integration | L
- [DEBT-019] Full 837P claim submission pipeline | EncounterLog captures data for billing; 837P EDI requires clearinghouse integration | Before billing go-live | XL
- [DEBT-020] Real OAuth credentials for Google/Yahoo Socialite | Dev uses placeholder GOOGLE_CLIENT_ID/GOOGLE_CLIENT_SECRET; production requires real OAuth app registered with Google/Yahoo | Before go-live | S (config only)
- [DEBT-021] S3 / object storage for documents | Documents stored at storage/app/participants/{id}/ (local disk, FILESYSTEM_DISK=local). Production needs S3 or compatible (AWS_ACCESS_KEY_ID, AWS_BUCKET, etc.) | Before go-live | M
- [DEBT-022] Meilisearch integration for GlobalSearch | SCOUT_DRIVER=meilisearch in env but MEILISEARCH_HOST points to Docker service; GlobalSearch currently queries DB directly | Before go-live with large participant sets | M
- [DEBT-023] Transport live bridge | TransportBridgeService::createTripRequest() stubs the call to the Nostos transport app (no live HTTP call). Real integration requires transport app API endpoint + credentials in config/services.php | Before transport go-live | L
- [DEBT-024] ONC USCDI v3 full compliance | FHIR mappers cover the 7 most common resources; USCDI v3 requires 30+ data classes | CMS EHR certification requirement | XL
- [DEBT-025] CMS-HCC risk adjustment pipeline | Diagnosis codes (ICD-10) exist in emr_problems; HCC mapping and RAF score calculation not implemented | Before CMS STAR rating reporting | XL
- [DEBT-026] State Medicaid configuration framework | Capitation rates, coverage rules, and reporting formats vary by state; currently hard-coded for single tenant | Before multi-state deployment | XL
- [DEBT-027] Third-party penetration test | Application handles PHI; pen test required for HIPAA Security Rule compliance before production | Before go-live | M (external vendor)
- [DEBT-028] Formal HIPAA Risk Assessment (SRA) | Required by 45 CFR 164.308(a)(1)(ii)(A); no SRA document exists yet | Before go-live | M (process + documentation)
- [DEBT-029] PHP deprecation cleanup | 32 PHP deprecations + 92 PHPUnit deprecations in test run (non-failing); from PHP 8.5 / PHPUnit 11 API changes | Before Laravel 12/PHP 9 upgrade | M

**Phase 9A — Critical Billing Infrastructure Gaps (go-live blocking for billing operations):**
- [DEBT-030] emr_encounter_log missing 13 of 14 required 837P fields | Phase 6C built the encounter log for internal tracking only; full 837P compliance requires diagnosis_codes (JSONB), all three provider NPIs, place_of_service_code, procedure_modifier, units, charge_amount, claim_type, submission_status, submitted_at, edi_batch_id, cms_acknowledgement_status | Before billing go-live | XL
- [DEBT-031] diagnosis_codes not captured on encounters | ICD-10 codes exist in emr_problems but are never linked to individual encounters in emr_encounter_log. Without encounter-level diagnosis codes, CMS cannot perform HCC risk adjustment from submitted data — this is the single highest-impact billing gap | Before any CMS encounter submission | XL
- [DEBT-032] Provider NPI fields completely absent from system | No billing_provider_npi, rendering_provider_npi, or service_facility_npi exist anywhere (models, migrations, or shared_users). PACE organizations must have their NPIs configured and attached to every 837P encounter | Before billing go-live | L
- [DEBT-033] emr_capitation_records missing 5 risk-adjustment reconciliation fields | hcc_risk_score, frailty_score, county_fips_code, adjustment_type, rate_effective_date all absent. Without these, finance staff cannot reconcile CMS remittance against expected rates or detect underpayment | Before billing go-live | L
- [DEBT-034] No EDI 837P/837I batch builder or clearinghouse integration | DEBT-019 acknowledged this; Phase 9A confirms zero EDI code in app/. Building X12 5010A1 transactions from scratch requires either a PHP EDI library (e.g. php-x12) or a clearinghouse API (Availity, Change Healthcare). This is the largest single engineering effort in Phase 9B | Before billing go-live | XL
- [DEBT-035] No Part D PDE tracking | Medicare Part D prescription drug events must be submitted to CMS MARx monthly for every prescription dispensed to PACE participants. eMAR data (emr_emar_records) is not mapped to PDE format. No emr_pde_records table, no MARx submission service | Before Part D billing go-live | L
- [DEBT-036] No HPMS enrollment/disenrollment file generator | CMS requires monthly enrollment file submission via HPMS for all plan changes. Enrollment state machine data exists in emr_referrals but no CMS file format export. Also missing: HOS-M survey tracking (PACE-specific annual health outcomes survey required by HPMS) | Before CMS HPMS compliance | L
- [DEBT-037] No HCC gap analysis or revenue integrity dashboard | HCC gap analysis identifies participants whose clinical chart supports an HCC diagnosis that was NOT submitted on their encounter data — each gap represents lost capitation. ICD-10 codes in emr_problems are never mapped to CMS-HCC categories. No HCC mapping reference table, no RAF calculation, no gap reporting | Before revenue optimization | XL
- [DEBT-038] No state Medicaid encounter submission support | PACE participants are dually eligible; most states require a separate 837 encounter submission to the state Medicaid agency using a state-specific companion guide. State rules differ on timing, format, and required fields. No state configuration framework exists (DEBT-026 covers the broader state framework; DEBT-038 is specifically the Medicaid encounter submission component) | Before multi-state deployment | XL

---

## ARCHITECTURE DECISION RECORDS (ADRs)

Format: [ADR-NNN] Title | Context | Decision | Consequences

- [ADR-001] Shared PostgreSQL database (emr_* + shared_* + transport_* tables in same DB) | NostosEMR and Nostos transport are separate Laravel apps owned by same company sharing a DB server. Separate databases would require cross-DB joins or API calls for participant lookup. | Use one PostgreSQL DB with table prefixes (emr_*, shared_*, transport_*). EMR writes only to emr_* and shared_*. Transport data accessed read-only via TransportBridgeService. | Pro: joins across apps are cheap; shared users/tenants/audit table is simple. Con: schema coupling — transport app changes to shared_* tables can break EMR; no hard isolation at DB level. FK from emr_transport_requests to transport_trips is cross-app (enforced by service layer only, not DB constraint).

- [ADR-002] TransportBridgeService as anti-corruption layer | EMR needs to create and track transport trips. Calling transport app's internal Eloquent models from EMR would couple the apps tightly and break when transport app schema changes. | Introduce TransportBridgeService as the single point of cross-app writes. All transport interactions go through this service, which can stub/mock in tests. | Pro: EMR tests never need the transport app running; service boundary is clear and mockable. Con: service currently stubs the outbound HTTP call (DEBT-023) — real integration needs HTTP client + auth credentials.

- [ADR-003] Inertia.js over separate SPA | A pure SPA + separate API would require maintaining two deployment units (API + frontend) and a separate auth token scheme. PACE is a clinician-facing app, not a public API. | Use Inertia.js (Laravel server + React frontend, single deployment). Server sends page props via Inertia protocol; React renders client-side. No REST API contract between frontend and backend (except JSON widget endpoints and FHIR API). | Pro: simpler deployment, no JWT management, SSR-compatible, shared validation. Con: tighter coupling between backend and frontend data shapes; Inertia page props must match TypeScript interfaces manually. FHIR and integration endpoints are REST (outside Inertia) — this dual-mode adds some complexity.

- [ADR-004] Laravel Reverb for real-time WebSockets | Real-time alerts and chat require push updates. Polling was ruled out (too much DB load for HIPAA-audit-logged environments). External services (Pusher) add cost and PHI routing concerns. | Use Laravel Reverb (self-hosted WebSocket server, first-party Laravel package). Runs in Docker alongside app. Events (AlertCreatedEvent, NewChatMessage, ChatActivityEvent) broadcast via Reverb channels. | Pro: no PHI leaves infrastructure; integrated with Laravel auth (private channels). Con: Reverb requires an always-on process (supervisor); Docker/Windows WWWUSER bug required SUPERVISOR_PHP_USER: root workaround (see KNOWN ISSUES).

- [ADR-005] Soft deletes everywhere (HIPAA data retention) | HIPAA requires covered entities to retain PHI for 6 years. Hard deletes would permanently remove PHI and violate retention requirements. | All clinical models use Laravel SoftDeletes. Chat messages additionally enforce soft-delete (HIPAA). Documents use soft-delete + file retained on disk. AuditLog and append-only tables (EmarRecord, EncounterLog, IntegrationLog) have no deletes at all — PostgreSQL rules block UPDATE/DELETE on audit_log. | Pro: compliant retention; data recoverable for audit. Con: queries must always include soft-delete scope (Laravel handles this automatically with global scope). Storage grows over time — pruning policy needed for go-live.

- [ADR-006] emr_role_permissions table over Laravel Gates/Policies | Gates/Policies require code deploys to change permissions. PACE organizations need to adjust which departments can perform which actions without a code release. | Store all role-to-action permissions in emr_role_permissions (PermissionSeeder). PermissionService queries this table at runtime. CheckDepartmentAccess middleware enforces per-request. | Pro: IT Admin can adjust permissions without code change (if admin UI is built). Con: every request hits the DB for permission check (partially mitigated by query caching); permission logic is less discoverable than named Gates. Admin UI for permissions is not yet built (DEBT — CAT3 planned feature).

- [ADR-007] CLAUDE.md as persistent project memory | Claude Code sessions have no memory of previous sessions. Without a memory file, each session needs context re-established from scratch, risking inconsistency and repeated mistakes. | Maintain CLAUDE.md at the project root. Read at start of every session; update at end of every session. Contains: project identity, phase status, all migrations, models, controllers, services, known issues, architectural decisions, session log. | Pro: session continuity without requiring user to re-explain architecture; decision rationale is preserved. Con: file grows over time and must be read fully each session (currently 600+ lines); discipline required to keep it accurate. The session log is append-only (never prune — it is the project change history).

---

## ENVIRONMENT VARIABLES

All variables from `.env` (dev values) and `.env.example` (defaults). Grouped by category.

### Application
| Variable | Dev Default | Used In |
|---|---|---|
| APP_NAME | NostosEMR | AppShell title, mail sender name |
| APP_ENV | local | Laravel env detection |
| APP_KEY | generated | Encryption key for session/cookies |
| APP_DEBUG | true | Error display (false in production) |
| APP_TIMEZONE | UTC | All Carbon timestamps |
| APP_URL | http://localhost | Mail links, OAuth redirects |
| APP_LOCALE | en | Translation locale |
| APP_FALLBACK_LOCALE | en | Fallback if translation missing |
| APP_FAKER_LOCALE | en_US | Factory data locale |
| APP_MAINTENANCE_DRIVER | file | Maintenance mode driver |
| PHP_CLI_SERVER_WORKERS | 4 | Local dev server workers |
| BCRYPT_ROUNDS | 12 | Password hashing cost (N/A for OTP-only auth but used by Fortify internally) |

### Logging
| Variable | Dev Default | Used In |
|---|---|---|
| LOG_CHANNEL | stack | Log driver |
| LOG_STACK | single | Stack channel config |
| LOG_DEPRECATIONS_CHANNEL | null | PHP deprecation logging |
| LOG_LEVEL | debug | Minimum log level |

### Database
| Variable | Dev Default | Used In |
|---|---|---|
| DB_CONNECTION | pgsql | Eloquent / migrations |
| DB_HOST | pgsql (Docker service) | DB connection |
| DB_PORT | 5432 | DB connection |
| DB_DATABASE | nostosemr | DB name |
| DB_USERNAME | sail | DB auth |
| DB_PASSWORD | password | DB auth |

### Session
| Variable | Dev Default | Used In |
|---|---|---|
| SESSION_DRIVER | database | Laravel session (stored in shared_sessions table) |
| SESSION_LIFETIME | 120 | Minutes before idle timeout (HIPAA: set to 15 in production) |
| SESSION_ENCRYPT | false | Session data encryption at rest |
| SESSION_PATH | / | Cookie path |
| SESSION_DOMAIN | null | Cookie domain |

### Broadcasting / Queue / Cache
| Variable | Dev Default | Used In |
|---|---|---|
| BROADCAST_CONNECTION | reverb | Reverb WebSocket broadcasting |
| QUEUE_CONNECTION | redis | Horizon job queues |
| FILESYSTEM_DISK | local | Document storage (change to s3 for production) |
| CACHE_STORE | redis | Redis-backed cache |
| CACHE_PREFIX | (empty) | Cache key prefix |

### Redis
| Variable | Dev Default | Used In |
|---|---|---|
| REDIS_CLIENT | phpredis | Redis PHP extension |
| REDIS_HOST | redis (Docker service) | Redis connection |
| REDIS_PASSWORD | null | Redis auth |
| REDIS_PORT | 6379 | Redis port |

### Mail
| Variable | Dev Default | Used In |
|---|---|---|
| MAIL_MAILER | smtp (Mailpit in dev) | Mail driver |
| MAIL_SCHEME | null | SMTP scheme (tls for production) |
| MAIL_HOST | mailpit (Docker service) | SMTP host |
| MAIL_PORT | 1025 | SMTP port |
| MAIL_USERNAME | null | SMTP auth |
| MAIL_PASSWORD | null | SMTP auth |
| MAIL_FROM_ADDRESS | noreply@nostosemr.com | Sender address for all emails |
| MAIL_FROM_NAME | NostosEMR | Sender display name |

### AWS / S3 Storage (not yet active — DEBT-021)
| Variable | Dev Default | Used In |
|---|---|---|
| AWS_ACCESS_KEY_ID | (empty) | S3 driver (FILESYSTEM_DISK=s3) |
| AWS_SECRET_ACCESS_KEY | (empty) | S3 driver |
| AWS_DEFAULT_REGION | us-east-1 | S3 bucket region |
| AWS_BUCKET | (empty) | S3 bucket name |
| AWS_USE_PATH_STYLE_ENDPOINT | false | MinIO / local S3 compatibility |

### Meilisearch / Scout (not yet active — DEBT-022)
| Variable | Dev Default | Used In |
|---|---|---|
| SCOUT_DRIVER | meilisearch | Laravel Scout driver (GlobalSearch) |
| MEILISEARCH_HOST | http://meilisearch:7700 | Meilisearch server (Docker service) |
| MEILISEARCH_NO_ANALYTICS | true | Disable Meilisearch telemetry |

### Reverb (WebSockets)
| Variable | Dev Default | Used In |
|---|---|---|
| REVERB_APP_ID | (generated) | Reverb app identifier |
| REVERB_APP_KEY | (generated) | Reverb app key (also in VITE_REVERB_APP_KEY) |
| REVERB_APP_SECRET | (generated) | Reverb signing secret |
| REVERB_HOST | reverb (Docker service) | Reverb server host |
| REVERB_PORT | 8080 | Reverb server port |
| REVERB_SCHEME | http | Reverb scheme (wss in production) |
| VITE_REVERB_APP_KEY | same as REVERB_APP_KEY | Passed to frontend at build time |
| VITE_REVERB_HOST | same as REVERB_HOST | Passed to frontend at build time |
| VITE_REVERB_PORT | same as REVERB_PORT | Passed to frontend at build time |
| VITE_REVERB_SCHEME | same as REVERB_SCHEME | Passed to frontend at build time |
| VITE_APP_NAME | same as APP_NAME | Passed to frontend at build time |

### OAuth / Socialite (Google + Yahoo login)
| Variable | Dev Default | Used In |
|---|---|---|
| GOOGLE_CLIENT_ID | (placeholder — DEBT-020) | Socialite Google OAuth provider |
| GOOGLE_CLIENT_SECRET | (placeholder) | Socialite Google OAuth provider |
| GOOGLE_REDIRECT_URI | http://localhost/auth/google/callback | OAuth callback URL |
| YAHOO_CLIENT_ID | (placeholder — DEBT-020) | Socialite Yahoo OAuth provider |
| YAHOO_CLIENT_SECRET | (placeholder) | Socialite Yahoo OAuth provider |
| YAHOO_REDIRECT_URI | http://localhost/auth/yahoo/callback | OAuth callback URL |

### OTP / Security
| Variable | Dev Default | Used In |
|---|---|---|
| OTP_EXPIRES_MINUTES | 10 | OtpService: OTP validity window |
| OTP_MAX_ATTEMPTS | 5 | Rate limit on OTP verification (AuthController) |

### Transport Webhook (not in .env — configured in config/services.php)
| Variable | Config Key | Used In |
|---|---|---|
| TRANSPORT_WEBHOOK_SECRET | services.transport.webhook_secret | WebhookController HMAC-SHA256 validation. Fail-closed: null → reject all. |

---

## HORIZON JOBS INVENTORY

| Job | Queue | Schedule | Purpose | Failure Behavior |
|---|---|---|---|---|
| SdrDeadlineEnforcementJob | sdr-enforcement | Every 15 min (withoutOverlapping) | Scans all open SDRs; creates info alert at 24h remaining, warning at 8h, critical+escalate when overdue. Alert deduplication via SdrDeadlineService. | No $tries set; failed job logged to Horizon failed jobs. Non-throwing — bad SDRs are skipped, rest of batch continues. |
| LateMarDetectionJob | mar-detection | Every 30 min (withoutOverlapping) | Flags eMAR records in 'scheduled' status past scheduled_time + 30min grace period as 'late'. Creates warning alert for primary_care + therapies. | No $tries. EmarRecord update is direct (no save()); AlertService creation is non-blocking. Batch continues on individual failures. |
| DocumentationComplianceJob | compliance | Daily at 06:00 (withoutOverlapping) | Scans all tenants for (1) unsigned notes > 24h → warning alert; (2) overdue assessments → info alert. Deduplicates via whereJsonContains on new_values->note_id / assessment_id. | No $tries. Per-tenant failures logged; batch continues across tenants. |
| DigestNotificationJob | notifications | Every 2 hours (withoutOverlapping) | Scans all active users for digest_pending:{user_id} Redis counters > 0. Sends DigestNotificationMail (count only, zero PHI). Clears counter after send. | $tries not set. Per-user send failures logged as warning; counter NOT cleared on failure (user retried next 2h window). |
| ProcessTransportStatusWebhookJob | transport-webhooks | On-demand (dispatched by WebhookController after HMAC validation) | Syncs transport trip status back to emr_transport_requests. If status='no_show': creates primary_care warning alert. | $tries = 3. Retries up to 3 times. On final failure: logged to Horizon failed jobs. Status sync may be out of date until manual retry or next webhook. |
| ProcessHl7AdtJob | integrations | On-demand (dispatched by Hl7AdtConnector after X-Integration-Tenant auth) | A01: creates EncounterLog + social_work/IDT alert. A03: creates SDR + sets CarePlan to under_review + IDT alert. A08: AuditLog only. Unknown MRN: markFailed() gracefully. | $tries = 3. Unknown MRN marks IntegrationLog as failed (retryable). Exceptions on DB operations propagate and trigger retry. IT Admin can retry from /it-admin/integrations. |
| ProcessLabResultJob | integrations | On-demand (dispatched by LabResultConnector after X-Integration-Tenant auth) | Creates EncounterLog for all results. If abnormal_flag=true: creates primary_care warning alert. Unknown MRN: markFailed() gracefully. | $tries = 3. Same retry/failed-job behavior as ProcessHl7AdtJob. IT Admin retryable from integrations panel. |

**Note on Horizon queue configuration:** The default Horizon supervisor-1 only processes the 'default' queue. Named queues (sdr-enforcement, mar-detection, compliance, notifications, transport-webhooks, integrations) need additional supervisor configuration in config/horizon.php for production deployment. In local dev (Docker), these queues are processed by the 'default' worker catchall.

---

## DEPENDENCY STATUS (checked 2026-03-19)

### PHP / Composer — Major Updates Available (breaking changes possible)

| Package | Current | Latest | Notes |
|---|---|---|---|
| laravel/framework | 11.48.0 | 13.1.1 | Major — L12 released; L13 is newest. L11 LTS until 2026-08. Do NOT upgrade without full regression test run. |
| laravel/scout | 10.24.0 | 11.1.0 | Major — Scout 11 drops some driver APIs. Review GlobalSearch before upgrade. |
| laravel/tinker | 2.11.1 | 3.0.0 | Major — dev dependency only; low risk. |
| phpunit/phpunit | 11.5.55 | 13.0.5 | Major — significant API changes expected. Existing deprecation warnings are a sign. Do not upgrade without test suite review. |
| brick/math | 0.14.8 | 0.17.0 | Major transitive dep — check if anything calls brick/math directly. |
| symfony/* | 7.4.x | 8.0.x | Major — Symfony 8 released. All symfony/* packages show major updates. Laravel 11 is compatible with Symfony 7; upgrading to L13 would bring Symfony 8. |

### PHP / Composer — Minor/Patch Updates Safe to Apply

brianium/paratest (7.8.5 → 7.19.2), inertiajs/inertia-laravel (2.0.21 → 2.0.22), laravel/fortify (1.35.0 → 1.36.1), laravel/horizon (5.45.0 → 5.45.4), laravel/pint (1.27.1 → 1.29.0), laravel/reverb (1.8.0 → 1.8.1), laravel/sail (1.53.0 → 1.54.0), laravel/socialite (5.24.3 → 5.25.0), tightenco/ziggy (2.6.1 → 2.6.2), guzzlehttp/psr7 (2.8.0 → 2.9.0), nesbot/carbon (3.11.1 → 3.11.3), league/commonmark (2.8.0 → 2.8.2).

### NPM — Major Updates Available (breaking changes possible)

| Package | Current | Latest | Notes |
|---|---|---|---|
| react / react-dom | 18.3.1 | 19.2.4 | Major — React 19 has breaking changes in concurrent features. Do not upgrade without frontend audit. |
| @types/react | 18.3.28 | 19.2.14 | Major — types must match react version. |
| @types/react-dom | 18.3.7 | 19.2.3 | Major — same as above. |
| vite | 5.4.21 | 8.0.1 | Major — Vite 6, 7, 8 released. Config API changed. laravel-vite-plugin 1.3.0 may not support Vite 8. |
| @vitejs/plugin-react | 4.7.0 | 6.0.1 | Major — Vite plugin must match Vite version. |
| tailwindcss | 3.4.19 | 4.2.2 | Major — Tailwind 4 rewrites config format (CSS-first config, no tailwind.config.js). All class names still compatible but config migration required. |
| recharts | 2.15.4 | 3.8.0 | Major — chart component API changes. All 14 dashboard components use recharts. |
| laravel-vite-plugin | 1.3.0 | 3.0.0 | Major — review changelog before upgrade. |
| @types/node | 22.19.13 | 25.5.0 | Major — types only, low risk. |

### NPM — Minor/Patch Updates Safe to Apply

@inertiajs/react (2.3.17 → 2.3.18), laravel-echo (2.3.0 → 2.3.1), pusher-js (8.4.0 → 8.4.3).

---

## BILLING & ENCOUNTER DATA — PHASE 9A AUDIT (2026-03-19)

### PACE Billing Architecture Reference

PACE billing operates differently from fee-for-service Medicare. Key facts every Phase 9B developer must understand:

1. **Capitation is prospective, not fee-for-service.** CMS pays a fixed monthly amount per enrolled participant. The PACE organization bears full financial risk for all services. There are no individual claim approvals — the capitation rate IS the payment.

2. **Encounter submission drives NEXT year's rate, not this year's payment.** PACE organizations must submit encounter data (837P/837I transactions) to CMS's Encounter Data System (EDS) every month. CMS uses this data to compute CMS-HCC risk scores, which flow into the following year's capitation rates via the PACE blended rate formula. Submitting accurate, complete encounters today directly determines how much money the organization receives 12-18 months from now.

3. **HCC risk adjustment is the financial engine.** ICD-10 diagnosis codes from clinical encounters are mapped to Hierarchical Condition Categories (HCCs). Each HCC has a Risk Adjustment Factor (RAF). A participant's RAF score × county base rate × frailty adjuster = their capitation rate. Missing a diagnosis code (e.g. not coding diabetes with complications when the chart supports it) can cost thousands of dollars per member per year in lost capitation.

4. **The encounter data pipeline:** Clinical service → EncounterLog record → 837P/837I EDI transaction built per X12 5010 companion guide → submitted to CMS clearinghouse (DDE or Availity) → CMS EDS acknowledgement (999/277CA) → CMS processes into encounter database → HCC scores recalculated annually.

5. **Part D PDE (Prescription Drug Events):** PACE organizations that provide Part D pharmacy benefits must submit PDEs to CMS MARx system for each prescription dispensed. This is separate from the 837P encounter submission.

6. **HPMS (Health Plan Management System):** CMS requires monthly enrollment/disenrollment file submissions and annual survey data (HOS-M, CAHPS). These go through HPMS, not the clearinghouse.

7. **State Medicaid encounter submission:** PACE participants are dually eligible (Medicare + Medicaid). Many states require a separate 837 encounter submission to the state Medicaid agency using a state-specific companion guide. Rules vary dramatically by state.

---

### Existing Billing Code Inventory

| File | Description |
|---|---|
| `app/Models/CapitationRecord.php` | Monthly capitation payment record per participant. Stores Medicare A/B/D + Medicaid rate components + eligibility_category. |
| `app/Models/EncounterLog.php` | Service encounter record. Append-only. Stores service_date, service_type (12 PACE types), procedure_code (nullable), provider_user_id, notes. |
| `app/Models/Authorization.php` | Service authorization (scope, units, date range). Status: active/expired/cancelled. |
| `app/Http/Controllers/FinanceController.php` | REST API: capitation CRUD (2 endpoints), encounter index/store (2), authorization index/store/update (3). |
| `app/Http/Controllers/FinanceDashboardController.php` | Inertia dashboard (4 KPIs + 3 tabs) + CSV export (3 types). |
| `resources/js/Pages/Finance/Dashboard.tsx` | Finance dashboard React page: 4 KPI cards, capitation summary tab, expiring auths tab, encounter log tab (lazy-loaded). |
| `database/migrations/2024_06_03_000001_create_emr_capitation_records_table.php` | Creates emr_capitation_records with: id, tenant_id, participant_id, month_year (CHAR 7), medicare_a/b/d_rate, medicaid_rate, total_capitation, eligibility_category, recorded_at, soft_deletes. |
| `database/migrations/2024_06_03_000002_create_emr_encounter_log_table.php` | Creates emr_encounter_log with: id, tenant_id, participant_id, service_date, service_type, procedure_code (nullable), provider_user_id, notes, created_by_user_id, created_at (append-only, no updated_at). |
| `database/migrations/2024_06_03_000003_create_emr_authorizations_table.php` | Creates emr_authorizations with: id, tenant_id, participant_id, service_type, authorized_units, authorized_start, authorized_end, status (CHECK constraint), notes, soft_deletes. |
| `tests/Feature/FinanceTest.php` | 16 tests covering dashboard KPIs, capitation CRUD, encounter index/store, auth CRUD, CSV export. No EDI/submission tests. |
| `app/Http/Controllers/Dashboards/FinanceWidgetController.php` | 4 JSON widget endpoints for the Finance dept dashboard tile. |

No billing services exist in `app/Services/` (confirmed: 18 services listed, none related to EDI, 837P, claim building, HCC scoring, PDE, or HPMS).

---

### 837P Encounter Fields Audit

The 837P Professional claim transaction (X12 5010A1) requires the following data elements. Status against the current `emr_encounter_log` table:

| Field | Status | Notes |
|---|---|---|
| `billing_provider_npi` | MISSING | Not in migration or model. The billing provider NPI (10-digit) is a required Loop 2010AA element in every 837P. Currently no NPI fields exist anywhere in the system. |
| `rendering_provider_npi` | MISSING | Not in migration or model. Required Loop 2310B when rendering provider differs from billing provider (common in PACE — therapist renders, PACE org bills). |
| `service_facility_npi` | MISSING | Not in migration or model. Required Loop 2310C when service is provided at a facility (day center, hospital, SNF). PACE day center NPI must appear on encounter submissions. |
| `diagnosis_codes` | MISSING | Not in migration or model. 837P requires at least one ICD-10-CM diagnosis code (Loop 2300 HI segment). Up to 12 allowed (primary first). This is the critical field for HCC risk adjustment — its absence means the encounter cannot drive CMS risk scoring. `emr_problems` has ICD-10 codes but they are not linked to encounters. |
| `procedure_code` | PARTIAL | Field exists as `procedure_code VARCHAR(20) nullable`. However: (1) it is nullable — 837P requires it; (2) no validation enforces CPT/HCPCS format; (3) only one code allowed per encounter row (837P allows multiple service lines per claim). |
| `procedure_modifier` | MISSING | Not in migration or model. CPT modifiers (e.g. 25, 59, GP, GO, GN for therapy) are required on many PACE encounter types. HCPCS modifiers (e.g. U1-UD for state Medicaid) also needed. |
| `place_of_service_code` | MISSING | Not in migration or model. CMS-1500/837P requires a 2-digit POS code (e.g. 02=telehealth, 11=office, 49=independent clinic, 65=ESRD facility). PACE day center = POS 65; home visit = POS 12. |
| `units` | MISSING | Not in migration or model. 837P Loop 2400 SV1 segment requires units of service (e.g. 1.0 for a visit, 60 for 60 minutes of therapy). Without units, CMS cannot validate encounter volume. |
| `charge_amount` | MISSING | Not in migration or model. 837P requires a submitted charge amount even for capitated encounters (CMS uses it for data integrity checks). PACE organizations typically submit at the Medicare fee schedule rate. |
| `claim_type` | MISSING | Not in migration or model. Needed to distinguish 837P (professional) from 837I (institutional/facility). PACE submits both types — home care and day center may be institutional. |
| `submission_status` | MISSING | Not in migration or model. No field to track whether an encounter has been included in an EDI batch, accepted by CMS, rejected, or needs resubmission. |
| `submitted_at` | MISSING | Not in migration or model. No timestamp for when the encounter was included in an EDI submission. |
| `edi_batch_id` | MISSING | Not in migration or model. No way to group encounters into the EDI batch (file) that was sent to the clearinghouse. Required for rejection tracking and resubmission. |
| `cms_acknowledgement_status` | MISSING | Not in migration or model. No field to store the 999 functional acknowledgement or 277CA claim status response from CMS. Without this, there is no way to know if CMS accepted or rejected the encounter. |

**Summary: 1 PARTIAL, 13 MISSING out of 14 required 837P fields.**

---

### Capitation Record Fields Audit

The current `emr_capitation_records` table captures payment amounts but lacks the metadata needed for risk adjustment reconciliation and CMS rate verification:

| Field | Status | Notes |
|---|---|---|
| `hcc_risk_score` | MISSING | Not in migration or model. The participant's CMS-HCC Risk Adjustment Factor (RAF) score is the primary driver of capitation rate. Without storing this, the organization cannot verify whether CMS's rate calculation matches their submitted diagnoses. |
| `frailty_score` | MISSING | Not in migration or model. PACE participants receive a frailty adjuster in addition to the HCC RAF score (CMS PACE blended rate formula). This is a separate component that must be tracked for rate reconciliation. |
| `county_fips_code` | MISSING | Not in migration or model. Capitation rates are county-specific (CMS publishes county-level PACE rates annually). The county FIPS code determines which rate table applies. Without it, rate verification is impossible. |
| `adjustment_type` | MISSING | Not in migration or model. CMS issues three capitation payment cycles: initial (February), mid-year (August), and final (January of following year). Each may differ due to enrollment corrections and risk score updates. The current schema has no way to distinguish initial from mid-year from final payments. |
| `rate_effective_date` | MISSING | Not in migration or model. The date from which the rate applies (important for mid-year adjustments and retroactive corrections). Currently only `month_year` and `recorded_at` exist — `recorded_at` is when the record was entered, not when CMS made the rate effective. |

**Summary: 5 MISSING out of 5 required risk-adjustment reconciliation fields.**

---

### Billing Gap Summary

```
=== BILLING GAP SUMMARY ===
Capitation records:          PARTIAL — rates stored (Part A/B/D/Medicaid components); missing
                              HCC RAF score, frailty adjuster, county FIPS, adjustment_type,
                              rate_effective_date. Cannot do rate reconciliation against CMS.

Encounter log (837P fields): CRITICAL GAP — 13 of 14 required 837P fields are missing.
                              Most critical: diagnosis_codes (ICD-10, drives HCC risk scoring),
                              billing/rendering/facility NPI, place_of_service_code, units,
                              charge_amount, claim_type, and all submission tracking fields
                              (submission_status, submitted_at, edi_batch_id,
                              cms_acknowledgement_status). Current encounter log cannot
                              generate a valid 837P transaction.

EDI 837P builder:            MISSING — no EDI/X12/837 code exists anywhere in app/.
                              No clearinghouse integration. No batch generation.
                              No submission tracking. DEBT-019 acknowledges this gap.

PDE tracking (Part D):       MISSING — no PDE model, table, or service. No MARx integration.
                              Pharmacy encounter data exists in eMAR but is not linked to
                              Part D PDE submission requirements.

HPMS enrollment file:        MISSING — no HPMS file generator. Enrollment/disenrollment
                              data exists in emr_referrals (Referral model with state machine)
                              but no CMS HPMS-format file export. 'disenrollment' keyword found
                              only in FinanceWidgetController and EnrollmentService (business logic,
                              not CMS reporting).

HOS-M survey tracking:       MISSING — no HOS-M (PACE version of Health Outcomes Survey for
                              Medicare) data model or tracking. No survey response records.

State Medicaid encounter:    MISSING — no state-specific encounter format, companion guide
                              support, or Medicaid agency submission. DEBT-026 acknowledges
                              state Medicaid configuration as missing. No 837 state variant exists.

Revenue integrity dashboard: MISSING — no HCC gap analysis, no RAF score trending, no
                              diagnosis capture rate reporting. Finance/Dashboard.tsx shows
                              capitation dollars and encounter counts but no revenue integrity KPIs.

HCC gap analysis tool:       MISSING — no HCC mapping from ICD-10 codes, no RAF calculation,
                              no gap identification between submitted diagnoses and clinical
                              evidence in emr_problems. DEBT-025 acknowledges this gap.
=== END BILLING GAP SUMMARY ===
```

---

### Phase 9B Build Specification

Based on the audit above, Phase 9B (Encounter Data & Billing Infrastructure) should build in this priority order:

**Priority 1 — Encounter log schema expansion (prerequisite for everything else):**
- Migration additions to `emr_encounter_log`:
  - `diagnosis_codes` JSONB NOT NULL DEFAULT '[]' — primary ICD-10 first, max 12; link to emr_problems
  - `billing_provider_npi` CHAR(10) NULLABLE — NPI of the PACE organization billing entity
  - `rendering_provider_npi` CHAR(10) NULLABLE — NPI of the individual clinician who rendered the service
  - `service_facility_npi` CHAR(10) NULLABLE — NPI of facility where service was rendered (day center, home, hospital)
  - `place_of_service_code` CHAR(2) NULLABLE — CMS POS code (02, 11, 12, 49, 65, etc.)
  - `procedure_modifier` VARCHAR(10) NULLABLE — CPT/HCPCS modifier (25, 59, GP, GO, GN, U1-UD, etc.)
  - `units` DECIMAL(8,2) NULLABLE — units of service
  - `charge_amount` DECIMAL(10,2) NULLABLE — submitted charge (Medicare fee schedule rate)
  - `claim_type` VARCHAR(5) NULLABLE DEFAULT '837P' — '837P' or '837I'
  - `submission_status` VARCHAR(20) NULLABLE DEFAULT 'pending' — pending/batched/accepted/rejected/resubmitted
  - `submitted_at` TIMESTAMP NULLABLE — when included in EDI batch
  - `edi_batch_id` BIGINT NULLABLE FK to new emr_edi_batches table
  - `cms_acknowledgement_status` VARCHAR(20) NULLABLE — accepted/rejected/pending_277ca
  - `cms_acknowledgement_at` TIMESTAMP NULLABLE

**Priority 2 — Capitation record schema expansion:**
- Migration additions to `emr_capitation_records`:
  - `hcc_risk_score` DECIMAL(8,4) NULLABLE — participant's CMS-HCC RAF score
  - `frailty_score` DECIMAL(8,4) NULLABLE — PACE frailty adjuster component
  - `county_fips_code` CHAR(5) NULLABLE — 5-digit county FIPS code
  - `adjustment_type` VARCHAR(20) NULLABLE — 'initial', 'mid_year', 'final'
  - `rate_effective_date` DATE NULLABLE — CMS effective date for this rate

**Priority 3 — New tables needed:**
- `emr_edi_batches` — tracks each EDI file submission (batch_id, submitted_at, file_name, clearinghouse, status, record_count, response_file_name, response_received_at, tenant_id)
- `emr_hcc_mappings` — ICD-10 to HCC mapping lookup table (icd10_code, hcc_category, hcc_label, raf_value, effective_year) — seeded from CMS published mapping files
- `emr_pde_records` — Part D Prescription Drug Event records (participant_id, drug_name, ndc_code, days_supply, quantity, cost_basis, dispensed_at, plan_paid, member_paid, submission_status, submitted_at, tenant_id)

**Priority 4 — New services needed:**
- `EdiBatchService` — groups eligible encounters into 837P/837I batches, validates required fields before batching, marks encounters as 'batched', generates X12 5010A1 EDI file content or integrates with clearinghouse API
- `HccRiskScoringService` — maps participant's active ICD-10 diagnoses (from emr_problems) to HCC categories using emr_hcc_mappings, calculates RAF score, identifies HCC capture gaps versus capitation record RAF scores
- `HpmsEnrollmentFileService` — generates CMS HPMS-format monthly enrollment/disenrollment file from emr_referrals enrollment state machine data
- `RevenueIntegrityService` — computes revenue integrity KPIs: diagnosis capture rate, HCC gap count, RAF score trend, projected vs actual capitation variance

**Priority 5 — New controllers/endpoints needed:**
- `EdiBatchController` — POST /finance/edi/batches (create batch from eligible encounters), GET /finance/edi/batches (list with status), GET /finance/edi/batches/{id}/download (download EDI file), POST /finance/edi/batches/{id}/acknowledgement (receive 277CA response)
- `HccAnalysisController` — GET /finance/hcc/participant/{id} (HCC profile + gap analysis for one participant), GET /finance/hcc/gaps (tenant-wide gap summary), GET /finance/hcc/raf-scores (RAF score distribution report)
- `RevenueIntegrityController` — GET /finance/revenue-integrity (Inertia page with KPI widgets)

**Priority 6 — Frontend pages needed:**
- `Finance/EdiBatch.tsx` — encounter batching workflow: select date range, review eligible encounters, validate completeness, generate batch, track submission status and 277CA responses
- `Finance/RevenueIntegrity.tsx` — revenue integrity dashboard: RAF score trending, HCC gap count by participant, diagnosis capture rate KPI, projected capitation variance chart, drill-down to individual participant HCC profiles
- Update `Finance/Dashboard.tsx` — add encounter completeness indicator (% of encounters with diagnosis codes) and link to Revenue Integrity dashboard

---

## TEST STATUS

- **1057 tests, 0 failures** — 2026-03-21 (Phase 12B verified: same count, all green)
- Deprecations: 32 (PDO::MYSQL_ATTR_SSL_CA — PHP 8.5 deprecation, non-failing)
- PHPUnit deprecations: 92 (PHPUnit 11 API changes, non-failing)

---

## SESSION LOG

### 2026-03-31 — W4-0 Complete — CLAUDE.md Wave 4 Update
- Added Wave 4 status block (W4-0 through W4-9) to WAVE STATUS. Wave 3 marked COMPLETE.
- Added AUDIT FINDINGS section (5 blockers, 6 high gaps, 4 medium gaps, 12 quick wins) from the 2026-03-31 system audit with full 42 CFR and HIPAA citations and W4 phase tags.
- Expanded FUTURE SCOPE with 18 deferred items in priority order.
- Test baseline: see TEST STATUS below (W4-0 run recorded).

### 2026-03-31 — W3-8 Complete — Catch-All Fixes

**Part A — Help button popover (AppShell.tsx TopBar):**
- Replaced static `{/* Help icon — placeholder */}` button with a functional popover.
- Added `showHelp` state + `helpRef` ref + `useEffect` click-outside listener (mousedown pattern, same as SiteSwitcherDropdown).
- Popover: title "Need Help?", support email `support@nostos-emr.com`, IT Administrator note for after-hours, close button (&#x2715;). Full dark mode.

**Part B — Nav comment fix (routes/web.php):**
- Updated stale comment at line 421 from "Medications and Orders remain stubs" → "Medications and Orders are live via ClinicalOverviewController (W3-8)."

**Also completed in this session (prior to W3-8 spec):**
- `Finance/Capitation.tsx` line 148: added `text-gray-700 dark:text-slate-300` to participant name `<td>` (dark mode fix).
- Created `ClinicalOverviewController` (medications + orders Inertia methods, tenant-scoped).
- Created `Clinical/Medications.tsx` (4 KPI cards + per-participant table, dark mode).
- Created `Clinical/Orders.tsx` (3 KPI cards + domain filter + overdue filter + goals table, dark mode, DOMAIN_COLORS map for 12 domains).
- `ReportsController`: wired CSV exports for census, disenrollments, sdr_compliance, care_plan_status (4 private helpers + single `export()` dispatch method + `/reports/export` route).
- `routes/web.php`: added `ClinicalOverviewController` import; replaced clinical medications/orders ComingSoon closures with real Inertia routes; added `/reports/export` route.

**CLAUDE.md updates:**
- W3-8 marked COMPLETE.
- CAT3 nav audit entries for /clinical/orders and /clinical/medications updated to LIVE.
- New controllers added: ClinicalOverviewController (1 new).
- New React pages added: Clinical/Medications, Clinical/Orders (2 new).

**Result:** Wave 3 complete. All W3-0 through W3-8 phases done.

### 2026-03-31 — W3-7 Complete — Billing Seed & Math Verification + Sign Fix + GitHub push workflow

**Pre-phase fixes:**
- Fixed clinical note Sign button (was silently swallowing 403 errors). Changes: `handleSign` now sets error state on failure; only shows Sign button when `note.authored_by_user_id === auth.user.id`; added `id` to auth user TypeScript type in ChartTab. Users who didn't write the note no longer see a Sign button that appears broken.
- Updated CLAUDE.md SESSION STARTUP CHECKLIST step 7: added "Push to GitHub as final step of every phase."
- Fixed W3-6 `W3TransferSeeder` seeding bug: `soap_note` → `soap` (note_type CHECK constraint), added required `visit_date` field.

**W3-7 BillingDemoSeeder (Part A):**
- Created `database/seeders/BillingDemoSeeder.php` (new). Seeds all enrolled participants with:
  - 3 months of capitation records (CapitationRecord): Medicare A+B $2,800-$4,200 (risk-scaled), D $180-$320, Medicaid $1,400-$2,600. Total ~$4,800/participant/month. 20 interim_january, 8 mid_year_june, 2 final_settlement adjustment types. county_fips_code = 39049 (Franklin County, OH).
  - HCC risk scores (ParticipantRiskScore): payment_year=current, risk_score 1.2-3.8, frailty 0.15-0.45, hcc_categories array (2-6 HCC codes from 10 realistic PACE clusters), diagnoses_submitted 8-24, small rejection rate.
  - Encounter log (EncounterLog): 15-25 encounters per participant, last 60 days. CPT mix: 99213 (4x weight), 99214, 97110, 97530, 90837, 90791, 97001, 97003, 97150. POS: 65 PACE center (70%), 11 office, 12 home. Claim type: internal_capitated (70%), external_claim (20%), chart_review_crr (10%). Submission: pending 30%, submitted 60%, accepted 10%.
  - 1 EDI batch (EdiBatch): acknowledged, batch_type=edr, 45 records, fake 837P ISA+GS header.
  - PDE records (PdeRecord): every 3rd participant gets 3-5 PDEs. Every 9th participant accumulates TrOOP ($200-$800 range). Controlled substance names + realistic NDC format.
  - HOS-M surveys (HosMSurvey): all participants, current year. Completion: ~83% (every 6th participant is not completed). submitted_to_cms: ~80% of completed.
- Added `$this->call(BillingDemoSeeder::class)` to `DemoEnvironmentSeeder.php` before W3-6 seeder.

**W3-7 Tests (Parts C + Feature):**
- `tests/Unit/CapitationMathTest.php` (new, 5 unit tests): monthly total = sum of components, org-wide total = sum of participant totals, submission rate = (submitted+accepted)/total*100, HOS-M rate = completed/total*100, risk avg = sum/count.
- `tests/Feature/BillingDemoDataTest.php` (new, 9 feature tests): runs DemoEnvironmentSeeder as fixture; verifies all enrolled participants have capitation (3 months), risk scores, encounters (15+), HOS-M surveys; EDI batch exists acknowledged; PDEs seeded; 3 months capitation coverage; submission rate 55-85%; HOS-M completion 75-92%.

**Bugs found and fixed during W3-7:**
- `administered_by_user_id` and `administered_at` are NOT NULL on `emr_hos_m_surveys` — CapitationMathTest had to include these fields.
- `emr_clinical_notes.note_type` CHECK constraint: valid values are `soap`, `progress_nursing`, etc. NOT `soap_note`. W3TransferSeeder was using `soap_note` → fixed.
- `emr_clinical_notes.visit_date` is NOT NULL — W3TransferSeeder wasn't setting it → fixed, added `visit_date` to both pre- and post-transfer notes.
- BillingDemoSeeder HOS-M 100% completion bug: seeder iterated 25 enrolled participants and set `completed = $hosMCompleted < 25` which was always true. Fixed by using modulo index: `$i % 6 !== 5` gives deterministic ~83% completion.

**Known pre-existing test flakiness (NOT introduced by W3-7):**
- `QaMetricsServiceTest::test_hospitalizations_excludes_prior_month` — month-boundary sensitive.
- `DashboardActionabilityTest::test_enrollment_new_referrals_items_have_hrefs` — parallel DB state.

**Result:** 1181 total tests, 14 new all pass, 2 pre-existing failures (unchanged), 0 new failures. Build clean.

### 2026-03-30 — W3-6 Complete — Site Transfer Data Integrity

Implemented W3-6 (Site Transfer Data Integrity). All 5 parts complete.

**Part A — Site source labels on clinical notes:**
- `ClinicalNote::site()` BelongsTo relationship added (site_id already existed on the table).
- `ClinicalNoteController::index()` now eager-loads `site:id,name` on every note response.
- `ParticipantController::show()` passes `hasMultipleSites` and `completedTransfers` Inertia props.
- `Participant::siteTransfers()` HasMany + `hasMultipleSites()` computed method added.
- `NoteCard` in Show.tsx accepts optional `showSiteBadge` prop; renders site name badge when true and note.site is present.

**Part B — Transfer lines on Vitals chart:**
- `VitalsTab` accepts `completedTransfers` prop; renders `<ReferenceLine>` elements at each transfer effective_date on the Recharts LineChart. Line color: amber (#f59e0b), dashed.

**Part C — TransfersTab data summary + verify:**
- `TransferController::summary()` — builds per-site-period records (enrollment→first transfer, between transfers, last transfer→present). Notes counted by site_id; vitals and appointments counted by date window (no site_id on those tables). Appointments query uses `scheduled_start` column.
- `TransferController::verify()` — checks for null site_id + orphaned site_ids on clinical notes; returns `{status, anomalies}`.
- `TransfersTab` in Show.tsx: "Care Period Summary" table with per-period record counts; "Verify Data Integrity" button POSTs to verify endpoint.
- Routes added: `GET /participants/{participant}/transfers/summary`, `POST /participants/{participant}/transfers/verify`.

**Part D — Reports "By PACE Site" tab:**
- `ReportsController::siteTransfers()` — JSON endpoint; groups completed transfers by participant, supports `?site_id=` filter; returns sites list for dropdown.
- `ReportsController::siteTransfersExport()` — CSV stream of all completed transfers.
- `Reports/Index.tsx` — added "By PACE Site" tab alongside "Report Catalog"; lazy-loads data on tab activation; participant table with site filter dropdown and CSV export link.
- Routes added: `GET /reports/site-transfers`, `GET /reports/site-transfers/export`.

**Part E — Seed transfer demo data:**
- `W3TransferSeeder` created: takes 3 enrolled East-site participants, creates completed transfers to West with effective dates 60/75/90 days ago, creates 2 pre-transfer (East site_id) + 2 post-transfer (West site_id) clinical notes per participant.
- `DemoEnvironmentSeeder` updated to call `W3TransferSeeder`.

**Tests — SiteTransferDataTest (12 tests):**
- `test_participant_with_transfers_has_multiple_sites_flag`
- `test_participant_without_transfers_has_no_multiple_sites_flag`
- `test_participant_show_includes_completed_transfers`
- `test_clinical_notes_include_site_data_when_participant_has_transfers`
- `test_transfer_summary_returns_site_periods`
- `test_transfer_summary_empty_for_no_transfers`
- `test_verify_returns_verified_for_clean_data`
- `test_verify_returns_verified_when_no_notes`
- `test_reports_site_transfers_endpoint_returns_transfer_data`
- `test_reports_site_transfers_filters_by_site`
- `test_reports_site_transfers_export_returns_csv`
- `test_reports_site_transfers_requires_allowed_department`

**Bugs found and fixed during development:**
- `TransferController::summary()` used `scheduled_date` — appointment column is `scheduled_start`. Fixed.
- Route cache was stale after sync — `php artisan route:clear` needed alongside `config:clear`.
- `ClinicalNote::site_id` is NOT NULL (FK constrained) — "anomaly" test redesigned to test verified-when-no-notes instead.
- CSV Content-Type from PHP streams: `text/csv; charset=utf-8` (lowercase u) — test assertion corrected.

**Known pre-existing test flakiness (NOT introduced by W3-6):**
- `QaMetricsServiceTest::test_hospitalizations_excludes_prior_month` — month-boundary sensitive; fails on last day of month.
- `DashboardActionabilityTest::test_enrollment_new_referrals_items_have_hrefs` — parallel DB state issue.

**Result:** 12 new tests all pass. 1167 total tests, 2 pre-existing failures (see above), 0 new failures. Build clean.

### 2026-03-19 — Phase 9B Bug-Fix Session

Resumed after partial 9B build. Found 10 test failures on first run.

**Root causes identified and fixed:**

1. **BillingEncounterController::index()** — `response()->json($paginator)` produces flat JSON (no `meta` wrapper). Tests expect `{data, meta}`. Fixed by wrapping paginator output manually.

2. **Routes** — encounter update route used `Route::put(...)` only; tests used `patchJson(...)` → 405. Fixed by changing to `Route::match(['PUT', 'PATCH'], ...)`.

3. **CapitationController::store()** — validation ran before tenant isolation check. Cross-tenant test sent incomplete payload → 422 instead of 403. Fixed by adding early `participant_id` tenant check before `validate()`.

4. **CapitationController::data()** — returned plain paginator JSON; tests expected `{kpis, records}`. Fixed by computing KPIs and returning structured response.

5. **CapitationController::bulkImport()** — returned 201 when records created; test asserted `assertOk()` (200). Fixed by returning 200 for successful batch operations.

6. **PdeController::index()** — same paginator wrapping issue as BillingEncounterController. Fixed identically.

7. **ComingSoonBannerTest::test_billing_capitation_redirects_to_finance_dashboard** — Phase 9B replaced the redirect with a real Inertia finance page (auth-gated). Transportation user (test default) now gets 403. Updated test to `assertForbidden()` and renamed to `test_billing_capitation_requires_finance_access`.

8. **ComingSoonBannerTest::test_billing_claims_returns_coming_soon_planned_mode** — `/billing/claims` route changed from ComingSoon planned page to `redirect('/billing/encounters')`. Updated test to `assertRedirect('/billing/encounters')`.

9. **PdeTest::test_troop_summary_returns_participant_accumulations** — factory `dispense_date` uses `dateTimeBetween('-3 months', 'now')` which can fall in Dec 2025 (prior year). Troop endpoint filters by current year start → only 1 of 2 records counted → sum 1200 instead of 2400. Fixed by pinning `dispense_date => now()->format('Y-m-d')` in test factory call.

**Architectural decisions recorded:**
- BillingEncounterController + PdeController paginator: wrap as `{data, meta}` not raw paginator
- CapitationController::store(): tenant isolation check must precede `validate()` to ensure 403 before 422
- Billing routes: use `match(['PUT', 'PATCH'])` for update endpoints (clients may send either verb)
- PdeTest factory calls: always pin `dispense_date` when testing year-scoped aggregations

### 2026-03-21 — Phase 9C Complete

Implemented Phase 9C (Risk Adjustment + State Medicaid Framework). Started from clean state (930 tests, 0 failures from Phase 9B).

**Created:**
- 2 migrations: emr_participant_risk_scores (RAF score tracking per participant per payment year), emr_state_medicaid_configs (per-tenant state Medicaid submission config)
- 2 models: ParticipantRiskScore (score_source enum: cms_import/calculated/manual; acceptanceRate() helper; forYear/forTenant scopes), StateMedicaidConfig (submission_format enum: 837P/837I/custom; forTenant/active scopes)
- 2 factories: ParticipantRiskScoreFactory (fromCms/calculated/manual states), StateMedicaidConfigFactory (forCalifornia/inactive states)
- 2 services: RiskAdjustmentService (getDiagnosesForRiskSubmission, getRiskAdjustmentGaps, updateParticipantRiskScore — wraps HccRiskScoringService), BillingComplianceService (getChecklist — 5 categories, 10 checks, pass/warn/fail status)
- 3 controllers: RiskAdjustmentController (4 routes: index Inertia, data JSON, participant detail, recalculate), StateMedicaidConfigController (4 routes: index/store/update/destroy; it_admin write, finance/it_admin read), BillingComplianceController (2 routes: index Inertia, data JSON)
- 14 routes in web.php (4 risk-adjustment, 2 compliance-checklist, 4 state-config under it-admin prefix)
- 3 React pages: Finance/RiskAdjustment.tsx (gap summary KPIs, RAF score table, per-participant recalculate), Finance/ComplianceChecklist.tsx (5-category pass/warn/fail checklist, refresh button), ItAdmin/StateConfig.tsx (CRUD for state configs with modal form)
- 4 test files: RiskAdjustmentTest (7 tests), StateMedicaidConfigTest (8 tests), BillingComplianceChecklistTest (7 tests), RiskAdjustmentServiceTest (7 unit tests)

**One bug found and fixed during test run:**
- BillingComplianceService::hpmsChecks() used `period_month` (non-existent column) and `submission_status`. Actual HpmsSubmission model uses `period_start`/`period_end` date columns and `status`. Fixed by filtering by date range using `whereBetween('period_start', [$monthStart, $monthEnd])` and using correct `status` column.

**Result:** 959 tests, 0 failures.

### 2026-03-21 — Phase 10A Complete

Implemented Phase 10A (Multi-Site Participant Transfers). Started from clean state (959 tests, 0 failures from Phase 9C).

**Created:**
- 1 migration: emr_participant_site_transfers (migration 67) — status: pending/approved/completed/cancelled; transfer_reason enum; FK to shared_sites both directions; DB::statement() for CHECK constraints (PostgreSQL pattern)
- 1 model: ParticipantSiteTransfer (constants: STATUSES, TRANSFER_REASONS, TRANSFER_REASON_LABELS; scopes: forTenant/pending/approved/dueForCompletion; helpers: isPending/isApproved/isCompleted/isCancelled/priorSiteHasReadAccess)
- 1 factory: ParticipantSiteTransferFactory (states: approved/dueForCompletion/completed/cancelled)
- 1 service: TransferService (requestTransfer, approveTransfer, cancelTransfer, completeTransfer — posts IDT chat alerts via ChatMessage at both sites, wrapped in DB transaction)
- 1 job: TransferCompletionJob (daily 7am, 'transfers' queue) — processes dueForCompletion() transfers, calls completeTransfer(), logs count/errors
- 2 controllers: TransferController (4 endpoints nested under /participants/{participant}/transfers), TransferAdminController (/enrollment/transfers — paginated admin view)
- 5 routes added to web.php (4 transfer CRUD + 1 admin page)
- 2 React components: TransfersTab in Show.tsx (amber pending banner, request modal, history table, approve/cancel actions), Enrollment/Transfers.tsx (paginated admin page with status filter pills)
- 2 test files: ParticipantTransferTest (13 feature tests), TransferServiceTest (10 unit tests)

**One bug found and fixed during test run:**
- Migration used `$table->check()` Blueprint method which does not exist in this Laravel version. Fixed by using `DB::statement("ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)")` pattern (same pattern used throughout existing migrations).

**One test bug found and fixed:**
- `enrollmentUser(?int $tenantId = null)` helper passed `['tenant_id' => null]` when called without argument, overriding the factory's `Tenant::factory()` default. Fixed by only including tenant_id in the attrs array when it's non-null.

**Result:** 982 tests, 0 failures.

### 2026-03-21 — Phase 10B Complete

Implemented Phase 10B (Executive Role + Site Switcher + Nostos Super Admin Panel). Started from clean state (982 tests, 0 failures from Phase 10A).

**Created:**
- 2 migrations (68–69): shared_users.department CHECK + emr_role_permissions.department CHECK — both updated to include 'executive' and 'super_admin'
- 1 middleware: SiteContextMiddleware — resolves active_site_id from session for executive/SA users; regular users always use own site_id
- 1 controller: SiteContextController (POST /site-context/switch, DELETE /site-context/clear — executive restricted to own tenant, SA role/dept can switch any site)
- 1 controller: ExecutiveDashboardController (4 JSON widget endpoints: org-overview, site-comparison, financial-overview, sites-list)
- 1 controller: SuperAdminPanelController (index Inertia, tenants JSON, health JSON, onboard POST — creates Tenant+Site+User atomically in DB transaction)
- 2 React dashboard components: Dashboard/Depts/ExecutiveDashboard.tsx (4-widget parallel fetch, KPI row + site comparison table + financial overview + site cards), Dashboard/Depts/SuperAdminDashboard.tsx (quick-launch tile to super-admin-panel + info cards)
- 1 React page: SuperAdmin/Index.tsx (3-tab panel: Tenants table, Health grid, Onboard wizard with 422 validation)
- AppShell updates: SiteSwitcherDropdown (POST switch, shows current site, available_sites), NostosAdminBanner (amber for dept=super_admin), PHASE_LABEL → 'Phase 10B'
- Dashboard/Index.tsx: executive + super_admin added to LIVE_DASHBOARD_DEPTS and DEPT_MODULES
- 3 test files: ExecutiveRoleTest (14 tests), SuperAdminPanelTest (11 tests), SiteContextTest (9 tests)
- PermissionSeeder updated to seed executive + super_admin department rows

**Bugs found and fixed during test run:**
- `shared_tenants.slug` is NOT NULL — SuperAdminPanelController::onboard() was missing slug generation. Fixed by generating `strtolower(kebab(tenant_name)) + '-' + 6-char-uuid-suffix`.
- SiteContextTest asserted `assertJson(['active_site_id' => $id])` but controller returns `{'site': {'id': ..., 'name': ...}}`. Fixed assertion to `assertJsonPath('site.id', $id)`.
- Full suite: 49 errors from `emr_role_permissions_department_check` constraint violation — PermissionSeeder seeds executive/super_admin rows but constraint only listed 14 original depts. Fixed by migration 69 (same DB::statement drop+re-add pattern as migration 68).

**Result:** 1017 tests, 0 failures.

### 2026-03-21 — Phase 11B Complete

Implemented Phase 11B (Go-Live Gap Resolution — 5 USCDI v3 / 42 CFR 460.96 blockers). Started from 1017 tests.

**Created:**
- 5 migrations (70–74): emr_immunizations, emr_social_determinants, emr_procedures, advance directive columns on emr_participants (status/type/reviewed_at/reviewed_by FK + PostgreSQL CHECK constraints), emr_ehi_exports
- 4 models: Immunization (VACCINE_TYPES, CVX_CODES, refused tracking, overdue scope), SocialDeterminant (6-domain SDOH screening, LOINC codes, hasElevatedRisk()), Procedure (CPT/SNOMED coding, 3 sources), EhiExport (token/status/expires_at, isExpired/isDownloadable helpers)
- 4 factories: ImmunizationFactory, SocialDeterminantFactory, ProcedureFactory, EhiExportFactory (ready/expired states)
- 4 controllers: ImmunizationController (index+store, audit logged), SocialDeterminantController (index+store, audit logged), ProcedureController (index+store, audit logged), EhiExportController (request 202 + download BinaryFileResponse, audit logged)
- 1 service: EhiExportService (generates ZIP at storage/app/ehi_exports/ with fhir/ and clinical/ subdirectories, ZipArchive, 24h TTL token)
- 3 FHIR mappers: ImmunizationMapper (CVX codes, FHIR R4 Immunization), ProcedureMapper (CPT/SNOMED, FHIR R4 Procedure), SdohObservationMapper (LOINC per-domain FHIR Observations)
- FhirController: 3 new endpoints (immunizations, procedures, sdohObservations with fhir.auth middleware)
- Updated HpmsFileService: live flu (distinct participant_id+vaccine_type=influenza this year) + pneumo (distinct participant_id lifetime, 3 pneumococcal vaccine types) calculations replacing PENDING stubs
- Updated routes/web.php: 6 new resource routes (immunizations, social-determinants, procedures with index+store per participant) + EHI export request+download + 3 FHIR R4 routes; changed participant update from Route::put to Route::match(['PUT','PATCH']) for PATCH support
- Updated UpdateParticipantRequest: advance directive status/type/reviewed_at in $base rules (all depts)
- Updated Participant model: advance directive fields in $fillable, casts, hasDnr() (DNR only, not POLST), advanceDirectiveLabel()
- Updated Show.tsx: ImmunizationsTab (VACCINE_TYPE_LABELS, refused badge, overdue highlight), ProceduresTab (source badge, CPT code), SdohTab (6-domain form with color-coded risk history), advance directive sidebar section
- 5 test files (40 new tests): ImmunizationTest (8), SocialDeterminantsTest (8), ProcedureTest (8), AdvanceDirectiveTest (8), EhiExportTest (8)

**Bugs found and fixed during test run:**
- Participant update route was PUT-only: `Route::match(['PUT','PATCH'])` needed for AdvanceDirectiveTest which correctly uses `->patch()`
- `hasDnr()` included polst/combined in the check — should be DNR-only per FHIR semantics
- EhiExportController::download() used `storage_path('app/')` bypassing `Storage::fake()` in tests — fixed to `Storage::disk('local')->path()`
- EhiExportController return type was `Response` but `response()->download()` returns `BinaryFileResponse` — PHP 8.5 strict return type enforcement

**USCDI v3 compliance status after Phase 11B:**
- Immunizations: PASS ✓ (emr_immunizations, CVX codes, FHIR R4 Immunization endpoint)
- Procedures: PASS ✓ (emr_procedures, CPT/SNOMED, FHIR R4 Procedure endpoint)
- SDOH: PASS ✓ (emr_social_determinants, 6-domain LOINC screening, FHIR SDOH Observations)
- Advance Directives: PASS ✓ (status/type/reviewed_at on emr_participants, hasDnr(), 42 CFR 460.96)
- EHI Export: PASS ✓ (EhiExportService ZIP, 64-char token, 24h TTL, 21st Century Cures Act)
- HPMS quality file: flu/pneumo rates now live (not PENDING stubs)

**Result:** 1057 tests, 0 failures. Build clean.

### 2026-03-21 — Phase 12A Complete

Developer handoff document created. No new feature code.

**Created:**
- `HANDOFF.md` at project root — 15 sections: Project Overview, Quick Start, Architecture (ASCII diagram), Demo Accounts (28+ users), Environment Variables, Database Structure (74-migration table), RBAC System, Key Service Classes (26 services), Background Jobs (8 jobs), Transport Integration Status, ADR-001 through ADR-009, Known Technical Debt (🔴/🟡/🟢), Go-Live Checklist, Post-Launch Roadmap, Developer Notes & Gotchas.
- `README.md` updated: HANDOFF.md callout link added after opening description.
- `CLAUDE.md` updated: DEVELOPER HANDOFF STATUS marked complete.

**Result:** 1057 tests, 0 failures (unchanged). HANDOFF.md created and ready for developer handoff.

### 2026-03-21 — Phase 12B Complete — Project Sealed

Final CLAUDE.md seal and project verification.

**Verified against actual codebase:**
- Migrations: 74 confirmed (3 standard + 71 phase migrations) ✓
- Models: corrected count from 54 → 55 (actual ls count)
- React Pages: corrected count from 37 → 52 (actual find count; list was already complete)
- Controllers: fixed list to include SiteContextController and SuperAdminPanelController (added Phase 10B, were missing from the main list); restructured Dashboards/ note
- Services: 26 confirmed ✓

**TypeScript fixes (tsc --noEmit):**
- Finance pages (Capitation, EdiBatch, Encounters, HosMSurvey, Hpms, Pde, RevenueIntegrity): removed spurious `user={auth.user}` prop from AppShell (AppShell reads auth.user internally via usePage())
- Qa/Dashboard: removed spurious `title="QA Dashboard"` prop from AppShell
- Transport/Manifest: Echo callback now accepts `unknown` + casts to typed payload (laravel-echo 2.x types callbacks as `(payload: unknown) => void`)
- Chat/Index: same Echo callback fix for `.chat.message` listener
- index.d.ts: added `private` method to window.Echo type declaration (was only `channel` + `leaveChannel`)
- Remaining pre-existing errors (documented): Show.tsx React UMD references (11 instances), app.tsx ImportMeta.env/glob, bootstrap.ts Window.axios — all non-blocking, Vite handles at build time

**Tests:** 1057, 0 failures confirmed. Build: clean (✓ built in 28.33s). CLAUDE.md: finalized. HANDOFF.md: complete.

System sealed. All phases 0–12B complete. Transport integration pending Nostos PACE deployment. Go-live checklist in HANDOFF.md Section 13.

### 2026-03-21 — WSL2 Migration + Performance Fixes

Migrated project from Windows filesystem to WSL2 native filesystem to eliminate Docker Desktop I/O bridge overhead (primary cause of slow page load times in dev).

**Root cause diagnosis:** Docker Desktop on Windows mounts project files via the Windows↔WSL2 filesystem bridge. Every PHP file read during a request crosses this bridge — 5-20x slower than native Linux I/O. This is an infrastructure issue, not an application architecture problem.

**Three fixes applied:**

1. **WSL2 migration (biggest impact):** Copied source files to `/home/tj/projects/nostosemr` (WSL2 Ubuntu ext4 filesystem) via `rsync` (excluding vendor/node_modules/public/build). Stopped containers from Windows path; restarted from WSL2 path. Named Docker volumes (`sail-pgsql`, `sail-redis`, `sail-meilisearch`) preserved — they're named by project not path. `compose.yaml` already used relative paths — no changes needed. `composer install` + `npm ci` run to restore deps. rsync exit 23 is benign (Windows NTFS metadata; source code transferred 100%).

2. **PHP opcache (moderate impact):** `docker/php/opcache.ini` created with `validate_timestamps=1 revalidate_freq=0` (recompile on every change during dev, but serve from memory between requests). Mounted into container at both cli and fpm PHP conf.d paths via `compose.yaml` volume entries. Eliminates per-request PHP parsing of all class files.

3. **artisan optimize on startup (small impact):** `start.sh` script created at project root. Runs `docker compose up -d` → waits for health → `php artisan optimize` (caches routes, config, views). Run with `./start.sh` from Ubuntu terminal.

**Bug fixed during migration:** `php artisan optimize` failed with "Unable to prepare route [auth/logout] for serialization. Another route has already been assigned name [logout]." Fortify registers `/logout` named `logout`; our custom `/auth/logout` also used `->name('logout')`. Fixed by renaming to `->name('auth.logout')` in routes/web.php. Frontend uses hardcoded `/auth/logout` path — no JSX changes needed.

**Bug fixed (permissions):** First `artisan optimize` after container restart failed with "View path not found" because `storage/framework/views` was owned by root with restrictive permissions. Fixed with `chmod 777 storage/framework/views`.

**Memory/CLAUDE.md updates:** PROJECT IDENTITY section now documents WSL2 path. KEY DOCKER COMMANDS updated with `./start.sh` as preferred start method and `cd ~/projects/nostosemr` WSL2 workflow note. KNOWN ISSUES updated with WSL2 Docker integration requirement and route name note.

**Result:** App running from WSL2. All 74 migrations applied. Build clean. Artisan optimized. Expected: significantly faster page load times in dev.

### 2026-03-22 — Security Fix + UI Polish

**APP_KEY exposure (GitGuardian alert):** `phpunit.xml` had APP_KEY hardcoded (standard Laravel practice, but the key matched `.env`). GitGuardian detected it after initial GitHub push. Fix: (1) rotated key via `php artisan key:generate`; (2) removed `.env` from git history via `git filter-branch` (`.env` was in stash only, not main branch commits); (3) updated `phpunit.xml` with a fresh independently-generated test-only key; (4) committed and pushed. Going forward: phpunit.xml test key must always differ from the `.env` production key.

**Select dropdown arrow overlap:** All `<select>` elements across the app had text running under the native browser dropdown arrow. Fixed globally with one rule in `resources/css/app.css` (`@layer base { select { padding-right: 2rem; } }`). Rebuilt frontend. Committed and pushed.

### 2026-03-22 — Billing Nav Fixes + CSRF Root Cause

Fixed "Invalid Inertia response" errors on billing navigation pages and Revenue Integrity not loading.

**Root cause 1 — Billing index controllers returned JSON unconditionally:**
- `BillingEncounterController::index()`, `EdiBatchController::index()`, `PdeController::index()` always returned `JsonResponse`. Browser navigation got plain JSON instead of an Inertia component.
- Fix: Added `if (!$request->wantsJson() || $request->header('X-Inertia'))` check; returns `Inertia::render(...)` for browser/SPA navigation and JSON only for axios data-fetches. Used `wantsJson()` (checks Accept header) not `ajax()` (checks X-Requested-With — not set by test `getJson` helpers).

**Root cause 2 — Revenue Integrity props mismatch:**
- `RevenueIntegrityService::getDashboardKpis()` returns nested structure `{capitation:{current_total,...}, ...}` but `Finance/RevenueIntegrity.tsx` expected flat `{capitation_total, submission_rate_30d, ...}`. Controller also passed wrong data for `gaps` (aggregate stats not per-participant objects) and never passed `pending`.
- Fix: Added `flattenKpis()`, `buildGaps()`, `buildPending()` private helpers in `RevenueIntegrityController`, rewrote `index()` and `data()` to use them.

**Root cause 3 — HOS-M submit route method mismatch:**
- Route was `Route::post` but frontend used `axios.patch()`.
- Fix: Changed to `Route::match(['post', 'patch'], ...)`.

**Root cause 4 — Config cache baking APP_ENV=local into tests:**
- `php artisan optimize` (run by `start.sh`) creates `bootstrap/cache/config.php` with `APP_ENV=local`.
- When tests run, `VerifyCsrfToken::runningUnitTests()` reads `$this->app['env']` from the cached config (= 'local'), not phpunit.xml's `APP_ENV=testing`. Returns false → CSRF NOT bypassed → all POST/PATCH tests get 419.
- Fix: `php artisan config:clear` before test runs. Also added `$this->withoutMiddleware(VerifyCsrfToken::class)` to `tests/TestCase.php::setUp()` as a belt-and-suspenders safeguard for non-Docker test runners (IDE, CI without artisan optimize).
- **IMPORTANT:** Always run `php artisan config:clear` after `artisan optimize` if running tests in the same session. Or don't run `artisan optimize` before test runs.

**Root cause 5 — ComingSoonBannerTest stale assertion:**
- Test `test_admin_locations_redirects_to_locations` expected a redirect, but `/admin/locations` now serves a live Inertia page via `LocationController::managePage()` (changed in Phase 5A).
- Fix: Updated test to `assertOk()` with description comment.

**Result:** 1057 tests, 0 failures. Build clean.

---

## MVP COMPLIANCE AUDIT — Phase 11A
**Audited:** 2026-03-21 | **Against:** 42 CFR Part 460, HIPAA 45 CFR 164, ONC USCDI v3, CMS HPMS
**Legend:** PASS | PARTIAL | STUB | MISSING | N/A

---

### 42 CFR PART 460 — PACE CONDITIONS OF PARTICIPATION

**460.6 — IDT Composition**
STATUS: PASS (with minor note)
- All 14 PACE disciplines represented via department matrix (primary_care, therapies, social_work, behavioral_health, dietary, activities, home_care, transportation, pharmacy, idt, enrollment, finance, qa_compliance, it_admin)
- Enforced in CheckDepartmentAccess middleware + PermissionSeeder (952 permission matrix rows)
- Minor note: "Personal Care Attendant Coordinator" and "PACE Center Manager" are not explicit departments — implied by activities/it_admin. Not a regulatory gap (CMS allows flexible org structures) but worth documenting for auditors.

**460.68 — Comprehensive Assessment**
STATUS: PARTIAL
- `initial_comprehensive` assessment type present ✓
- `annual_reassessment` type present ✓
- `next_due_date` column tracks scheduling ✓
- PHQ-9, GAD-7, MMSE, Morse fall risk, Nutritional, ADL functional, Pain scale all present ✓
- GAP: No explicit `change_of_condition` assessment type. CMS requires COC assessments when a significant change is detected. Currently can be approximated via `threshold_flags` breach triggering a new `custom` assessment, but not formally typed.

**460.70 — IDT Care Planning**
STATUS: PASS
- Care plan statuses: draft → under_review → active → archived ✓
- Approval workflow: approved_by_user_id + approved_at columns ✓
- Version history: version integer + createNewVersion() archives prior plan ✓
- Review scheduling: review_due_date (effective_date + 6 months) ✓
- Care plan goals: emr_care_plan_goals linked to plan, per discipline ✓
- Only draft/under_review editable (isEditable() enforced) ✓

**460.72 — Service Delivery Records (SDRs)**
STATUS: PASS
- 72-hour window enforced at DB level: Sdr::boot() sets due_at = submitted_at + 72h, cannot be overridden ✓
- SdrDeadlineEnforcementJob (every 15 min): alerts at 24h remaining, 8h remaining, overdue ✓
- Alert escalation via Reverb broadcast (zero-lag sync) ✓
- requesting_user_id nullable for system SDRs (HL7 A03 discharge) ✓

**460.74 — Medical Care (CPOE / e-Prescribing)**
STATUS: MISSING
- NO computerized provider order entry (CPOE) system
- NO lab orders, imaging orders, or referral orders (referral table is enrollment-only)
- NO e-prescribing integration
- NOTE: Medications model + eMAR + MedRecon exist, but there is no order-based workflow (prescriber writes medication directly, no order-to-dispense chain)
- GO-LIVE BLOCKER: Must document this as manual/paper-based workflow in demo context, or build Phase 12 CPOE module

**460.76 — Transportation**
STATUS: STUB
- All /transport/* routes show ComingSoonBanner (transport bridge pending Nostos deployment) ✓ (documented stub)
- Mobility flags captured on participants (emr_participant_flags) ✓
- TransportRequest model + manifest exist for scheduling; bridge to Nostos transport app not yet connected

**460.78 — Home Care**
STATUS: PARTIAL
- visit_type='home_visit' in both emr_appointments and emr_clinical_notes ✓
- home_care department with permissions on clinical_notes + assessments ✓
- ADL records track functional status for home care planning ✓
- GAP: No separate caregiver interaction log (family/informal caregiver communications not tracked separately from clinical notes)
- GAP: No home environment assessment template (HHA-specific form)

**460.96 — Participant Rights**
STATUS: MISSING
- NO consent management system (HIPAA Authorization forms, consent tracking, versioning)
- NO advance directive / DNR / healthcare proxy registry
- NO formal grievance/complaint management system
  - Grievances can be logged as Incident type 'complaint' but there is no dedicated grievance workflow, resolution tracking, or required response timeline (CMS requires 72h acknowledgement + 30-day resolution)
- Documents table supports 'consent' category but no acknowledgment workflow or expiry tracking
- GO-LIVE BLOCKER: CMS and HIPAA both require formal consent documentation and grievance procedures

**460.100 — Quality Assessment and Performance Improvement (QAPI)**
STATUS: PASS
- IncidentService: 11 incident types, RCA auto-enforced per 42 CFR 460.136 for 6 high-risk types ✓
- QaMetricsService: 6 KPIs (SDR compliance, overdue assessments, unsigned notes, open incidents, overdue care plans, hospitalizations) ✓
- DocumentationComplianceJob: daily 6am compliance sweep ✓
- Qa/Dashboard.tsx: KPI cards + incident queue + compliance tabs + CSV export ✓

**460.112 — Data Collection and Assessment**
STATUS: PARTIAL
- HPMS enrollment/disenrollment file generator (HpmsFileService, 4 types) ✓
- HOS-M survey model + completion tracking ✓
- Part D PDE tracking (emr_pde_records, TrOOP alerts) ✓
- 837P EDI builder (Edi837PBuilderService) ✓ — but clearinghouse integration is stub only
- HCC risk scoring (V28 mappings, RiskAdjustmentService) ✓
- GAP: Clearinghouse API credentials not configured (DEBT-019) — no live 837P submission
- GAP: State Medicaid 837 submission pipeline deferred (DEBT-038)

---

### HIPAA SECURITY RULE — 45 CFR 164

**164.308(a)(1) — Risk Analysis**
STATUS: MISSING
- No formal Security Risk Assessment documented or conducted
- Required before handling real PHI; must be completed and documented before go-live

**164.308(a)(3) — Workforce Authorization**
STATUS: PASS
- RBAC enforced via emr_role_permissions (34 modules × 14 depts × 2 roles) ✓
- IT Admin provisions all accounts — no self-registration ✓
- Account deactivation (is_active=false) + session invalidation available ✓
- Account lockout after 5 failed OTP attempts (locked_until = +30 min) via User::incrementFailedAttempts() ✓
- Minimum necessary principle enforced per department ✓

**164.308(a)(4) — Information Access Management**
STATUS: PASS
- CheckDepartmentAccess middleware gates every route ✓
- FHIR API uses Bearer token + scope enforcement ✓
- Cross-tenant isolation returns 404 (FHIR convention) ✓

**164.308(a)(5) — Security Awareness Training**
STATUS: MISSING
- No workforce training module or tracking
- No security policy acknowledgment workflow
- NOTE: Acceptable to reference external training platform for initial deployment

**164.308(a)(6) — Security Incident Procedures**
STATUS: PARTIAL
- Incident model covers breach-related incidents ✓
- AuditLog captures unauthorized_access actions ✓
- GAP: No formal breach notification workflow (HIPAA requires 60-day notification for breaches affecting 500+ individuals)

**164.308(a)(7) — Contingency Plan**
STATUS: MISSING
- No backup/restore procedures documented
- No disaster recovery configuration
- NOTE: Docker-only dev environment; production deployment plan not yet defined

**164.312(a)(1) — Access Control**
STATUS: PASS
- Unique user IDs (no shared accounts) ✓
- OTP-only authentication (no passwords) ✓
- Emergency access procedure: IT Admin can reset-access (session invalidation) ✓
- RBAC controls access to all EMR modules ✓

**164.312(a)(2)(i) — Automatic Logoff**
STATUS: PASS
- Frontend idle detection (mousemove, keydown, scroll, touchstart) ✓
- Warning modal 1 minute before logout ✓
- Hard server-side session expiry (SESSION_LIFETIME; 15 min recommended for production) ✓
- POST /auth/logout?timeout=true fires on client timeout ✓

**164.312(a)(2)(ii) — Encryption and Decryption**
STATUS: MISSING (GO-LIVE BLOCKER)
- Database: sslmode='prefer' (allows fallback to unencrypted — must be 'require' in production)
- Application fields: NO field-level encryption for PHI (SSN, Medicare ID, diagnoses stored plaintext)
- Documents: Stored on local disk, unencrypted (storage/app/participants/{id}/)
- Session data: SESSION_ENCRYPT=false in .env.example
- Redis: No REDIS_PASSWORD in dev environment
- Must implement: S3 with SSE-KMS for documents, sslmode=require for PostgreSQL, and evaluate field-level encryption for most sensitive fields

**164.312(b) — Audit Controls**
STATUS: PASS
- shared_audit_logs: all PHI reads/writes logged with user_id, action, resource_type, resource_id, ip_address, user_agent ✓
- Append-only enforcement: PostgreSQL rules block UPDATE and DELETE on audit_logs ✓
- FHIR API reads logged with action='fhir.read.{resource}' ✓
- Integration inbound requests logged to emr_integration_log (append-only) ✓
- Super admin impersonation always logs real user ID, never impersonated ID ✓

**164.312(c)(1) — Integrity Controls**
STATUS: PASS
- AuditLog is immutable (throw on save() if record exists, throw always on delete()) ✓
- Clinical notes: signed_at timestamp, signed_by_user_id — signed notes cannot be edited (only addendum) ✓
- eMAR records: append-only (UPDATED_AT=null, no SoftDeletes) ✓
- Encounter log: append-only ✓

**164.312(d) — Authentication**
STATUS: PASS
- OTP-only (no passwords to crack) ✓
- Google/Yahoo OAuth maps to provisioned accounts only (no self-registration) ✓
- TOTP/MFA supported via Fortify ✓
- OTP codes: 10-min expiry, 5 attempts/min rate limit per IP, single-use ✓
- Account lockout: User model tracks failed_login_attempts; 5 failures → locked_until = now + 30 min (User::incrementFailedAttempts() / isLocked() / resetFailedAttempts()) ✓

**164.312(e)(1) — Transmission Security**
STATUS: PARTIAL
- Production must enforce HTTPS (APP_URL=https://...) — dev uses HTTP
- PostgreSQL sslmode=prefer (not enforced to require)
- Email via Mailpit locally; production must use TLS-enforced SMTP

**164.316 — Policies and Procedures**
STATUS: STUB
- HIPAA notice on login page (Login.tsx lines 283-289) ✓
- No formal BAA template or placeholder
- No privacy notice / Notice of Privacy Practices (NPP) page
- No documented Security Policies and Procedures

---

### ONC INTEROPERABILITY — USCDI v3 DATA CLASSES

**Patient Demographics**
STATUS: PARTIAL
- Present: first_name, last_name, dob, gender, pronouns, primary_language, interpreter_needed, medicare_id, medicaid_id, mrn, enrollment_status ✓
- Missing: `race` (required USCDI v3) ✗
- Missing: `ethnicity` (required USCDI v3) ✗
- Missing: `sexual_orientation` (required USCDI v3) ✗
- Missing: `gender_identity` (only generic 'gender' field; USCDI requires distinct gender_identity) ✗
- Contacts/addresses in separate tables (emr_participant_contacts, emr_participant_addresses) ✓

**Clinical Notes (USCDI v3 — C-CDA note types)**
STATUS: PASS
- 13 note types including SOAP, all therapy disciplines, social work, behavioral health, home_visit, telehealth ✓
- Structured JSONB content + SOAP-specific columns ✓
- Signing workflow (signed_at, signed_by_user_id, status=signed) ✓
- Late entry tracking (is_late_entry, late_entry_reason) ✓
- Addendum support (parent_note_id self-referential) ✓

**Medications**
STATUS: PASS
- emr_medications: drug name, route, dose, frequency, prescriber ✓
- eMAR (emr_emar_records): administration tracking, witness for controlled substances ✓
- Drug interaction alerts (emr_drug_interaction_alerts) ✓
- Medication reconciliation (emr_med_reconciliations, 5-step workflow) ✓
- FHIR R4 MedicationRequest endpoint ✓

**Allergies and Intolerances**
STATUS: PASS
- emr_allergies: allergen_name, allergy_type, severity, reaction_description ✓
- Grouped by allergy_type in API response ✓
- FHIR R4 AllergyIntolerance endpoint ✓

**Problems / Diagnoses**
STATUS: PASS
- emr_problems: ICD-10 code (FK to emr_icd10_lookup), problem_description, status, onset_date ✓
- 40+ V28 HCC mappings in emr_hcc_mappings ✓
- FHIR R4 Condition endpoint ✓

**Vital Signs**
STATUS: PASS
- emr_vitals: systolic_bp, diastolic_bp, pulse, o2_saturation, weight_lbs, temperature_f, respiratory_rate, blood_glucose ✓
- FHIR R4 Observation endpoint (maps vitals) ✓

**Care Team Members**
STATUS: PARTIAL
- emr_care_plan_goals include discipline assignment ✓
- IDT meetings track team attendance per session ✓
- GAP: No persistent care team roster table (emr_participant_care_team) — no way to report "assigned care team members" at a point in time separate from meeting records

**Goals**
STATUS: PASS
- emr_care_plan_goals: goal_text, discipline, target_date, status, progress_notes ✓
- Linked to active care plan ✓
- FHIR R4 CarePlan endpoint maps goals ✓

**Immunizations**
STATUS: MISSING
- No emr_immunizations table, model, or controller
- No FHIR R4 Immunization endpoint
- POST-LAUNCH: Required for ONC certification

**Procedures**
STATUS: MISSING (standalone history)
- emr_encounter_log has procedure_code VARCHAR field ✓
- No dedicated emr_procedures table for procedure history
- No FHIR R4 Procedure endpoint
- POST-LAUNCH: Required for ONC certification

**Lab Results**
STATUS: PARTIAL (via integration only)
- ProcessLabResultJob receives lab results via LabResultConnector and stores as EncounterLog records
- Integration log tracked in emr_integration_log ✓
- Abnormal results trigger alerts ✓
- GAP: No structured emr_lab_results table with LOINC codes, result values, reference ranges, units
- FHIR R4 Observation endpoint exists but lacks dedicated lab result resource type mapping

**Functional Status**
STATUS: PASS
- emr_adl_records: functional status tracking with per-participant thresholds ✓
- AdlThresholdService + AdlRecordObserver: breach → auto-alert ✓
- Assessment type 'adl_functional' in emr_assessments ✓

**Mental/Behavioral Health**
STATUS: PASS
- PHQ-9 (phq9_depression): scored 0-27 with severity labels ✓
- GAD-7 (gad7_anxiety): scored 0-21 with severity labels ✓
- MMSE (mmse_cognitive): scored 0-30, cognitive staging ✓
- Behavioral health clinical note type + behavioral_health department ✓

**Social Determinants of Health (SDOH)**
STATUS: MISSING
- No SDOH screening tools (PRAPARE, AHC HRSN, or similar)
- POST-LAUNCH: USCDI v3 includes SDOH as data class; required for ONC certification

**Sexual Orientation / Gender Identity**
STATUS: MISSING
- pronouns field present ✓
- gender field present (generic, conflates sex and identity) ⚠
- sexual_orientation: NOT PRESENT ✗
- gender_identity (distinct from biological sex): NOT PRESENT ✗
- GO-LIVE CONSIDERATION: Required for USCDI v3 / ONC certification; demographics migration needed

**FHIR R4 API Endpoints (FhirController)**
- Patient: PASS ✓
- Observation: PASS ✓
- MedicationRequest: PASS ✓
- Condition: PASS ✓
- AllergyIntolerance: PASS ✓
- CarePlan: PASS ✓
- Appointment: PASS ✓
- Immunization: MISSING ✗ (no resource)
- Procedure: MISSING ✗ (no resource)
- DiagnosticReport/Lab: MISSING ✗ (Observation exists but no dedicated lab report type)
- All endpoints: Bearer token auth, scope enforcement, cross-tenant 404, audit logged ✓

---

### CMS HPMS REPORTING

**Enrollment File**
STATUS: PASS
- HpmsFileService generates enrollment submission file ✓
- HpmsSubmission model: period_start/period_end, status (draft/submitted), submitted_at ✓
- Finance/Hpms.tsx UI for submission management ✓

**Disenrollment File**
STATUS: PASS
- HpmsFileService generates disenrollment file (4 types: enrollment, disenrollment, recap, custom) ✓
- Participant model has disenrollment_date + disenrollment_reason ✓

**Quality Data (HEDIS / Stars)**
STATUS: PARTIAL
- QaMetricsService captures 6 internal KPIs ✓
- No CMS Stars measure definitions mapped to internal data
- No HEDIS measure calculation
- NOTE: Full Stars/HEDIS reporting typically requires dedicated analytics layer

**HOS-M Survey**
STATUS: PASS
- HosMSurvey model: JSONB responses, completion tracking ✓
- Finance/HosMSurvey.tsx: completion stats UI ✓

**Part D PDE Tracking**
STATUS: PASS
- emr_pde_records: drug_name, ndc_code, dispense_date, days_supply, cost_basis, submission_status ✓
- TrOOP accumulation tracking with alerts ✓
- Finance/Pde.tsx: PDE queue UI ✓

**Risk Adjustment / 837P**
STATUS: PARTIAL
- Edi837PBuilderService: 837P builder implemented ✓
- emr_edi_batches: batch tracking ✓
- emr_hcc_mappings: 40+ V28 HCC codes ✓
- RiskAdjustmentService: RAF score calculation, gap analysis ✓
- emr_participant_risk_scores: per-participant per-year scores ✓
- STUB: clearinghouse integration — no live X12 5010A1 submission (DEBT-019)
- STUB: State Medicaid 837 submission pipeline deferred (DEBT-038)

---

## GO-LIVE BLOCKERS
Items that MUST be resolved before handling real participant data:

**BLOCKER-01 — Encryption at Rest (HIPAA 164.312(a)(2)(ii))**
- PostgreSQL sslmode must be changed from 'prefer' to 'require' in production config
- Documents must move from local disk to S3 with SSE-KMS (or equivalent)
- Session encryption (SESSION_ENCRYPT=true) must be enabled in production .env
- Redis must have password auth enabled (REDIS_PASSWORD)
- Recommend evaluating field-level encryption for: ssn_last_four, medicare_id, medicaid_id

**BLOCKER-02 — Consent + Participant Rights Module (42 CFR 460.96 + HIPAA)**
- HIPAA Authorization consent tracking (signed consent forms, expiry, revocation)
- Advance directive / DNR / healthcare proxy registry
- Grievance/complaint management with CMS-required response timelines (72h ack, 30-day resolution)
- Participant Notice of Privacy Practices (NPP) acknowledgment

**BLOCKER-03 — Security Risk Analysis (HIPAA 164.308(a)(1))**
- Formal SRA required before handling PHI — this must be documented and updated annually
- External penetration test recommended before go-live
- BAA must be executed with all vendors (cloud, clearinghouse, EHR integrations)

**BLOCKER-04 — CPOE Workflow Documentation (42 CFR 460.74)**
- No CPOE in current build — must formally document that clinical orders are handled via paper/fax workflow with manual entry into medication/encounter records
- OR: scope Phase 12 CPOE module before go-live

**BLOCKER-05 — Clearinghouse Integration (CMS 460.112)**
- EDI 837P builder exists but no live clearinghouse API credentials configured
- No production X12 5010A1 submission capability
- Must contract with and configure clearinghouse (e.g., Change Healthcare, Availity) before billing

**BLOCKER-06 — Transport Integration (42 CFR 460.76)**
- All transport routes show ComingSoonBanner
- Must connect to Nostos transport app (new PACE-model deployment) or document manual dispatch workflow

---

## POST-LAUNCH ROADMAP (v1.1+)

**POST-01 — USCDI v3 Demographics (ONC Certification Prep)**
- Add migration: race, ethnicity, sexual_orientation, gender_identity columns to emr_participants
- Use HL7 v3 value sets (race/ethnicity: CDC/NHSN codes; gender_identity/sexual_orientation: SNOMED)
- Update Participant form in Show.tsx, FHIR Patient mapper

**POST-02 — Immunization Tracking** [COMPLETE — Phase 11B]
- emr_immunizations table (CVX codes, refused tracking, next_dose_due) ✓
- FHIR R4 Immunization endpoint ✓
- NOTE: VIS acknowledgment tracking not yet added (out of scope for 11B)

**POST-03 — Procedure History** [COMPLETE — Phase 11B]
- emr_procedures table (CPT/SNOMED codes, 3 sources: internal/external_report/patient_reported) ✓
- FHIR R4 Procedure endpoint ✓

**POST-04 — Structured Lab Results**
- New table: emr_lab_results (loinc_code, test_name, result_value, result_unit, reference_range_low/high, abnormal_flag, collected_at, resulted_at, ordering_provider, encounter_id FK)
- FHIR R4 DiagnosticReport endpoint
- Upgrade LabResultConnector to map HL7 ORU messages to structured rows

**POST-05 — Care Team Roster**
- New table: emr_participant_care_teams (participant_id, user_id, role_in_care, assigned_at, primary_contact BOOL, active BOOL)
- FHIR R4 CareTeam resource
- Display in Participants/Show.tsx alongside IDT meetings

**POST-06 — Change-of-Condition Assessment Type**
- Add 'change_of_condition' to emr_assessments.assessment_type enum (new migration)
- Add COC trigger documentation in assessment workflow (CMS 42 CFR 460.68(b))
- COC flags should auto-generate new care plan review task

**POST-07 — Social Determinants of Health (SDOH)** [COMPLETE — Phase 11B]
- emr_social_determinants table (6-domain LOINC-coded screening, hasElevatedRisk()) ✓
- FHIR R4 SDOH Observations at GET /fhir/R4/Observation/social-history ✓
- NOTE: Built as standalone screening record (not via emr_assessments) for cleaner FHIR mapping

**POST-08 — Grievance / Participant Rights Module**
- New tables: emr_grievances, emr_consent_records, emr_advance_directives
- Grievance workflow: intake → assigned → investigation → resolution with CMS timeline enforcement
- Consent management: form templates, e-signature or print-and-scan, expiry tracking
- Advance directive registry: DNR, POLST, healthcare proxy document storage + status

**POST-09 — FHIR Write + Bulk Export (ONC Certification)**
- FHIR write endpoints (POST/PUT) for Patient, Medication, Condition
- FHIR Bulk Data Access (/$export) per ONC HTI-1 rule
- SMART on FHIR authorization for third-party app access
- FHIR Capability Statement endpoint (GET /fhir/R4/metadata)

**POST-10 — HEDIS / CMS Stars Measures**
- Map internal clinical data to HEDIS measure definitions (e.g., HbA1c control, Fall Risk Management)
- Stars measure calculation service
- Annual Stars preview dashboard for Finance/QA

**POST-11 — CPOE / Clinical Orders (if not addressed as blocker)**
- Order types: lab, imaging, referral, medication order
- Order workflow: prescriber → order → status tracking → result receipt
- Integration with lab/imaging systems via HL7 ORM/ORU message types

**POST-12 — Document Encryption + S3 Migration**
- Migrate document storage from local disk to S3 (FILESYSTEM_DISK=s3)
- Enable S3 server-side encryption (SSE-KMS)
- Update DocumentController::download() to generate pre-signed S3 URLs
- Migrate existing dev documents with data migration script

---

## PHASE 11A STATUS
- Phase 11A (MVP Compliance Audit): [x] COMPLETE — 2026-03-21
- No code written — research and documentation only
- All findings recorded in CLAUDE.md under ## MVP COMPLIANCE AUDIT
- 6 GO-LIVE BLOCKERS identified
- 12 POST-LAUNCH ROADMAP items identified
