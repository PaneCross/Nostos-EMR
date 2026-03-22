// ─── QaComplianceDashboard ────────────────────────────────────────────────────
// Real-data dashboard for the QA / Compliance department.
// Rendered from Dashboard/Index.tsx when department === 'qa_compliance'.
// Uses QaMetricsService KPIs served via the widget controller.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/qa-compliance/metrics     — all 6 QA KPIs
//   GET /dashboards/qa-compliance/incidents   — open incidents list
//   GET /dashboards/qa-compliance/docs        — unsigned notes + overdue assessments
//   GET /dashboards/qa-compliance/care-plans  — overdue care plans
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant { id: number; name: string; mrn?: string }

interface KpiData {
    sdr_compliance_rate: number;
    overdue_assessments_count: number;
    unsigned_notes_count: number;
    open_incidents_count: number;
    overdue_care_plans_count: number;
    hospitalizations_count: number;
}

interface IncidentItem {
    id: number;
    participant: Participant | null;
    incident_type: string;
    status: string;
    rca_required: boolean;
    rca_completed: boolean;
    occurred_at: string | null;
    reported_by: string | null;
}

interface UnsignedNote {
    id: number;
    participant: { id: number; name: string } | null;
    department: string;
    note_type: string;
    hours_old: number;
}

interface OverdueAssessment {
    id: number;
    participant: { id: number; name: string } | null;
    assessment_type: string;
    next_due_date: string | null;
    days_overdue: number;
}

