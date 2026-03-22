// ─── SocialWorkDashboard ───────────────────────────────────────────────────────
// Real-data dashboard for the Social Work department.
// Rendered from Dashboard/Index.tsx when department === 'social_work'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/social-work/schedule   — today's social work / home visit appointments
//   GET /dashboards/social-work/alerts     — active alerts for social_work (incl. HL7 ADT)
//   GET /dashboards/social-work/sdrs       — open/overdue SDRs for social_work
//   GET /dashboards/social-work/incidents  — recent open incidents
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant { id: number; name: string }

interface Appointment {
    id: number;
    participant: Participant | null;
    appointment_type: string;
    type_label: string;
    scheduled_start: string | null;
    status: string;
}

interface AlertItem {
    id: number;
    title: string;
    message: string;
    severity: 'critical' | 'warning' | 'info';
    type_label: string;
    acknowledged: boolean;
    participant: Participant | null;
    created_at: string;
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

interface Incident {
    id: number;
    incident_type: string;
    type_label: string;
    status: string;
    status_label: string;
    rca_required: boolean;
    occurred_at: string | null;
    participant: Participant | null;
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

export default function SocialWorkDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]     = useState(true);
    const [schedule, setSchedule]   = useState<{ appointments: Appointment[] } | null>(null);
    const [alerts, setAlerts]       = useState<{ alerts: AlertItem[] } | null>(null);
    const [sdrs, setSdrs]           = useState<{ sdrs: Sdr[]; overdue_count: number; open_count: number } | null>(null);
    const [incidents, setIncidents] = useState<{ incidents: Incident[]; open_count: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/social-work/schedule'),
            axios.get('/dashboards/social-work/alerts'),
            axios.get('/dashboards/social-work/sdrs'),
            axios.get('/dashboards/social-work/incidents'),
        ]).then(([sched, alert, sdr, inc]) => {
            setSchedule(sched.data);
            setAlerts(alert.data);
            setSdrs(sdr.data);
            setIncidents(inc.data);
        }).finally(() => setLoading(false));
    }, []);

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Today's Schedule */}
            <WidgetCard
                title="Today's Schedule"
                badge={schedule ? { label: `${schedule.appointments.length} appts`, color: 'bg-blue-100 text-blue-700' } : undefined}
            >
                {loading ? <Skeleton /> : !schedule?.appointments.length ? <Empty message="No appointments today" /> : (
                    <div className="overflow-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left py-1 font-medium text-slate-500">Time</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Participant</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Type</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {schedule.appointments.map(a => (
                                    <tr key={a.id} className="hover:bg-slate-50">
                                        <td className="py-1.5 text-slate-600 whitespace-nowrap">{a.scheduled_start ?? '—'}</td>
                                        <td className="py-1.5 font-medium text-slate-800">{a.participant?.name ?? '—'}</td>
                                        <td className="py-1.5 text-slate-600">{a.type_label}</td>
                                        <td className="py-1.5"><StatusBadge status={a.status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </WidgetCard>

            {/* Alerts (includes HL7 ADT admissions) */}
            <WidgetCard
                title="Active Alerts"
                badge={alerts?.alerts.length
                    ? { label: `${alerts.alerts.length} active`, color: alerts.alerts.some(a => a.severity === 'critical') ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }
                    : undefined}
            >
                {loading ? <Skeleton /> : !alerts?.alerts.length ? <Empty message="No active alerts" /> : (
                    <div className="space-y-2 max-h-56 overflow-auto">
                        {alerts.alerts.map(a => (
                            <div key={a.id} className={`flex items-start gap-2 p-2 rounded-lg border ${a.severity === 'critical' ? 'border-red-200 bg-red-50' : 'border-amber-100 bg-amber-50'}`}>
                                <SeverityBadge severity={a.severity} />
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-medium text-slate-800 truncate">{a.title}</p>
                                    {a.participant && <p className="text-[10px] text-slate-500">{a.participant.name} · {a.created_at}</p>}
                                </div>
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

            {/* Open Incidents */}
            <WidgetCard
                title="Open Incidents"
                badge={incidents ? { label: `${incidents.open_count} open`, color: incidents.open_count > 0 ? 'bg-orange-100 text-orange-700' : 'bg-green-100 text-green-700' } : undefined}
            >
                {loading ? <Skeleton /> : !incidents?.incidents.length ? <Empty message="No open incidents" /> : (
                    <div className="space-y-1.5 overflow-auto max-h-56">
                        {incidents.incidents.map(i => (
                            <div key={i.id} className="flex items-center justify-between p-2 rounded-lg border border-slate-100 hover:bg-slate-50">
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-medium text-slate-800">{i.participant?.name ?? 'No participant'}</p>
                                    <p className="text-[10px] text-slate-500">{i.type_label} · {i.occurred_at ?? 'Date unknown'}</p>
                                </div>
                                <div className="flex items-center gap-1.5 ml-2 shrink-0">
                                    {i.rca_required && (
                                        <span className="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-100 text-red-700">RCA</span>
                                    )}
                                    <span className="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-700">{i.status_label}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>

        </div>
    );
}
