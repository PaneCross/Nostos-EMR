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
}

interface DisenrollItem {
    id: number;
    name: string;
    mrn: string;
    enrollment_status: string;
    disenrollment_date: string;
    days_until: number;
    disenrollment_reason: string | null;
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
}

// ── Widget shell ────────────────────────────────────────────────────────────────

function WidgetCard({ title, badge, children }: {
    title: string;
    badge?: { label: string; color: string };
    children: React.ReactNode;
}) {
    return (
        <div className="card p-5 flex flex-col">
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold text-slate-700">{title}</h3>
                {badge && (
                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${badge.color}`}>
                        {badge.label}
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}

function Skeleton() {
    return (
        <div className="space-y-2 animate-pulse">
            {[1, 2, 3].map(i => <div key={i} className="h-8 bg-slate-100 rounded" />)}
        </div>
    );
}

function Empty({ message }: { message: string }) {
    return <p className="text-xs text-slate-400 py-4 text-center">{message}</p>;
}

// ── Kanban column colors by status ─────────────────────────────────────────────
const COLUMN_COLORS: Record<string, string> = {
    new:                 'bg-slate-100 text-slate-700',
    intake_scheduled:    'bg-blue-100 text-blue-700',
    intake_in_progress:  'bg-indigo-100 text-indigo-700',
    intake_complete:     'bg-teal-100 text-teal-700',
    eligibility_pending: 'bg-amber-100 text-amber-700',
    pending_enrollment:  'bg-orange-100 text-orange-700',
    enrolled:            'bg-green-100 text-green-700',
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

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Referral Pipeline */}
            <WidgetCard
                title="Referral Pipeline"
                badge={pipeline ? { label: `${pipeline.total_active} active`, color: 'bg-blue-100 text-blue-700' } : undefined}
            >
                {loading ? <Skeleton /> : !pipeline ? <Empty message="No pipeline data" /> : (
                    <div className="space-y-2">
                        <div className="flex flex-wrap gap-1.5">
                            {pipeline.pipeline.map(col => (
                                <a key={col.status}
                                   href={`/enrollment?status=${col.status}`}
                                   className={`flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs ${COLUMN_COLORS[col.status] ?? 'bg-slate-100 text-slate-600'} hover:opacity-80 transition-opacity`}>
                                    <span className="font-bold">{col.count}</span>
                                    <span>{col.status_label}</span>
                                </a>
                            ))}
                        </div>
                        <div className="flex gap-4 pt-1 border-t border-slate-100 text-[10px] text-slate-400">
                            <span>{pipeline.declined_this_month} declined this month</span>
                            <span>{pipeline.withdrawn_this_month} withdrawn this month</span>
                        </div>
                    </div>
                )}
            </WidgetCard>

            {/* Eligibility Pending */}
            <WidgetCard
                title="Pending Eligibility Verification"
                badge={eligibility?.count ? { label: `${eligibility.count} waiting`, color: 'bg-amber-100 text-amber-700' } : undefined}
            >
                {loading ? <Skeleton /> : !eligibility?.referrals.length ? <Empty message="No referrals awaiting eligibility" /> : (
                    <div className="overflow-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left py-1 font-medium text-slate-500">Referral</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Source</th>
                                    <th className="text-right py-1 font-medium text-slate-500">Days</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {eligibility.referrals.map(r => (
                                    <tr key={r.id} className={r.days_pending > 30 ? 'bg-red-50' : 'hover:bg-slate-50'}>
                                        <td className="py-1.5 font-medium text-slate-800">{r.referred_name ?? '—'}</td>
                                        <td className="py-1.5 text-slate-600 text-[10px]">{r.source}</td>
                                        <td className={`py-1.5 text-right font-semibold ${r.days_pending > 30 ? 'text-red-600' : 'text-slate-600'}`}>
                                            {r.days_pending}d
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </WidgetCard>

            {/* Upcoming Disenrollments */}
            <WidgetCard
                title="Upcoming Disenrollments (30 days)"
                badge={disenrollments?.count ? { label: `${disenrollments.count} upcoming`, color: 'bg-red-100 text-red-700' } : undefined}
            >
                {loading ? <Skeleton /> : !disenrollments?.participants.length ? <Empty message="No upcoming disenrollments" /> : (
                    <div className="overflow-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left py-1 font-medium text-slate-500">Participant</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Date</th>
                                    <th className="text-right py-1 font-medium text-slate-500">Days</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {disenrollments.participants.map(p => (
                                    <tr key={p.id} className={p.days_until <= 7 ? 'bg-red-50' : 'hover:bg-slate-50'}>
                                        <td className="py-1.5 font-medium text-slate-800">{p.name}</td>
                                        <td className="py-1.5 text-slate-600">{p.disenrollment_date}</td>
                                        <td className={`py-1.5 text-right font-semibold ${p.days_until <= 7 ? 'text-red-600' : 'text-slate-700'}`}>
                                            {p.days_until}d
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </WidgetCard>

            {/* New Referrals This Week */}
            <WidgetCard
                title="New Referrals This Week"
                badge={newReferrals?.week_count ? { label: `${newReferrals.week_count} this week`, color: 'bg-green-100 text-green-700' } : undefined}
            >
                {loading ? <Skeleton /> : !newReferrals?.referrals.length ? <Empty message="No new referrals this week" /> : (
                    <div className="space-y-1.5">
                        {newReferrals.referrals.map(r => (
                            <div key={r.id} className="flex items-center justify-between py-1 border-b border-slate-50 last:border-0">
                                <div>
                                    <p className="text-xs font-medium text-slate-800">{r.referred_name ?? '—'}</p>
                                    <p className="text-[10px] text-slate-500">{r.source} · {r.created_at}</p>
                                </div>
                                <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${COLUMN_COLORS[r.status] ?? 'bg-slate-100 text-slate-600'}`}>
                                    {r.status_label}
                                </span>
                            </div>
                        ))}
                        <a href="/enrollment" className="text-xs text-blue-600 hover:underline block text-center pt-1">
                            View full Kanban →
                        </a>
                    </div>
                )}
            </WidgetCard>

        </div>
    );
}
