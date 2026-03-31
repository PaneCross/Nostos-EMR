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
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

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
    href: string;
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
    href: string;
}

interface Goal {
    id: number;
    goal_description: string;
    target_date: string | null;
    status: string;
    participant: Participant | null;
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

export default function HomeCareDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]     = useState(true);
    const [schedule, setSchedule]   = useState<{ appointments: HomeVisit[] } | null>(null);
    const [adlAlerts, setAdlAlerts] = useState<{ alerts: AlertItem[]; unacknowledged_count: number } | null>(null);
    const [goals, setGoals]         = useState<{ goals: Goal[] } | null>(null);
    const [sdrs, setSdrs]           = useState<{ sdrs: Sdr[]; overdue_count: number; open_count: number } | null>(null);

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

    // Map home visits to ActionItems; note transport_required in sublabel
    const scheduleItems: ActionItem[] = (schedule?.appointments ?? []).map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label}`,
        href: a.href,
        badge: a.status,
        badgeColor: apptBadgeColor(a.status),
        sublabel: [a.scheduled_start, a.transport_required ? 'Transport required' : null].filter(Boolean).join(' | ') || undefined,
    }));

    // Map ADL alerts to ActionItems
    const adlAlertItems: ActionItem[] = (adlAlerts?.alerts ?? []).map(a => ({
        label: `${a.participant?.name ?? 'No participant'} — ${a.type_label}`,
        href: a.href,
        badge: a.severity,
        badgeColor: severityColor(a.severity),
        sublabel: a.acknowledged ? `${a.created_at} (ack'd)` : a.created_at,
    }));

    // Map goals to ActionItems
    const goalItems: ActionItem[] = (goals?.goals ?? []).map(g => ({
        label: `${g.participant?.name ?? '-'} — Home Care Goal`,
        href: g.href,
        badge: g.target_date ? `Due ${g.target_date}` : undefined,
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: g.goal_description,
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

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <ActionWidget
                title="Today's Home Visits"
                description="Home visits scheduled for today. Complete visit documentation within 24h."
                items={scheduleItems}
                emptyMessage="No home visits scheduled today"
                viewAllHref="/schedule"
                loading={loading}
            />

            <ActionWidget
                title="ADL Alerts"
                description="Participants with ADL threshold breaches — functional decline requiring care plan review."
                items={adlAlertItems}
                emptyMessage="No active ADL alerts"
                viewAllHref="/participants"
                loading={loading}
            />

            <ActionWidget
                title="Goals Due"
                description="Home care goals with target dates within 14 days or past due."
                items={goalItems}
                emptyMessage="No active home care goals due soon"
                viewAllHref="/clinical/care-plans"
                loading={loading}
            />

            <ActionWidget
                title="Overdue SDRs"
                description="SDRs assigned to home care past their 72-hour deadline."
                items={sdrItems}
                emptyMessage="No open SDRs"
                viewAllHref="/sdrs"
                loading={loading}
            />

        </div>
    );
}