interface CarePlanItem {
    id: number;
    participant: Participant | null;
    status: string;
    review_due_date: string | null;
    days_overdue: number;
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

// ── KPI card ───────────────────────────────────────────────────────────────────

function KpiCard({ label, value, sub, threshold, isPercent }: {
    label: string;
    value: number;
    sub?: string;
    threshold?: number;
    isPercent?: boolean;
}) {
    // For SDR compliance: green if >=95, amber if >=80, red otherwise
    // For counts: green if 0, amber if <= threshold, red otherwise
    let color = 'bg-green-50 border-green-200 text-green-800';
    if (isPercent) {
        color = value >= 95 ? 'bg-green-50 border-green-200 text-green-800'
            : value >= 80 ? 'bg-amber-50 border-amber-200 text-amber-800'
            : 'bg-red-50 border-red-200 text-red-800';
    } else if (threshold !== undefined) {
        color = value === 0 ? 'bg-green-50 border-green-200 text-green-800'
            : value <= threshold ? 'bg-amber-50 border-amber-200 text-amber-800'
            : 'bg-red-50 border-red-200 text-red-800';
    }

    return (
        <div className={`rounded-xl border p-3 ${color}`}>
            <p className="text-[10px] font-semibold uppercase tracking-wide opacity-70">{label}</p>
            <p className="text-2xl font-bold mt-1">
                {isPercent ? `${value}%` : value}
            </p>
            {sub && <p className="text-[10px] mt-0.5 opacity-60">{sub}</p>}
        </div>
    );
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function QaComplianceDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]     = useState(true);
    const [kpis, setKpis]           = useState<KpiData | null>(null);
    const [incidents, setIncidents] = useState<{
        incidents: IncidentItem[]; open_count: number; rca_pending_count: number;
    } | null>(null);
    const [docs, setDocs]           = useState<{
        unsigned_notes: UnsignedNote[];
        unsigned_count: number;
        notes_by_department: Record<string, number>;
        overdue_assessments: OverdueAssessment[];
        overdue_assess_count: number;
    } | null>(null);
    const [carePlans, setCarePlans] = useState<{
        care_plans: CarePlanItem[]; overdue_count: number;
    } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/qa-compliance/metrics'),
            axios.get('/dashboards/qa-compliance/incidents'),
            axios.get('/dashboards/qa-compliance/docs'),
            axios.get('/dashboards/qa-compliance/care-plans'),
        ]).then(([kpi, inc, doc, cp]) => {
            setKpis(kpi.data);
            setIncidents(inc.data);
            setDocs(doc.data);
            setCarePlans(cp.data);
        }).finally(() => setLoading(false));
    }, []);

    return (
        <div className="space-y-6">

            {/* KPI Card Row */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                {loading || !kpis ? (
                    Array.from({ length: 6 }).map((_, i) => (
                        <div key={i} className="h-20 bg-slate-100 rounded-xl animate-pulse" />
                    ))
                ) : (
                    <>
                        <KpiCard label="SDR Compliance" value={kpis.sdr_compliance_rate} isPercent sub="30-day rate" />
                        <KpiCard label="Overdue Assessments" value={kpis.overdue_assessments_count} threshold={5} sub="Past due date" />
                        <KpiCard label="Unsigned Notes >24h" value={kpis.unsigned_notes_count} threshold={3} sub="Documentation gap" />
                        <KpiCard label="Open Incidents" value={kpis.open_incidents_count} threshold={5} sub="All statuses" />
                        <KpiCard label="Overdue Care Plans" value={kpis.overdue_care_plans_count} threshold={3} sub="Review past due" />
                        <KpiCard label="Hospital/ER (Month)" value={kpis.hospitalizations_count} threshold={2} sub="This calendar month" />
                    </>
                )}
            </div>

            {/* Widget Grid */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

                {/* Open Incidents */}
                <WidgetCard
                    title="Open Incidents"
                    badge={incidents?.rca_pending_count ? {
                        label: `${incidents.rca_pending_count} RCA pending`,
                        color: 'bg-red-100 text-red-700',
                    } : incidents?.open_count ? {
                        label: `${incidents.open_count} open`,
                        color: 'bg-amber-100 text-amber-700',
                    } : undefined}
                >
                    {loading ? <Skeleton /> : !incidents?.incidents.length ? <Empty message="No open incidents" /> : (
                        <div className="space-y-1.5">
                            {incidents.incidents.map(i => (
                                <div key={i.id} className={`flex items-start gap-2 p-2 rounded-lg border ${i.rca_required && !i.rca_completed ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-slate-50'}`}>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-1.5">
                                            <p className="text-xs font-medium text-slate-800 capitalize">{i.incident_type.replace('_', ' ')}</p>
                                            {i.rca_required && !i.rca_completed && (
                                                <span className="text-[9px] px-1 py-0.5 rounded bg-red-100 text-red-700 font-bold uppercase">RCA Due</span>
                                            )}
                                        </div>
                                        <p className="text-[10px] text-slate-500">
                                            {i.participant?.name ?? 'N/A'} · {i.status.replace('_', ' ')} · {i.occurred_at ?? '?'}
                                        </p>
                                    </div>
                                </div>
                            ))}
                            <a href="/qa/dashboard" className="text-xs text-blue-600 hover:underline block text-center pt-1">
                                View all in QA Dashboard →
                            </a>
                        </div>
                    )}
                </WidgetCard>

                {/* Documentation Compliance */}
                <WidgetCard
                    title="Documentation Compliance"
                    badge={docs ? {
                        label: `${(docs.unsigned_count || 0) + (docs.overdue_assess_count || 0)} issues`,
                        color: 'bg-amber-100 text-amber-700',
                    } : undefined}
                >
                    {loading ? <Skeleton /> : (
                        <div className="space-y-3">
                            <div>
                                <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">
                                    Unsigned Notes &gt;24h <span className="text-amber-600">({docs?.unsigned_count ?? 0})</span>
                                </p>
                                {docs && Object.keys(docs.notes_by_department).length > 0 ? (
                                    Object.entries(docs.notes_by_department).map(([dept, count]) => (
                                        <div key={dept} className="flex items-center justify-between py-0.5">
                                            <span className="text-xs text-slate-600 capitalize">{dept.replace('_', ' ')}</span>
                                            <span className="text-xs font-semibold text-amber-600">{count}</span>
                                        </div>
                                    ))
                                ) : <p className="text-xs text-slate-400">None</p>}
                            </div>
                            <div>
                                <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">
                                    Overdue Assessments <span className="text-red-600">({docs?.overdue_assess_count ?? 0})</span>
                                </p>
                                {!docs?.overdue_assessments.length
                                    ? <p className="text-xs text-slate-400">None</p>
                                    : docs.overdue_assessments.slice(0, 4).map(a => (
                                        <div key={a.id} className="flex items-center justify-between py-0.5">
                                            <span className="text-xs text-slate-700">{a.participant?.name ?? '—'}</span>
                                            <span className="text-[10px] text-red-600">{a.days_overdue}d overdue</span>
                                        </div>
                                    ))}
                            </div>
                            <a href="/qa/dashboard" className="text-xs text-blue-600 hover:underline">
                                View full compliance detail →
                            </a>
                        </div>
                    )}
                </WidgetCard>

                {/* Overdue Care Plans */}
                <WidgetCard
                    title="Overdue Care Plans"
                    badge={carePlans?.overdue_count ? {
                        label: `${carePlans.overdue_count} overdue`,
                        color: 'bg-red-100 text-red-700',
                    } : undefined}
                >
                    {loading ? <Skeleton /> : !carePlans?.care_plans.length ? <Empty message="No overdue care plans" /> : (
                        <div className="overflow-auto">
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="border-b border-slate-100">
                                        <th className="text-left py-1 font-medium text-slate-500">Participant</th>
                                        <th className="text-left py-1 font-medium text-slate-500">Status</th>
                                        <th className="text-right py-1 font-medium text-slate-500">Days Over</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {carePlans.care_plans.map(p => (
                                        <tr key={p.id} className="bg-red-50 hover:bg-red-100">
                                            <td className="py-1.5 font-medium text-slate-800">{p.participant?.name ?? '—'}</td>
                                            <td className="py-1.5 text-slate-600 capitalize">{p.status.replace('_', ' ')}</td>
                                            <td className="py-1.5 text-right text-red-600 font-semibold">{p.days_overdue}d</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </WidgetCard>

                {/* Quick Links */}
                <WidgetCard title="Quick Links to Full QA Dashboard">
                    <div className="space-y-2">
                        {[
                            { label: 'Incident Reports', href: '/qa/dashboard#incidents', icon: '⚠️' },
                            { label: 'SDR Compliance Report', href: '/qa/dashboard#sdrs', icon: '⏱' },
                            { label: 'Unsigned Notes Detail', href: '/qa/dashboard#notes', icon: '📋' },
                            { label: 'Overdue Assessments Detail', href: '/qa/dashboard#assessments', icon: '📊' },
                            { label: 'Export QA Report', href: '/qa/reports/export', icon: '📥' },
                        ].map(link => (
                            <a key={link.href} href={link.href}
                               className="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 hover:border-blue-200 transition-colors">
                                <span className="text-base">{link.icon}</span>
                                <span className="text-xs font-medium text-slate-700 hover:text-blue-700">{link.label}</span>
                                <svg className="w-3 h-3 text-slate-300 ml-auto" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </a>
                        ))}
                    </div>
                </WidgetCard>

            </div>
        </div>
    );
}
