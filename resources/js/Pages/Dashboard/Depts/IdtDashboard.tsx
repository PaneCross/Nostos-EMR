// ─── IdtDashboard ──────────────────────────────────────────────────────────────
// Real-data dashboard for the IDT / Care Coordination department.
// Rendered from Dashboard/Index.tsx when department === 'idt'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/idt/meetings     — today's IDT meetings with Start Meeting links
//   GET /dashboards/idt/overdue-sdrs — escalated SDRs grouped by department
//   GET /dashboards/idt/care-plans   — care plans due for review within 30 days
//   GET /dashboards/idt/alerts       — last 24h cross-department alert feed
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant { id: number; name: string; mrn?: string }

interface MeetingItem {
    id: number;
    meeting_type: string;
    type_label: string;
    meeting_time: string | null;
    status: string;
    facilitator: string | null;
    site: string | null;
    run_url: string;
}

interface SdrItem {
    id: number;
    participant: Participant | null;
    request_type: string;
    type_label: string;
    assigned_department: string;
    status: string;
    priority: string;
    due_at: string | null;
    hours_overdue: number | null;
}

interface SdrDeptGroup {
    department: string;
    count: number;
    sdrs: SdrItem[];
}

interface CarePlanItem {
    id: number;
    participant: Participant | null;
    status: string;
    review_due_date: string | null;
    is_overdue: boolean;
    days_until_due: number | null;
}

interface AlertItem {
    id: number;
    title: string;
    severity: 'critical' | 'warning' | 'info';
    type_label: string;
    target_depts: string[];
    acknowledged: boolean;
    participant: Participant | null;
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

function SeverityBadge({ severity }: { severity: string }) {
    const cls = severity === 'critical'
        ? 'bg-red-100 text-red-700'
        : severity === 'warning'
        ? 'bg-amber-100 text-amber-700'
        : 'bg-blue-100 text-blue-700';
    return (
        <span className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase ${cls}`}>
            {severity}
        </span>
    );
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function IdtDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]       = useState(true);
    const [meetings, setMeetings]     = useState<{ meetings: MeetingItem[]; count: number; has_meeting_today: boolean } | null>(null);
    const [sdrs, setSdrs]             = useState<{ departments: SdrDeptGroup[]; total_count: number } | null>(null);
    const [carePlans, setCarePlans]   = useState<{
        care_plans: CarePlanItem[]; overdue_count: number; due_soon_count: number;
    } | null>(null);
    const [alerts, setAlerts]         = useState<{ alerts: AlertItem[]; critical_count: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/idt/meetings'),
            axios.get('/dashboards/idt/overdue-sdrs'),
            axios.get('/dashboards/idt/care-plans'),
            axios.get('/dashboards/idt/alerts'),
        ]).then(([meet, sdr, cp, alert]) => {
            setMeetings(meet.data);
            setSdrs(sdr.data);
            setCarePlans(cp.data);
            setAlerts(alert.data);
        }).finally(() => setLoading(false));
    }, []);

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Today's Meetings */}
            <WidgetCard
                title="Today's IDT Meetings"
                badge={meetings?.count ? { label: `${meetings.count} scheduled`, color: 'bg-blue-100 text-blue-700' } : undefined}
            >
                {loading ? <Skeleton /> : !meetings?.has_meeting_today ? (
                    <div className="py-4 text-center space-y-2">
                        <p className="text-xs text-slate-400">No meetings scheduled today</p>
                        <a href="/idt/meetings/create" className="inline-flex text-xs text-blue-600 hover:underline">
                            + Schedule a Meeting
                        </a>
                    </div>
                ) : (
                    <div className="space-y-2">
                        {meetings!.meetings.map(m => (
                            <div key={m.id} className="flex items-center justify-between p-2 rounded-lg border border-slate-200 hover:bg-slate-50">
                                <div>
                                    <p className="text-xs font-medium text-slate-800">{m.type_label}</p>
                                    <p className="text-[10px] text-slate-500">
                                        {m.meeting_time ?? '—'} · {m.site ?? 'All Sites'} · {m.facilitator ?? '?'}
                                    </p>
                                </div>
                                <a href={m.run_url}
                                   className="text-[10px] px-2 py-1 rounded bg-blue-600 text-white hover:bg-blue-700 whitespace-nowrap">
                                    {m.status === 'in_progress' ? 'Resume' : 'Start'}
                                </a>
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>

            {/* Overdue SDRs by Department */}
            <WidgetCard
                title="Escalated SDRs"
                badge={sdrs?.total_count ? { label: `${sdrs.total_count} escalated`, color: 'bg-red-100 text-red-700' } : undefined}
            >
                {loading ? <Skeleton /> : !sdrs?.departments.length ? <Empty message="No escalated SDRs" /> : (
                    <div className="space-y-2">
                        {sdrs.departments.map(g => (
                            <div key={g.department} className="p-2 rounded-lg border border-slate-200 bg-slate-50">
                                <div className="flex items-center justify-between">
                                    <span className="text-xs font-semibold text-slate-700 capitalize">
                                        {g.department.replace('_', ' ')}
                                    </span>
                                    <span className="text-xs font-bold text-red-600">{g.count} overdue</span>
                                </div>
                                {g.sdrs.slice(0, 2).map(s => (
                                    <p key={s.id} className="text-[10px] text-slate-500 mt-0.5">
                                        {s.participant?.name ?? '—'} · {s.type_label} · {s.hours_overdue}h overdue
                                    </p>
                                ))}
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>

            {/* Care Plans Due for Review */}
            <WidgetCard
                title="Care Plans Due Within 30 Days"
                badge={carePlans ? {
                    label: `${(carePlans.overdue_count || 0) + (carePlans.due_soon_count || 0)} plans`,
                    color: carePlans.overdue_count > 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700',
                } : undefined}
            >
                {loading ? <Skeleton /> : !carePlans?.care_plans.length ? <Empty message="No care plans due within 30 days" /> : (
                    <div className="overflow-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left py-1 font-medium text-slate-500">Participant</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Status</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Due</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {carePlans.care_plans.map(p => (
                                    <tr key={p.id} className={p.is_overdue ? 'bg-red-50' : 'hover:bg-slate-50'}>
                                        <td className="py-1.5 font-medium text-slate-800">{p.participant?.name ?? '—'}</td>
                                        <td className="py-1.5 capitalize text-slate-600">{p.status.replace('_', ' ')}</td>
                                        <td className={`py-1.5 ${p.is_overdue ? 'text-red-600 font-semibold' : 'text-slate-600'}`}>
                                            {p.is_overdue
                                                ? `${Math.abs(p.days_until_due ?? 0)}d overdue`
                                                : `${p.days_until_due}d`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </WidgetCard>

            {/* Cross-Dept Alert Feed (24h) */}
            <WidgetCard
                title="Alerts — Last 24 Hours"
                badge={alerts?.alerts.length
                    ? { label: `${alerts.alerts.length} active`, color: alerts.critical_count > 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }
                    : undefined}
            >
                {loading ? <Skeleton /> : !alerts?.alerts.length ? <Empty message="No alerts in the last 24 hours" /> : (
                    <div className="space-y-1.5 overflow-auto max-h-52">
                        {alerts.alerts.map(a => (
                            <div key={a.id} className="flex items-start gap-2 py-1 border-b border-slate-50 last:border-0">
                                <SeverityBadge severity={a.severity} />
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-medium text-slate-800 truncate">{a.title}</p>
                                    <p className="text-[10px] text-slate-500">
                                        {a.participant?.name ?? 'System'} · {a.created_at}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>

        </div>
    );
}
