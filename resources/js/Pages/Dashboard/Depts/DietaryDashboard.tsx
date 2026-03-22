// ─── DietaryDashboard ──────────────────────────────────────────────────────────
// Real-data dashboard for the Dietary / Nutrition department.
// Rendered from Dashboard/Index.tsx when department === 'dietary'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/dietary/assessments   — nutritional assessments overdue/due soon
//   GET /dashboards/dietary/goals         — active dietary care plan goals
//   GET /dashboards/dietary/restrictions  — allergy/restriction summary + critical food allergies
//   GET /dashboards/dietary/sdrs          — open/overdue SDRs for dietary
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant { id: number; name: string }

interface AssessmentItem {
    id: number;
    participant: Participant | null;
    next_due_date: string | null;
    days_overdue: number | null;
}

interface Goal {
    id: number;
    goal_description: string;
    target_date: string | null;
    status: string;
    participant: Participant | null;
}

interface FoodAllergy {
    id: number;
    allergen: string;
    reaction: string;
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

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function DietaryDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]         = useState(true);
    const [assessments, setAssessments] = useState<{
        overdue: AssessmentItem[];
        due_soon: AssessmentItem[];
        overdue_count: number;
        due_soon_count: number;
    } | null>(null);
    const [goals, setGoals]             = useState<{ goals: Goal[] } | null>(null);
    const [restrictions, setRestrictions] = useState<{
        counts_by_type: Record<string, number>;
        critical_food_allergies: FoodAllergy[];
    } | null>(null);
    const [sdrs, setSdrs]               = useState<{ sdrs: Sdr[]; overdue_count: number; open_count: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/dietary/assessments'),
            axios.get('/dashboards/dietary/goals'),
            axios.get('/dashboards/dietary/restrictions'),
            axios.get('/dashboards/dietary/sdrs'),
        ]).then(([assess, goal, restrict, sdr]) => {
            setAssessments(assess.data);
            setGoals(goal.data);
            setRestrictions(restrict.data);
            setSdrs(sdr.data);
        }).finally(() => setLoading(false));
    }, []);

    // Friendly label for allergy_type values
    const allergyTypeLabel = (type: string) =>
        ({ food: 'Food', medication: 'Medication', environmental: 'Environmental', latex: 'Latex', contrast: 'Contrast', other: 'Other' }[type] ?? type);

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Nutritional Assessments */}
            <WidgetCard
                title="Nutritional Assessments"
                badge={assessments ? {
                    label: assessments.overdue_count > 0 ? `${assessments.overdue_count} overdue` : `${assessments.due_soon_count} due soon`,
                    color: assessments.overdue_count > 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700',
                } : undefined}
            >
                {loading ? <Skeleton /> : (
                    <div className="space-y-3">
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
                        {assessments?.overdue.length ? (
                            <div>
                                <p className="text-[10px] font-semibold text-red-500 uppercase tracking-wide mb-1">Most Overdue</p>
                                {assessments.overdue.slice(0, 4).map(a => (
                                    <div key={a.id} className="flex items-center justify-between py-1 border-b border-slate-50 last:border-0">
                                        <span className="text-xs text-slate-700">{a.participant?.name ?? '—'}</span>
                                        <span className="text-[10px] text-red-600">{a.days_overdue}d overdue</span>
                                    </div>
                                ))}
                            </div>
                        ) : null}
                    </div>
                )}
            </WidgetCard>

            {/* Dietary Goals */}
            <WidgetCard
                title="Dietary Care Plan Goals"
                badge={goals ? { label: `${goals.goals.length} active`, color: 'bg-green-100 text-green-700' } : undefined}
            >
                {loading ? <Skeleton /> : !goals?.goals.length ? <Empty message="No active dietary goals" /> : (
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

            {/* Allergy & Restriction Summary */}
            <WidgetCard
                title="Allergy & Restriction Summary"
                badge={restrictions?.critical_food_allergies.length
                    ? { label: `${restrictions.critical_food_allergies.length} critical food`, color: 'bg-red-100 text-red-700' }
                    : undefined}
            >
                {loading ? <Skeleton /> : (
                    <div className="space-y-3">
                        {/* Counts by type */}
                        {restrictions?.counts_by_type && Object.keys(restrictions.counts_by_type).length > 0 ? (
                            <div className="grid grid-cols-2 gap-1.5">
                                {Object.entries(restrictions.counts_by_type).map(([type, count]) => (
                                    <div key={type} className="flex items-center justify-between px-2.5 py-1.5 rounded-lg bg-slate-50 border border-slate-100">
                                        <span className="text-xs text-slate-600">{allergyTypeLabel(type)}</span>
                                        <span className="text-xs font-bold text-slate-800">{count}</span>
                                    </div>
                                ))}
                            </div>
                        ) : <p className="text-xs text-slate-400">No allergy data</p>}
                        {/* Critical food allergies */}
                        {restrictions?.critical_food_allergies.length ? (
                            <div>
                                <p className="text-[10px] font-semibold text-red-500 uppercase tracking-wide mb-1">Life-Threatening Food Allergies</p>
                                {restrictions.critical_food_allergies.slice(0, 4).map(a => (
                                    <div key={a.id} className="flex items-center justify-between py-1 border-b border-red-50 last:border-0">
                                        <span className="text-xs font-medium text-red-700">{a.allergen}</span>
                                        <span className="text-[10px] text-slate-500">{a.participant?.name ?? '—'}</span>
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

        </div>
    );
}
