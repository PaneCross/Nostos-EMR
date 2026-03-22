// ─── HomeCareDashboard ─────────────────────────────────────────────────────────
// Real-data dashboard for the Home Care department.
// Rendered from Dashboard/Index.tsx when department === 'home_care'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/home-care/schedule    — today's home visits
//   GET /dashboards/home-care/adl-alerts  — active ADL decline alerts for home_care
//   GET /dashboards/home-care/goals       — active home_care domain care plan goals
//   GET /dashboards/home-care/sdrs        — open/overdue SDRs for home_care
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant { id: number; name: string; mrn?: string }

interface HomeVisit {
    id: number;
    participant: Participant | null;
    type_label: string;
    scheduled_start: string | null;
    scheduled_end: string | null;
    status: string;
    provider_name: string | null;
    transport_required: boolean;
}

interface AlertItem {
    id: number;
    title: string;
    message: string;
    severity: 'critical' | 'warning' | 'info';
    alert_type: string;
    type_label: string;
    acknowledged: boolean;
    participant: Participant | null;
    created_at: string;
}

interface Goal {
    id: number;
    goal_description: string;
    target_date: string | null;
    status: string;
    participant: Participant | null;
}

interface Sdr {
    id: number;
    participant: Participant | null;
    request_type: string;
    type_label: string;
    priority: string;
    status: string;
    is_overdue: boolean;
    hours_remaining: number;
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
    const cls = severity === 'critical' ? 'bg-red-100 text-red-700'
        : severity === 'warning' ? 'bg-amber-100 text-amber-700'
        : 'bg-blue-100 text-blue-700';
    return <span className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase ${cls}`}>{severity}</span>;
}

function StatusBadge({ status }: { status: string }) {
    const cls = status === 'confirmed' ? 'bg-green-100 text-green-700'
        : status === 'scheduled' ? 'bg-blue-100 text-blue-700'
        : 'bg-slate-100 text-slate-500';
    return <span className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium ${cls}`}>{status}</span>;
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function HomeCareDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]   = useState(true);
    const [schedule, setSchedule] = useState<{ appointments: HomeVisit[] } | null>(null);
    const [adlAlerts, setAdlAlerts] = useState<{ alerts: AlertItem[]; unacknowledged_count: number } | null>(null);
    const [goals, setGoals]       = useState<{ goals: Goal[] } | null>(null);
    const [sdrs, setSdrs]         = useState<{ sdrs: Sdr[]; overdue_count: number; open_count: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/home-care/schedule'),
            axios.get('/dashboards/home-care/adl-alerts'),
            axios.get('/dashboards/home-care/goals'),
            axios.get('/dashboards/home-care/sdrs'),
        ]).then(([sched, alert, goal, sdr]) => {
            setSchedule(sched.data);
            setAdlAlerts(alert.data);
            setGoals(goal.data);
            setSdrs(sdr.data);
        }).finally(() => setLoading(false));
    }, []);

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Today's Home Visits */}
            <WidgetCard
                title="Today's Home Visits"
                badge={schedule ? { label: `${schedule.appointments.length} visits`, color: 'bg-blue-100 text-blue-700' } : undefined}
            >
                {loading ? <Skeleton /> : !schedule?.appointments.length ? <Empty message="No home visits scheduled today" /> : (
                    <div className="overflow-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left py-1 font-medium text-slate-500">Time</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Participant</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Provider</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {schedule.appointments.map(a => (
                                    <tr key={a.id} className="hover:bg-slate-50">
                                        <td className="py-1.5 text-slate-600 whitespace-nowrap">{a.scheduled_start ?? '—'}</td>
                                        <td className="py-1.5">
                                            <span className="font-medium text-slate-800">{a.participant?.name ?? '—'}</span>
                                            {a.transport_required && (
                                                <span className="ml-1.5 inline-flex px-1 py-0.5 rounded text-[9px] font-medium bg-slate-100 text-slate-500">🚐</span>
                                            )}
                                        </td>
                                        <td className="py-1.5 text-slate-600">{a.provider_name ?? '—'}</td>
                                        <td className="py-1.5"><StatusBadge status={a.status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </WidgetCard>

            {/* ADL Decline Alerts */}
            <WidgetCard
                title="ADL Decline Alerts"
                badge={adlAlerts ? {
                    label: adlAlerts.unacknowledged_count > 0 ? `${adlAlerts.unacknowledged_count} unacknowledged` : `${adlAlerts.alerts.length} active`,
                    color: adlAlerts.unacknowledged_count > 0 ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700',
                } : undefined}
            >
                {loading ? <Skeleton /> : !adlAlerts?.alerts.length ? <Empty message="No active ADL alerts" /> : (
                    <div className="space-y-2 max-h-56 overflow-auto">
                        {adlAlerts.alerts.map(a => (
                            <div key={a.id} className={`flex items-start gap-2 p-2 rounded-lg border ${a.severity === 'critical' ? 'border-red-200 bg-red-50' : 'border-amber-100 bg-amber-50'}`}>
                                <SeverityBadge severity={a.severity} />
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-medium text-slate-800 truncate">{a.title}</p>
                                    {a.participant && (
                                        <p className="text-[10px] text-slate-500">{a.participant.name} · {a.created_at}</p>
                                    )}
                                </div>
                                {a.acknowledged && (
                                    <span className="text-[10px] text-slate-400 shrink-0">ack'd</span>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>

            {/* Home Care Goals */}
            <WidgetCard
                title="Home Care Goals"
                badge={goals ? { label: `${goals.goals.length} active`, color: 'bg-green-100 text-green-700' } : undefined}
            >
                {loading ? <Skeleton /> : !goals?.goals.length ? <Empty message="No active home care goals" /> : (
                    <div className="space-y-1.5 overflow-auto max-h-56">
                        {goals.goals.map(g => (
                            <div key={g.id} className="p-2 rounded-lg border border-slate-100 hover:bg-slate-50">
                                <p className="text-xs text-slate-700 leading-snug">{g.goal_description}</p>
                                <p className="text-[10px] text-slate-500 mt-0.5">
                                    {g.participant?.name ?? '—'}{g.target_date ? ` · Due ${g.target_date}` : ''}
                                </p>
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>

            {/* SDR Queue */}
            <WidgetCard
                title="SDR Queue"
                badge={sdrs ? {
                    label: sdrs.overdue_count > 0 ? `${sdrs.overdue_count} overdue` : `${sdrs.open_count} open`,
                    color: sdrs.overdue_count > 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700',
                } : undefined}
            >
                {loading ? <Skeleton /> : !sdrs?.sdrs.length ? <Empty message="No open SDRs" /> : (
                    <div className="overflow-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left py-1 font-medium text-slate-500">Participant</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Type</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Hrs Left</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Priority</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {sdrs.sdrs.map(s => (
                                    <tr key={s.id} className={s.is_overdue ? 'bg-red-50' : 'hover:bg-slate-50'}>
                                        <td className="py-1.5 font-medium text-slate-800">{s.participant?.name ?? '—'}</td>
                                        <td className="py-1.5 text-slate-600">{s.type_label}</td>
                                        <td className={`py-1.5 font-semibold ${s.is_overdue ? 'text-red-600' : 'text-slate-600'}`}>
                                            {s.is_overdue ? 'Overdue' : `${s.hours_remaining}h`}
                                        </td>
                                        <td className="py-1.5 capitalize text-slate-500">{s.priority}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </WidgetCard>

        </div>
    );
}
