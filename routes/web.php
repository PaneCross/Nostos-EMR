<?php

use App\Http\Controllers\AdlController;
use App\Http\Controllers\DisenrollmentController;
use App\Http\Controllers\BillingEncounterController;
use App\Http\Controllers\CapitationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\EdiBatchController;
use App\Http\Controllers\HosMSurveyController;
use App\Http\Controllers\HpmsController;
use App\Http\Controllers\PdeController;
use App\Http\Controllers\RevenueIntegrityController;
use App\Http\Controllers\RiskAdjustmentController;
use App\Http\Controllers\BillingComplianceController;
use App\Http\Controllers\StateMedicaidConfigController;
use App\Http\Controllers\EhiExportController;
use App\Http\Controllers\ImmunizationController;
use App\Http\Controllers\ProcedureController;
use App\Http\Controllers\SocialDeterminantController;
use App\Http\Controllers\DayCenterController;
use App\Http\Controllers\ClinicalOverviewController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TransferAdminController;
use App\Http\Controllers\SiteContextController;
use App\Http\Controllers\SuperAdminPanelController;
use App\Http\Controllers\Dashboards\ExecutiveDashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ThemePreferenceController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\ItAdminController;
use App\Http\Controllers\MedReconciliationController;
use App\Http\Controllers\MedicationController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AllergyController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\CarePlanController;
use App\Http\Controllers\ClinicalNoteController;
use App\Http\Controllers\TransportRequestController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\ClinicalDashboardController;
use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\TransportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IdtMeetingController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ParticipantContactController;
use App\Http\Controllers\ParticipantController;
use App\Http\Controllers\ParticipantFlagController;
use App\Http\Controllers\ProblemController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinanceDashboardController;
use App\Http\Controllers\FhirController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\GrievanceController;
use App\Http\Controllers\SecurityComplianceController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\QaDashboardController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\SdrController;
use App\Http\Controllers\VitalController;
use App\Http\Controllers\Dashboards\PrimaryCareDashboardController;
use App\Http\Controllers\Dashboards\TherapiesDashboardController;
use App\Http\Controllers\Dashboards\SocialWorkDashboardController;
use App\Http\Controllers\Dashboards\BehavioralHealthDashboardController;
use App\Http\Controllers\Dashboards\DietaryDashboardController;
use App\Http\Controllers\Dashboards\ActivitiesDashboardController;
use App\Http\Controllers\Dashboards\HomeCareDashboardController;
use App\Http\Controllers\Dashboards\TransportationDashboardController;
use App\Http\Controllers\Dashboards\PharmacyDashboardController;
use App\Http\Controllers\Dashboards\IdtDashboardController;
use App\Http\Controllers\Dashboards\EnrollmentDashboardController;
use App\Http\Controllers\Dashboards\FinanceWidgetController;
use App\Http\Controllers\Dashboards\QaComplianceDashboardController;
use App\Http\Controllers\Dashboards\ItAdminDashboardController;
use Illuminate\Support\Facades\Route;

// ─── Public / Guest Routes ────────────────────────────────────────────────────

Route::middleware('guest')->group(function () {
    Route::get('/login', [OtpController::class, 'showLogin'])->name('login');

    // OTP Auth
    Route::post('/auth/request-otp', [OtpController::class, 'requestOtp'])->name('auth.otp.request');
    Route::post('/auth/verify-otp',  [OtpController::class, 'verifyOtp'])->name('auth.otp.verify');

    // Social Login
    Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->name('auth.social.redirect');
    Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])->name('auth.social.callback');
});

// ─── Authenticated Routes ─────────────────────────────────────────────────────

