// ─── IdtDashboard ──────────────────────────────────────────────────────────────
// Real-data dashboard for the IDT / Care Coordination department.
// Rendered from Dashboard/Index.tsx when department === 'idt'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/idt/meetings     — today's IDT meetings with Start Meeting links
//   GET /dashboards/idt/overdue-sdrs — escalated SDRs grouped by department
//   GET /dashboards/idt/care-plans   — care plans due for review within 30 days
//   GET /dashboards/idt/alerts       — last 24h cross-department alert feed
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant { id: number; name: string; mrn?: string }

interface MeetingItem {
    id: number;
    meeting_type: string;
    type_label: string;
    meeting_time: string | null;
    status: string;
    facilitator: string | null;
    site: string | null;
    run_url: string;
}

interface SdrItem {
    id: number;
    participant: Participant | null;
    request_type: string;
    type_label: string;
    assigned_department: string;
    status: string;
    priority: string;
    due_at: string | null;
    hours_overdue: number | null;
    href?: string;
}

interface SdrDeptGroup {
    department: string;
    count: number;
    sdrs: SdrItem[];
}

interface CarePlanItem {
    id: number;
    participant: Participant | null;
    status: string;
    review_due_date: string | null;
    is_overdue: boolean;
    days_until_due: number | null;
    href?: string;
}

interface AlertItem {
    id: number;
    title: string;
    severity: 'critical' | 'warning' | 'info';
    type_label: string;
    target_depts: string[];
    acknowledged: boolean;
    participant: Participant | null;
    created_at: string;
    href?: string;
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function IdtDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]       = useState(true);
    const [meetings, setMeetings]     = useState<{ meetings: MeetingItem[]; count: number; has_meeting_today: boolean } | null>(null);
    const [sdrs, setSdrs]             = useState<{ departments: SdrDeptGroup[]; total_count: number } | null>(null);
    const [carePlans, setCarePlans]   = useState<{
        care_plans: CarePlanItem[]; overdue_count: number; due_soon_count: number;
    } | null>(null);
    const [alerts, setAlerts]         = useState<{ alerts: AlertItem[]; critical_count: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/idt/meetings'),
            axios.get('/dashboards/idt/overdue-sdrs'),
            axios.get('/dashboards/idt/care-plans'),
            axios.get('/dashboards/idt/alerts'),
        ]).then(([meet, sdr, cp, alert]) => {
            setMeetings(meet.data);
            setSdrs(sdr.data);
            setCarePlans(cp.data);
            setAlerts(alert.data);
        }).finally(() => setLoading(false));
    }, []);

    // Build ActionItems for Today's Meetings
    const meetingItems: ActionItem[] = (meetings?.meetings ?? []).map(m => ({
        label: m.type_label,
        href: m.run_url,
        badge: m.status === 'in_progress' ? 'In Progress' : 'Scheduled',
        badgeColor: m.status === 'in_progress'
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        sublabel: `${m.meeting_time ?? '-'} | ${m.site ?? 'All Sites'}${m.facilitator ? ` | ${m.facilitator}` : ''}`,
    }));

    // Build ActionItems for Overdue SDRs — flatten all groups into one list
    const sdrItems: ActionItem[] = (sdrs?.departments ?? []).flatMap(g =>
        g.sdrs.map(s => ({
            label: `${s.participant?.name ?? '-'} : ${s.type_label}`,
            href: s.href ?? `/sdrs/${s.id}`,
            badge: s.hours_overdue != null ? `${s.hours_overdue}h overdue` : 'Overdue',
            badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
            sublabel: g.department.replace(/_/g, ' '),
        }))
    );

    // Build ActionItems for Care Plans
    const carePlanItems: ActionItem[] = (carePlans?.care_plans ?? []).map(p => ({
        label: p.participant?.name ?? '-',
        href: p.href ?? (p.participant ? `/participants/${p.participant.id}?tab=care-plans` : '/clinical/care-plans'),
        badge: p.is_overdue
            ? `${Math.abs(p.days_until_due ?? 0)}d overdue`
            : `${p.days_until_due}d`,
        badgeColor: p.is_overdue
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: p.status.replace(/_/g, ' '),
    }));

    // Build ActionItems for Alerts (24h)
    const alertItems: ActionItem[] = (alerts?.alerts ?? []).map(a => ({
        label: a.title,
        href: a.href ?? (a.participant ? `/participants/${a.participant.id}` : '/qa/dashboard'),
        badge: a.severity,
        badgeColor: a.severity === 'critical'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : a.severity === 'warning'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        sublabel: `${a.participant?.name ?? 'System'} | ${a.created_at}`,
    }));

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <ActionWidget
                title="Today's IDT Meetings"
                description="IDT meetings scheduled for today. Click a meeting to open the run sheet and document participant reviews."
                items={meetingItems}
                emptyMessage="No meetings scheduled today"
                viewAllHref="/idt/meetings"
                loading={loading}
            />

            <ActionWidget
                title="Escalated SDRs"
                description="Escalated SDRs across all departments that have passed their 72-hour deadline. Group by department for follow-up."
                items={sdrItems}
                emptyMessage="No escalated SDRs"
                viewAllHref="/sdrs"
                loading={loading}
            />

            <ActionWidget
                title="Care Plans Due Within 30 Days"
                description="Active care plans with a review date within 30 days or past due. Review scheduled at next IDT meeting."
                items={carePlanItems}
                emptyMessage="No care plans due within 30 days"
                viewAllHref="/clinical/care-plans"
                loading={loading}
            />

            <ActionWidget
                title="Alerts: Last 24 Hours"
                description="All active alerts created in the last 24 hours across all departments. IDT coordinates cross-discipline follow-up."
                items={alertItems}
                emptyMessage="No alerts in the last 24 hours"
                viewAllHref="/qa/dashboard"
                loading={loading}
            />

        </div>
    );
}
