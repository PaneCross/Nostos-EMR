// ─── Dashboard/Index ───────────────────────────────────────────────────────────
// Main department dashboard page — rendered via Inertia for all 14 departments.
// Data flow:
//   - DashboardController injects department, departmentLabel, role, navGroups,
//     permissions as Inertia props
//   - For all 14 departments: delegates to a dept-specific component that fetches
//     real widget data via Promise.all (4 widget endpoints per dept, Phase 7A/7B)
// ─────────────────────────────────────────────────────────────────────────────

import React from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';
import { PageProps, Department } from '@/types';

// ── Clinical dept dashboard components (Phase 7A — real data, Promise.all widgets) ──
import PrimaryCareDashboard      from './Depts/PrimaryCareDashboard';
import TherapiesDashboard        from './Depts/TherapiesDashboard';
import SocialWorkDashboard       from './Depts/SocialWorkDashboard';
import BehavioralHealthDashboard from './Depts/BehavioralHealthDashboard';
import DietaryDashboard          from './Depts/DietaryDashboard';
import ActivitiesDashboard       from './Depts/ActivitiesDashboard';
import HomeCareDashboard         from './Depts/HomeCareDashboard';

// ── Operations dept dashboard components (Phase 7B — real data, Promise.all widgets) ─
import TransportationDashboard from './Depts/TransportationDashboard';
import PharmacyDashboard       from './Depts/PharmacyDashboard';
import IdtDashboard            from './Depts/IdtDashboard';
import EnrollmentDashboard     from './Depts/EnrollmentDashboard';
import FinanceDashboard        from './Depts/FinanceDashboard';
import QaComplianceDashboard   from './Depts/QaComplianceDashboard';
import ItAdminDashboard        from './Depts/ItAdminDashboard';

// ── Phase 10B: Executive + Nostos Super Admin dashboards ──────────────────────
import ExecutiveDashboard from './Depts/ExecutiveDashboard';
import SuperAdminDashboard from './Depts/SuperAdminDashboard';

// ─── Per-department module cards (non-clinical depts — Phase 1 scaffold) ──────
const DEPT_MODULES: Record<Department, Array<{ title: string; desc: string; icon: string; color: string }>> = {
    primary_care: [],      // replaced by PrimaryCareDashboard widget grid
    therapies: [],         // replaced by TherapiesDashboard widget grid
    social_work: [],       // replaced by SocialWorkDashboard widget grid
    behavioral_health: [], // replaced by BehavioralHealthDashboard widget grid
    dietary: [],           // replaced by DietaryDashboard widget grid
    activities: [],        // replaced by ActivitiesDashboard widget grid
    home_care: [],         // replaced by HomeCareDashboard widget grid
    transportation: [], // replaced by TransportationDashboard widget grid (Phase 7B)
    pharmacy:       [], // replaced by PharmacyDashboard widget grid (Phase 7B)
    idt:            [], // replaced by IdtDashboard widget grid (Phase 7B)
    enrollment:     [], // replaced by EnrollmentDashboard widget grid (Phase 7B)
    finance:        [], // replaced by FinanceDashboard widget grid (Phase 7B)
    qa_compliance:  [], // replaced by QaComplianceDashboard widget grid (Phase 7B)
    it_admin:       [], // replaced by ItAdminDashboard widget grid (Phase 7B)
    executive:      [], // replaced by ExecutiveDashboard widget grid (Phase 10B)
    super_admin:    [], // replaced by SuperAdminDashboard (Phase 10B)
};

// ─── Departments with live widget dashboards (Phase 7A + 7B — all 14) ────────
const LIVE_DASHBOARD_DEPTS: Department[] = [
    'primary_care', 'therapies', 'social_work',
    'behavioral_health', 'dietary', 'activities', 'home_care',
    'transportation', 'pharmacy', 'idt', 'enrollment',
    'finance', 'qa_compliance', 'it_admin',
    'executive', 'super_admin', // Phase 10B
];

function ModuleCard({ title, desc, icon }: { title: string; desc: string; icon: string; color: string }) {
    return (
        <div className="card p-5 hover:shadow-md transition-all duration-150 cursor-pointer group">
            <div className="flex items-start gap-3">
                <span className="text-2xl leading-none mt-0.5">{icon}</span>
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-slate-800 group-hover:text-blue-700 transition-colors">
                        {title}
                    </p>
                    <p className="text-xs text-slate-500 mt-0.5 leading-relaxed">{desc}</p>
                </div>
            </div>
            <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between">
                <span className="text-xs text-slate-400">Click to open</span>
                <svg className="w-3.5 h-3.5 text-slate-300 group-hover:text-blue-500 transition-colors" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
            </div>
        </div>
    );
}

// ─── Stat card (placeholder metrics — non-clinical depts) ─────────────────────
function StatCard({ label, value, sub, color }: { label: string; value: string; sub: string; color: string }) {
    const colors: Record<string, string> = {
        blue:   'bg-blue-50 border-blue-200',
        green:  'bg-emerald-50 border-emerald-200',
        amber:  'bg-amber-50 border-amber-200',
        red:    'bg-red-50 border-red-200',
    };
    return (
        <div className={`rounded-xl border p-4 ${colors[color] ?? colors.blue}`}>
            <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">{label}</p>
            <p className="text-2xl font-bold text-slate-800 mt-1">{value}</p>
            <p className="text-xs text-slate-500 mt-0.5">{sub}</p>
        </div>
    );
}

