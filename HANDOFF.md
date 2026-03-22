# NostosEMR — Developer Handoff Document

> **Last updated:** 2026-03-21 | **Build status:** 1057 tests, 0 failures | **Phase:** 11B complete
>
> This document is the single source of truth for a developer joining this project.
> Read it top to bottom on day one. You should not need any other document to get started.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Quick Start](#2-quick-start)
3. [Architecture](#3-architecture)
4. [Demo Accounts](#4-demo-accounts)
5. [Environment Variables](#5-environment-variables)
6. [Database Structure](#6-database-structure)
7. [RBAC System](#7-rbac-system)
8. [Key Service Classes](#8-key-service-classes)
9. [Background Jobs](#9-background-jobs)
10. [Transport Integration Status](#10-transport-integration-status)
11. [Architecture Decision Records](#11-architecture-decision-records)
12. [Known Technical Debt](#12-known-technical-debt)
13. [Go-Live Checklist](#13-go-live-checklist)
14. [Post-Launch Roadmap](#14-post-launch-roadmap)
15. [Developer Notes & Gotchas](#15-developer-notes--gotchas)

---

## 1. Project Overview

### What NostosEMR Is

NostosEMR is an Electronic Medical Record system purpose-built for **PACE** (Program of All-inclusive Care for the Elderly) organizations. It is a separate Laravel 11 application that **shares a PostgreSQL database** with the existing Nostos transportation platform.

The application is currently a **fully functional local MVP** intended for demo and development. It is not yet deployed to production and has not handled real patient data.

### What PACE Is (for developers)

PACE is a CMS-regulated care delivery model for elderly adults who are dually eligible for Medicare and Medicaid. Key facts every developer needs:

- **Participants** are enrolled members receiving all-inclusive care (the PACE alternative to nursing home placement)
- **IDT (Interdisciplinary Team)** — 14 clinical and administrative departments collaborate on every participant's care plan
- **Billing is capitation, not fee-for-service** — CMS pays a fixed monthly amount per enrolled participant. There are no individual claim approvals. Accurate encounter data submission drives *next year's* payment rates via CMS-HCC risk adjustment
- **CMS compliance is non-negotiable** — 42 CFR Part 460 governs PACE operations. HIPAA 45 CFR 164 governs PHI handling. ONC USCDI v3 governs data interoperability
- **72-hour SDR rule** — Service Delivery Reports must be resolved within 72 hours. This is a CMS requirement enforced by a Horizon job, not just a UI warning

### Relationship to Nostos Transport App

```
┌─────────────────────┐    shared PostgreSQL DB    ┌─────────────────────┐
│   NostosEMR (this)  │◄──────────────────────────►│  Nostos Transport   │
│   Laravel 11        │    shared_* tables          │  Laravel (existing) │
│   Port 80           │    emr_* tables (EMR only)  │  Separate port      │
└─────────────────────┘    transport_* (read-only)  └─────────────────────┘
```

- EMR reads transport data via `TransportBridgeService` (currently stubbed — see Section 10)
- EMR writes only to `emr_*` and `shared_*` tables
- Transport data (`transport_*`) is read-only from EMR's perspective
- The `shared_users`, `shared_tenants`, `shared_sites`, `shared_audit_logs`, and `shared_sessions` tables are used by both apps

### Current State

| Area | Status |
|------|--------|
| Core clinical (notes, vitals, assessments, meds, care plans) | ✅ Complete |
| RBAC (14 departments × 2 roles × 34 modules) | ✅ Complete |
| Billing engine (837P, capitation, HCC, PDE, HPMS) | ✅ Complete |
| FHIR R4 API (10 endpoints) | ✅ Complete |
| Real-time (Reverb WebSockets, alerts, chat) | ✅ Complete |
| Transport integration | ⏳ Stubbed (ComingSoonBanner) |
| Production deployment | ❌ Not started |
| Real patient data | ❌ Not applicable (local demo only) |

---

## 2. Quick Start

### Prerequisites

- Docker Desktop installed and running
- Git

### Setup

```bash
# 1. Clone the repo
git clone <repo-url> nostosemr
cd nostosemr

# 2. Copy environment file
cp .env.example .env

# 3. Install PHP dependencies (via Docker)
docker compose run --rm laravel.test composer install

# 4. Generate app key
docker compose run --rm laravel.test php artisan key:generate

# 5. Start all services
docker compose up -d

# 6. Run migrations + demo seed
docker compose exec -T laravel.test php artisan migrate:fresh --seed --seeder=DemoEnvironmentSeeder

# 7. Build frontend assets
docker compose exec -T laravel.test npm run build
```

### Access

| Service | URL |
|---------|-----|
| Application | http://localhost |
| Mailpit (OTP emails) | http://localhost:8025 |
| Horizon (queue monitor) | http://localhost/horizon |

### First Login

1. Go to http://localhost
2. Enter any demo user email (see Section 4)
3. Check http://localhost:8025 for the OTP code
4. Enter OTP to complete login

> **Alternative:** Use Google OAuth with `superadmin@nostos.dev` (see demo accounts)

### Common Commands

```bash
# Run all tests (parallel, 8 processes)
docker compose exec -T laravel.test php artisan test --parallel --processes=8

# Run a specific test file
docker compose exec -T laravel.test php artisan test tests/Feature/ParticipantTest.php

# Build frontend (must run inside Docker — node_modules are Linux-native)
docker compose exec -T laravel.test npm run build

# Fresh database reset
docker compose exec -T laravel.test php artisan migrate:fresh --seed --seeder=DemoEnvironmentSeeder

# Tail application logs
docker compose exec -T laravel.test tail -f storage/logs/laravel.log

# Horizon dashboard (queue workers)
# Visit http://localhost/horizon in browser while app is running
```

---

## 3. Architecture

### System Diagram

```
┌──────────────────────────────────────────────────────────────────┐
│                         Browser / Client                          │
│  React 18 + TypeScript + Tailwind CSS + Inertia.js               │
└───────────────────────────┬──────────────────────────────────────┘
                            │ HTTP / WebSocket
┌───────────────────────────▼──────────────────────────────────────┐
│                      Laravel 11 (PHP 8.5)                         │
│  ┌─────────────┐  ┌──────────────┐  ┌──────────────────────────┐ │
│  │ Inertia     │  │ FHIR R4 API  │  │ Integration Endpoints     │ │
│  │ (web pages) │  │ /fhir/R4/*   │  │ /integrations/hl7/*      │ │
│  │             │  │ Bearer token │  │ X-Integration-Tenant hdr  │ │
│  └──────┬──────┘  └──────┬───────┘  └──────────────┬───────────┘ │
│         │                │                          │             │
│  ┌──────▼────────────────▼──────────────────────────▼───────────┐ │
│  │              Controllers → Services → Models                  │ │
│  └──────────────────────────────┬────────────────────────────────┘ │
│                                 │                                 │
│  ┌──────────────────────────────▼────────────────────────────────┐ │
│  │                Laravel Reverb (WebSockets)                     │ │
│  │  PrivateChannel('chat.{id}')  PrivateChannel('user.{id}')     │ │
│  └───────────────────────────────────────────────────────────────┘ │
│                                                                   │
│  ┌───────────────────────────────────────────────────────────────┐ │
│  │               Laravel Horizon (Redis queues)                   │ │
│  │  sdr-enforcement | mar-detection | compliance | notifications  │ │
│  │  transport-webhooks | integrations | transfers                 │ │
│  └───────────────────────────────────────────────────────────────┘ │
└───────────────────────────┬──────────────────────────────────────┘
                            │
┌───────────────────────────▼──────────────────────────────────────┐
│                      PostgreSQL                                    │
│  emr_*        NostosEMR-only tables (74 migrations)              │
│  shared_*     Shared with transport app (users, tenants, audit)   │
│  transport_*  Transport-only tables (EMR reads via bridge)        │
└──────────────────────────────────────────────────────────────────┘
```

### Key Patterns

**Inertia.js:** The frontend is NOT a separate SPA with a REST API. Laravel renders page props server-side; React handles client rendering. No JWT tokens. Session auth applies to all page requests. JSON-only endpoints exist for dashboard widgets and FHIR.

**Multi-tenancy:** Every EMR model carries `tenant_id`. All queries must be scoped to `Auth::user()->tenant_id`. The `CheckDepartmentAccess` middleware enforces this plus module-level RBAC.

**Append-only records:** `shared_audit_logs`, `emr_encounter_log`, `emr_emar_records`, `emr_integration_log` have no `updated_at` and reject mutations. `AuditLog::record()` throws `ImmutableRecordException` if you call `save()` on an existing record.

**Soft deletes everywhere:** HIPAA requires 6-year PHI retention. Nothing is hard-deleted. Always use `withTrashed()` or `onlyTrashed()` if you need deleted records.

### Request Lifecycle (typical page)

```
1. Browser → GET /participants/42 (with session cookie)
2. Laravel Router → CheckDepartmentAccess middleware
   → checks emr_role_permissions for user's dept + role + module
   → abort(403) if denied, or pass through
3. ParticipantController::show()
   → loads Participant model (scoped to user's tenant_id)
   → passes props to Inertia::render('Participants/Show', [...])
4. Inertia → React hydrates Show.tsx with server-side props
5. React → subsequent data (chart tab, vitals) fetched via axios JSON calls
6. Real-time: Reverb WebSocket keeps alert badge updated without polling
```

---

## 4. Demo Accounts

All passwords: **DemoP@ce2025!** (OTP delivery: http://localhost:8025)

### Nostos Super Admin (cross-tenant, full access)
| Email | Role | Department | Notes |
|-------|------|------------|-------|
| superadmin@nostos.dev | admin | super_admin | Nostos staff account, cross-tenant read |
| tj@nostos.tech | super_admin | it_admin | First super-admin, Google OAuth login |

### Clinical Staff — Sunrise PACE Demo
| Email | Department | Role |
|-------|------------|------|
| alice.primary_care@sunrisepace-demo.test | primary_care | admin |
| bob.primary_care@sunrisepace-demo.test | primary_care | standard |
| carol.therapies@sunrisepace-demo.test | therapies | admin |
| dave.therapies@sunrisepace-demo.test | therapies | standard |
| emily.social_work@sunrisepace-demo.test | social_work | admin |
| frank.social_work@sunrisepace-demo.test | social_work | standard |
| grace.behavioral_health@sunrisepace-demo.test | behavioral_health | admin |
| henry.behavioral_health@sunrisepace-demo.test | behavioral_health | standard |
| iris.dietary@sunrisepace-demo.test | dietary | admin |
| james.dietary@sunrisepace-demo.test | dietary | standard |
| kate.activities@sunrisepace-demo.test | activities | admin |
| liam.activities@sunrisepace-demo.test | activities | standard |
| mary.home_care@sunrisepace-demo.test | home_care | admin |
| nick.home_care@sunrisepace-demo.test | home_care | standard |

### Operations Staff — Sunrise PACE Demo
| Email | Department | Role |
|-------|------------|------|
| olivia.transportation@sunrisepace-demo.test | transportation | admin |
| peter.transportation@sunrisepace-demo.test | transportation | standard |
| quinn.pharmacy@sunrisepace-demo.test | pharmacy | admin |
| rachel.pharmacy@sunrisepace-demo.test | pharmacy | standard |
| sam.idt@sunrisepace-demo.test | idt | admin |
| tina.idt@sunrisepace-demo.test | idt | standard |
| uma.enrollment@sunrisepace-demo.test | enrollment | admin |
| victor.enrollment@sunrisepace-demo.test | enrollment | standard |
| wendy.finance@sunrisepace-demo.test | finance | admin |
| xavier.finance@sunrisepace-demo.test | finance | standard |
| yvonne.qa_compliance@sunrisepace-demo.test | qa_compliance | admin |
| zach.qa_compliance@sunrisepace-demo.test | qa_compliance | standard |
| aaron.it_admin@sunrisepace-demo.test | it_admin | admin |
| beth.it_admin@sunrisepace-demo.test | it_admin | standard |

### Executive
| Email | Department | Role | Notes |
|-------|------------|------|-------|
| exec@sunrisepace-demo.test | executive | admin | Cross-site read-only within tenant |

### Demo Data Summary
- **1 tenant:** Sunrise PACE - Demo Organization
- **2 sites:** Sunrise PACE East, Sunrise PACE West
- **30 participants:** Last name 'Testpatient', ages 65–95
- **28 staff users:** Last name 'Demo', 2 per dept/role

---

## 5. Environment Variables

Copy `.env.example` to `.env`. The following variables must be configured for the application to run.

### Application
```env
APP_NAME=NostosEMR
APP_ENV=local
APP_KEY=                    # generate with: php artisan key:generate
APP_DEBUG=true              # false in production
APP_TIMEZONE=UTC
APP_URL=http://localhost
```

### Database
```env
DB_CONNECTION=pgsql
DB_HOST=pgsql               # Docker service name
DB_PORT=5432
DB_DATABASE=nostosemr
DB_USERNAME=sail
DB_PASSWORD=password
```

### Session
```env
SESSION_DRIVER=database     # stored in shared_sessions table
SESSION_LIFETIME=120        # minutes — set to 15 in production (HIPAA)
SESSION_ENCRYPT=false       # MUST be true in production
```

### Queue / Cache / Broadcasting
```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local       # change to s3 in production
```

### Redis
```env
REDIS_CLIENT=phpredis
REDIS_HOST=redis            # Docker service name
REDIS_PASSWORD=null         # MUST be set in production
REDIS_PORT=6379
```

### Mail (Mailpit in dev)
```env
MAIL_MAILER=smtp
MAIL_HOST=mailpit           # Docker service — view at http://localhost:8025
MAIL_PORT=1025
MAIL_FROM_ADDRESS=noreply@nostosemr.com
MAIL_FROM_NAME=NostosEMR
```

### Laravel Reverb (WebSockets)
```env
REVERB_APP_ID=              # generate: php artisan reverb:install
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=reverb          # Docker service
REVERB_PORT=8080
REVERB_SCHEME=http          # wss in production
VITE_REVERB_APP_KEY=${REVERB_APP_KEY}
VITE_REVERB_HOST=${REVERB_HOST}
VITE_REVERB_PORT=${REVERB_PORT}
VITE_REVERB_SCHEME=${REVERB_SCHEME}
```

### OAuth (Google / Yahoo)
```env
GOOGLE_CLIENT_ID=           # register at console.cloud.google.com
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost/auth/google/callback
YAHOO_CLIENT_ID=
YAHOO_CLIENT_SECRET=
YAHOO_REDIRECT_URI=http://localhost/auth/yahoo/callback
```

### OTP / Security
```env
OTP_EXPIRES_MINUTES=10
OTP_MAX_ATTEMPTS=5
```

### Transport Webhook (in config/services.php)
```php
'transport' => [
    'webhook_secret' => env('TRANSPORT_WEBHOOK_SECRET'),
    // null = fail-closed (rejects ALL webhooks)
]
```

### Not Yet Active (production requirements)
```env
# S3 document storage (DEBT-021)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=

# Meilisearch (DEBT-022)
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://meilisearch:7700
```

---

## 6. Database Structure

### Table Prefix Rules

| Prefix | Owner | Access |
|--------|-------|--------|
| `emr_*` | NostosEMR | EMR reads + writes |
| `shared_*` | Both apps | Both apps read + write |
| `transport_*` | Nostos Transport | EMR reads only (via TransportBridgeService) |

### Migration List (74 migrations)

| # | Migration | Creates / Alters |
|---|-----------|------------------|
| 1–3 | `0001_01_01_*` | Laravel defaults (users, cache, jobs) |
| 4 | `2024_01_01_000001` | `shared_tenants` |
| 5 | `2024_01_01_000002` | `shared_sites` |
| 6 | `2024_01_01_000003` | `shared_users` |
| 7 | `2024_01_01_000004` | `shared_otp_codes` |
| 8 | `2024_01_01_000005` | `shared_sessions` |
| 9 | `2024_01_01_000006` | `shared_audit_logs` |
| 10 | `2024_01_01_000007` | `emr_role_permissions` |
| 11 | `2024_02_01_000001` | adds `mrn_prefix` to `shared_sites` |
| 12 | `2024_02_01_000002` | `emr_participants` |
| 13 | `2024_02_01_000003` | `emr_participant_addresses` |
| 14 | `2024_02_01_000004` | `emr_participant_contacts` |
| 15 | `2024_02_01_000005` | `emr_insurance_coverages` |
| 16 | `2024_02_01_000006` | `emr_participant_flags` |
| 17 | `2024_03_01_000001` | `emr_clinical_notes` |
| 18 | `2024_03_01_000002` | `emr_vitals` |
| 19 | `2024_03_01_000003` | `emr_assessments` |
| 20 | `2024_03_01_000004` | `emr_problems` |
| 21 | `2024_03_01_000005` | `emr_allergies` |
| 22 | `2024_03_01_000006` | `emr_adl_records` |
| 23 | `2024_03_01_000007` | `emr_adl_thresholds` |
| 24 | `2024_03_01_000008` | `emr_icd10_lookup` |
| 25 | `2024_04_01_000001` | `emr_alerts` |
| 26 | `2024_04_01_000002` | `emr_care_plans` |
| 27 | `2024_04_01_000003` | `emr_care_plan_goals` |
| 28 | `2024_04_01_000004` | `emr_idt_meetings` |
| 29 | `2024_04_01_000005` | `emr_idt_participant_reviews` |
| 30 | `2024_04_01_000006` | `emr_sdrs` |
| 31 | `2024_05_01_000001` | adds index to `shared_audit_logs` |
| 32 | `2024_05_01_000002` | adds `super_admin` role to `shared_users` CHECK |
| 33 | `2024_05_01_000003` | `emr_locations` |
| 34 | `2024_05_01_000004` | `emr_appointments` |
| 35 | `2024_05_02_000001` | `emr_transport_requests` |
| 36 | `2024_05_02_000002` | adds FK `transport_request_id` to `emr_appointments` |
| 37 | `2024_05_03_000001` | `emr_medications` |
| 38 | `2024_05_03_000002` | `emr_emar_records` |
| 39 | `2024_05_03_000003` | `emr_med_reconciliations` |
| 40 | `2024_05_03_000004` | `emr_drug_interaction_alerts` |
| 41 | `2024_05_03_000005` | `emr_medications_reference` |
| 42 | `2024_05_03_000006` | `emr_drug_interactions_reference` |
| 43 | `2024_05_04_000001` | adds Phase 5D columns to `emr_med_reconciliations` |
| 44 | `2024_05_04_000002` | makes `reconciled_at` nullable |
| 45 | `2024_06_01_000001` | `emr_referrals` (enrollment state machine) |
| 46 | `2024_06_02_000001` | `emr_incidents` |
| 47 | `2024_06_03_000001` | `emr_capitation_records` |
| 48 | `2024_06_03_000002` | `emr_encounter_log` |
| 49 | `2024_06_03_000003` | `emr_authorizations` |
| 50 | `2024_06_03_000004` | `emr_api_tokens` |
| 51 | `2024_06_04_000001` | `emr_integration_log` |
| 52 | `2024_06_04_000002` | makes `requesting_user_id` nullable on `emr_sdrs` |
| 53 | `2024_07_01_000001` | `emr_chat_channels` |
| 54 | `2024_07_01_000002` | `emr_chat_memberships` |
| 55 | `2024_07_01_000003` | `emr_chat_messages` |
| 56 | `2024_07_01_000004` | adds `notification_preferences` to `shared_users` |
| 57 | `2024_08_01_000001` | `emr_documents` |
| 58 | `2024_09_01_000001` | adds 14 billing fields to `emr_encounter_log` |
| 59 | `2024_09_01_000002` | `emr_edi_batches` |
| 60 | `2024_09_01_000003` | adds HCC fields to `emr_capitation_records` |
| 61 | `2024_09_01_000004` | `emr_pde_records` |
| 62 | `2024_09_01_000005` | `emr_hos_m_surveys` |
| 63 | `2024_09_01_000006` | `emr_hpms_submissions` |
| 64 | `2024_09_01_000007` | `emr_hcc_mappings` |
| 65 | `2024_09_02_000001` | `emr_participant_risk_scores` |
| 66 | `2024_09_02_000002` | `emr_state_medicaid_configs` |
| 67 | `2024_10_01_000001` | `emr_participant_site_transfers` |
| 68 | `2024_10_02_000001` | adds `executive` + `super_admin` to `shared_users.department` CHECK |
| 69 | `2024_10_02_000002` | adds `executive` + `super_admin` to `emr_role_permissions.department` CHECK |
| 70 | `2024_11_01_000001` | `emr_immunizations` |
| 71 | `2024_11_01_000002` | `emr_social_determinants` |
| 72 | `2024_11_01_000003` | `emr_procedures` |
| 73 | `2024_11_01_000004` | adds advance directive columns to `emr_participants` |
| 74 | `2024_11_01_000005` | `emr_ehi_exports` |

### Key Relationships

```
shared_tenants
  └── shared_sites (tenant_id FK)
  └── shared_users (tenant_id FK)
       └── emr_participants (tenant_id + site_id FK)
            ├── emr_clinical_notes
            ├── emr_vitals (append-only)
            ├── emr_assessments
            ├── emr_problems ──► emr_icd10_lookup
            ├── emr_allergies
            ├── emr_adl_records (append-only)
            ├── emr_medications ──► emr_emar_records
            ├── emr_care_plans ──► emr_care_plan_goals
            ├── emr_sdrs
            ├── emr_appointments ──► emr_transport_requests
            ├── emr_immunizations
            ├── emr_procedures
            ├── emr_social_determinants
            ├── emr_participant_risk_scores
            └── emr_ehi_exports

emr_capitation_records (tenant + participant)
emr_encounter_log (append-only, tenant + participant)
emr_edi_batches (tenant)
shared_audit_logs (append-only, tenant)
emr_chat_channels ──► emr_chat_memberships ──► emr_chat_messages
```

### Adding a New Department (requires DB change)

When adding a new department value, you MUST update two PostgreSQL CHECK constraints via `DB::statement()` in a new migration:

```php
// Drop and re-add shared_users.department CHECK
DB::statement("ALTER TABLE shared_users DROP CONSTRAINT IF EXISTS shared_users_department_check");
DB::statement("ALTER TABLE shared_users ADD CONSTRAINT shared_users_department_check
    CHECK (department IN ('primary_care', ..., 'new_dept'))");

// Drop and re-add emr_role_permissions.department CHECK
DB::statement("ALTER TABLE emr_role_permissions DROP CONSTRAINT IF EXISTS emr_role_permissions_department_check");
DB::statement("ALTER TABLE emr_role_permissions ADD CONSTRAINT emr_role_permissions_department_check
    CHECK (department IN ('primary_care', ..., 'new_dept'))");
```

See migrations 68 and 69 for the pattern.

---

## 7. RBAC System

### How It Works

Permissions are stored in `emr_role_permissions` (34 modules × 14 departments × 2 roles = up to 952 rows). Each row grants a department+role combination access to a module with specific capabilities.

```php
// Schema: emr_role_permissions
department  | VARCHAR  -- e.g. 'primary_care'
role        | VARCHAR  -- 'admin' or 'standard'
module      | VARCHAR  -- e.g. 'clinical_notes'
can_view    | BOOLEAN
can_create  | BOOLEAN
can_edit    | BOOLEAN
can_delete  | BOOLEAN
```

### How Access Is Checked

`CheckDepartmentAccess` middleware is applied to all authenticated routes. It:

1. Reads `module` from the route's middleware parameter (e.g., `->middleware('department:clinical_notes')`)
2. Queries `emr_role_permissions` for the user's dept + role + that module
3. Returns 403 if not found or `can_view = false`
4. Special bypasses: `isSuperAdmin()` (role='super_admin'), `isDeptSuperAdmin()` (department='super_admin')

```php
// In CheckDepartmentAccess.php
if ($user->isSuperAdmin() && !session('impersonating_user_id')) {
    return $next($request); // bypass all checks
}
```

### Navigation Is Also Permission-Driven

`PermissionService::navGroups()` returns only the nav items the current user can access. The frontend (`AppShell.tsx`) renders navigation based entirely on the server-provided `auth.nav_groups` prop — no client-side permission checks.

### How to Add a New Module

1. **Create controller and routes** with `->middleware('department:your_module')` on routes
2. **Seed permissions** in `PermissionSeeder.php`:
```php
// Add to the $modules array
'your_module' => [
    'primary_care' => $full,   // ['can_view'=>1, 'can_create'=>1, 'can_edit'=>1, 'can_delete'=>1]
    'it_admin'     => $full,
    'finance'      => $view,   // ['can_view'=>1, 'can_create'=>0, 'can_edit'=>0, 'can_delete'=>0]
    // ... other depts
],
```
3. **Run the seeder** (safe to re-run — uses upsert):
```bash
docker compose exec -T laravel.test php artisan db:seed --class=PermissionSeeder
```
4. **Add nav item** in `PermissionService::navGroups()` if needed

### Special Roles

| Role / Department | Who | What they can do |
|---|---|---|
| `role = 'super_admin'` | tj@nostos.tech | Impersonate any user, access all pages, view all depts. Can see SuperAdmin/Index panel. |
| `department = 'super_admin'` | Nostos staff | Cross-tenant read access to all orgs. NostosAdminBanner shown. No impersonation UI. |
| `department = 'executive'` | PACE org executives | Cross-site read-only within own tenant. Site switcher available. Executive dashboard. |

**Important:** These two "super_admin" concepts are different. Do not conflate them.
- `role = 'super_admin'` is the impersonation/platform admin role for Nostos employees
- `department = 'super_admin'` is a cross-tenant read-only role for Nostos support staff

---

## 8. Key Service Classes

All services are in `app/Services/`. Each is registered as a singleton or injected via constructor DI.

---

### AdlThresholdService
**Purpose:** Evaluates ADL scores against per-participant thresholds. Fires alerts on breach.
**Key methods:** `evaluate(AdlRecord $record)`, `createAlert(Participant $p, string $adlType)`
**Called by:** `AdlRecordObserver` (fires on every ADL record creation)
**Test:** `tests/Unit/AdlThresholdServiceTest.php`

---

### AlertService
**Purpose:** Creates and broadcasts real-time alerts to target departments.
**Key methods:** `create(array $attrs)`, `acknowledge(Alert $alert, User $user)`, `resolve(Alert $alert, User $user)`
**Note:** Always use `AlertService::create()` — never `Alert::create()` directly. The service fires `AlertCreatedEvent` for Reverb broadcast.
**Test:** `tests/Feature/AlertTest.php`

---

### BillingComplianceService
**Purpose:** Runs a 5-category, 10-check billing compliance checklist. Returns pass/warn/fail per check.
**Key methods:** `getChecklist(int $tenantId): array`
**Overall status:** worst of all 10 (fail > warn > pass)
**Test:** `tests/Feature/BillingComplianceChecklistTest.php`

---

### ChatService
**Purpose:** Creates and manages chat channels. Handles DM deduplication, department channels, participant IDT channels.
**Key methods:** `createDepartmentChannels(Tenant $tenant)`, `getOrCreateDmChannel(User $a, User $b)`, `createParticipantIdtChannel(Participant $p)`, `addMembersToChannel(ChatChannel $ch, array $userIds)`
**Note:** `addMembersToChannel` uses `insertOrIgnore` — safe to call multiple times (idempotent).
**Test:** `tests/Feature/ChatTest.php`, `tests/Feature/ChannelAutoCreationTest.php`

---

### ConflictDetectionService
**Purpose:** Detects overlapping appointments. Two distinct checks: participant time-overlap (any dept) and 2-hour transport buffer (transport-required appts only).
**Key methods:** `checkParticipantConflict(...)`, `checkTransportWindow(...)`
**Returns:** `null` (no conflict) or `['type'=>'conflict'|'transport_conflict', ...]`
**Note:** Half-open intervals — adjacent appointments (end == next start) are NOT conflicts.
**Test:** `tests/Unit/ConflictDetectionServiceTest.php`

---

### DrugInteractionService
**Purpose:** Non-blocking drug interaction checks using `emr_drug_interactions_reference` table.
**Key methods:** `checkInteractions(Medication $med): array`, `hasPendingInteraction(Participant $p, string $drugName): bool`
**Idempotency:** Uses `drug_name` (not `medication_id`) to prevent duplicate alerts when the same drug is re-prescribed.
**Test:** `tests/Unit/DrugInteractionServiceTest.php`

---

### Edi837PBuilderService
**Purpose:** Builds X12 5010A1 EDI 837P professional claim batches from encounter records.
**Key methods:** `buildBatch(EdiBatch $batch): string`, `parseBatchResponse(string $edi): array`
**Note:** EDI builder is complete. Live clearinghouse submission is not yet configured (DEBT-019).
**Test:** `tests/Unit/Edi837PBuilderServiceTest.php`

---

### EhiExportService
**Purpose:** Generates a complete EHI (Electronic Health Information) export ZIP per the 21st Century Cures Act.
**Key methods:** `generate(Participant $participant, User $requestedBy): EhiExport`
**Output:** ZIP at `storage/app/ehi_exports/` containing `fhir/` (10 FHIR R4 resources) + `clinical/` (5 clinical datasets) + `manifest.json`
**Token:** 64-char hex, 24h TTL, single-use
**Note:** Uses raw `ZipArchive` (not Storage facade). The controller uses `Storage::disk('local')->path()` for testability.
**Test:** `tests/Feature/EhiExportTest.php`

---

### EnrollmentService
**Purpose:** Enforces the 9-state enrollment workflow state machine.
**Key methods:** `transition(Referral $ref, string $toStatus, array $data): Referral`, `handleEnrollment(...)`, `disenroll(...)`
**Valid transitions:** Defined in `VALID_TRANSITIONS` constant. Throws `InvalidStateTransitionException` on invalid moves.
**Test:** `tests/Unit/EnrollmentStateMachineTest.php`, `tests/Feature/EnrollmentTest.php`

---

### HccRiskScoringService
**Purpose:** Maps ICD-10 diagnosis codes to CMS-HCC categories (V28 model). Calculates RAF scores.
**Key methods:** `scoreParticipant(Participant $p): array`, `getGapSummary(Participant $p): array`
**Data:** 40+ V28 HCC mappings in `emr_hcc_mappings` (seeded by `HccMappingSeeder`)
**Test:** `tests/Unit/HccRiskScoringServiceTest.php`

---

### HpmsFileService
**Purpose:** Generates CMS HPMS-format enrollment/disenrollment/quality files.
**Key methods:** `generateEnrollmentFile(int $tenantId): string`, `generateDisenrollmentFile(...)`, `generateQualityDataFile(...)`
**Note:** `generateQualityDataFile()` now computes live flu/pneumo immunization rates from `emr_immunizations` (Phase 11B).
**Test:** `tests/Unit/HpmsFileServiceTest.php`

---

### ImpersonationService
**Purpose:** Session-based user impersonation for super-admins. Manages two session keys.
**Key methods:** `start(User $target, User $actor)`, `stop(User $actor)`, `getImpersonatedUser(): ?User`, `isImpersonating(): bool`, `setViewAs(string $dept)`, `clearViewAs()`
**Session keys:** `impersonating_user_id` (full impersonation) + `viewing_as_department` (dashboard preview only)
**Audit:** Always logs with REAL super-admin's user ID, never the impersonated user's.
**Test:** `tests/Feature/ImpersonationTest.php`

---

### IncidentService
**Purpose:** Creates and manages incidents. Auto-sets `rca_required` based on CMS 42 CFR 460.136.
**Key methods:** `createIncident(array $data, User $user): Incident`, `submitRca(...)`, `closeIncident(...)`
**Critical rule:** `rca_required` is NEVER set via request — only by this service based on `Incident::RCA_REQUIRED_TYPES`. Incidents cannot be closed while RCA is pending.
**Test:** `tests/Feature/IncidentTest.php`

---

### MedReconciliationService
**Purpose:** 5-step medication reconciliation workflow.
**Key methods:** `startReconciliation(...)`, `addPriorMedications(...)`, `generateComparison(...)`, `applyDecisions(...)`, `providerApproval(...)`
**Lifecycle:** `in_progress → decisions_made → approved` (approved is immutable — `assertNotLocked()` guard)
**Note:** Only one active reconciliation per participant at a time. `startReconciliation()` is idempotent.
**Test:** `tests/Unit/MedReconciliationServiceTest.php`

---

### MedicationScheduleService
**Purpose:** Generates nightly MAR (Medication Administration Record) entries based on active medication schedules.
**Key methods:** `generateDailyMar(int $tenantId, Carbon $date)`, `getScheduledDoses(Medication $med, Carbon $date): array`
**Test:** `tests/Unit/MedicationScheduleServiceTest.php`

---

### MrnService
**Purpose:** Generates unique MRNs for new participants.
**Key methods:** `generate(Site $site): string`
**Format:** `{site.mrn_prefix}-{zero-padded-sequence}` (e.g., `EAST-00042`)
**Note:** MRN is globally unique across all tenants. SiteFactory must set a unique `mrn_prefix` to avoid collisions in parallel tests.
**Test:** `tests/Unit/ParticipantMrnTest.php`

---

### NotificationDispatcher
**Purpose:** Routes notifications to users based on their notification preferences.
**Key methods:** `dispatch(User $user, string $type, string $message): void`
**Delivery modes:** `in_app_only` (default), `email_immediate`, `email_digest` (Redis counter), `off`
**Note:** Email bodies contain ZERO PHI — only a generic "log in to view" message.
**Test:** `tests/Unit/NotificationDispatcherTest.php`

---

### OtpService
**Purpose:** Generates and validates OTP codes for passwordless login.
**Key methods:** `generate(User $user): OtpCode`, `validate(User $user, string $code): bool`
**Security:** 10-min expiry, max 5 attempts per IP (rate-limited), single-use (`markUsed()` sets `used_at`).
**Test:** `tests/Feature/AuthTest.php`

---

### PermissionService
**Purpose:** Provides navigation groups + permission maps for the current user. Used by `HandleInertiaRequests` to build `auth.nav_groups` and `auth.permissions` shared props.
**Key methods:** `navGroups(User $user): array`, `permissionMap(User $user): array`
**Note:** Super-admins get ALL nav groups. When impersonating, the effective user's permissions are used.

---

### QaMetricsService
**Purpose:** Computes 6 PACE quality KPIs for the QA dashboard.
**Key methods:** `getSdrComplianceRate(int $tenantId): float`, `getOverdueAssessments(...)`, `getUnsignedNotes(...)`, `getOpenIncidents(...)`, `getOverdueCarePlans(...)`, `getHospitalizationsThisMonth(...)`
**Test:** Covered in `tests/Feature/QaDashboardTest.php`

---

### RevenueIntegrityService
**Purpose:** Computes 6 revenue integrity KPIs: diagnosis capture rate, HCC gap count, RAF trending, projected vs actual capitation variance.
**Key methods:** `getKpis(int $tenantId): array`
**Test:** Covered in `tests/Feature/RevenueIntegrityTest.php`

---

### RiskAdjustmentService
**Purpose:** Lifecycle management for participant RAF scores. Wraps `HccRiskScoringService`.
**Key methods:** `updateParticipantRiskScore(Participant $p): ParticipantRiskScore`, `getRiskAdjustmentGaps(int $tenantId): array`
**Test:** `tests/Unit/RiskAdjustmentServiceTest.php`

---

### SdrDeadlineService
**Purpose:** Computes SDR deadline status for use by `SdrDeadlineEnforcementJob`.
**Key methods:** `getStatus(Sdr $sdr): array` — returns hours remaining and whether overdue
**Note:** The 72-hour window starts at `submitted_at`, which is set in `Sdr::boot()`. Never override `due_at` manually.

---

### TransferService
**Purpose:** Manages multi-site participant transfers through the full lifecycle.
**Key methods:** `requestTransfer(...)`, `approveTransfer(...)`, `cancelTransfer(...)`, `completeTransfer(...)`
**Note:** All operations wrapped in DB transactions. IDT chat alerts posted to both origin and destination sites. `completeTransfer()` passes `userId=null` (system job context).
**Test:** `tests/Unit/TransferServiceTest.php`

---

### TransportBridgeService
**Purpose:** Anti-corruption layer for all writes to the transport app's data. Currently stubbed.
**Key methods:** `createTripRequest(TransportRequest $req): ?string`, `cancelTrip(...)`, `validateWebhookSignature(Request $req): bool`
**Current state:** `createTripRequest()` stubs the outbound call. Real integration requires Nostos PACE transport deployment (see Section 10).
**Webhook:** HMAC-SHA256 validation is fail-closed — null secret rejects ALL webhooks.

---

## 9. Background Jobs

All jobs run via **Laravel Horizon** (Redis-backed). Monitor at http://localhost/horizon (it_admin access only).

| Job | Queue | Schedule | Purpose |
|-----|-------|----------|---------|
| `SdrDeadlineEnforcementJob` | `sdr-enforcement` | Every 15 min | Alerts at 24h / 8h remaining; escalates when overdue |
| `LateMarDetectionJob` | `mar-detection` | Every 30 min | Flags eMAR records past scheduled time + 30min grace |
| `DocumentationComplianceJob` | `compliance` | Daily 6:00 AM | Unsigned notes + overdue assessments → alerts (deduplicated) |
| `DigestNotificationJob` | `notifications` | Every 2 hours | Sends digest email to users with pending notifications |
| `ProcessTransportStatusWebhookJob` | `transport-webhooks` | On-demand (webhook) | Syncs transport trip status back from transport app |
| `ProcessHl7AdtJob` | `integrations` | On-demand (HL7) | A01 admit / A03 discharge / A08 update from hospital |
| `ProcessLabResultJob` | `integrations` | On-demand (lab) | Normal lab → encounter log; abnormal → primary_care alert |
| `TransferCompletionJob` | `transfers` | Daily 7:00 AM | Completes approved participant transfers past target date |

### Failure Behavior

- **SdrDeadlineEnforcementJob, LateMarDetectionJob, DocumentationComplianceJob:** No `$tries` set. Failed job logged to Horizon. Non-throwing — bad records skipped, batch continues.
- **DigestNotificationJob:** Per-user send failures logged as warning; Redis counter NOT cleared on failure (retried next cycle).
- **ProcessTransportStatusWebhookJob, ProcessHl7AdtJob, ProcessLabResultJob:** `$tries = 3`. After 3 failures, moves to Horizon failed jobs queue. IT Admin can retry from `/it-admin/integrations`.
- **TransferCompletionJob:** Per-transfer try/catch — individual failures logged and skipped, batch continues.

### Production Queue Configuration

In development, all queues fall through to the `default` worker. For production, add supervisor configurations in `config/horizon.php` for each named queue:

```php
'supervisor-sdr' => ['queue' => ['sdr-enforcement'], 'processes' => 2],
'supervisor-mar' => ['queue' => ['mar-detection'], 'processes' => 1],
// etc.
```

---

## 10. Transport Integration Status

### Current State

All transport-facing routes show a `ComingSoonBanner` with the message "Nostos Transport Integration Pending". This is intentional — the transport integration requires a fresh PACE-specific deployment of the Nostos transport app (not a plug-in of the existing non-PACE deployment).

### What Works Today

- `emr_transport_requests` table — full CRUD via `TransportRequestController`
- `TransportBridgeService` — written and tested with stubbed HTTP calls
- Webhook endpoint (`POST /integrations/transport/status-webhook`) — HMAC validated, job dispatched
- Transport manifest page (`/transport/manifest`) — UI built, shows ComingSoonBanner

### Stubbed Routes (all show ComingSoonBanner)

```
/transport/scheduler    /transport/map         /transport/cancellations
/transport/vehicles     /transport/vendors     /transport/credentials
/transport/broker       /transport/calls
Transportation Dashboard (all 4 widgets)
```

### How to Connect the Live Integration

When the Nostos PACE transport deployment is ready:

1. **Update `TransportBridgeService::createTripRequest()`** — replace the stub with a real HTTP call to the transport app's API using Guzzle
2. **Configure `config/services.php`** — add transport app base URL + HMAC secret per tenant
3. **Remove `ComingSoonBanner`** from transport route controllers and replace with live data
4. **Test the webhook flow** — POST `/integrations/transport/status-webhook` with HMAC signature from transport app

---

## 11. Architecture Decision Records

These are the "why" behind non-obvious decisions. Read before changing anything architectural.

---

**ADR-001: Shared PostgreSQL database**

EMR and transport apps share one PostgreSQL database with table prefixes (`emr_*`, `shared_*`, `transport_*`). This enables cheap cross-app joins and a shared user/tenant model.

*Consequence:* Transport app schema changes to `shared_*` tables can break EMR. No hard DB isolation between apps. FKs across `emr_*` → `transport_*` are service-layer-only (no DB FK constraint).

---

**ADR-002: TransportBridgeService as anti-corruption layer**

All EMR writes to transport data go through `TransportBridgeService`. EMR never accesses `transport_*` models directly.

*Consequence:* Tests never need the transport app running. The service boundary is clear and mockable. Real integration requires only updating the service.

---

**ADR-003: Inertia.js over separate SPA**

A single Laravel + React deployment. Server renders page props via Inertia; React handles client rendering. No JWT/API auth for page requests.

*Consequence:* Simpler deployment, no token management. Tighter coupling between backend data shapes and frontend TypeScript interfaces. FHIR and integration endpoints are REST (outside Inertia) — this dual-mode adds some complexity.

---

**ADR-004: Laravel Reverb for WebSockets**

Self-hosted WebSocket server (first-party Laravel package). No PHI routes through Pusher or other external services.

*Consequence:* No PHI leaves the infrastructure. Reverb requires an always-on supervisor process. On Docker + Windows, `SUPERVISOR_PHP_USER: root` is required to bypass a gosu user-switching bug.

---

**ADR-005: Soft deletes everywhere (HIPAA data retention)**

HIPAA requires 6-year PHI retention. Hard deletes are prohibited for clinical records.

*Consequence:* All queries use Laravel's global soft-delete scope automatically. Storage grows over time — a pruning policy is needed for go-live (move to cold storage after 7 years). AuditLog and append-only tables have no deletes at all (PostgreSQL rules block UPDATE/DELETE).

---

**ADR-006: emr_role_permissions table over Laravel Gates**

Permissions are stored in the database, not in code. IT admins can adjust permissions without a code deploy.

*Consequence:* Every authenticated request hits the DB for a permission check. A future enhancement could cache the permission map per user session.

---

**ADR-007: CLAUDE.md as AI session memory**

The `CLAUDE.md` file at project root serves as working memory for Claude Code AI sessions. It is read at the start of every session and updated at the end.

*For human developers:* The `CLAUDE.md` "SESSION LOG" section is the closest thing to a git-log of what was built and why. Read it for historical context.

---

**ADR-008: OTP-only authentication**

No passwords in the system. All accounts are provisioned by IT Admin. Authentication is via 6-digit OTP (email) or Google/Yahoo OAuth.

*Consequence:* No password reset flow needed. No brute-force password attacks possible. Requires a reliable email delivery system in production.

---

**ADR-009: Capitation billing model (not fee-for-service)**

PACE billing is monthly capitation. Encounter data drives *next year's* rate via CMS-HCC risk adjustment, not this year's payment.

*Consequence:* The billing engine (Phase 9B) must focus on accurate diagnosis code capture and HCC mapping, not claim approval status. An "accepted" status from CMS on encounters means "we received your data," not "we're paying you for this visit."

---

## 12. Known Technical Debt

### 🔴 Must Resolve Before Go-Live

| ID | Issue | Why It Matters |
|----|-------|----------------|
| DEBT-019 | **No live EDI 837P clearinghouse submission** | 837P builder exists but no clearinghouse API credentials configured. CMS will not receive encounter data. |
| DEBT-021 | **Documents stored on local disk** | `storage/app/participants/{id}/` — not encrypted, not backed up, not HIPAA-eligible |
| DEBT-027 | **No penetration test** | HIPAA Security Rule requires external security testing before PHI goes live |
| DEBT-028 | **No formal HIPAA Risk Assessment** | Required by 45 CFR 164.308(a)(1)(ii)(A) before handling real PHI |
| DEBT-020 | **OAuth credentials are placeholders** | Google/Yahoo OAuth requires real registered client IDs |

### 🟡 High Priority (before first real user)

| ID | Issue | Impact |
|----|-------|--------|
| DEBT-023 | **Transport integration is stubbed** | All transport scheduling shows ComingSoonBanner |
| DEBT-035 | **Part D PDE tracking not submitted** | PDE records captured but no MARx submission pipeline |
| DEBT-036 | **HPMS enrollment file not submitted** | File generator exists but no live CSSC submission |
| DEBT-038 | **State Medicaid 837 submission deferred** | Config framework done; actual EDI submission not built |
| DEBT-022 | **Meilisearch not configured** | GlobalSearch falls back to DB queries — slow at scale |

### 🟢 Low Priority (v1.1+)

| ID | Issue | Notes |
|----|-------|-------|
| DEBT-001–007 | Missing factories (7 models) | Covered by feature tests; needed only for isolated unit tests |
| DEBT-008–012 | Missing unit tests (5 services) | Services covered by feature tests; unit tests for edge cases |
| DEBT-013–015 | Missing feature tests (3 models) | InsuranceCoverage, ParticipantAddress, Icd10Lookup |
| DEBT-016 | PHPDoc gaps (ImpersonationService) | 4 trivial getter/setter methods |
| DEBT-017 | FHIR write endpoints | R4 reads are complete; writes needed for ONC cert |
| DEBT-018 | Real HL7 v2 parser | Current connector accepts pre-parsed JSON |
| DEBT-024 | USCDI v3 full compliance | USCDI requires 30+ data classes; ~18 implemented |
| DEBT-025 | Demographics: race/ethnicity/SOGI | Required for ONC certification |
| DEBT-029 | PHP deprecation cleanup | 32 PDO + 92 PHPUnit deprecations (non-blocking) |

---

## 13. Go-Live Checklist

This checklist must be 100% complete before real patient data enters the system. Items are grouped by responsible party.

### Security & Compliance

- [ ] Formal HIPAA Security Risk Assessment (SRA) — 45 CFR 164.308(a)(1)(ii)(A) — **required by law**
- [ ] External penetration test by qualified security firm
- [ ] BAA signed with all vendors: AWS, email delivery (SES/Mailgun), Redis provider, clearinghouse
- [ ] HIPAA-trained Security Officer designated in writing
- [ ] All staff complete HIPAA Privacy & Security training — documented with sign-off dates
- [ ] Privacy Policy + Notice of Privacy Practices reviewed by healthcare attorney
- [ ] Incident Response Plan documented and tested
- [ ] Business Associate Agreement template created for downstream subcontractors

### CMS / HPMS Enrollment

- [ ] PACE H-number registered with CMS per participating tenant/organization
- [ ] CSSC Operations account obtained for 837P encounter submission
- [ ] EDI testing completed with CSSC (test environment first)
- [ ] HPMS system access obtained per organization (requires CMS credentialing)
- [ ] MARx / Part D enrollment with CMS if providing Part D pharmacy benefit
- [ ] State Medicaid PACE contract in place + state Medicaid encounter submission setup

### Technical Infrastructure

- [ ] Deployed to HIPAA-eligible infrastructure (AWS HIPAA BAA required — EC2, RDS, S3, KMS)
- [ ] TLS 1.3 enforced on all endpoints (no HTTP, no TLS 1.0/1.1)
- [ ] Database encryption at rest (AWS RDS encrypted storage + `sslmode=require` in DB config)
- [ ] PHI field-level encryption for sensitive fields: SSN (`ssn_last_four`), Medicare ID, Medicaid ID — use AWS KMS
- [ ] Documents migrated to S3 with SSE-KMS (`FILESYSTEM_DISK=s3`)
- [ ] Session encryption enabled (`SESSION_ENCRYPT=true`)
- [ ] Redis password authentication enabled (`REDIS_PASSWORD=...`)
- [ ] `SESSION_LIFETIME=15` (HIPAA idle timeout for production)
- [ ] Automated database backup configured + point-in-time recovery tested
- [ ] CloudWatch / PagerDuty monitoring and alerting configured
- [ ] Laravel Horizon production queue workers configured (named queues)
- [ ] Meilisearch configured for production (`SCOUT_DRIVER=meilisearch`)

### Authentication & Access

- [ ] Google OAuth credentials registered and configured (`GOOGLE_CLIENT_ID/SECRET`)
- [ ] Yahoo OAuth credentials configured (if needed)
- [ ] Production `.env` secrets stored in AWS Secrets Manager (not in `.env` file on disk)
- [ ] Real email delivery configured (not Mailpit) — AWS SES or equivalent with DKIM/SPF

### Transport Integration

- [ ] Nostos PACE transport deployment completed and tested
- [ ] `TransportBridgeService::createTripRequest()` updated to live API calls
- [ ] Webhook endpoint configured with correct HMAC keys per tenant (`TRANSPORT_WEBHOOK_SECRET`)
- [ ] All `ComingSoonBanner` components removed from transport routes
- [ ] Transport integration tested end-to-end with sample trips

### Data & Go-Live

- [ ] Data migration plan for existing participant records (if any)
- [ ] Test/demo data (`*@sunrisepace-demo.test` users, 'Testpatient' participants) removed from production DB
- [ ] Initial load tested with de-identified data
- [ ] Staff training completed for each department
- [ ] IT Admin provisioned for each participating organization
- [ ] PermissionSeeder run for each new tenant

---

## 14. Post-Launch Roadmap (v1.1+)

These items are planned but not yet built. They are documented in the codebase as `ComingSoon` stubs or `PlannedFeatureBanner` components.

### Clinical / USCDI v3

| Feature | Why | Complexity |
|---------|-----|-----------|
| **USCDI v3 demographics** (race, ethnicity, SOGI, gender identity) | ONC certification requirement | S — migration + form fields |
| **Structured lab results** (`emr_lab_results` with LOINC codes) | USCDI v3 + real-time clinical decision support | M |
| **Care team roster** (`emr_participant_care_teams`) | USCDI v3 CareTeam resource | S |
| **Change-of-condition assessment type** | CMS 42 CFR 460.68(b) | S |
| **CPOE / clinical orders** (lab, imaging, referral) | 42 CFR 460.74 — currently documented as manual workflow | XL |
| **Consent + grievance module** | 42 CFR 460.96 + HIPAA | L |

### Interoperability

| Feature | Why | Complexity |
|---------|-----|-----------|
| **FHIR write endpoints** (POST/PUT) | ONC HTI-1 Information Blocking Rule | L |
| **FHIR Bulk Data Access** (`/$export`) | ONC certification | L |
| **SMART on FHIR** authorization | Third-party app access | XL |
| **Real HL7 v2 parser** | Current connector accepts pre-parsed JSON only | L |
| **NCPDP SCRIPT e-prescribing** | Electronic prescriptions to pharmacies | XL |

### Billing

| Feature | Why | Complexity |
|---------|-----|-----------|
| **Live clearinghouse submission** (Availity / Change Healthcare) | CMS encounter data required for risk adjustment | L |
| **State Medicaid 837 submission pipeline** | Dual-eligible encounter reporting | XL |
| **MARx / Part D PDE submission** | CMS Part D pharmacy benefit reporting | L |
| **HPMS live submission** | CMS enrollment reporting | M |

### Analytics & AI

| Feature | Why | Complexity |
|---------|-----|-----------|
| **HEDIS / CMS Stars measure calculation** | Quality reporting, annual Stars rating | XL |
| **AI documentation scribe** | Reduce clinical documentation burden | XL |
| **Advanced analytics + custom reports** | Operational insights, custom dashboards | L |

### Integrations

| Feature | Why | Complexity |
|---------|-----|-----------|
| **Video telehealth** (Zoom/Doxy integration) | Telehealth visit documentation | L |
| **Lab system direct integration** (HL7 ORU) | Real-time lab results | L |
| **Patient/family portal** | CMS transparency + patient engagement | XL |
| **Mobile app for PACE staff** | Field documentation (home care, transport) | XL |
| **ONC Health IT Certification** (ONC-ACB) | May be required for CMS program participation | XL |

---

## 15. Developer Notes & Gotchas

These are non-obvious behaviors that will waste your time if you don't know about them.

---

### node_modules are Linux-native

The `node_modules` directory was installed inside Docker (Linux). Running `npm run build` or `vite dev` on Windows will fail with cryptic errors.

**Always build inside Docker:**
```bash
docker compose exec -T laravel.test npm run build
```

---

### The AuditLog model throws on update/delete

`AuditLog` throws `ImmutableRecordException` if you call `save()` on an existing record or try to delete one. This is intentional — HIPAA requires an append-only audit trail. PostgreSQL rules at the DB level *also* block UPDATE/DELETE on `shared_audit_logs`.

**Always use `AuditLog::record(...)` to create audit entries. Never modify existing ones.**

---

### TransportBridgeService uses DB::table(), not Eloquent

The transport app's tables (`transport_trips`, etc.) have no corresponding Eloquent models in the EMR codebase. `TransportBridgeService` uses raw `DB::table()` queries because adding Eloquent models for transport tables would create tight coupling and break when the transport app schema changes.

---

### The 72-hour SDR rule and Carbon::setTestNow()

The SDR `due_at` timestamp is set in `Sdr::boot()` as `submitted_at + 72 hours`. It cannot be overridden by request. `SdrDeadlineEnforcementJob` enforces the deadline every 15 minutes.

When writing tests for deadline behavior, use:
```php
Carbon::setTestNow(now()->addHours(73)); // simulate time passing
```

---

### Why note templates are in config/, not the database

`config/emr_note_templates.php` contains the 13 note type templates (SOAP fields, therapy-specific fields, etc.). Templates are in config (not DB) because they require code changes to modify anyway — the frontend dropdowns are hardcoded to match the template field names. Moving them to DB would add complexity without benefit at this stage.

---

### The two "super_admin" concepts — do NOT confuse them

```
role = 'super_admin'          → tj@nostos.tech, impersonation UI, platform admin
department = 'super_admin'    → Nostos support staff, cross-tenant read, NostosAdminBanner
```

These are checked differently in code:
```php
$user->isSuperAdmin()      // checks role = 'super_admin'
$user->isDeptSuperAdmin()  // checks department = 'super_admin'
```

---

### How the site context switcher works

**Regular users:** Always see their own `site_id` from `shared_users`. `SiteContextMiddleware` returns this unconditionally.

**Executive users:** See their own site by default. Can switch via `POST /site-context/switch` to other sites in *their own tenant*. Active site stored in `session('active_site_id')`.

**Super-admin (role or dept):** Can switch to any site across any tenant.

---

### Why EhiExportService uses ZipArchive directly

`EhiExportService` writes ZIP files using PHP's `ZipArchive` (raw filesystem), not the Laravel `Storage` facade. This is because `ZipArchive` requires a real filesystem path and cannot write to virtual disks.

The download controller uses `Storage::disk('local')->path($filePath)` instead of `storage_path('app/' . $filePath)` to maintain testability with `Storage::fake()`.

**If you change one, change both.**

---

### Why advance directives are on emr_participants, not a separate table

`advance_directive_status`, `advance_directive_type`, `advance_directive_reviewed_at`, and `advance_directive_reviewed_by_user_id` are columns on `emr_participants` (migration 73), not a separate `emr_advance_directives` table. This is because 42 CFR 460.96 treats directive status as a participant attribute, not a clinical event. The PACE facesheet (Overview tab) shows directive status prominently.

`hasDnr()` returns `true` ONLY for `advance_directive_type = 'dnr'`. POLST is a physician order (not a patient directive) and is distinct.

---

### CLAUDE.md and the AI memory system

`CLAUDE.md` at the project root is used as persistent memory by Claude Code AI sessions. It contains:
- Phase completion status
- Migration list
- Model/controller/service inventories
- Known issues and architectural decisions
- Session log (append-only history of changes)

It is NOT a substitute for this HANDOFF.md. CLAUDE.md is dense and AI-optimized. This document is human-optimized. Both should stay in sync.

---

### PostgreSQL max_locks_per_transaction

Running tests with `--processes=16` causes `out of shared memory` errors with 74+ tables. Always use `--processes=8`:

```bash
docker compose exec -T laravel.test php artisan test --parallel --processes=8
```

---

### SocialDeterminant enum values are non-obvious

The `SocialDeterminant` model has specific enum values that differ from what you might guess:

```php
FOOD_VALUES   = ['secure', 'at_risk', 'insecure', 'unknown']     // NOT 'adequate'
STRAIN_VALUES = ['none', 'mild', 'moderate', 'severe', 'unknown'] // NOT 'low'/'high'
TRANSPORT_VALUES = ['adequate', 'limited', 'none', 'unknown']     // 'adequate' is correct for transport
```

**Always reference the model constants, never hardcode these strings.**

---

*End of HANDOFF.md — if anything is unclear or missing, add it here.*
