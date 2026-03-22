// ─── PrimaryCareDashboard ──────────────────────────────────────────────────────
// Real-data dashboard for the Primary Care / Nursing department.
// Rendered from Dashboard/Index.tsx when department === 'primary_care'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/primary-care/schedule   — today's clinic/lab/telehealth appointments
//   GET /dashboards/primary-care/alerts     — active alerts targeting primary_care
//   GET /dashboards/primary-care/docs       — unsigned notes + overdue assessments
//   GET /dashboards/primary-care/vitals     — 5 most recent vitals records
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant { id: number; name: string; mrn?: string }

interface Appointment {
    id: number;
    participant: Participant | null;
    appointment_type: string;
    type_label: string;
    scheduled_start: string | null;
    scheduled_end: string | null;
    status: string;
    provider_name: string | null;
}

interface AlertItem {
    id: number;
    title: string;
    severity: 'critical' | 'warning' | 'info';
    type_label: string;
    acknowledged: boolean;
    participant: Participant | null;
    created_at: string;
}

interface UnsignedNote {
    id: number;
    participant: Participant | null;
    note_type: string;
    type_label: string;
    author: string | null;
    visit_date: string | null;
    created_at: string;
}

interface OverdueAssessment {
    id: number;
    participant: Participant | null;
    assessment_type: string;
    type_label: string;
    next_due_date: string | null;
    days_overdue: number | null;
}

interface VitalRecord {
    id: number;
    participant: Participant | null;
    bp: string | null;
    pulse: number | null;
    o2_saturation: number | null;
    temperature_f: number | null;
    weight_lbs: number | null;
    out_of_range: boolean;
    recorded_at: string;
    recorded_by: string | null;
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
            {[1, 2, 3].map(i => (
                <div key={i} className="h-8 bg-slate-100 rounded" />
            ))}
        </div>
    );
}

function Empty({ message }: { message: string }) {
    return <p className="text-xs text-slate-400 py-4 text-center">{message}</p>;
}

// ── Severity badge ─────────────────────────────────────────────────────────────

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

// ── Status badge ───────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: string }) {
    const cls = status === 'confirmed' ? 'bg-green-100 text-green-700'
        : status === 'scheduled' ? 'bg-blue-100 text-blue-700'
        : status === 'completed' ? 'bg-slate-100 text-slate-500'
        : 'bg-slate-100 text-slate-500';
    return (
        <span className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium ${cls}`}>
            {status}
        </span>
    );
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function PrimaryCareDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]       = useState(true);
    const [schedule, setSchedule]     = useState<{ appointments: Appointment[] } | null>(null);
    const [alerts, setAlerts]         = useState<{ alerts: AlertItem[] } | null>(null);
    const [docs, setDocs]             = useState<{
        unsigned_notes: UnsignedNote[];
        unsigned_count: number;
        overdue_assessments: OverdueAssessment[];
        overdue_count: number;
    } | null>(null);
    const [vitals, setVitals]         = useState<{ vitals: VitalRecord[] } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/primary-care/schedule'),
            axios.get('/dashboards/primary-care/alerts'),
            axios.get('/dashboards/primary-care/docs'),
            axios.get('/dashboards/primary-care/vitals'),
        ]).then(([sched, alert, doc, vital]) => {
            setSchedule(sched.data);
            setAlerts(alert.data);
            setDocs(doc.data);
            setVitals(vital.data);
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

            {/* Active Alerts */}
            <WidgetCard
                title="Active Alerts"
                badge={alerts?.alerts.length
                    ? { label: `${alerts.alerts.length} active`, color: alerts.alerts.some(a => a.severity === 'critical') ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }
                    : undefined}
            >
                {loading ? <Skeleton /> : !alerts?.alerts.length ? <Empty message="No active alerts" /> : (
                    <div className="space-y-2">
                        {alerts.alerts.map(a => (
                            <div key={a.id} className={`flex items-start gap-2 p-2 rounded-lg border ${a.severity === 'critical' ? 'border-red-200 bg-red-50' : 'border-amber-100 bg-amber-50'}`}>
                                <SeverityBadge severity={a.severity} />
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-medium text-slate-800 truncate">{a.title}</p>
                                    {a.participant && (
                                        <p className="text-[10px] text-slate-500">{a.participant.name} · {a.created_at}</p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>

            {/* Documentation Queue */}
            <WidgetCard
                title="Documentation Queue"
                badge={docs ? { label: `${(docs.unsigned_count || 0) + (docs.overdue_count || 0)} items`, color: 'bg-amber-100 text-amber-700' } : undefined}
            >
                {loading ? <Skeleton /> : (
                    <div className="space-y-3">
                        {/* Unsigned notes */}
                        <div>
                            <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">
                                Unsigned Notes <span className="text-amber-600">({docs?.unsigned_count ?? 0})</span>
                            </p>
                            {!docs?.unsigned_notes.length
                                ? <p className="text-xs text-slate-400">None pending</p>
                                : docs.unsigned_notes.map(n => (
                                    <div key={n.id} className="flex items-center justify-between py-1 border-b border-slate-50 last:border-0">
                                        <span className="text-xs text-slate-700">{n.participant?.name ?? '—'}</span>
                                        <span className="text-[10px] text-slate-500">{n.type_label}</span>
                                    </div>
                                ))
                            }
                        </div>
                        {/* Overdue assessments */}
                        <div>
                            <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">
                                Overdue Assessments <span className="text-red-600">({docs?.overdue_count ?? 0})</span>
                            </p>
                            {!docs?.overdue_assessments.length
                                ? <p className="text-xs text-slate-400">None overdue</p>
                                : docs.overdue_assessments.slice(0, 4).map(a => (
                                    <div key={a.id} className="flex items-center justify-between py-1 border-b border-slate-50 last:border-0">
                                        <span className="text-xs text-slate-700">{a.participant?.name ?? '—'}</span>
                                        <span className="text-[10px] text-red-600">{a.days_overdue}d overdue</span>
                                    </div>
                                ))
                            }
                        </div>
                    </div>
                )}
            </WidgetCard>

            {/* Recent Vitals */}
            <WidgetCard title="Recent Vitals">
                {loading ? <Skeleton /> : !vitals?.vitals.length ? <Empty message="No vitals recorded today" /> : (
                    <div className="overflow-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left py-1 font-medium text-slate-500">Participant</th>
                                    <th className="text-left py-1 font-medium text-slate-500">BP</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Pulse</th>
                                    <th className="text-left py-1 font-medium text-slate-500">O₂</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Recorded</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {vitals.vitals.map(v => (
                                    <tr key={v.id} className={v.out_of_range ? 'bg-red-50' : 'hover:bg-slate-50'}>
                                        <td className="py-1.5 font-medium text-slate-800">{v.participant?.name ?? '—'}</td>
                                        <td className={`py-1.5 ${v.out_of_range ? 'text-red-600 font-semibold' : 'text-slate-600'}`}>{v.bp ?? '—'}</td>
                                        <td className="py-1.5 text-slate-600">{v.pulse ?? '—'}</td>
                                        <td className="py-1.5 text-slate-600">{v.o2_saturation != null ? `${v.o2_saturation}%` : '—'}</td>
                                        <td className="py-1.5 text-slate-500">{v.recorded_at}</td>
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
