# NostosEMR

**Electronic Medical Record system for PACE (Program of All-inclusive Care for the Elderly) organizations.**

NostosEMR is a HIPAA-compliant, multi-tenant EMR built as a companion application to the Nostos transportation platform. It manages the full clinical workflow for a PACE organization — from participant enrollment through interdisciplinary team care coordination, medication management, billing, compliance, and real-time communication.

> **For full developer context, see [HANDOFF.md](HANDOFF.md)** — architecture decisions, service inventory, technical debt log, go-live checklist, and developer gotchas.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Quick Start](#2-quick-start)
3. [Demo Login Accounts](#3-demo-login-accounts)
4. [Architecture](#4-architecture)
5. [Database Conventions](#5-database-conventions)
6. [RBAC System](#6-rbac-system)
7. [Transport Bridge](#7-transport-bridge)
8. [Testing](#8-testing)
9. [Phase Roadmap](#9-phase-roadmap)
10. [Known MVP Limitations](#10-known-mvp-limitations)
11. [Compliance Notes](#11-compliance-notes)

---

## 1. Overview

### What is PACE?
PACE (Program of All-inclusive Care for the Elderly) is a CMS-regulated model for delivering comprehensive care to dual-eligible (Medicare + Medicaid) elderly participants. Care is delivered by an **Interdisciplinary Team (IDT)** across 14 departments. Billing is monthly capitation (flat per-participant rate), not fee-for-service. CMS compliance is non-negotiable.

### What NostosEMR Does
- **Participant Management** — Enrollment intake, MRN assignment, demographics, insurance, flags
- **Clinical Documentation** — SOAP notes (8 templates), vitals, assessments, ADL tracking, problem list, allergies
- **Care Plans** — 12-domain plans with goals, versioning, and IDT approval workflow
- **IDT Meetings** — Meeting coordination, participant review queue, minutes, action items
- **SDR Tracking** — Service Delivery Requests with CMS-enforced 72-hour response window
- **Medications + eMAR** — Medication management, controlled substance tracking, daily MAR, drug interaction alerts
- **Medication Reconciliation** — 5-step structured workflow with provider approval
- **Scheduling** — Appointment booking with conflict detection and transport integration
- **Transport Manifest** — Real-time run sheet synced with the Nostos transport platform
- **Finance + FHIR** — Capitation records, encounter log, prior authorizations, R4 API (7 resources)
- **QA + Compliance** — Incident reporting (RCA enforcement), 6-KPI compliance dashboard, CSV export
- **Enrollment Pipeline** — 9-status Kanban (referral → enrolled), CMS eligibility workflow
- **Integrations** — HL7 ADT v2 (A01/A03/A08), lab result webhook, FHIR R4 API
- **Dashboards** — 14 department-specific live dashboards with real-time widgets
- **Chat + Notifications** — Real-time department channels, 1:1 DMs, participant IDT channels, 4 notification modes

### Relationship to Nostos Transport App
Both applications share a PostgreSQL database. NostosEMR reads from `transport_*` tables (read-only) and writes via `TransportBridgeService`, which calls the transport app's internal API. EMR tables use the `emr_*` prefix; shared tables (users, tenants, sessions) use `shared_*`.

---

## 2. Quick Start

### Prerequisites
- Docker Desktop
- Git

### Setup

```bash
# Clone the repository
git clone <repo-url> nostosemr
cd nostosemr

# Copy environment file
cp .env.example .env

# Start all Docker services
./vendor/bin/sail up -d

# Install PHP dependencies (inside Docker)
./vendor/bin/sail composer install

# Install Node dependencies (inside Docker — node_modules are Linux-native)
./vendor/bin/sail npm ci

# Generate app key
./vendor/bin/sail artisan key:generate

# Run migrations and seed demo data
./vendor/bin/sail artisan migrate:fresh --seed --seeder=DemoEnvironmentSeeder

# Build frontend assets
./vendor/bin/sail npm run build
```

### Access

| Service | URL |
|---------|-----|
| NostosEMR App | http://localhost |
| Mailpit (OTP emails) | http://localhost:8025 |
| Horizon (queue dashboard) | http://localhost/horizon |

### Login Flow (OTP)
1. Go to http://localhost/login
2. Enter any demo email from the table below
3. Check http://localhost:8025 (Mailpit) for the 6-digit OTP code
4. Enter the code — session begins

> **Note:** There are no passwords. NostosEMR uses OTP-only authentication. IT Admin provisions all accounts; self-registration is not supported.

---

## 3. Demo Login Accounts

All accounts use the email pattern: `firstname.department@sunrisepace-demo.test`

OTP codes are delivered to http://localhost:8025 (Mailpit).

| Department | Role | Email |
|---|---|---|
| Primary Care / Nursing | Admin | margaret.primary_care@sunrisepace-demo.test |
| Primary Care / Nursing | Standard | robert.primary_care@sunrisepace-demo.test |
| Therapies | Admin | patricia.therapies@sunrisepace-demo.test |
| Therapies | Standard | james.therapies@sunrisepace-demo.test |
| Social Work | Admin | dorothy.social_work@sunrisepace-demo.test |
| Social Work | Standard | richard.social_work@sunrisepace-demo.test |
| Behavioral Health | Admin | gloria.behavioral_health@sunrisepace-demo.test |
| Behavioral Health | Standard | thomas.behavioral_health@sunrisepace-demo.test |
| Dietary / Nutrition | Admin | helen.dietary@sunrisepace-demo.test |
| Dietary / Nutrition | Standard | david.dietary@sunrisepace-demo.test |
| Activities / Recreation | Admin | shirley.activities@sunrisepace-demo.test |
| Activities / Recreation | Standard | george.activities@sunrisepace-demo.test |
| Home Care | Admin | norma.home_care@sunrisepace-demo.test |
| Home Care | Standard | raymond.home_care@sunrisepace-demo.test |
| Transportation | Admin | barbara.transportation@sunrisepace-demo.test |
| Transportation | Standard | carlos.transportation@sunrisepace-demo.test |
| Pharmacy | Admin | ruth.pharmacy@sunrisepace-demo.test |
| Pharmacy | Standard | harold.pharmacy@sunrisepace-demo.test |
| IDT / Care Coordination | Admin | eleanor.idt@sunrisepace-demo.test |
| IDT / Care Coordination | Standard | frank.idt@sunrisepace-demo.test |
| Enrollment / Intake | Admin | diane.enrollment@sunrisepace-demo.test |
| Enrollment / Intake | Standard | walter.enrollment@sunrisepace-demo.test |
| Finance / Billing | Admin | susan.finance@sunrisepace-demo.test |
| Finance / Billing | Standard | william.finance@sunrisepace-demo.test |
| QA / Compliance | Admin | karen.qa_compliance@sunrisepace-demo.test |
| QA / Compliance | Standard | charles.qa_compliance@sunrisepace-demo.test |
| IT / Administration | Admin | nancy.it_admin@sunrisepace-demo.test |
| IT / Administration | Standard | joseph.it_admin@sunrisepace-demo.test |
| **Super Admin** | super_admin | tj@nostos.tech |

The Super Admin account (`tj@nostos.tech`) has unrestricted access to all modules and can impersonate any user via the "Imitate User" button in the header. It logs in via the same OTP flow.

---

## 4. Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                     Browser (React 18 + TypeScript)                 │
│              Inertia.js (SPA routing, no separate API layer)        │
└────────────────────────────┬────────────────────────────────────────┘
                             │ HTTP / Inertia requests
┌────────────────────────────▼────────────────────────────────────────┐
│                      Laravel 11 (PHP 8.3)                           │
│  ┌─────────────┐  ┌──────────────┐  ┌───────────────────────────┐  │
│  │ Controllers │  │   Services   │  │  Middleware               │  │
│  │ (35+ files) │  │ (18 classes) │  │  CheckDepartmentAccess    │  │
│  │             │  │              │  │  FhirAuthMiddleware        │  │
│  └──────┬──────┘  └──────┬───────┘  └───────────────────────────┘  │
│         └────────────────┴────────────────────────────────┐         │
│  ┌────────────────────────────────────────────────────────▼───────┐ │
│  │              Models (41) — Eloquent ORM                         │ │
│  │  Multi-tenant by tenant_id on all emr_* tables                 │ │
│  └────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────┬───────────────────────────────────────┘
                              │
          ┌───────────────────┼────────────────────────┐
          │                   │                        │
┌─────────▼─────────┐ ┌──────▼──────┐  ┌─────────────▼──────────────┐
│   PostgreSQL 16   │ │  Redis 7    │  │ Laravel Reverb (WebSockets) │
│                   │ │             │  │                              │
│  emr_*  tables   │ │  Cache      │  │  private-chat.{channelId}   │
│  shared_* tables │ │  Sessions   │  │  private-user.{userId}      │
│  transport_* (RO)│ │  Queues     │  │  dept.{dept}                │
│  Audit log       │ │  Horizon    │  │  alerts.{tenantId}          │
│  (append-only)   │ │  Digest jobs│  │                              │
└───────────────────┘ └─────────────┘  └────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  Laravel Horizon (Queue Worker)                                     │
│  Queues: default / transport-webhooks / mar-detection / compliance  │
│  Jobs: SdrDeadlineEnforcementJob · LateMarDetectionJob             │
│        DocumentationComplianceJob · DigestNotificationJob          │
│        ProcessHl7AdtJob · ProcessLabResultJob                       │
│        ProcessTransportStatusWebhookJob                             │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  External Integrations                                              │
│  ├── HL7 ADT v2  →  POST /integrations/hl7/adt                     │
│  ├── Lab Results →  POST /integrations/labs/result                 │
│  ├── Transport   →  POST /integrations/transport/status-webhook     │
│  │   (HMAC-SHA256 signature validation, fail-closed)               │
│  └── FHIR R4 API →  GET /fhir/R4/{Resource}                        │
│      (Bearer token · 7 resources: Patient, Observation,            │
│       MedicationRequest, Condition, AllergyIntolerance,            │
│       CarePlan, Appointment)                                        │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│  Transport Bridge  (TransportBridgeService)                         │
│  Reads:  transport_* tables directly (shared PostgreSQL, read-only) │
│  Writes: POST to Nostos transport app internal API endpoint         │
│  Future: Replace direct reads with REST API calls (see §7)         │
└─────────────────────────────────────────────────────────────────────┘
```

### Key Technology Choices

| Layer | Technology | Why |
|---|---|---|
| Backend | Laravel 11 | Rapid development, strong ecosystem, CMS-friendly audit tooling |
| Frontend | React 18 + Inertia.js | SPA feel without a separate API; server data passed as props |
| Database | PostgreSQL 16 | Append-only audit rules, JSON columns, strong FK enforcement |
| Real-time | Laravel Reverb | Self-hosted WebSockets, native Echo integration, no vendor lock-in |
| Queues | Horizon + Redis | Job visibility, priority queues, retry management |
| Auth | Fortify OTP + Socialite | No passwords = reduced phishing surface; HIPAA-friendly |
| Styling | Tailwind CSS | Rapid iteration, consistent tokens, small bundle |

---

## 5. Database Conventions

### Table Prefixes

| Prefix | Owner | Access |
|---|---|---|
| `emr_*` | NostosEMR | Read/write |
| `shared_*` | Both apps | Read/write (users, tenants, sessions, audit_log) |
| `transport_*` | Nostos transport | Read-only (writes via TransportBridgeService) |

### Soft Deletes Everywhere
All business data uses `deleted_at` soft deletes. Nothing is ever hard-deleted. This preserves the audit trail required by HIPAA (6-year retention). `emr_chat_messages` also uses soft deletes but nulls `message_text` on delete — the audit record is kept, PHI is erasable on request.

### Append-Only Tables
Some tables are explicitly append-only (`UPDATED_AT = null`, no soft deletes):

| Table | Reason |
|---|---|
| `shared_audit_logs` | PostgreSQL rules block UPDATE and DELETE at the DB level |
| `emr_vitals`, `emr_adl_records` | Vitals are always appended, never corrected in place |
| `emr_emar_records`, `emr_drug_interaction_alerts` | eMAR is permanent medication record |
| `emr_encounter_log` | PACE encounter record; corrections create new entries |
| `emr_integration_log` | Inbound integration log; append only, retry_count incremented |

### Audit Logging
Every PHI access and mutation is logged to `shared_audit_logs`:
```
user_id | tenant_id | action | resource_type | resource_id | old_values | new_values | ip_address | created_at
```
FHIR reads use action `fhir.read.{resource}`. Impersonation always logs the real super-admin's `user_id`, never the impersonated user's.

### Multi-Tenancy
Every `emr_*` model carries `tenant_id`. All queries are scoped by `auth()->user()->tenant_id` at the controller level. There is no Eloquent global scope — tenant isolation is explicit and auditable.

---

## 6. RBAC System

### How It Works
Every `(department, role)` pair has a row in `emr_role_permissions` with boolean flags:

```
department    | role     | module         | can_view | can_create | can_edit | can_delete | can_export
primary_care  | admin    | clinical_notes | true     | true       | true     | true       | true
dietary       | standard | care_plans     | true     | false      | false    | false      | false
```

`PermissionService` is the single source of truth for:
1. The complete navigation manifest (`allNavGroups()`)
2. Permission checks (`can(user, module, ability)`)
3. The `permissionMap` prop shared with every Inertia response

**Super admins bypass the table entirely** and receive all permissions set to true.

### Enforcement Layers
- **Route-level**: `CheckDepartmentAccess` middleware (on all authenticated routes)
- **Controller-level**: `$this->can(module, ability)` from `PermissionService`
- **Frontend**: `permissionMap` prop hides controls the user can't use (defense-in-depth — server is authoritative)

### Adding a New Module
1. Add an entry to `PermissionService::allNavGroups()` with `label`, `module`, and `href`.
2. Add rows for every `(department, role)` pair in `PermissionSeeder.php`.
3. Run `php artisan db:seed --class=PermissionSeeder`.
4. The nav item and permission checks are immediately active.

---

## 7. Transport Bridge

### How It Works
NostosEMR and the Nostos transport app share a PostgreSQL database. The EMR reads transport data from `transport_*` tables (read-only). All writes go through `TransportBridgeService`:

```
EMR creates TransportRequest
  → TransportBridgeService::createTripRequest(TransportRequest)
  → POST to Nostos transport app internal endpoint
  → Returns trip ID → stored on emr_transport_requests.transport_trip_id

Transport app completes trip
  → POST /integrations/transport/status-webhook  (HMAC-signed)
  → ProcessTransportStatusWebhookJob
  → Updates emr_transport_requests.status + actual_pickup/dropoff times
```

### HMAC Webhook Security
The webhook endpoint is outside the session auth middleware. It validates `X-Transport-Signature: sha256=<hmac>` using `services.transport.webhook_secret`. If the secret is `null` or the signature does not match, the request is rejected (fail-closed — misconfigured environments cannot silently accept unauthenticated webhooks).

### Future Migration Path
The shared-database approach was chosen to avoid network latency for the demo MVP. When deploying to separate infrastructure:
1. Replace `TransportBridgeService` direct DB reads with REST API calls to the transport app
2. The webhook flow already uses HTTP — no changes required
3. FHIR and integration endpoints are already HTTP-first and fully portable

---

## 8. Testing

### Running Tests

```bash
# Full suite — 8 parallel processes (optimal for 50+ table schema)
docker compose exec -T laravel.test php artisan test --parallel --processes=8

# Single file
docker compose exec -T laravel.test php artisan test tests/Feature/ChatTest.php

# Single test method
docker compose exec -T laravel.test php artisan test --filter test_creates_department_channels

# TypeScript check (no emit)
docker compose exec -T laravel.test npx tsc --noEmit

# Code style check
docker compose exec -T laravel.test ./vendor/bin/pint --test
```

> **Important:** Do not use `--processes=16` or higher. PostgreSQL hits `max_locks_per_transaction` limits with 50+ tables and many parallel workers. Use `--processes=8`.

### Test Structure

```
tests/
├── Feature/           # HTTP-level tests (controller → route → response)
│   ├── Auth/          # OTP login, rate limiting, session management
│   ├── ParticipantTest.php
│   ├── ClinicalNoteTest.php
│   ├── MedicationTest.php
│   ├── ChatTest.php
│   └── ...            # One test file per major feature area
└── Unit/              # Service and model unit tests
    ├── DrugInteractionServiceTest.php
    ├── ConflictDetectionServiceTest.php
    ├── ChannelAutoCreationTest.php
    └── ...
```

### TDD Expectations
- Every model must have a factory
- Every controller must have a feature test
- Every service must have a unit test
- Every Horizon job must have a unit test with Carbon time-travel
- Tests are written **before or alongside** code, never after
- Zero failing tests before merging to `main`

### Current Test Status
**857 tests, 0 failures** (Phase 7C complete — March 2026)

---

## 9. Phase Roadmap

| Phase | What Was Built |
|---|---|
| **1A–1C** | Laravel scaffold, Sail (Docker), OTP auth, RBAC system, App Shell, Super Admin |
| **2A–2C** | Participant management (demographics, MRN, insurance, flags, contacts), Transport Bridge |
| **3A–3D** | Clinical notes (8 templates), vitals, assessments, problem list, allergies, ADL tracking |
| **Audits A–D** | DB integrity, auth/RBAC hardening, clinical doc gaps, Reverb wiring |
| **4A–4D** | Care plans (12 domains, versioning), IDT meetings, SDRs (72h CMS rule), real-time alerts |
| **5A** | Locations CRUD, appointment scheduling, conflict detection service |
| **5B** | Transport manifest, TransportRequest model, HMAC webhook, status sync job |
| **5C** | Medications + eMAR (drug interaction service, scheduled MAR, LateMarDetectionJob) |
| **5D** | Medication reconciliation (5-step workflow, provider approval) |
| **6A** | Enrollment intake (9-status state machine, Kanban board) |
| **6B** | QA/Compliance dashboard, incident reporting (RCA enforcement per CMS 42 CFR 460.136) |
| **6C** | Finance dashboard, capitation records, FHIR R4 API (7 resources, Bearer token auth) |
| **6D** | HL7 ADT + lab result integrations, IT Admin panel (user provisioning, audit) |
| **Super Admin** | Session-based impersonation, amber banner, department view selector |
| **7A** | Clinical dashboards 1–7 (live widget panels for 7 clinical departments) |
| **7B** | Operations dashboards 8–14 (7 ops departments) |
| **7C** | Real-time chat (14 dept channels + DMs + participant IDT), notification preferences |
| **7D** | Demo data polish, GitHub Actions CI, this README |

---

## 10. Known MVP Limitations

These are intentional scope cuts for the local demo MVP.

| Limitation | Details | Migration Path |
|---|---|---|
| **FHIR writes** | FHIR R4 API is read-only (GET only). No write endpoints. | Add POST/PUT endpoints per each resource profile |
| **HL7 parser** | ADT handler parses basic MSH/PID/EVN fields only. Does not handle ORU^R01 (uses JSON webhook instead). | Integrate a proper HL7 MLLP library (e.g., `hauntd/hl7`) |
| **EDI billing** | No 837P/835 EDI generation. Finance tracks capitation but does not produce CMS billing files. | Integrate an EDI library or clearinghouse API |
| **OAuth credentials** | Google OAuth is configured but requires real credentials in `.env`. Yahoo OAuth is a stub. | Add `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET` to `.env` |
| **File storage** | No document uploads. Care plan PDFs are client-side print-to-PDF only. | Configure S3 disk and use Laravel Storage |
| **Meilisearch** | Container is in docker-compose but models are not indexed. Search is SQL LIKE only. | Enable `Laravel\Scout` on Participant and ClinicalNote |
| **Cloud deployment** | No production config (no Forge recipes, no Kubernetes manifests). | Add `.env.production`, configure SSL, set up Horizon supervisor |
| **Transport decoupling** | TransportBridgeService reads `transport_*` tables directly. Not suitable for separate deployments. | Replace direct reads with REST API calls |
| **Reverb in production** | Reverb runs fine in Docker but is not configured for SSL/WSS. | Add `REVERB_SCHEME=https` and SSL certificate |
| **TOTP** | TOTP is stubbed in the auth flow but not wired to a TOTP library. Only OTP email is functional. | Integrate `pragmarx/google2fa-laravel` |

---

## 11. Compliance Notes

NostosEMR is a **local demo environment only**. It has not undergone the certifications required for production use with real participant data.

### HIPAA
- [ ] Complete formal HIPAA Risk Assessment (Security Rule, 45 CFR §164.308)
- [ ] Execute Business Associate Agreements (BAA) with all vendors (hosting, email, logging)
- [ ] Designate a Privacy Officer and Security Officer
- [ ] Complete workforce HIPAA training and maintain records
- [ ] Implement and test Breach Notification procedures (45 CFR §164.400)
- [ ] Configure audit log archival — `shared_audit_logs` is append-only but not yet archived to long-term storage (6-year minimum retention required)

### CMS / PACE Regulations
- [ ] 42 CFR Part 460 audit — review all CMS-required documentation workflows
- [ ] HPMS credentialing — register system access with CMS HPMS portal
- [ ] SDR 72-hour enforcement — already in code; verify Horizon worker SLA in production
- [ ] RCA enforcement (42 CFR 460.136) — already in code; verify QA workflow with compliance officer
- [ ] Annual PACE audit readiness — mock audit recommended before CMS site visit

### Security
- [ ] Penetration test by a qualified third party before go-live
- [ ] Vulnerability scan of Docker images and PHP/npm dependencies
- [ ] Configure Content Security Policy (CSP) headers
- [ ] Enable HTTPS/TLS everywhere (app, Reverb WSS, FHIR API)
- [ ] Review and restrict CORS policy for FHIR endpoints
- [ ] Rotate all demo credentials before any non-local deployment

---

*The seeded data (`Testpatient`, `sunrisepace-demo.test`) is entirely synthetic. No real PHI exists in the demo environment. `migrate:fresh --seed` destroys and recreates all data.*
