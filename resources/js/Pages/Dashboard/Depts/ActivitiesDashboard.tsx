// ─── ActivitiesDashboard ───────────────────────────────────────────────────────
// Real-data dashboard for the Activities / Recreation Therapy department.
// Rendered from Dashboard/Index.tsx when department === 'activities'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/activities/schedule  — today's activity group sessions + day center count
//   GET /dashboards/activities/goals     — active activity/recreational domain goals
//   GET /dashboards/activities/sdrs      — open/overdue SDRs for activities
//   GET /dashboards/activities/docs      — unsigned activities department notes
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

// ── Badge color helpers ────────────────────────────────────────────────────────

// Maps appointment status to a badge color class
function apptBadgeColor(status: string): string {
    if (status === 'confirmed') return 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300';
    if (status === 'scheduled') return 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300';
    return 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300';
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function ActivitiesDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]   = useState(true);
    const [schedule, setSchedule] = useState<{ appointments: Appointment[]; day_center_count: number } | null>(null);
    const [goals, setGoals]       = useState<{ goals: Goal[] } | null>(null);
    const [sdrs, setSdrs]         = useState<{ sdrs: Sdr[]; overdue_count: number; open_count: number } | null>(null);
    const [docs, setDocs]         = useState<{ notes: Note[]; unsigned_count: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/activities/schedule'),
            axios.get('/dashboards/activities/goals'),
            axios.get('/dashboards/activities/sdrs'),
            axios.get('/dashboards/activities/docs'),
        ]).then(([sched, goal, sdr, doc]) => {
            setSchedule(sched.data);
            setGoals(goal.data);
            setSdrs(sdr.data);
            setDocs(doc.data);
        }).finally(() => setLoading(false));
    }, []);

    // Map appointments to ActionItems; include day center count in sublabel when relevant
    const scheduleItems: ActionItem[] = (schedule?.appointments ?? []).map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label}`,
        href: a.href,
        badge: a.status,
        badgeColor: apptBadgeColor(a.status),
        sublabel: a.scheduled_start ?? undefined,
    }));

    // Map goals to ActionItems
    const goalItems: ActionItem[] = (goals?.goals ?? []).map(g => ({
        label: `${g.participant?.name ?? '-'} — Activity Goal`,
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

    // Map unsigned notes to ActionItems
    const noteItems: ActionItem[] = (docs?.notes ?? []).map(n => ({
        label: `${n.participant?.name ?? '-'} — ${n.type_label}`,
        href: n.href,
        sublabel: n.visit_date ?? n.created_at,
    }));

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <ActionWidget
                title="Today's Activities"
                description="Activity sessions and day center attendance scheduled for today."
                items={scheduleItems}
                emptyMessage="No activity sessions today"
                viewAllHref="/schedule"
                loading={loading}
            />

            <ActionWidget
                title="Goals Due"
                description="Activities care plan goals with target dates within 14 days or past due."
                items={goalItems}
                emptyMessage="No active activity goals due soon"
                viewAllHref="/clinical/care-plans"
                loading={loading}
            />

            <ActionWidget
                title="Overdue SDRs"
                description="SDRs assigned to activities past their 72-hour deadline."
                items={sdrItems}
                emptyMessage="No open SDRs"
                viewAllHref="/sdrs"
                loading={loading}
            />

            <ActionWidget
                title="Unsigned Notes"
                description="Activity session notes not yet signed."
                items={noteItems}
                emptyMessage="No unsigned notes — all clear!"
                viewAllHref="/clinical/notes"
                loading={loading}
            />

        </div>
    );
}
