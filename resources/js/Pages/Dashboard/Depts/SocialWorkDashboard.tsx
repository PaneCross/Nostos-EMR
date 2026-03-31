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
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant { id: number; name: string }

interface Appointment {
    id: number;
    participant: Participant | null;
    appointment_type: string;
    type_label: string;
    scheduled_start: string | null;
    status: string;
    href: string;
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
    href: string;
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
    href: string;
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

    // Map SDRs to ActionItems — overdue SDRs shown in red
    const sdrItems: ActionItem[] = (sdrs?.sdrs ?? []).map(s => ({
        label: `${s.participant?.name ?? '-'} — ${s.type_label}`,
        href: s.href,
        badge: s.is_overdue ? 'Overdue' : `${s.hours_remaining}h left`,
        badgeColor: s.is_overdue
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: `Priority: ${s.priority}`,
    }));

    // Map incidents to ActionItems — RCA-required incidents flagged in red
    const incidentItems: ActionItem[] = (incidents?.incidents ?? []).map(i => ({
        label: `${i.participant?.name ?? 'No participant'} — ${i.type_label}`,
        href: i.href,
        badge: i.rca_required ? 'RCA required' : i.status_label,
        badgeColor: i.rca_required
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: i.occurred_at ?? 'Date unknown',
    }));

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <ActionWidget
                title="Today's Schedule"
                description="Visits, assessments, and resource appointments for social work today."
                items={scheduleItems}
                emptyMessage="No appointments today"
                viewAllHref="/schedule"
                loading={loading}
            />

            <ActionWidget
                title="Active Alerts"
                description="Active alerts for social work — new hospital admissions, discharge events, and care coordination flags."
                items={alertItems}
                emptyMessage="No active alerts"
                viewAllHref="/participants"
                loading={loading}
            />

            <ActionWidget
                title="Overdue SDRs"
                description="SDRs assigned to social work past their 72-hour deadline. Escalated and visible to QA."
                items={sdrItems}
                emptyMessage="No open SDRs"
                viewAllHref="/sdrs"
                loading={loading}
            />

            <ActionWidget
                title="Open Incidents"
                description="Open incidents relevant to social work — falls, complaints, and participant welfare events."
                items={incidentItems}
                emptyMessage="No open incidents"
                viewAllHref="/qa/dashboard"
                loading={loading}
            />

        </div>
    );
}
