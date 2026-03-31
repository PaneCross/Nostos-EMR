// ─── EnrollmentDashboard ───────────────────────────────────────────────────────
// Real-data dashboard for the Enrollment department.
// Rendered from Dashboard/Index.tsx when department === 'enrollment'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/enrollment/pipeline            — referral counts per status column
//   GET /dashboards/enrollment/eligibility-pending — oldest-first eligibility queue
//   GET /dashboards/enrollment/disenrollments      — upcoming disenrollments (30 days)
//   GET /dashboards/enrollment/new-referrals       — new referrals this week
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

// ── Types ──────────────────────────────────────────────────────────────────────

interface PipelineColumn {
    status: string;
    status_label: string;
    count: number;
}

interface EligibilityItem {
    id: number;
    referred_name: string | null;
    referral_date: string | null;
    days_pending: number;
    assigned_to: string | null;
    source: string;
    href?: string;
}

interface DisenrollItem {
    id: number;
    name: string;
    mrn: string;
    enrollment_status: string;
    disenrollment_date: string;
    days_until: number;
    disenrollment_reason: string | null;
    href?: string;
}

interface NewReferral {
    id: number;
    referred_name: string | null;
    referral_date: string | null;
    source: string;
    status: string;
    status_label: string;
    assigned_to: string | null;
    created_at: string;
    href?: string;
}

// ── Kanban column colors by status ─────────────────────────────────────────────
const COLUMN_COLORS: Record<string, string> = {
    new:                 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300',
    intake_scheduled:    'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    intake_in_progress:  'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300',
    intake_complete:     'bg-teal-100 text-teal-700 dark:text-teal-300',
    eligibility_pending: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    pending_enrollment:  'bg-orange-100 text-orange-700',
    enrolled:            'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
};

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function EnrollmentDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]               = useState(true);
    const [pipeline, setPipeline]             = useState<{
        pipeline: PipelineColumn[]; total_active: number;
        declined_this_month: number; withdrawn_this_month: number;
    } | null>(null);
    const [eligibility, setEligibility]       = useState<{ referrals: EligibilityItem[]; count: number } | null>(null);
    const [disenrollments, setDisenrollments] = useState<{ participants: DisenrollItem[]; count: number } | null>(null);
    const [newReferrals, setNewReferrals]     = useState<{ referrals: NewReferral[]; week_count: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/enrollment/pipeline'),
            axios.get('/dashboards/enrollment/eligibility-pending'),
            axios.get('/dashboards/enrollment/disenrollments'),
            axios.get('/dashboards/enrollment/new-referrals'),
        ]).then(([pipe, elig, disenroll, refs]) => {
            setPipeline(pipe.data);
            setEligibility(elig.data);
            setDisenrollments(disenroll.data);
            setNewReferrals(refs.data);
        }).finally(() => setLoading(false));
    }, []);

    // Pipeline Summary — kept as a static stat grid (KPI counts, not clickable list items)
    const renderPipeline = () => {
        if (loading) {
            return (
                <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5">
                    <div className="space-y-2 animate-pulse">
                        {[1, 2, 3].map(i => <div key={i} className="h-8 bg-slate-100 dark:bg-slate-800 rounded" />)}
                    </div>
                </div>
            );
        }
        return (
            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5">
                <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Referral Pipeline</h3>
                {!pipeline ? (
                    <p className="text-xs text-gray-400 dark:text-slate-500 py-4 text-center">No pipeline data</p>
                ) : (
                    <div className="space-y-2">
                        <div className="flex flex-wrap gap-1.5">
                            {pipeline.pipeline.map(col => (
                                <a key={col.status}
                                   href={`/enrollment?status=${col.status}`}
                                   className={`flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs ${COLUMN_COLORS[col.status] ?? 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400'} hover:opacity-80 transition-opacity`}>
                                    <span className="font-bold">{col.count}</span>
                                    <span>{col.status_label}</span>
                                </a>
                            ))}
                        </div>
                        <div className="flex gap-4 pt-1 border-t border-slate-100 dark:border-slate-700 text-[10px] text-slate-400">
                            <span>{pipeline.declined_this_month} declined this month</span>
                            <span>{pipeline.withdrawn_this_month} withdrawn this month</span>
                        </div>
                        <a href="/enrollment" className="text-xs text-blue-600 dark:text-blue-400 hover:underline block">
                            View full Kanban board
                        </a>
                    </div>
                )}
            </div>
        );
    };

    // Build ActionItems for Eligibility Pending
    const eligibilityItems: ActionItem[] = (eligibility?.referrals ?? []).map(r => ({
        label: r.referred_name ?? '-',
        href: r.href ?? `/enrollment/referrals/${r.id}`,
        badge: `${r.days_pending}d`,
        badgeColor: r.days_pending > 30
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: `${r.source}${r.assigned_to ? ` | ${r.assigned_to}` : ''}`,
    }));

    // Build ActionItems for Disenrollments
    const disenrollItems: ActionItem[] = (disenrollments?.participants ?? []).map(p => ({
        label: `${p.name} (${p.mrn})`,
        href: p.href ?? `/enrollment/referrals`,
        badge: `${p.days_until}d`,
        badgeColor: p.days_until <= 7
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: `${p.disenrollment_date}${p.disenrollment_reason ? ` | ${p.disenrollment_reason}` : ''}`,
    }));

    // Build ActionItems for New Referrals
    const newReferralItems: ActionItem[] = (newReferrals?.referrals ?? []).map(r => ({
        label: r.referred_name ?? '-',
        href: r.href ?? `/enrollment/referrals/${r.id}`,
        badge: r.status_label,
        badgeColor: COLUMN_COLORS[r.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        sublabel: `${r.source} | ${r.created_at}`,
    }));

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Pipeline Summary — KPI stat grid, not a list of clickable items */}
            {renderPipeline()}

            <ActionWidget
                title="Pending Eligibility Verification"
                description="Referrals awaiting Medicare/Medicaid eligibility verification. Enrollment cannot proceed until eligibility is confirmed."
                items={eligibilityItems}
                emptyMessage="No referrals awaiting eligibility"
                viewAllHref="/enrollment"
                loading={loading}
            />

            <ActionWidget
                title="Upcoming Disenrollments (30 days)"
                description="Participants with pending or recent disenrollment actions requiring follow-up."
                items={disenrollItems}
                emptyMessage="No upcoming disenrollments"
                viewAllHref="/enrollment"
                loading={loading}
            />

            <ActionWidget
                title="New Referrals This Week"
                description="Referrals received in the last 7 days awaiting intake scheduling."
                items={newReferralItems}
                emptyMessage="No new referrals this week"
                viewAllHref="/enrollment"
                loading={loading}
            />

        </div>
    );
}
