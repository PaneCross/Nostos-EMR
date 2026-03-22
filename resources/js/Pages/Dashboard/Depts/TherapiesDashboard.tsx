// ─── TherapiesDashboard ────────────────────────────────────────────────────────
// Real-data dashboard for the Therapies (PT/OT/ST) department.
// Rendered from Dashboard/Index.tsx when department === 'therapies'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/therapies/schedule  — today's PT/OT/ST appointments
//   GET /dashboards/therapies/goals     — active therapy domain care plan goals
//   GET /dashboards/therapies/sdrs      — open/overdue SDRs for therapies
//   GET /dashboards/therapies/docs      — unsigned therapy notes
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
    provider_name: string | null;
}

interface Goal {
    id: number;
    domain: string;
    domain_label: string;
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
    due_at: string | null;
}

interface Note {
    id: number;
    participant: Participant | null;
    note_type: string;
    type_label: string;
    author: string | null;
    visit_date: string | null;
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

function StatusBadge({ status }: { status: string }) {
    const cls = status === 'confirmed' ? 'bg-green-100 text-green-700'
        : status === 'scheduled'  ? 'bg-blue-100 text-blue-700'
        : 'bg-slate-100 text-slate-500';
    return <span className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium ${cls}`}>{status}</span>;
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function TherapiesDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]   = useState(true);
    const [schedule, setSchedule] = useState<{ appointments: Appointment[] } | null>(null);
    const [goals, setGoals]       = useState<{ goals: Goal[] } | null>(null);
    const [sdrs, setSdrs]         = useState<{ sdrs: Sdr[]; overdue_count: number; open_count: number } | null>(null);
    const [docs, setDocs]         = useState<{ notes: Note[]; unsigned_count: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/therapies/schedule'),
            axios.get('/dashboards/therapies/goals'),
            axios.get('/dashboards/therapies/sdrs'),
            axios.get('/dashboards/therapies/docs'),
        ]).then(([sched, goal, sdr, doc]) => {
            setSchedule(sched.data);
            setGoals(goal.data);
            setSdrs(sdr.data);
            setDocs(doc.data);
        }).finally(() => setLoading(false));
    }, []);

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Today's Therapy Sessions */}
            <WidgetCard
                title="Today's Sessions"
                badge={schedule ? { label: `${schedule.appointments.length} sessions`, color: 'bg-blue-100 text-blue-700' } : undefined}
            >
                {loading ? <Skeleton /> : !schedule?.appointments.length ? <Empty message="No therapy sessions today" /> : (
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

            {/* Active Therapy Goals */}
            <WidgetCard
                title="Active Therapy Goals"
                badge={goals ? { label: `${goals.goals.length} goals`, color: 'bg-indigo-100 text-indigo-700' } : undefined}
            >
                {loading ? <Skeleton /> : !goals?.goals.length ? <Empty message="No active therapy goals" /> : (
                    <div className="space-y-1.5 overflow-auto max-h-56">
                        {goals.goals.map(g => (
                            <div key={g.id} className="flex items-start gap-2 p-2 rounded-lg border border-slate-100 hover:bg-slate-50">
                                <span className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold whitespace-nowrap ${
                                    g.domain === 'therapy_pt' ? 'bg-green-100 text-green-700'
                                    : g.domain === 'therapy_ot' ? 'bg-blue-100 text-blue-700'
                                    : 'bg-purple-100 text-purple-700'
                                }`}>{g.domain_label}</span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs text-slate-700 truncate">{g.goal_description}</p>
                                    <p className="text-[10px] text-slate-500">{g.participant?.name ?? '—'}{g.target_date ? ` · Due ${g.target_date}` : ''}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>

            {/* SDRs */}
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
                                    <th className="text-left py-1 font-medium text-slate-500">Status</th>
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
                                        <td className="py-1.5 capitalize text-slate-500">{s.status}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </WidgetCard>

            {/* Documentation Queue */}
            <WidgetCard
                title="Unsigned Notes"
                badge={docs ? { label: `${docs.unsigned_count} pending`, color: docs.unsigned_count > 0 ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700' } : undefined}
            >
                {loading ? <Skeleton /> : !docs?.notes.length ? <Empty message="No unsigned notes — all clear!" /> : (
                    <div className="space-y-1.5">
                        {docs.notes.map(n => (
                            <div key={n.id} className="flex items-center justify-between py-1.5 border-b border-slate-50 last:border-0">
                                <div>
                                    <p className="text-xs font-medium text-slate-800">{n.participant?.name ?? '—'}</p>
                                    <p className="text-[10px] text-slate-500">{n.type_label}{n.author ? ` · ${n.author}` : ''}</p>
                                </div>
                                <span className="text-[10px] text-slate-400 whitespace-nowrap">{n.visit_date ?? n.created_at}</span>
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>

        </div>
    );
}
