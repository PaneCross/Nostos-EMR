// ─── BehavioralHealthDashboard ─────────────────────────────────────────────────
// Real-data dashboard for the Behavioral Health department.
// Rendered from Dashboard/Index.tsx when department === 'behavioral_health'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/behavioral-health/schedule     — today's BH appointments
//   GET /dashboards/behavioral-health/assessments  — PHQ-9 / GAD-7 overdue + due soon
//   GET /dashboards/behavioral-health/sdrs         — open/overdue SDRs for behavioral_health
//   GET /dashboards/behavioral-health/goals        — active BH domain care plan goals
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

interface AssessmentItem {
    id: number;
    participant: Participant | null;
    assessment_type: string;
    type_label: string;
    next_due_date: string | null;
    days_overdue: number | null;
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

interface Goal {
    id: number;
    goal_description: string;
    target_date: string | null;
    status: string;
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

function StatusBadge({ status }: { status: string }) {
    const cls = status === 'confirmed' ? 'bg-green-100 text-green-700'
        : status === 'scheduled' ? 'bg-blue-100 text-blue-700'
        : 'bg-slate-100 text-slate-500';
    return <span className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium ${cls}`}>{status}</span>;
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function BehavioralHealthDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]         = useState(true);
    const [schedule, setSchedule]       = useState<{ appointments: Appointment[] } | null>(null);
    const [assessments, setAssessments] = useState<{
        overdue: AssessmentItem[];
        due_soon: AssessmentItem[];
        overdue_count: number;
        due_soon_count: number;
    } | null>(null);
    const [sdrs, setSdrs]               = useState<{ sdrs: Sdr[]; overdue_count: number; open_count: number } | null>(null);
    const [goals, setGoals]             = useState<{ goals: Goal[] } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/behavioral-health/schedule'),
            axios.get('/dashboards/behavioral-health/assessments'),
            axios.get('/dashboards/behavioral-health/sdrs'),
            axios.get('/dashboards/behavioral-health/goals'),
        ]).then(([sched, assess, sdr, goal]) => {
            setSchedule(sched.data);
            setAssessments(assess.data);
            setSdrs(sdr.data);
            setGoals(goal.data);
        }).finally(() => setLoading(false));
    }, []);

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Today's Schedule */}
            <WidgetCard
                title="Today's Sessions"
                badge={schedule ? { label: `${schedule.appointments.length} sessions`, color: 'bg-purple-100 text-purple-700' } : undefined}
            >
                {loading ? <Skeleton /> : !schedule?.appointments.length ? <Empty message="No BH sessions today" /> : (
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

            {/* Assessments (PHQ-9, GAD-7) */}
            <WidgetCard
                title="BH Assessments"
                badge={assessments ? {
                    label: assessments.overdue_count > 0 ? `${assessments.overdue_count} overdue` : `${assessments.due_soon_count} due soon`,
                    color: assessments.overdue_count > 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700',
                } : undefined}
            >
                {loading ? <Skeleton /> : (
                    <div className="space-y-3">
                        {/* Summary counts */}
                        <div className="grid grid-cols-2 gap-3">
                            <div className="rounded-lg bg-red-50 border border-red-100 p-3 text-center">
                                <p className="text-2xl font-bold text-red-600">{assessments?.overdue_count ?? 0}</p>
                                <p className="text-[10px] font-medium text-red-500 mt-0.5">Overdue</p>
                            </div>
                            <div className="rounded-lg bg-amber-50 border border-amber-100 p-3 text-center">
                                <p className="text-2xl font-bold text-amber-600">{assessments?.due_soon_count ?? 0}</p>
                                <p className="text-[10px] font-medium text-amber-500 mt-0.5">Due within 14d</p>
                            </div>
                        </div>
                        {/* Overdue list */}
                        {assessments?.overdue.length ? (
                            <div>
                                <p className="text-[10px] font-semibold text-red-500 uppercase tracking-wide mb-1">Overdue</p>
                                {assessments.overdue.slice(0, 4).map(a => (
                                    <div key={a.id} className="flex items-center justify-between py-1 border-b border-slate-50 last:border-0">
                                        <span className="text-xs text-slate-700">{a.participant?.name ?? '—'}</span>
                                        <span className="text-[10px] text-red-600">{a.type_label} · {a.days_overdue}d</span>
                                    </div>
                                ))}
                            </div>
                        ) : null}
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

            {/* BH Goals */}
            <WidgetCard
                title="BH Care Plan Goals"
                badge={goals ? { label: `${goals.goals.length} active`, color: 'bg-purple-100 text-purple-700' } : undefined}
            >
                {loading ? <Skeleton /> : !goals?.goals.length ? <Empty message="No active BH goals" /> : (
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

        </div>
    );
}
