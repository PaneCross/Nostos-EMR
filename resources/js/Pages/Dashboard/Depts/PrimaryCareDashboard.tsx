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
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

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
    href: string;
}

interface AlertItem {
    id: number;
    title: string;
    severity: 'critical' | 'warning' | 'info';
    type_label: string;
    acknowledged: boolean;
    participant: Participant | null;
    created_at: string;
    href: string;
}

interface UnsignedNote {
    id: number;
    participant: Participant | null;
    note_type: string;
    type_label: string;
    author: string | null;
    visit_date: string | null;
    created_at: string;
    href: string;
}

interface OverdueAssessment {
    id: number;
    participant: Participant | null;
    assessment_type: string;
    type_label: string;
    next_due_date: string | null;
    days_overdue: number | null;
    href: string;
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
    href: string;
}

// ── Badge color helpers ────────────────────────────────────────────────────────

// Maps appointment status to a badge color class
function apptBadgeColor(status: string): string {
    if (status === 'confirmed') return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300';
    if (status === 'scheduled') return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300';
    return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300';
}

// Maps alert severity to a badge color class
function severityColor(severity: string): string {
    if (severity === 'critical') return 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300';
    if (severity === 'warning') return 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300';
    return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300';
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

    // Map appointments to ActionItems
    const scheduleItems: ActionItem[] = (schedule?.appointments ?? []).map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label}`,
        href: a.href,
        badge: a.status,
        badgeColor: apptBadgeColor(a.status),
        sublabel: a.scheduled_start ?? undefined,
    }));

    // Map alerts to ActionItems
    const alertItems: ActionItem[] = (alerts?.alerts ?? []).map(a => ({
        label: `${a.participant?.name ?? 'No participant'} — ${a.type_label}`,
        href: a.href,
        badge: a.severity,
        badgeColor: severityColor(a.severity),
        sublabel: a.created_at,
    }));

    // Map unsigned notes to ActionItems
    const unsignedNoteItems: ActionItem[] = (docs?.unsigned_notes ?? []).map(n => ({
        label: `${n.participant?.name ?? '-'} — ${n.type_label}`,
        href: n.href,
        badge: n.author ? undefined : 'unassigned',
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: n.visit_date ?? n.created_at,
    }));

    // Map overdue assessments to ActionItems
    const overdueAssessmentItems: ActionItem[] = (docs?.overdue_assessments ?? []).map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label}`,
        href: a.href,
        badge: a.days_overdue != null ? `${a.days_overdue}d overdue` : 'overdue',
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        sublabel: a.next_due_date ? `Due ${a.next_due_date}` : undefined,
    }));

    // Map vitals to ActionItems
    const vitalsItems: ActionItem[] = (vitals?.vitals ?? []).map(v => ({
        label: `${v.participant?.name ?? '-'} — Vitals`,
        href: v.href,
        badge: v.out_of_range ? 'Out of range' : undefined,
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        sublabel: [v.bp ? `BP ${v.bp}` : null, v.o2_saturation != null ? `O2 ${v.o2_saturation}%` : null, v.recorded_at].filter(Boolean).join(' | ') || undefined,
    }));

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <ActionWidget
                title="Today's Schedule"
                description="Today's clinic visits, labs, telehealth, and specialist appointments for primary care. Upcoming in blue, completed in gray."
                items={scheduleItems}
                emptyMessage="No appointments today"
                viewAllHref="/schedule"
                loading={loading}
            />

            <ActionWidget
                title="Active Alerts"
                description="Active alerts flagged for primary care. Red = critical (act immediately). Amber = warning. Acknowledge each after reviewing."
                items={alertItems}
                emptyMessage="No active alerts"
                viewAllHref="/participants"
                loading={loading}
            />

            {/* Documentation Queue — two stacked ActionWidgets: unsigned notes + overdue assessments */}
            <div className="flex flex-col gap-4">
                <ActionWidget
                    title="Unsigned Notes"
                    description="Notes you authored that have not yet been signed. Unsigned notes older than 24h are flagged by QA."
                    items={unsignedNoteItems}
                    emptyMessage="No unsigned notes — all clear!"
                    viewAllHref="/clinical/notes"
                    loading={loading}
                />
                <ActionWidget
                    title="Overdue Assessments"
                    description="Annual and clinical assessments past their due date. Reassessment is required before the next IDT meeting."
                    items={overdueAssessmentItems}
                    emptyMessage="No overdue assessments"
                    viewAllHref="/clinical/assessments"
                    loading={loading}
                />
            </div>

            <ActionWidget
                title="Recent Vitals"
                description="Most recent vitals across the tenant. Participants with out-of-range values need follow-up. BP >160/100 or O2 <92% requires immediate attention."
                items={vitalsItems}
                emptyMessage="No vitals recorded today"
                viewAllHref="/clinical/vitals"
                loading={loading}
            />

        </div>
    );
}