// ─── Render the correct live dashboard component per dept ─────────────────────
function LiveDeptDashboard({ department, departmentLabel, role }: { department: Department; departmentLabel: string; role: string }) {
    const props = { departmentLabel, role };
    switch (department) {
        // Clinical depts (Phase 7A)
        case 'primary_care':      return <PrimaryCareDashboard {...props} />;
        case 'therapies':         return <TherapiesDashboard {...props} />;
        case 'social_work':       return <SocialWorkDashboard {...props} />;
        case 'behavioral_health': return <BehavioralHealthDashboard {...props} />;
        case 'dietary':           return <DietaryDashboard {...props} />;
        case 'activities':        return <ActivitiesDashboard {...props} />;
        case 'home_care':         return <HomeCareDashboard {...props} />;
        // Operations depts (Phase 7B)
        case 'transportation':    return <TransportationDashboard {...props} />;
        case 'pharmacy':          return <PharmacyDashboard {...props} />;
        case 'idt':               return <IdtDashboard {...props} />;
        case 'enrollment':        return <EnrollmentDashboard {...props} />;
        case 'finance':           return <FinanceDashboard {...props} />;
        case 'qa_compliance':     return <QaComplianceDashboard {...props} />;
        case 'it_admin':          return <ItAdminDashboard {...props} />;
        // Phase 10B
        case 'executive':         return <ExecutiveDashboard {...props} />;
        case 'super_admin':       return <SuperAdminDashboard {...props} />;
        default:                  return null;
    }
}

// ─── Main Dashboard Page ───────────────────────────────────────────────────────
export default function DashboardIndex() {
    const { auth, department, departmentLabel, role } = usePage<PageProps & {
        department: Department;
        departmentLabel: string;
        role: string;
    }>().props;

    const user = auth.user!;
    const isLiveDept = LIVE_DASHBOARD_DEPTS.includes(department);
    const modules = DEPT_MODULES[department] ?? [];

    const breadcrumbs = [
        { label: 'Home', href: '/' },
        { label: departmentLabel },
    ];

    // Placeholder stats for non-clinical depts (transportation, finance, IDT, etc.)
    const stats = department === 'transportation'
        ? [
            { label: 'Trips Today',        value: '47',  sub: '3 unassigned',       color: 'blue'  },
            { label: 'Cancellations',       value: '5',   sub: 'Today so far',        color: 'amber' },
            { label: 'On-Time Rate',         value: '94%', sub: 'Last 30 days',        color: 'green' },
            { label: 'No-Shows',             value: '2',   sub: 'This week',           color: 'red'   },
          ]
        : department === 'finance'
        ? [
            { label: 'Members (This Month)', value: '312', sub: 'Active enrollees',   color: 'blue'  },
            { label: 'Capitation Expected',  value: '$1.2M', sub: 'Current month',    color: 'green' },
            { label: 'Pending Claims',        value: '8',    sub: 'Awaiting review',   color: 'amber' },
            { label: 'Outstanding Items',     value: '3',    sub: 'Require action',    color: 'red'   },
          ]
        : department === 'idt'
        ? [
            { label: 'IDT Reviews Due',     value: '12',  sub: 'Next 7 days',         color: 'amber' },
            { label: 'SDRs Overdue',         value: '2',   sub: 'Past 72-hr window',   color: 'red'   },
            { label: 'Active Care Plans',    value: '48',  sub: 'Across all PPTs',     color: 'blue'  },
            { label: 'Upcoming Meetings',    value: '3',   sub: 'This week',           color: 'green' },
          ]
        : [
            { label: 'My Participants',     value: '24',  sub: 'Active on caseload',  color: 'blue'  },
            { label: 'Pending Items',        value: '7',   sub: 'Require your action', color: 'amber' },
            { label: 'Due This Week',        value: '4',   sub: 'SDRs / assessments',  color: 'red'   },
            { label: 'Completed Today',      value: '11',  sub: 'Notes & tasks',       color: 'green' },
          ];

    return (
        <AppShell breadcrumbs={breadcrumbs}>
            <Head title={departmentLabel} />

            {/* Welcome header */}
            <div className="mb-6">
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900">
                            Welcome back, {user.first_name}
                        </h1>
                        <div className="flex items-center gap-2 mt-1">
                            <span className="badge badge-blue">{departmentLabel}</span>
                            <span className="badge badge-gray capitalize">{role}</span>
                        </div>
                    </div>
                    <div className="text-right text-sm text-slate-400 hidden md:block">
                        <p>{new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}</p>
                        <p className="mt-0.5">{user.tenant?.name}</p>
                    </div>
                </div>
            </div>

            {isLiveDept ? (
                /* ── Clinical depts: real-data widget grid (Phase 7A) ── */
                <LiveDeptDashboard department={department} departmentLabel={departmentLabel} role={role} />
            ) : (
                /* ── Other depts: placeholder stat row + module cards ── */
                <>
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                        {stats.map(s => <StatCard key={s.label} {...s} />)}
                    </div>

                    <div className="mb-4 flex items-center justify-between">
                        <h2 className="text-base font-semibold text-slate-700">Your Modules</h2>
                        <span className="text-xs text-slate-400">{modules.length} modules available</span>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                        {modules.map(mod => (
                            <ModuleCard key={mod.title} {...mod} />
                        ))}
                    </div>

                    <div className="mt-8 p-4 rounded-lg border border-dashed border-slate-300 bg-slate-50 text-center">
                        <p className="text-sm text-slate-400">
                            <span className="font-medium text-slate-500">Phase 1 Scaffold</span> — Module pages are wired up in subsequent phases.
                            Navigation, permissions, and layout are fully functional.
                        </p>
                    </div>
                </>
            )}
        </AppShell>
    );
}
