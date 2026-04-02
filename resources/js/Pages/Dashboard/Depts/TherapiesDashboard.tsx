// ─── TherapiesDashboard ────────────────────────────────────────────────────────
// Real-data dashboard for the Therapies (PT/OT/ST) department.
// Rendered from Dashboard/Index.tsx when department === 'therapies'.
// Fetches 5 widget endpoints in parallel on mount:
//   GET /dashboards/therapies/schedule  — today's PT/OT/ST appointments
//   GET /dashboards/therapies/goals     — active therapy domain care plan goals
//   GET /dashboards/therapies/sdrs      — open/overdue SDRs for therapies
//   GET /dashboards/therapies/docs      — unsigned therapy notes
//   GET /dashboards/therapies/orders    — active therapy orders (PT/OT/ST) (W4-7)
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
    provider_name: string | null;
    href: string;
}

interface Goal {
    id: number;
    domain: string;
    domain_label: string;
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
    due_at: string | null;
    href: string;
}

interface Note {
    id: number;
    participant: Participant | null;
    note_type: string;
    type_label: string;
    author: string | null;
    visit_date: string | null;
    created_at: string;
    href: string;
}

// W4-7: Active therapy order for orders widget
interface OrderItem {
    id: number;
    participant_id: number;
    participant_first_name: string;
    participant_last_name: string;
    order_type_label: string;
    priority: string;
    status: string;
    is_overdue: boolean;
    href: string;
}

// ── Badge color helpers ────────────────────────────────────────────────────────

// Maps appointment status to a badge color class
function apptBadgeColor(status: string): string {
    if (status === 'confirmed') return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300';
    if (status === 'scheduled') return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300';
    return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300';
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function TherapiesDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]   = useState(true);
    const [schedule, setSchedule] = useState<{ appointments: Appointment[] } | null>(null);
    const [goals, setGoals]       = useState<{ goals: Goal[] } | null>(null);
    const [sdrs, setSdrs]         = useState<{ sdrs: Sdr[]; overdue_count: number; open_count: number } | null>(null);
    const [docs, setDocs]         = useState<{ notes: Note[]; unsigned_count: number } | null>(null);
    // W4-7: therapy orders (PT/OT/ST)
    const [orders, setOrders]     = useState<{ orders: OrderItem[]; pending_count: number; stat_count: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/therapies/schedule'),
            axios.get('/dashboards/therapies/goals'),
            axios.get('/dashboards/therapies/sdrs'),
            axios.get('/dashboards/therapies/docs'),
            axios.get('/dashboards/therapies/orders'),
        ]).then(([sched, goal, sdr, doc, ord]) => {
            setSchedule(sched.data);
            setGoals(goal.data);
            setSdrs(sdr.data);
            setDocs(doc.data);
            setOrders(ord.data);
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

    // Map goals to ActionItems
    const goalItems: ActionItem[] = (goals?.goals ?? []).map(g => ({
        label: `${g.participant?.name ?? '-'} — ${g.domain_label}`,
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
        sublabel: s.participant?.name ? `Priority: ${s.priority}` : undefined,
    }));

    // Map unsigned notes to ActionItems
    const noteItems: ActionItem[] = (docs?.notes ?? []).map(n => ({
        label: `${n.participant?.name ?? '-'} — ${n.type_label}`,
        href: n.href,
        sublabel: n.visit_date ?? n.created_at,
    }));

    // W4-7: Map therapy orders to ActionItems — stat=red, urgent=amber
    const orderItems: ActionItem[] = (orders?.orders ?? []).map(o => ({
        label: `${o.participant_first_name} ${o.participant_last_name} — ${o.order_type_label}`,
        href: o.href,
        badge: o.priority.toUpperCase(),
        badgeColor: o.priority === 'stat'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 font-bold'
            : o.priority === 'urgent'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        sublabel: o.is_overdue ? 'OVERDUE' : o.status,
    }));

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <ActionWidget
                title="Today's Sessions"
                description="Today's PT, OT, and ST sessions. Completed sessions shown in gray."
                items={scheduleItems}
                emptyMessage="No therapy sessions today"
                viewAllHref="/schedule"
                loading={loading}
            />

            <ActionWidget
                title="Goals Due"
                description="Care plan goals in therapy disciplines with a target date within 14 days or past due. Required for care plan update at next IDT."
                items={goalItems}
                emptyMessage="No goals due soon"
                viewAllHref="/clinical/care-plans"
                loading={loading}
            />

            <ActionWidget
                title="Overdue SDRs"
                description="SDRs assigned to therapies that have passed their 72-hour completion deadline. These are escalated and visible to QA."
                items={sdrItems}
                emptyMessage="No open SDRs"
                viewAllHref="/sdrs"
                loading={loading}
            />

            <ActionWidget
                title="Unsigned Notes"
                description="Therapy session notes not yet signed. Documentation must be completed within 24h of the session."
                items={noteItems}
                emptyMessage="No unsigned notes — all clear!"
                viewAllHref="/clinical/notes"
                loading={loading}
            />

            {/* W4-7: Therapy orders widget (PT/OT/ST/Speech) */}
            <ActionWidget
                title={`Therapy Orders${orders?.stat_count ? ` (${orders.stat_count} STAT)` : ''}`}
                description="Active PT, OT, ST, and Speech therapy orders. STAT orders require immediate attention. Click any row to manage in the participant's Orders tab."
                items={orderItems}
                emptyMessage="No active therapy orders"
                viewAllHref="/orders"
                loading={loading}
            />

        </div>
    );
}
