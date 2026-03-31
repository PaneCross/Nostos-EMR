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
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

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
    href?: string;
}

interface UnsignedNote {
    id: number;
    participant: { id: number; name: string } | null;
    department: string;
    note_type: string;
    hours_old: number;
    href?: string;
}

interface OverdueAssessment {
    id: number;
    participant: { id: number; name: string } | null;
    assessment_type: string;
    next_due_date: string | null;
    days_overdue: number;
    href?: string;
}

interface CarePlanItem {
    id: number;
    participant: Participant | null;
    status: string;
    review_due_date: string | null;
    days_overdue: number;
    href?: string;
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
    let color = 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300';
    if (isPercent) {
        color = value >= 95 ? 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300'
            : value >= 80 ? 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300'
            : 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300';
    } else if (threshold !== undefined) {
        color = value === 0 ? 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300'
            : value <= threshold ? 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300'
            : 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300';
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

    // Build ActionItems for Incidents
    const incidentItems: ActionItem[] = (incidents?.incidents ?? []).map(i => ({
        label: `${i.participant?.name ?? 'N/A'} : ${i.incident_type.replace(/_/g, ' ')}`,
        href: i.href ?? `/qa/dashboard`,
        badge: i.rca_required && !i.rca_completed ? 'RCA Due' : i.status.replace(/_/g, ' '),
        badgeColor: i.rca_required && !i.rca_completed
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: `${i.status.replace(/_/g, ' ')}${i.occurred_at ? ` | ${i.occurred_at}` : ''}`,
    }));

    // Build ActionItems for Documentation Gaps — flatten unsigned notes + overdue assessments
    const docItems: ActionItem[] = [
        ...(docs?.unsigned_notes ?? []).map(n => ({
            label: `${n.participant?.name ?? '-'} : ${n.note_type.replace(/_/g, ' ')}`,
            href: n.href ?? (n.participant ? `/participants/${n.participant.id}?tab=chart` : '/qa/dashboard'),
            badge: `${n.hours_old}h`,
            badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' as string,
            sublabel: n.department.replace(/_/g, ' '),
        })),
        ...(docs?.overdue_assessments ?? []).map(a => ({
            label: `${a.participant?.name ?? '-'} : ${a.assessment_type.replace(/_/g, ' ')}`,
            href: a.href ?? (a.participant ? `/participants/${a.participant.id}?tab=assessments` : '/qa/dashboard'),
            badge: `${a.days_overdue}d overdue`,
            badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' as string,
            sublabel: a.next_due_date ?? undefined,
        })),
    ];

    // Build ActionItems for Overdue Care Plans
    const carePlanItems: ActionItem[] = (carePlans?.care_plans ?? []).map(p => ({
        label: p.participant?.name ?? '-',
        href: p.href ?? (p.participant ? `/participants/${p.participant.id}?tab=care-plans` : '/clinical/care-plans'),
        badge: `${p.days_overdue}d overdue`,
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        sublabel: p.status.replace(/_/g, ' '),
    }));

    return (
        <div className="space-y-6">

            {/* KPI Card Row — kept as-is (KPI counts, not list items) */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                {loading || !kpis ? (
                    Array.from({ length: 6 }).map((_, i) => (
                        <div key={i} className="h-20 bg-slate-100 dark:bg-slate-800 rounded-xl animate-pulse" />
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

                <ActionWidget
                    title="Open Incidents"
                    description="Open incidents requiring review or RCA completion. Red = RCA required per CMS 42 CFR 460.136."
                    items={incidentItems}
                    emptyMessage="No open incidents"
                    viewAllHref="/qa/dashboard"
                    loading={loading}
                />

                <ActionWidget
                    title="Documentation Compliance"
                    description="Unsigned notes older than 24h and overdue assessments. QA monitors these for audit readiness."
                    items={docItems}
                    emptyMessage="No documentation gaps"
                    viewAllHref="/qa/dashboard"
                    loading={loading}
                />

                <ActionWidget
                    title="Overdue Care Plans"
                    description="Active care plans with overdue or upcoming review dates. Reviewed at IDT."
                    items={carePlanItems}
                    emptyMessage="No overdue care plans"
                    viewAllHref="/clinical/care-plans"
                    loading={loading}
                />

            </div>
        </div>
    );
}