Route::middleware('auth')->group(function () {
    // Logout (supports GET for timeout redirects and POST for manual logout)
    Route::match(['get', 'post'], '/auth/logout', [OtpController::class, 'logout'])->name('auth.logout'); // Named auth.logout — Fortify also registers 'logout'; duplicate names break route:cache

    // Root → redirect to department dashboard
    Route::get('/', [DashboardController::class, 'redirect'])->name('home');

    // Department dashboards
    Route::get('/dashboard/{department}', [DashboardController::class, 'show'])
        ->name('dept.dashboard')
        ->where('department', implode('|', [
            'primary_care', 'therapies', 'social_work', 'behavioral_health',
            'dietary', 'activities', 'home_care', 'transportation',
            'pharmacy', 'idt', 'enrollment', 'finance', 'qa_compliance', 'it_admin',
            'executive', 'super_admin',
        ]));

    // ─── Phase 7A: Department Dashboard Widget Endpoints (JSON) ──────────────
    // All widget endpoints are GET JSON — loaded in parallel client-side via Promise.all.
    // Each controller enforces its own dept guard (dept user or super_admin only).

    Route::prefix('dashboards')->group(function () {
        Route::prefix('primary-care')->group(function () {
            Route::get('/schedule',   [PrimaryCareDashboardController::class, 'schedule'])->name('dashboards.primary-care.schedule');
            Route::get('/alerts',     [PrimaryCareDashboardController::class, 'alerts'])->name('dashboards.primary-care.alerts');
            Route::get('/docs',       [PrimaryCareDashboardController::class, 'docs'])->name('dashboards.primary-care.docs');
            Route::get('/vitals',     [PrimaryCareDashboardController::class, 'vitals'])->name('dashboards.primary-care.vitals');
        });

        Route::prefix('therapies')->group(function () {
            Route::get('/schedule',   [TherapiesDashboardController::class, 'schedule'])->name('dashboards.therapies.schedule');
            Route::get('/goals',      [TherapiesDashboardController::class, 'goals'])->name('dashboards.therapies.goals');
            Route::get('/sdrs',       [TherapiesDashboardController::class, 'sdrs'])->name('dashboards.therapies.sdrs');
            Route::get('/docs',       [TherapiesDashboardController::class, 'docs'])->name('dashboards.therapies.docs');
        });

        Route::prefix('social-work')->group(function () {
            Route::get('/schedule',   [SocialWorkDashboardController::class, 'schedule'])->name('dashboards.social-work.schedule');
            Route::get('/alerts',     [SocialWorkDashboardController::class, 'alerts'])->name('dashboards.social-work.alerts');
            Route::get('/sdrs',       [SocialWorkDashboardController::class, 'sdrs'])->name('dashboards.social-work.sdrs');
            Route::get('/incidents',  [SocialWorkDashboardController::class, 'incidents'])->name('dashboards.social-work.incidents');
        });

        Route::prefix('behavioral-health')->group(function () {
            Route::get('/schedule',    [BehavioralHealthDashboardController::class, 'schedule'])->name('dashboards.behavioral-health.schedule');
            Route::get('/assessments', [BehavioralHealthDashboardController::class, 'assessments'])->name('dashboards.behavioral-health.assessments');
            Route::get('/sdrs',        [BehavioralHealthDashboardController::class, 'sdrs'])->name('dashboards.behavioral-health.sdrs');
            Route::get('/goals',       [BehavioralHealthDashboardController::class, 'goals'])->name('dashboards.behavioral-health.goals');
        });

        Route::prefix('dietary')->group(function () {
            Route::get('/assessments',  [DietaryDashboardController::class, 'assessments'])->name('dashboards.dietary.assessments');
            Route::get('/goals',        [DietaryDashboardController::class, 'goals'])->name('dashboards.dietary.goals');
            Route::get('/restrictions', [DietaryDashboardController::class, 'restrictions'])->name('dashboards.dietary.restrictions');
            Route::get('/sdrs',         [DietaryDashboardController::class, 'sdrs'])->name('dashboards.dietary.sdrs');
        });

        Route::prefix('activities')->group(function () {
            Route::get('/schedule',   [ActivitiesDashboardController::class, 'schedule'])->name('dashboards.activities.schedule');
            Route::get('/goals',      [ActivitiesDashboardController::class, 'goals'])->name('dashboards.activities.goals');
            Route::get('/sdrs',       [ActivitiesDashboardController::class, 'sdrs'])->name('dashboards.activities.sdrs');
            Route::get('/docs',       [ActivitiesDashboardController::class, 'docs'])->name('dashboards.activities.docs');
        });

        Route::prefix('home-care')->group(function () {
            Route::get('/schedule',    [HomeCareDashboardController::class, 'schedule'])->name('dashboards.home-care.schedule');
            Route::get('/adl-alerts',  [HomeCareDashboardController::class, 'adlAlerts'])->name('dashboards.home-care.adl-alerts');
            Route::get('/goals',       [HomeCareDashboardController::class, 'goals'])->name('dashboards.home-care.goals');
            Route::get('/sdrs',        [HomeCareDashboardController::class, 'sdrs'])->name('dashboards.home-care.sdrs');
        });

        // ─── Phase 7B: Operations Department Dashboard Widget Endpoints ───────────

        Route::prefix('transportation')->group(function () {
            Route::get('/manifest-summary', [TransportationDashboardController::class, 'manifestSummary'])->name('dashboards.transportation.manifest-summary');
            Route::get('/add-ons',          [TransportationDashboardController::class, 'addOns'])->name('dashboards.transportation.add-ons');
            Route::get('/flag-alerts',      [TransportationDashboardController::class, 'flagAlerts'])->name('dashboards.transportation.flag-alerts');
            Route::get('/config',           [TransportationDashboardController::class, 'config'])->name('dashboards.transportation.config');
        });

        Route::prefix('pharmacy')->group(function () {
            Route::get('/med-changes',  [PharmacyDashboardController::class, 'medChanges'])->name('dashboards.pharmacy.med-changes');
            Route::get('/interactions', [PharmacyDashboardController::class, 'interactions'])->name('dashboards.pharmacy.interactions');
            Route::get('/controlled',   [PharmacyDashboardController::class, 'controlled'])->name('dashboards.pharmacy.controlled');
            Route::get('/refills',      [PharmacyDashboardController::class, 'refills'])->name('dashboards.pharmacy.refills');
        });

        Route::prefix('idt')->group(function () {
            Route::get('/meetings',     [IdtDashboardController::class, 'meetings'])->name('dashboards.idt.meetings');
            Route::get('/overdue-sdrs', [IdtDashboardController::class, 'overdueSdrs'])->name('dashboards.idt.overdue-sdrs');
            Route::get('/care-plans',   [IdtDashboardController::class, 'carePlans'])->name('dashboards.idt.care-plans');
            Route::get('/alerts',       [IdtDashboardController::class, 'alerts'])->name('dashboards.idt.alerts');
            // W4-5: 42 CFR §460.104(c) — IDT reassessment frequency tracking
            Route::get('/idt-review-overdue', [IdtDashboardController::class, 'idtReviewOverdue'])->name('dashboards.idt.idt-review-overdue');
        });

        Route::prefix('enrollment')->group(function () {
            Route::get('/pipeline',            [EnrollmentDashboardController::class, 'pipeline'])->name('dashboards.enrollment.pipeline');
            Route::get('/eligibility-pending', [EnrollmentDashboardController::class, 'eligibilityPending'])->name('dashboards.enrollment.eligibility-pending');
            Route::get('/disenrollments',      [EnrollmentDashboardController::class, 'disenrollments'])->name('dashboards.enrollment.disenrollments');
            Route::get('/new-referrals',       [EnrollmentDashboardController::class, 'newReferrals'])->name('dashboards.enrollment.new-referrals');
        });

        Route::prefix('finance')->group(function () {
            Route::get('/capitation',          [FinanceWidgetController::class, 'capitation'])->name('dashboards.finance.capitation');
            Route::get('/authorizations',      [FinanceWidgetController::class, 'authorizations'])->name('dashboards.finance.authorizations');
            Route::get('/enrollment-changes',  [FinanceWidgetController::class, 'enrollmentChanges'])->name('dashboards.finance.enrollment-changes');
            Route::get('/encounters',          [FinanceWidgetController::class, 'encounters'])->name('dashboards.finance.encounters');
        });

        Route::prefix('qa-compliance')->group(function () {
            Route::get('/metrics',     [QaComplianceDashboardController::class, 'metrics'])->name('dashboards.qa-compliance.metrics');
            Route::get('/incidents',   [QaComplianceDashboardController::class, 'incidents'])->name('dashboards.qa-compliance.incidents');
            Route::get('/docs',        [QaComplianceDashboardController::class, 'docs'])->name('dashboards.qa-compliance.docs');
            Route::get('/care-plans',  [QaComplianceDashboardController::class, 'carePlans'])->name('dashboards.qa-compliance.care-plans');
        });

        Route::prefix('it-admin')->group(function () {
            Route::get('/users',        [ItAdminDashboardController::class, 'users'])->name('dashboards.it-admin.users');
            Route::get('/integrations', [ItAdminDashboardController::class, 'integrations'])->name('dashboards.it-admin.integrations');
            Route::get('/audit',        [ItAdminDashboardController::class, 'audit'])->name('dashboards.it-admin.audit');
            Route::get('/config',       [ItAdminDashboardController::class, 'config'])->name('dashboards.it-admin.config');
        });

        // ─── Phase 10B: Executive Dashboard Widget Endpoints ──────────────────
        // Executive dept users + isSuperAdmin + isDeptSuperAdmin. Pure JSON — no Inertia.
        // All 4 loaded in parallel by ExecutiveDashboard.tsx via Promise.all on mount.

        Route::prefix('executive')->group(function () {
            Route::get('/org-overview',       [ExecutiveDashboardController::class, 'orgOverview'])->name('dashboards.executive.org-overview');
            Route::get('/site-comparison',    [ExecutiveDashboardController::class, 'siteComparison'])->name('dashboards.executive.site-comparison');
            Route::get('/financial-overview', [ExecutiveDashboardController::class, 'financialOverview'])->name('dashboards.executive.financial-overview');
            Route::get('/sites-list',         [ExecutiveDashboardController::class, 'sitesList'])->name('dashboards.executive.sites-list');
        });
    });

    // ─── Participant Module ───────────────────────────────────────────────────

    // Global search (JSON) — must be before the resource routes to avoid {id} conflict
    Route::get('/participants/search', [ParticipantController::class, 'search'])
        ->name('participants.search');

    // Participant CRUD
    Route::get('/participants',            [ParticipantController::class, 'index'])->name('participants.index');
    Route::post('/participants',           [ParticipantController::class, 'store'])->name('participants.store');
    Route::get('/participants/{participant}',    [ParticipantController::class, 'show'])->name('participants.show');
    Route::match(['PUT', 'PATCH'], '/participants/{participant}', [ParticipantController::class, 'update'])->name('participants.update');
    Route::delete('/participants/{participant}', [ParticipantController::class, 'destroy'])->name('participants.destroy');

    // Participant Photo
    Route::post('/participants/{participant}/photo',   [ParticipantController::class, 'uploadPhoto'])->name('participants.photo.upload');
    Route::delete('/participants/{participant}/photo', [ParticipantController::class, 'deletePhoto'])->name('participants.photo.delete');

    // Participant Flags
    Route::get('/participants/{participant}/flags',              [ParticipantFlagController::class, 'index'])->name('participants.flags.index');
    Route::post('/participants/{participant}/flags',             [ParticipantFlagController::class, 'store'])->name('participants.flags.store');
    Route::put('/participants/{participant}/flags/{flag}',       [ParticipantFlagController::class, 'update'])->name('participants.flags.update');
    Route::post('/participants/{participant}/flags/{flag}/resolve', [ParticipantFlagController::class, 'resolve'])->name('participants.flags.resolve');

    // Participant Contacts
    Route::get('/participants/{participant}/contacts',            [ParticipantContactController::class, 'index'])->name('participants.contacts.index');
    Route::post('/participants/{participant}/contacts',           [ParticipantContactController::class, 'store'])->name('participants.contacts.store');
    Route::put('/participants/{participant}/contacts/{contact}',  [ParticipantContactController::class, 'update'])->name('participants.contacts.update');

    // ─── Phase 3: Clinical Documentation (nested under participant) ───────────
    // All clinical records are scoped to a participant for tenant isolation.

    Route::prefix('participants/{participant}')->group(function () {

        // Clinical Notes — SOAP + 7 other templates; signed notes are immutable
        Route::get('/notes',                          [ClinicalNoteController::class, 'index'])->name('participants.notes.index');
        Route::post('/notes',                         [ClinicalNoteController::class, 'store'])->name('participants.notes.store');
        Route::get('/notes/{note}',                   [ClinicalNoteController::class, 'show'])->name('participants.notes.show');
        Route::put('/notes/{note}',                   [ClinicalNoteController::class, 'update'])->name('participants.notes.update');
        Route::post('/notes/{note}/sign',             [ClinicalNoteController::class, 'sign'])->name('participants.notes.sign');
        Route::post('/notes/{note}/addendum',         [ClinicalNoteController::class, 'addendum'])->name('participants.notes.addendum');

        // Vitals — append-only; trends endpoint for Recharts
        Route::get('/vitals',                         [VitalController::class, 'index'])->name('participants.vitals.index');
        Route::post('/vitals',                        [VitalController::class, 'store'])->name('participants.vitals.store');
        Route::get('/vitals/trends',                  [VitalController::class, 'trends'])->name('participants.vitals.trends');

        // Assessments — scored structured tools (PHQ-9, MoCA, Barthel, etc.)
        Route::get('/assessments',                    [AssessmentController::class, 'index'])->name('participants.assessments.index');
        Route::post('/assessments',                   [AssessmentController::class, 'store'])->name('participants.assessments.store');
        Route::get('/assessments/due',                [AssessmentController::class, 'due'])->name('participants.assessments.due');
        Route::get('/assessments/{assessment}',       [AssessmentController::class, 'show'])->name('participants.assessments.show');

        // Problem List — ICD-10 coded, grouped by status
        Route::get('/problems',                       [ProblemController::class, 'index'])->name('participants.problems.index');
        Route::post('/problems',                      [ProblemController::class, 'store'])->name('participants.problems.store');
        Route::put('/problems/{problem}',             [ProblemController::class, 'update'])->name('participants.problems.update');
        Route::delete('/problems/{problem}',          [ProblemController::class, 'destroy'])->name('participants.problems.destroy');

        // Allergies & Dietary Restrictions — life-threatening banner driven by severity
        Route::get('/allergies',                      [AllergyController::class, 'index'])->name('participants.allergies.index');
        Route::post('/allergies',                     [AllergyController::class, 'store'])->name('participants.allergies.store');
        Route::put('/allergies/{allergy}',            [AllergyController::class, 'update'])->name('participants.allergies.update');
        Route::delete('/allergies/{allergy}',         [AllergyController::class, 'destroy'])->name('participants.allergies.destroy');

        // ADL Tracking — append-only records; configurable breach thresholds
        Route::get('/adl',                            [AdlController::class, 'index'])->name('participants.adl.index');
        Route::post('/adl',                           [AdlController::class, 'store'])->name('participants.adl.store');
        Route::put('/adl/thresholds',                 [AdlController::class, 'updateThresholds'])->name('participants.adl.thresholds');
    });

    // ─── Phase 5C: Medications + eMAR (nested under participant) ─────────────
    Route::prefix('participants/{participant}')->group(function () {
        // Medication list
        Route::get('/medications',                                             [MedicationController::class, 'index'])->name('participants.medications.index');
        Route::post('/medications',                                            [MedicationController::class, 'store'])->name('participants.medications.store');
        Route::put('/medications/{medication}/discontinue',                    [MedicationController::class, 'discontinue'])->name('participants.medications.discontinue');
        // Drug interaction alerts
        Route::get('/medications/interactions',                                [MedicationController::class, 'interactions'])->name('participants.medications.interactions');
        Route::post('/medications/{medication}/interactions/{alert}/acknowledge', [MedicationController::class, 'acknowledgeInteraction'])->name('participants.medications.interactions.acknowledge');
        // eMAR
        Route::get('/emar',                                                    [MedicationController::class, 'emarIndex'])->name('participants.emar.index');
        Route::post('/emar/{record}/administer',                               [MedicationController::class, 'administer'])->name('participants.emar.administer');
        Route::post('/medications/{medication}/prn-dose',                      [MedicationController::class, 'recordPrnDose'])->name('participants.medications.prn-dose');
        // ── Phase 5D: Medication Reconciliation (5-step workflow) ────────────────
        // These replace the Phase 5C stub reconciliation endpoints.
        // Ordered so static segment /start comes before implicit /{rec} patterns.
        Route::post('/med-reconciliation/start',         [MedReconciliationController::class, 'start'])->name('participants.med-reconciliation.start');
        Route::post('/med-reconciliation/prior-meds',    [MedReconciliationController::class, 'savePriorMeds'])->name('participants.med-reconciliation.prior-meds');
        Route::get('/med-reconciliation/comparison',     [MedReconciliationController::class, 'comparison'])->name('participants.med-reconciliation.comparison');
        Route::post('/med-reconciliation/decisions',     [MedReconciliationController::class, 'decisions'])->name('participants.med-reconciliation.decisions');
        Route::post('/med-reconciliation/approve',       [MedReconciliationController::class, 'approve'])->name('participants.med-reconciliation.approve');
        Route::get('/med-reconciliation/history',        [MedReconciliationController::class, 'history'])->name('participants.med-reconciliation.history');
    });

    // ─── Phase 8A: Documents (nested under participant) ──────────────────────
    // Upload, list, stream-download, and soft-delete participant documents.
    // File path is never exposed to the client — downloads go through the controller.
    Route::prefix('participants/{participant}/documents')->group(function () {
        Route::get('/',                          [DocumentController::class, 'index'])->name('participants.documents.index');
        Route::post('/',                         [DocumentController::class, 'store'])->name('participants.documents.store');
        Route::get('/{document}/download',       [DocumentController::class, 'download'])->name('participants.documents.download');
        Route::delete('/{document}',             [DocumentController::class, 'destroy'])->name('participants.documents.destroy');
    });

    // ─── Phase 6A: Disenrollment (nested under participant) ──────────────────
    Route::post('/participants/{participant}/disenroll', [ReferralController::class, 'disenroll'])->name('participants.disenroll');

    // ─── W4-5: Disenrollment record (42 CFR §460.116 transition plan) ─────────
    Route::prefix('participants/{participant}/disenrollment')->group(function () {
        Route::get('/',   [DisenrollmentController::class, 'show'])->name('participants.disenrollment.show');
        Route::patch('/', [DisenrollmentController::class, 'update'])->name('participants.disenrollment.update');
    });

    // ─── Phase 11B: Immunizations (nested under participant) ─────────────────
    Route::prefix('participants/{participant}/immunizations')->group(function () {
        Route::get('/',   [ImmunizationController::class, 'index'])->name('participants.immunizations.index');
        Route::post('/',  [ImmunizationController::class, 'store'])->name('participants.immunizations.store');
    });

    // ─── Phase 11B: Social Determinants (nested under participant) ────────────
    Route::prefix('participants/{participant}/social-determinants')->group(function () {
        Route::get('/',   [SocialDeterminantController::class, 'index'])->name('participants.social_determinants.index');
        Route::post('/',  [SocialDeterminantController::class, 'store'])->name('participants.social_determinants.store');
    });

    // ─── Phase 11B: Procedures (nested under participant) ────────────────────
    Route::prefix('participants/{participant}/procedures')->group(function () {
        Route::get('/',   [ProcedureController::class, 'index'])->name('participants.procedures.index');
        Route::post('/',  [ProcedureController::class, 'store'])->name('participants.procedures.store');
    });

    // ─── Phase 11B: EHI Export (nested under participant) ────────────────────
    // POST generates a new export (returns 202 with download URL).
    // GET download validates the token and streams the ZIP (410 Gone if expired).
    Route::post('/participants/{participant}/ehi-export', [EhiExportController::class, 'request'])->name('participants.ehi_export.request');
    Route::get('/participants/{participant}/ehi-export/{token}/download', [EhiExportController::class, 'download'])->name('participants.ehi_export.download');

    // ─── Phase 10A: Site Transfers (nested under participant) ─────────────────
    // View history: any authenticated user (dept check in controller for write ops).
    // Request/Approve/Cancel: enrollment + it_admin + super_admin only.
    Route::prefix('participants/{participant}/transfers')->group(function () {
        Route::get('/',                         [TransferController::class, 'index'])->name('participants.transfers.index');
        Route::get('/sites',                    [TransferController::class, 'sites'])->name('participants.transfers.sites');
        Route::get('/summary',                  [TransferController::class, 'summary'])->name('participants.transfers.summary');
        Route::post('/verify',                  [TransferController::class, 'verify'])->name('participants.transfers.verify');
        Route::post('/',                        [TransferController::class, 'request'])->name('participants.transfers.request');
        Route::post('/{transfer}/approve',      [TransferController::class, 'approve'])->name('participants.transfers.approve');
        Route::post('/{transfer}/cancel',       [TransferController::class, 'cancel'])->name('participants.transfers.cancel');
    });

    // ─── Phase 10A: Transfer Admin Page ──────────────────────────────────────
    // Enrollment + IT Admin only. Lists all tenant transfers with status filter.
    Route::get('/enrollment/transfers', [TransferAdminController::class, 'index'])->name('enrollment.transfers.index');

    // ICD-10 Lookup — tenant-agnostic reference data; used by problem typeahead
    Route::get('/icd10/search', [ProblemController::class, 'icd10Search'])->name('icd10.search');

    // Medication reference search — typeahead for "Add Medication" modal (Phase 5C)
    Route::get('/medications/reference/search', [MedicationController::class, 'referenceSearch'])->name('medications.reference.search');

    // ─── Phase 3: Care Plan (nested under participant) ────────────────────────
    Route::prefix('participants/{participant}/careplan')->group(function () {
        Route::get('/',                         [CarePlanController::class, 'show'])->name('participants.careplan.show');
        Route::post('/',                        [CarePlanController::class, 'store'])->name('participants.careplan.store');
        Route::get('/{carePlan}',              [CarePlanController::class, 'showVersion'])->name('participants.careplan.version');
        Route::put('/{carePlan}/goals/{domain}', [CarePlanController::class, 'upsertGoal'])->name('participants.careplan.goal');
        Route::post('/{carePlan}/approve',     [CarePlanController::class, 'approve'])->name('participants.careplan.approve');
        Route::post('/{carePlan}/new-version', [CarePlanController::class, 'newVersion'])->name('participants.careplan.new-version');
        // W4-5: 42 CFR §460.104(d) participant participation acknowledgment
        Route::patch('/{carePlan}/participation', [CarePlanController::class, 'updateParticipation'])->name('participants.careplan.participation');
    });

    // ─── Phase 4: Alerts ──────────────────────────────────────────────────────
    // Must declare /alerts/unread-count BEFORE /alerts/{alert} to avoid route collision
    Route::get('/alerts/unread-count',          [AlertController::class, 'unreadCount'])->name('alerts.unread-count');
    Route::get('/alerts',                       [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts',                      [AlertController::class, 'store'])->name('alerts.store');
    Route::patch('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');
    Route::patch('/alerts/{alert}/resolve',     [AlertController::class, 'resolve'])->name('alerts.resolve');

    // ─── Phase 4: SDRs ────────────────────────────────────────────────────────
    Route::get('/sdrs',         [SdrController::class, 'index'])->name('sdrs.index');
    Route::post('/sdrs',        [SdrController::class, 'store'])->name('sdrs.store');
    Route::get('/sdrs/{sdr}',   [SdrController::class, 'show'])->name('sdrs.show');
    Route::patch('/sdrs/{sdr}', [SdrController::class, 'update'])->name('sdrs.update');
    Route::delete('/sdrs/{sdr}',[SdrController::class, 'destroy'])->name('sdrs.destroy');

    // ─── Phase 4: IDT Meetings ────────────────────────────────────────────────
    Route::get('/idt',                     [IdtMeetingController::class, 'index'])->name('idt.index');
    // W3-2: meetings list page (Meeting Minutes nav item)
    Route::get('/idt/meetings',            [IdtMeetingController::class, 'meetingsList'])->name('idt.meetings.index');
    Route::post('/idt/meetings',           [IdtMeetingController::class, 'store'])->name('idt.meetings.store');
    Route::get('/idt/meetings/{meeting}',  [IdtMeetingController::class, 'show'])->name('idt.meetings.show');
    Route::patch('/idt/meetings/{meeting}',[IdtMeetingController::class, 'update'])->name('idt.meetings.update');
    Route::post('/idt/meetings/{meeting}/start',    [IdtMeetingController::class, 'start'])->name('idt.meetings.start');
    Route::post('/idt/meetings/{meeting}/complete', [IdtMeetingController::class, 'complete'])->name('idt.meetings.complete');
    Route::post('/idt/meetings/{meeting}/participants', [IdtMeetingController::class, 'addParticipant'])->name('idt.meetings.participants.add');
    Route::patch('/idt/meetings/{meeting}/participants/{review}', [IdtMeetingController::class, 'updateReview'])->name('idt.meetings.participants.update');
    Route::post('/idt/meetings/{meeting}/participants/{review}/reviewed', [IdtMeetingController::class, 'markReviewed'])->name('idt.meetings.participants.reviewed');

    // ─── Clinical Module Landing Pages ───────────────────────────────────────
    // Cross-participant views powering the Clinical nav items.
    // Notes/Vitals/Assessments/CarePlans are fully implemented.
    // Medications and Orders are live via ClinicalOverviewController (W3-8).

    // ─── Phase 6A: Enrollment & Intake (Kanban pipeline + state machine) ────────
    Route::get('/enrollment/referrals',                         [ReferralController::class, 'index'])->name('enrollment.referrals.index');
    Route::post('/enrollment/referrals',                        [ReferralController::class, 'store'])->name('enrollment.referrals.store');
    Route::get('/enrollment/referrals/{referral}',              [ReferralController::class, 'show'])->name('enrollment.referrals.show');
    Route::put('/enrollment/referrals/{referral}',              [ReferralController::class, 'update'])->name('enrollment.referrals.update');
    Route::post('/enrollment/referrals/{referral}/transition',  [ReferralController::class, 'transition'])->name('enrollment.referrals.transition');
    // Redirect /enrollment → Kanban pipeline
    Route::get('/enrollment', fn () => redirect()->route('enrollment.referrals.index'))->name('enrollment.index');

    Route::prefix('clinical')->group(function () {
        Route::get('/notes',       [ClinicalDashboardController::class, 'notes'])->name('clinical.notes');
        Route::get('/vitals',      [ClinicalDashboardController::class, 'vitals'])->name('clinical.vitals');
        Route::get('/assessments', [ClinicalDashboardController::class, 'assessments'])->name('clinical.assessments');
        Route::get('/care-plans',  [ClinicalDashboardController::class, 'carePlans'])->name('clinical.care-plans');
        Route::get('/medications', [ClinicalOverviewController::class, 'medications'])->name('clinical.medications');
        Route::get('/orders',      [ClinicalOverviewController::class, 'orders'])->name('clinical.orders');
    });

    // ─── Transport Module ─────────────────────────────────────────────────────
    // Dashboard + Manifest live (Phase 5B). Add-On queue for all staff.
    // Remaining items (scheduler, map, dispatch) are Phase 5C stubs.

    Route::prefix('transport')->group(function () {
        Route::get('/',              [TransportController::class, 'dashboard'])->name('transport.index');
        // Manifest run-sheet (Phase 5B)
        Route::get('/manifest',      [TransportRequestController::class, 'manifest'])->name('transport.manifest');
        Route::get('/manifest/runs', [TransportRequestController::class, 'runs'])->name('transport.manifest.runs');
        // Add-On queue (Phase 5B) — any dept submits; Transportation Team approves
        // Old nav link /transport/add-ons → redirects to manifest (nav was updated in Phase 7C polish)
        Route::get('/add-ons',                           fn () => redirect('/transport/manifest'))->name('transport.add-ons.index');
        Route::get('/add-ons/pending',                   [TransportRequestController::class, 'pending'])->name('transport.add-ons.pending');
        Route::post('/add-ons',                          [TransportRequestController::class, 'store'])->name('transport.add-ons.store');
        Route::put('/add-ons/{transportRequest}',        [TransportRequestController::class, 'update'])->name('transport.add-ons.update');
        Route::post('/add-ons/{transportRequest}/cancel',[TransportRequestController::class, 'cancel'])->name('transport.add-ons.cancel');
        // ── CAT1: Nostos Transport integration pending — ComingSoonBanner ────────
        Route::get('/scheduler',     fn () => app(ComingSoonController::class)->show('Transport Scheduler', 8, 'transport'))->name('transport.scheduler');
        Route::get('/map',           fn () => app(ComingSoonController::class)->show('Dispatch Map',        8, 'transport'))->name('transport.map');
        Route::get('/cancellations', fn () => app(ComingSoonController::class)->show('Cancellations',       8, 'transport'))->name('transport.cancellations');
        Route::get('/vehicles',      fn () => app(ComingSoonController::class)->show('Vehicles',            8, 'transport'))->name('transport.vehicles');
        Route::get('/vendors',       fn () => app(ComingSoonController::class)->show('Vendors',             8, 'transport'))->name('transport.vendors');
        Route::get('/credentials',   fn () => app(ComingSoonController::class)->show('Driver Credentials',  8, 'transport'))->name('transport.credentials');
        Route::get('/broker',        fn () => app(ComingSoonController::class)->show('Broker Settings',     8, 'transport'))->name('transport.broker');
        Route::get('/calls',         fn () => app(ComingSoonController::class)->show('Courtesy Calls',      8, 'transport'))->name('transport.calls');
    });

    // ─── Phase 5A: Locations ──────────────────────────────────────────────────
    // Managed by Transportation Team. All staff can view (for appointment booking).
    Route::get('/locations',             [LocationController::class, 'index'])->name('locations.index');
    Route::post('/locations',            [LocationController::class, 'store'])->name('locations.store');
    Route::get('/locations/{location}',  [LocationController::class, 'show'])->name('locations.show');
    Route::put('/locations/{location}',  [LocationController::class, 'update'])->name('locations.update');
    Route::delete('/locations/{location}',[LocationController::class, 'destroy'])->name('locations.destroy');

    // ─── Phase 5A: Schedule Calendar Page ────────────────────────────────────
    Route::get('/schedule',                  [AppointmentController::class, 'calendarPage'])->name('schedule.index');
    Route::get('/schedule/appointments',     [AppointmentController::class, 'calendarAppointments'])->name('schedule.appointments');

    // ─── Phase 5A: Appointments (nested under participant) ────────────────────
    Route::prefix('participants/{participant}')->group(function () {
        Route::get('/appointments',                          [AppointmentController::class, 'index'])->name('participants.appointments.index');
        Route::post('/appointments',                         [AppointmentController::class, 'store'])->name('participants.appointments.store');
        Route::get('/appointments/{appointment}',            [AppointmentController::class, 'show'])->name('participants.appointments.show');
        Route::put('/appointments/{appointment}',            [AppointmentController::class, 'update'])->name('participants.appointments.update');
        Route::patch('/appointments/{appointment}/confirm',  [AppointmentController::class, 'confirm'])->name('participants.appointments.confirm');
        Route::patch('/appointments/{appointment}/complete', [AppointmentController::class, 'complete'])->name('participants.appointments.complete');
        Route::patch('/appointments/{appointment}/cancel',   [AppointmentController::class, 'cancel'])->name('participants.appointments.cancel');
        Route::patch('/appointments/{appointment}/no-show',  [AppointmentController::class, 'noShow'])->name('participants.appointments.no-show');
    });

    // ─── Coming Soon: Phase 4b (scheduling — later) ──────────────────────────
    // Day Center scheduling remains a coming-soon stub.

    Route::prefix('scheduling')->group(function () {
        Route::get('/appointments', fn () => redirect()->route('schedule.index'));
        // W3-2: Day center attendance is now a live page
        Route::get('/day-center',         [DayCenterController::class, 'index'])->name('scheduling.day-center');
        Route::get('/day-center/roster',  [DayCenterController::class, 'roster'])->name('scheduling.day-center.roster');
        Route::post('/day-center/check-in', [DayCenterController::class, 'checkIn'])->name('scheduling.day-center.check-in');
        Route::post('/day-center/absent',   [DayCenterController::class, 'markAbsent'])->name('scheduling.day-center.absent');
        Route::get('/day-center/summary',   [DayCenterController::class, 'summary'])->name('scheduling.day-center.summary');
    });

    Route::prefix('idt')->group(function () {
        // Legacy redirects — nav hrefs updated in W3-2 but keep these for bookmark compat
        Route::get('/minutes', fn () => redirect('/idt/meetings'))->name('idt.minutes'); // W3-2: now redirects to meetings list
        Route::get('/sdr',     fn () => redirect('/sdrs'))->name('idt.sdr');             // SDR tracker is live at /sdrs
    });

    // ─── W4-1: Grievance Management (42 CFR §460.120–§460.121) ──────────────────
    // Grievance workflow: open → under_review → resolved/escalated/withdrawn.
    // Standard resolution: 30 days. Urgent: 72 hours.
    // NOTE: Static string routes (/overdue, /escalation-staff) MUST be declared
    // before /{grievance} to prevent matching as Eloquent route model binding IDs.
    Route::prefix('grievances')->group(function () {
        Route::get('/',                              [GrievanceController::class, 'index'])->name('grievances.index');
        Route::post('/',                             [GrievanceController::class, 'store'])->name('grievances.store');
        Route::get('/overdue',                       [GrievanceController::class, 'overdue'])->name('grievances.overdue');
        // Returns designation holders for the escalate-to dropdown (QA admin only)
        Route::get('/escalation-staff',              [GrievanceController::class, 'escalationStaff'])->name('grievances.escalation-staff');
        Route::get('/{grievance}',                   [GrievanceController::class, 'show'])->name('grievances.show');
        Route::put('/{grievance}',                   [GrievanceController::class, 'update'])->name('grievances.update');
        Route::post('/{grievance}/start-review',      [GrievanceController::class, 'startReview'])->name('grievances.start-review');
        Route::post('/{grievance}/resolve',          [GrievanceController::class, 'resolve'])->name('grievances.resolve');
        Route::post('/{grievance}/escalate',         [GrievanceController::class, 'escalate'])->name('grievances.escalate');
        Route::post('/{grievance}/withdraw',          [GrievanceController::class, 'withdraw'])->name('grievances.withdraw');
        Route::post('/{grievance}/cms-reportable',    [GrievanceController::class, 'setCmsReportable'])->name('grievances.cms-reportable');
        Route::post('/{grievance}/cms-reported',      [GrievanceController::class, 'markCmsReported'])->name('grievances.cms-reported');
        Route::post('/{grievance}/notify-participant',[GrievanceController::class, 'notifyParticipant'])->name('grievances.notify');
    });

    // ─── W4-1: Participant Consent Records (HIPAA 45 CFR §164.520) ───────────
    // Tracks NPP acknowledgment and other consent forms per participant.
    // Accessible via the Consents tab in Participants/Show.tsx.
    Route::prefix('participants/{participant}/consents')->group(function () {
        Route::get('/',    [ConsentController::class, 'index'])->name('participant.consents.index');
        Route::post('/',   [ConsentController::class, 'store'])->name('participant.consents.store');
        Route::put('/{consent}', [ConsentController::class, 'update'])->name('participant.consents.update');
    });

    // ─── Phase 6B: QA / Compliance Dashboard + Incidents ─────────────────────
    // Dashboard page (Inertia) + lazy-load compliance detail endpoints (JSON).
    // Incident CRUD is nested under /qa/incidents (not participant) so QA can
    // view the full tenant incident queue without being in a participant context.
    Route::prefix('qa')->group(function () {
        // Dashboard page — pre-loads 6 KPIs; compliance detail loaded lazily
        Route::get('/dashboard',                             [QaDashboardController::class, 'dashboard'])->name('qa.dashboard');
        // Incident CRUD
        Route::get('/incidents',                             [IncidentController::class, 'index'])->name('qa.incidents.index');
        Route::post('/incidents',                            [IncidentController::class, 'store'])->name('qa.incidents.store');
        Route::get('/incidents/{incident}',                  [IncidentController::class, 'show'])->name('qa.incidents.show');
        Route::put('/incidents/{incident}',                  [IncidentController::class, 'update'])->name('qa.incidents.update');
        // Incident lifecycle transitions
        Route::post('/incidents/{incident}/rca',             [IncidentController::class, 'rca'])->name('qa.incidents.rca');
        Route::post('/incidents/{incident}/close',           [IncidentController::class, 'close'])->name('qa.incidents.close');
        // Compliance detail endpoints (lazy-loaded by compliance tabs)
        Route::get('/compliance/unsigned-notes',             [QaDashboardController::class, 'unsignedNotes'])->name('qa.compliance.unsigned-notes');
        Route::get('/compliance/overdue-assessments',        [QaDashboardController::class, 'overdueAssessments'])->name('qa.compliance.overdue-assessments');
        // CSV export — type param: incidents|unsigned_notes|overdue_assessments
        Route::get('/reports/export',                        [QaDashboardController::class, 'exportCsv'])->name('qa.reports.export');
    });

    // ─── Finance / Billing (Phase 6C) ────────────────────────────────────────
    // Finance dashboard (Inertia) and REST API for capitation, encounters, auths.

    Route::prefix('finance')->group(function () {
        // Dashboard page
        Route::get('/dashboard',         [FinanceDashboardController::class, 'dashboard'])->name('finance.dashboard');
        Route::get('/reports/export',    [FinanceDashboardController::class, 'exportCsv'])->name('finance.export');

        // Capitation records
        Route::get('/capitation',        [FinanceController::class, 'capitationIndex'])->name('finance.capitation.index');
        Route::post('/capitation',       [FinanceController::class, 'capitationStore'])->name('finance.capitation.store');

        // Encounter log
        Route::get('/encounters',        [FinanceController::class, 'encounterIndex'])->name('finance.encounters.index');
        Route::post('/encounters',       [FinanceController::class, 'encounterStore'])->name('finance.encounters.store');

        // Authorizations
        Route::get('/authorizations',              [FinanceController::class, 'authIndex'])->name('finance.authorizations.index');
        Route::post('/authorizations',             [FinanceController::class, 'authStore'])->name('finance.authorizations.store');
        Route::put('/authorizations/{authorization}', [FinanceController::class, 'authUpdate'])->name('finance.authorizations.update');
    });

    // ── Billing Engine (Phase 9B) ─────────────────────────────────────────────
    // Full billing engine: EDI 837P, capitation, PDE, HPMS, HOS-M, revenue integrity
    Route::prefix('billing')->group(function () {
        // Legacy redirects (kept for backward compatibility with nav links)
        Route::get('/', fn () => redirect('/finance/dashboard'))->name('billing.index');

        // Encounter Submission Queue
        Route::get('/encounters',           [BillingEncounterController::class, 'index'])->name('billing.encounters.index');
        Route::post('/encounters/batch',    [BillingEncounterController::class, 'batch'])->name('billing.encounters.batch');
        Route::post('/encounters',          [BillingEncounterController::class, 'store'])->name('billing.encounters.store');
        Route::match(['PUT', 'PATCH'], '/encounters/{encounter}', [BillingEncounterController::class, 'update'])->name('billing.encounters.update');

        // EDI Batches
        Route::get('/batches',              [EdiBatchController::class, 'index'])->name('billing.batches.index');
        Route::get('/batches/{batch}/download', [EdiBatchController::class, 'download'])->name('billing.batches.download');
        Route::post('/batches/{batch}/acknowledge', [EdiBatchController::class, 'acknowledge'])->name('billing.batches.acknowledge');

        // Capitation (Inertia page + JSON data + store + bulk-import)
        Route::get('/capitation',           [CapitationController::class, 'index'])->name('billing.capitation.index');
        Route::get('/capitation/data',      [CapitationController::class, 'data'])->name('billing.capitation.data');
        Route::post('/capitation/bulk-import', [CapitationController::class, 'bulkImport'])->name('billing.capitation.bulk-import');
        Route::post('/capitation',          [CapitationController::class, 'store'])->name('billing.capitation.store');

        // Part D PDE Records
        Route::get('/pde/troop',            [PdeController::class, 'troop'])->name('billing.pde.troop');
        Route::get('/pde',                  [PdeController::class, 'index'])->name('billing.pde.index');
        Route::post('/pde',                 [PdeController::class, 'store'])->name('billing.pde.store');

        // HPMS File Submissions
        Route::get('/hpms',                 [HpmsController::class, 'index'])->name('billing.hpms.index');
        Route::post('/hpms/generate',       [HpmsController::class, 'generate'])->name('billing.hpms.generate');
        Route::get('/hpms/{submission}/download', [HpmsController::class, 'download'])->name('billing.hpms.download');
        Route::patch('/hpms/{submission}/submit', [HpmsController::class, 'markSubmitted'])->name('billing.hpms.submit');

        // HOS-M Annual Surveys
        Route::get('/hos-m',                [HosMSurveyController::class, 'index'])->name('billing.hosm.index');
        Route::post('/hos-m',               [HosMSurveyController::class, 'store'])->name('billing.hosm.store');
        Route::put('/hos-m/{survey}',       [HosMSurveyController::class, 'update'])->name('billing.hosm.update');
        Route::match(['post', 'patch'], '/hos-m/{survey}/submit', [HosMSurveyController::class, 'submit'])->name('billing.hosm.submit');

        // Revenue Integrity Dashboard
        Route::get('/revenue-integrity',        [RevenueIntegrityController::class, 'index'])->name('billing.revenue-integrity.index');
        Route::get('/revenue-integrity/data',   [RevenueIntegrityController::class, 'data'])->name('billing.revenue-integrity.data');

        // Risk Adjustment (HCC RAF Tracking) — Phase 9C
        Route::get('/risk-adjustment',                          [RiskAdjustmentController::class, 'index'])->name('billing.risk-adjustment.index');
        Route::get('/risk-adjustment/data',                     [RiskAdjustmentController::class, 'data'])->name('billing.risk-adjustment.data');
        Route::get('/risk-adjustment/participant/{id}',         [RiskAdjustmentController::class, 'participant'])->name('billing.risk-adjustment.participant');
        Route::post('/risk-adjustment/recalculate/{id}',        [RiskAdjustmentController::class, 'recalculate'])->name('billing.risk-adjustment.recalculate');

        // Billing Compliance Checklist — Phase 9C
        Route::get('/compliance-checklist',                     [BillingComplianceController::class, 'index'])->name('billing.compliance.index');
        Route::get('/compliance-checklist/data',                [BillingComplianceController::class, 'data'])->name('billing.compliance.data');

        // Legacy: Claims stub (now replaced by full billing engine — keep for graceful 404 redirect)
        Route::get('/claims', fn () => redirect('/billing/encounters'))->name('billing.claims');
    });

    // W3-2: Reports landing page (replaces ComingSoon stub)
    Route::get('/reports',                        [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports/data',                   [ReportsController::class, 'data'])->name('reports.data');
    Route::get('/reports/export',                 [ReportsController::class, 'export'])->name('reports.export');
    Route::get('/reports/site-transfers',         [ReportsController::class, 'siteTransfers'])->name('reports.site-transfers');
    Route::get('/reports/site-transfers/export',  [ReportsController::class, 'siteTransfersExport'])->name('reports.site-transfers.export');
    Route::get('/audit',   fn () => redirect('/it-admin/audit'))->name('audit.index'); // Live audit trail is at /it-admin/audit

    // Admin prefix — legacy nav stubs; real admin pages are at /it-admin/*
    Route::prefix('admin')->group(function () {
        Route::get('/users',    fn () => redirect('/it-admin/users'))->name('admin.users');     // CAT2: user management is live at /it-admin/users
        Route::get('/locations', [LocationController::class, 'managePage'])->name('admin.locations'); // Locations management Inertia page
        // W3-2: System Settings is now a live page (replaces ComingSoon stub)
        Route::get('/settings',  [SystemSettingsController::class, 'index'])->name('admin.settings');
        Route::put('/settings',  [SystemSettingsController::class, 'update'])->name('admin.settings.update');
    });

    // ─── Phase 7C: Chat ────────────────────────────────────────────────────────
    // Inertia page
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');

    // Chat JSON API (all require authenticated session + channel membership)
    Route::prefix('chat')->name('chat.')->group(function () {
        // Must declare /users/search BEFORE /channels to avoid param collision
        Route::get('/users/search',                [ChatController::class, 'searchUsers'])->name('users.search');
        Route::get('/channels',                    [ChatController::class, 'channels'])->name('channels');
        Route::get('/channels/{channel}/messages', [ChatController::class, 'messages'])->name('messages');
        Route::post('/channels/{channel}/messages',[ChatController::class, 'send'])->name('send');
        Route::post('/channels/{channel}/read',    [ChatController::class, 'markRead'])->name('read');
        Route::post('/direct/{user}',              [ChatController::class, 'directMessage'])->name('direct');
        Route::get('/unread-count',                [ChatController::class, 'unreadCount'])->name('unread');
    });

    // ─── Phase 7C: Profile / Notification Preferences ─────────────────────────
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/notifications',  [ProfileController::class, 'notifications'])->name('notifications');
        Route::put('/notifications',  [ProfileController::class, 'updateNotifications'])->name('notifications.update');
    });

    // ─── W3-1: User Theme Preference ──────────────────────────────────────────
    // Persists light/dark theme choice server-side. Frontend also writes to
    // localStorage for FOUC prevention between page loads.
    Route::post('/user/theme', [ThemePreferenceController::class, 'update'])->name('user.theme');

    // ─── Phase 10B: Nostos Super Admin Panel ──────────────────────────────────
    // Platform-level tenant management. Only accessible to isSuperAdmin() or isDeptSuperAdmin().
    // NOT for PACE org IT admins — those use /it-admin/* for their own tenant.

    Route::prefix('super-admin-panel')->group(function () {
        Route::get('/',         [SuperAdminPanelController::class, 'index'])->name('super-admin-panel.index');
        Route::get('/tenants',  [SuperAdminPanelController::class, 'tenants'])->name('super-admin-panel.tenants');
        Route::get('/health',   [SuperAdminPanelController::class, 'health'])->name('super-admin-panel.health');
        Route::post('/onboard', [SuperAdminPanelController::class, 'onboard'])->name('super-admin-panel.onboard');
    });

    // ─── Phase 10B: Site Context Switcher ─────────────────────────────────────
    // Executives and SA dept users can switch active site. Regular users get 403.

    Route::post('/site-context/switch', [SiteContextController::class, 'switch'])->name('site-context.switch');
    Route::delete('/site-context',      [SiteContextController::class, 'clear'])->name('site-context.clear');

    // ─── Phase 6D: IT Admin Panel ──────────────────────────────────────────────
    // ── Super Admin Impersonation (super_admin role only) ─────────────────────
    // No dept restriction — enforced via ImpersonationController::requireSuperAdmin().
    // Audit log always records real super-admin's user ID, not the impersonated user.

    Route::prefix('super-admin')->group(function () {
        Route::get('/users',                         [ImpersonationController::class, 'users'])->name('super-admin.users');
        Route::post('/impersonate/{user}',           [ImpersonationController::class, 'start'])->name('super-admin.impersonate');
        Route::delete('/impersonate',                [ImpersonationController::class, 'stop'])->name('super-admin.impersonate.stop');
        Route::post('/view-as',                      [ImpersonationController::class, 'setViewAs'])->name('super-admin.view-as');
        Route::delete('/view-as',                    [ImpersonationController::class, 'clearViewAs'])->name('super-admin.view-as.clear');
    });

    // All routes require department='it_admin' (enforced in controller).
    // Integrations monitor, user management, audit log viewer.

    Route::prefix('it-admin')->group(function () {
        // Integrations monitoring
        Route::get('/integrations',               [ItAdminController::class, 'integrations'])->name('it-admin.integrations');
        Route::get('/integrations/log',           [ItAdminController::class, 'integrationLog'])->name('it-admin.integrations.log');
        Route::post('/integrations/{log}/retry',  [ItAdminController::class, 'retryIntegration'])->name('it-admin.integrations.retry');
        // User management
        Route::get('/users',                        [ItAdminController::class, 'users'])->name('it-admin.users');
        Route::post('/users',                       [ItAdminController::class, 'provisionUser'])->name('it-admin.users.provision');
        Route::post('/users/{user}/deactivate',     [ItAdminController::class, 'deactivateUser'])->name('it-admin.users.deactivate');
        Route::post('/users/{user}/reactivate',     [ItAdminController::class, 'reactivateUser'])->name('it-admin.users.reactivate');
        Route::post('/users/{user}/reset-access',   [ItAdminController::class, 'resetAccess'])->name('it-admin.users.reset-access');
        // Designation management — assigns accountability roles for targeted alerting
        Route::patch('/users/{user}/designations',  [ItAdminController::class, 'updateDesignations'])->name('it-admin.users.designations');
        // Audit log viewer
        Route::get('/audit',                      [ItAdminController::class, 'audit'])->name('it-admin.audit');
        Route::get('/audit/log',                  [ItAdminController::class, 'auditLog'])->name('it-admin.audit.log');
        Route::get('/audit/export',               [ItAdminController::class, 'exportAuditCsv'])->name('it-admin.audit.export');
        // State Medicaid Configuration — Phase 9C (DEBT-038)
        Route::get('/state-config',               [StateMedicaidConfigController::class, 'index'])->name('it-admin.state-config.index');
        Route::post('/state-config',              [StateMedicaidConfigController::class, 'store'])->name('it-admin.state-config.store');
        Route::put('/state-config/{config}',      [StateMedicaidConfigController::class, 'update'])->name('it-admin.state-config.update');
        Route::delete('/state-config/{config}',   [StateMedicaidConfigController::class, 'destroy'])->name('it-admin.state-config.destroy');
        // W4-2: Security & Compliance — BAA tracking + SRA records + encryption status (BLOCKERs 01+03)
        Route::get('/security',                   [SecurityComplianceController::class, 'index'])->name('it-admin.security.index');
        Route::post('/baa',                       [SecurityComplianceController::class, 'baaStore'])->name('it-admin.baa.store');
        Route::put('/baa/{baa}',                  [SecurityComplianceController::class, 'baaUpdate'])->name('it-admin.baa.update');
        Route::post('/sra',                       [SecurityComplianceController::class, 'sraStore'])->name('it-admin.sra.store');
        Route::put('/sra/{sra}',                  [SecurityComplianceController::class, 'sraUpdate'])->name('it-admin.sra.update');
    });
});

// ─── FHIR R4 API (public — Bearer token authenticated) ───────────────────────
// No 'auth' session middleware: external systems authenticate via emr_api_tokens.
// Security is enforced by FhirAuthMiddleware (SHA-256 Bearer token + scope check).
// All reads are logged to audit_log with source_type='fhir_api'.
// Cross-tenant access returns 404 per FHIR conventions (not 403).
Route::prefix('fhir/R4')
    ->middleware(['fhir.auth'])
    ->group(function () {
        // Patient resource
        Route::get('/Patient/{id}', [FhirController::class, 'patient'])
            ->middleware('fhir.auth:patient.read')
            ->name('fhir.patient');

        // Observation (vitals) — search by patient
        Route::get('/Observation', [FhirController::class, 'observations'])
            ->middleware('fhir.auth:observation.read')
            ->name('fhir.observations');

        // MedicationRequest — search by patient
        Route::get('/MedicationRequest', [FhirController::class, 'medicationRequests'])
            ->middleware('fhir.auth:medication.read')
            ->name('fhir.medication_requests');

        // Condition (problem list) — search by patient
        Route::get('/Condition', [FhirController::class, 'conditions'])
            ->middleware('fhir.auth:condition.read')
            ->name('fhir.conditions');

        // AllergyIntolerance — search by patient
        Route::get('/AllergyIntolerance', [FhirController::class, 'allergyIntolerances'])
            ->middleware('fhir.auth:allergy.read')
            ->name('fhir.allergy_intolerances');

        // CarePlan — search by patient
        Route::get('/CarePlan', [FhirController::class, 'carePlans'])
            ->middleware('fhir.auth:careplan.read')
            ->name('fhir.care_plans');

        // Appointment — search by patient
        Route::get('/Appointment', [FhirController::class, 'appointments'])
            ->middleware('fhir.auth:appointment.read')
            ->name('fhir.appointments');

        // Immunization — search by patient (Phase 11B)
        Route::get('/Immunization', [FhirController::class, 'immunizations'])
            ->middleware('fhir.auth:immunization.read')
            ->name('fhir.immunizations');

        // Procedure — search by patient (Phase 11B)
        Route::get('/Procedure', [FhirController::class, 'procedures'])
            ->middleware('fhir.auth:procedure.read')
            ->name('fhir.procedures');

        // Observation (SDOH/social-history category) — search by patient + category=social-history (Phase 11B)
        // Note: reuses observation.read scope; category param distinguishes vitals vs SDOH in FhirController
        Route::get('/Observation/social-history', [FhirController::class, 'sdohObservations'])
            ->middleware('fhir.auth:observation.read')
            ->name('fhir.sdoh_observations');
    });

// ─── Transport Webhooks (public — HMAC-authenticated) ────────────────────────
// No 'auth' middleware: the transport app sends server-to-server with no session.
// Security is enforced by HMAC-SHA256 in WebhookController::transportStatus().
Route::post('/integrations/transport/status-webhook', [WebhookController::class, 'transportStatus'])
    ->name('integrations.transport.webhook');

// ─── Phase 6D: Inbound Integration Endpoints (public — tenant-header auth) ───
// No 'auth' session middleware: external systems (hospitals, labs) send server-to-server.
// Tenant resolved from X-Integration-Tenant header. Processing is async via queued jobs.
Route::prefix('integrations')->group(function () {
    Route::post('/hl7/adt',    [IntegrationController::class, 'adtMessage'])->name('integrations.hl7.adt');
    Route::post('/labs/result',[IntegrationController::class, 'labResult'])->name('integrations.labs.result');
});

// ─── Health Check ─────────────────────────────────────────────────────────────
Route::get('/up', fn () => response()->json(['status' => 'ok']))->name('health');
