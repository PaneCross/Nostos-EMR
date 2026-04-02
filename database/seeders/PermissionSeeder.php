<?php

namespace Database\Seeders;

use App\Models\RolePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    /**
     * All modules in the system.
     * Shape: ['module_key' => 'label']
     */
    private const MODULES = [
        // Participant
        'participants'             => 'Participants',
        'enrollment'               => 'Enrollment / Intake',

        // Clinical
        'clinical_notes'           => 'Clinical Notes',
        'vitals'                   => 'Vitals',
        'assessments'              => 'Assessments',
        'care_plans'               => 'Care Plans',
        'medications'              => 'Medications',
        'orders'                   => 'Orders',

        // IDT
        'idt_dashboard'            => 'IDT Dashboard',
        'idt_minutes'              => 'IDT Meeting Minutes',
        'sdr_tracker'              => 'SDR Tracker',

        // Scheduling
        'appointments'             => 'Appointments',
        'day_center'               => 'Day Center',

        // Transport
        'transport_dashboard'      => 'Transport Dashboard',
        'transport_scheduler'      => 'Transport Scheduler',
        'dispatch_map'             => 'Dispatch Map',
        'cancellations'            => 'Cancellations',
        'transport_addons'         => 'Add-Ons',
        'vehicles'                 => 'Vehicles',
        'vendors'                  => 'Vendors',
        'transport_credentials'    => 'Transport Credentials',
        'broker_settings'          => 'Broker Settings',
        'courtesy_calls'           => 'Courtesy Calls / Texts',
        'chat'                     => 'Chat',
        'locations'                => 'Locations',
        'user_management'          => 'User Management',

        // Billing (Phase 6C + Phase 9B)
        'billing'                  => 'Billing Dashboard',
        'capitation'               => 'Capitation',
        'claims'                   => 'Claims',
        'encounters'               => 'Encounter Submission Queue',
        'edi_batches'              => 'EDI Batch Files',
        'pde_records'              => 'PDE Records',
        'hpms_submissions'         => 'HPMS File Submissions',
        'hos_m_surveys'            => 'HOS-M Surveys',
        'revenue_integrity'        => 'Revenue Integrity Dashboard',

        // QA / Incidents + Grievances
        // grievances: all staff may file; QA Admin manages/escalates/submits to CMS
        'grievances'               => 'Grievances',
        'incident_reports'         => 'Incident Reports',
        'quality_metrics'          => 'Quality Metrics',
        // W4-6: QAPI quality improvement project tracking (42 CFR §460.136–§460.140)
        'qapi_projects'            => 'QAPI Projects',

        // Reports + Audit
        'reports'                  => 'Reports',
        'audit_log'                => 'Audit Log',

        // Admin
        'system_settings'          => 'System Settings',
        // W4-2: HIPAA BAA tracking + SRA records + encryption status (BLOCKERs 01+03)
        'security_compliance'      => 'Security & Compliance',

        // Phase 10B — Executive + Nostos SA
        'executive_overview'       => 'Executive Overview',
        'tenant_management'        => 'Tenant Management',
    ];

    private const DEPARTMENTS = [
        'primary_care', 'therapies', 'social_work', 'behavioral_health',
        'dietary', 'activities', 'home_care', 'transportation',
        'pharmacy', 'idt', 'enrollment', 'finance', 'qa_compliance', 'it_admin',
        // Phase 10B — new cross-role departments
        'executive',    // PACE org leadership: read-only on all modules
        'super_admin',  // Nostos staff: full access across tenants (distinct from role='super_admin')
    ];

    private const ROLES = ['admin', 'standard'];

    public function run(): void
    {
        DB::table('emr_role_permissions')->truncate();

        $matrix = $this->buildMatrix();
        $now    = now();
        $rows   = [];

        foreach ($matrix as $dept => $roles) {
            foreach ($roles as $role => $modules) {
                foreach ($modules as $module => $perms) {
                    $rows[] = [
                        'department' => $dept,
                        'role'       => $role,
                        'module'     => $module,
                        'can_view'   => $perms['v'] ?? false,
                        'can_create' => $perms['c'] ?? false,
                        'can_edit'   => $perms['e'] ?? false,
                        'can_delete' => $perms['d'] ?? false,
                        'can_export' => $perms['x'] ?? false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('emr_role_permissions')->insert($chunk);
        }

        $this->command->info('Permission matrix seeded: ' . count($rows) . ' rules.');
    }

    private function buildMatrix(): array
    {
        // Helper closure: full CRUD+export
        $full  = fn () => ['v' => true, 'c' => true, 'e' => true, 'd' => true, 'x' => true];
        // Read only
        $read  = fn () => ['v' => true, 'c' => false, 'e' => false, 'd' => false, 'x' => false];
        // Read + Export
        $readX = fn () => ['v' => true, 'c' => false, 'e' => false, 'd' => false, 'x' => true];
        // Create + Read (reports)
        $cr    = fn () => ['v' => true, 'c' => true, 'e' => false, 'd' => false, 'x' => true];
        // No access
        $none  = fn () => ['v' => false, 'c' => false, 'e' => false, 'd' => false, 'x' => false];
        // CRUD without delete
        $cru   = fn () => ['v' => true, 'c' => true, 'e' => true, 'd' => false, 'x' => false];
        // Soft-delete friendly CRUD (cannot hard delete)
        $cruD  = fn () => ['v' => true, 'c' => true, 'e' => true, 'd' => true, 'x' => false];

        // ─── IT Admin — sees everything, can do everything ────────────────────
        $itAdmin = [];
        foreach (self::MODULES as $mod => $_) {
            $itAdmin[$mod] = $full();
        }

        // ─── QA / Compliance — everything read + incidents + grievances full ───
        $qaBase = [];
        foreach (self::MODULES as $mod => $_) {
            $qaBase[$mod] = $readX();
        }
        $qaBase['incident_reports'] = $full();
        $qaBase['quality_metrics']  = $full();
        // QA owns the grievance workflow: manage, escalate, resolve, submit to CMS
        // (42 CFR §460.120–§460.121 compliance officer responsibility)
        $qaBase['grievances']       = $full();
        // W4-6: QA owns QAPI project management (42 CFR §460.136–§460.140)
        $qaBase['qapi_projects']    = $full();

        // ─── Finance — billing CRUD, enrollment/participants read ─────────────
        $financeBase = [];
        foreach (self::MODULES as $mod => $_) {
            $financeBase[$mod] = $none();
        }
        $financeBase['participants']   = $read();
        $financeBase['enrollment']     = $read();
        $financeBase['billing']            = $full();
        $financeBase['capitation']         = $full();
        $financeBase['claims']             = $full();
        // Phase 9B billing engine modules
        $financeBase['encounters']         = $full();
        $financeBase['edi_batches']        = $full();
        $financeBase['pde_records']        = $full();
        $financeBase['hpms_submissions']   = $full();
        $financeBase['hos_m_surveys']      = $full();
        $financeBase['revenue_integrity']  = $full();
        $financeBase['reports']            = $cr();
        $financeBase['audit_log']          = $read();
        $financeBase['chat']               = $full();
        $financeBase['grievances']         = $cr();

        // ─── Transportation — transport matrix from Master Context ────────────
        $transBase = [];
        foreach (self::MODULES as $mod => $_) {
            $transBase[$mod] = $none();
        }
        $transBase['transport_dashboard']   = $read();
        $transBase['broker_settings']       = $full();
        $transBase['locations']             = $cruD();
        $transBase['participants']          = $cruD();
        $transBase['user_management']       = $full();
        $transBase['vehicles']              = $full();
        $transBase['vendors']               = $full();
        $transBase['transport_scheduler']   = $full();
        $transBase['cancellations']         = $full();
        $transBase['transport_addons']      = $full();
        $transBase['chat']                  = $full();
        $transBase['dispatch_map']          = $read();
        $transBase['transport_credentials'] = $full();
        $transBase['reports']               = $cr();
        $transBase['courtesy_calls']        = $read();
        $transBase['audit_log']             = $read();
        $transBase['grievances']            = $cr();

        // ─── Enrollment / Intake ──────────────────────────────────────────────
        $enrollBase = [];
        foreach (self::MODULES as $mod => $_) {
            $enrollBase[$mod] = $none();
        }
        $enrollBase['participants']  = $full();
        $enrollBase['enrollment']    = $full();
        $enrollBase['care_plans']    = $read();
        $enrollBase['appointments']  = $cru();
        $enrollBase['reports']       = $cr();
        $enrollBase['audit_log']     = $read();
        $enrollBase['chat']          = $full();
        $enrollBase['grievances']    = $cr();
        $enrollBase['transport_dashboard'] = $read();
        $enrollBase['cancellations'] = $full();
        $enrollBase['transport_addons'] = $full();

        // ─── IDT / Care Coordination ──────────────────────────────────────────
        $idtBase = [];
        foreach (self::MODULES as $mod => $_) {
            $idtBase[$mod] = $none();
        }
        $idtBase['participants']     = $full();
        $idtBase['idt_dashboard']    = $full();
        $idtBase['idt_minutes']      = $full();
        $idtBase['sdr_tracker']      = $full();
        $idtBase['care_plans']       = $full();
        $idtBase['clinical_notes']   = $read();
        $idtBase['vitals']           = $read();
        $idtBase['assessments']      = $read();
        $idtBase['medications']      = $read();
        $idtBase['appointments']     = $full();
        $idtBase['day_center']       = $full();
        $idtBase['transport_dashboard'] = $read();
        $idtBase['cancellations']    = $full();
        $idtBase['chat']             = $full();
        $idtBase['reports']          = $cr();
        $idtBase['audit_log']        = $read();
        $idtBase['grievances']       = $cr();

        // ─── Primary Care / Nursing ───────────────────────────────────────────
        $pcBase = [];
        foreach (self::MODULES as $mod => $_) {
            $pcBase[$mod] = $none();
        }
        $pcBase['participants']   = $full();
        $pcBase['clinical_notes'] = $full();
        $pcBase['vitals']         = $full();
        $pcBase['assessments']    = $full();
        $pcBase['care_plans']     = $full();
        $pcBase['medications']    = $full();
        $pcBase['orders']         = $full();
        $pcBase['idt_dashboard']  = $read();
        $pcBase['sdr_tracker']    = $cru();
        $pcBase['appointments']   = $full();
        $pcBase['transport_dashboard'] = $read();
        $pcBase['cancellations']  = $full();
        $pcBase['transport_addons'] = $full();
        $pcBase['chat']           = $full();
        $pcBase['reports']        = $cr();
        $pcBase['audit_log']      = $read();
        // All staff may file grievances on behalf of participants (42 CFR §460.120)
        $pcBase['grievances']     = $cr();

        // ─── Therapies ────────────────────────────────────────────────────────
        $therapiesBase = [];
        foreach (self::MODULES as $mod => $_) {
            $therapiesBase[$mod] = $none();
        }
        $therapiesBase['participants']   = $read();
        $therapiesBase['clinical_notes'] = $cru();  // therapy notes only in practice
        $therapiesBase['vitals']         = $read();
        $therapiesBase['assessments']    = $full();
        $therapiesBase['care_plans']     = $cru();
        $therapiesBase['orders']         = $cru();   // therapies receives therapy orders (PT/OT/ST)
        $therapiesBase['idt_dashboard']  = $read();
        $therapiesBase['sdr_tracker']    = $cru();
        $therapiesBase['appointments']   = $full();
        $therapiesBase['transport_dashboard'] = $read();
        $therapiesBase['cancellations']  = $full();
        $therapiesBase['transport_addons'] = $full();
        $therapiesBase['chat']           = $full();
        $therapiesBase['reports']        = $cr();
        $therapiesBase['audit_log']      = $read();
        $therapiesBase['grievances']     = $cr();

        // ─── Social Work ──────────────────────────────────────────────────────
        $swBase = [];
        foreach (self::MODULES as $mod => $_) {
            $swBase[$mod] = $none();
        }
        $swBase['participants']    = $full();
        $swBase['clinical_notes']  = $cru();
        $swBase['care_plans']      = $cru();
        $swBase['assessments']     = $full();
        $swBase['orders']          = $cru();   // social_work receives hospice_referral orders
        $swBase['idt_dashboard']   = $read();
        $swBase['sdr_tracker']     = $cru();
        $swBase['appointments']    = $full();
        $swBase['transport_dashboard'] = $read();
        $swBase['cancellations']   = $full();
        $swBase['transport_addons'] = $full();
        $swBase['chat']            = $full();
        $swBase['reports']         = $cr();
        $swBase['audit_log']       = $read();
        $swBase['incident_reports'] = $cru();
        $swBase['grievances']      = $cr();

        // ─── Behavioral Health ────────────────────────────────────────────────
        $bhBase = [];
        foreach (self::MODULES as $mod => $_) {
            $bhBase[$mod] = $none();
        }
        $bhBase['participants']    = $full();
        $bhBase['clinical_notes']  = $cru();
        $bhBase['assessments']     = $full();
        $bhBase['care_plans']      = $cru();
        $bhBase['idt_dashboard']   = $read();
        $bhBase['sdr_tracker']     = $cru();
        $bhBase['appointments']    = $full();
        $bhBase['transport_dashboard'] = $read();
        $bhBase['cancellations']   = $full();
        $bhBase['transport_addons'] = $full();
        $bhBase['chat']            = $full();
        $bhBase['reports']         = $cr();
        $bhBase['audit_log']       = $read();
        $bhBase['grievances']      = $cr();

        // ─── Dietary / Nutrition ──────────────────────────────────────────────
        $dietaryBase = [];
        foreach (self::MODULES as $mod => $_) {
            $dietaryBase[$mod] = $none();
        }
        // Can see participant allergy/restriction list and care plan nutrition goals
        $dietaryBase['participants']   = $read();
        $dietaryBase['care_plans']     = $cru();  // can edit nutrition section
        $dietaryBase['assessments']    = $cru();  // dietary assessments
        $dietaryBase['idt_dashboard']  = $read();
        $dietaryBase['sdr_tracker']    = $cru();
        $dietaryBase['appointments']   = $read();
        $dietaryBase['chat']           = $full();
        $dietaryBase['reports']        = $cr();
        $dietaryBase['audit_log']      = $read();
        $dietaryBase['grievances']     = $cr();

        // ─── Activities / Recreation ──────────────────────────────────────────
        $activitiesBase = [];
        foreach (self::MODULES as $mod => $_) {
            $activitiesBase[$mod] = $none();
        }
        $activitiesBase['participants']   = $read();
        $activitiesBase['assessments']    = $cru();
        $activitiesBase['care_plans']     = $cru();
        $activitiesBase['idt_dashboard']  = $read();
        $activitiesBase['sdr_tracker']    = $cru();
        $activitiesBase['day_center']     = $full();
        $activitiesBase['appointments']   = $full();
        $activitiesBase['chat']           = $full();
        $activitiesBase['reports']        = $cr();
        $activitiesBase['audit_log']      = $read();
        $activitiesBase['grievances']     = $cr();

        // ─── Home Care ────────────────────────────────────────────────────────
        $homeCareBase = [];
        foreach (self::MODULES as $mod => $_) {
            $homeCareBase[$mod] = $none();
        }
        $homeCareBase['participants']   = $read();
        $homeCareBase['clinical_notes'] = $cru();
        $homeCareBase['assessments']    = $cru();
        $homeCareBase['care_plans']     = $read();
        $homeCareBase['orders']         = $cru();    // home_care receives DME and home_health orders
        $homeCareBase['idt_dashboard']  = $read();
        $homeCareBase['sdr_tracker']    = $cru();
        $homeCareBase['appointments']   = $read();
        $homeCareBase['transport_dashboard'] = $read();
        $homeCareBase['cancellations']  = $full();
        $homeCareBase['transport_addons'] = $full();
        $homeCareBase['chat']           = $full();
        $homeCareBase['reports']        = $cr();
        $homeCareBase['audit_log']      = $read();
        $homeCareBase['grievances']     = $cr();

        // ─── Pharmacy ─────────────────────────────────────────────────────────
        $pharmacyBase = [];
        foreach (self::MODULES as $mod => $_) {
            $pharmacyBase[$mod] = $none();
        }
        $pharmacyBase['participants']   = $read();
        $pharmacyBase['medications']    = $full();
        $pharmacyBase['orders']         = $cru();
        $pharmacyBase['care_plans']     = $read();
        $pharmacyBase['clinical_notes'] = $read();
        $pharmacyBase['assessments']    = $read();
        $pharmacyBase['idt_dashboard']  = $read();
        $pharmacyBase['sdr_tracker']    = $cru();
        $pharmacyBase['appointments']   = $read();
        $pharmacyBase['chat']           = $full();
        $pharmacyBase['reports']        = $cr();
        $pharmacyBase['audit_log']      = $read();
        $pharmacyBase['grievances']     = $cr();

        // ─── Phase 10B: Executive — all modules read+export (no write) ──────────
        // Executives are cross-site, cross-department viewers within their tenant.
        // They see everything but cannot create, edit, or delete any clinical data.
        $executiveBase = [];
        foreach (self::MODULES as $mod => $_) {
            $executiveBase[$mod] = $readX();
        }
        // Executives also get read access to the executive module itself
        $executiveBase['executive_overview'] = $read();

        // ─── Phase 10B: Super Admin dept — full access on all modules ────────
        // Nostos staff with cross-tenant support access (distinct from role='super_admin').
        // Permissions seeded here are consulted for non-bypass paths (e.g. permissionMap UI).
        $superAdminDeptBase = [];
        foreach (self::MODULES as $mod => $_) {
            $superAdminDeptBase[$mod] = $full();
        }
        $superAdminDeptBase['tenant_management'] = $full();
        $superAdminDeptBase['executive_overview'] = $full();

        // ─── Assemble final matrix — admin always gets full, standard gets base
        // (Admin within a dept can do everything their dept has access to)
        $deptBases = [
            'primary_care'      => $pcBase,
            'therapies'         => $therapiesBase,
            'social_work'       => $swBase,
            'behavioral_health' => $bhBase,
            'dietary'           => $dietaryBase,
            'activities'        => $activitiesBase,
            'home_care'         => $homeCareBase,
            'transportation'    => $transBase,
            'pharmacy'          => $pharmacyBase,
            'idt'               => $idtBase,
            'enrollment'        => $enrollBase,
            'finance'           => $financeBase,
            'qa_compliance'     => $qaBase,
            'it_admin'          => $itAdmin,
            // Phase 10B
            'executive'         => $executiveBase,
            'super_admin'       => $superAdminDeptBase,
        ];

        $matrix = [];

        foreach ($deptBases as $dept => $base) {
            // Standard role = base permissions
            $matrix[$dept]['standard'] = $base;

            // Admin role = elevate all true-able perms (same modules, but CRUD enabled)
            $adminPerms = [];
            foreach ($base as $mod => $perms) {
                if ($perms['v']) {
                    // Admin gets full CRUD on modules their dept can access
                    $adminPerms[$mod] = $full();
                } else {
                    $adminPerms[$mod] = $none();
                }
            }
            $matrix[$dept]['admin'] = $adminPerms;
        }

        return $matrix;
    }
}
