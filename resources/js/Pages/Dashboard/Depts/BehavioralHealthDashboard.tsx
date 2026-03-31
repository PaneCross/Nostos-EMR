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

interface AssessmentItem {
    id: number;
    participant: Participant | null;
    assessment_type: string;
    type_label: string;
    next_due_date: string | null;
    days_overdue: number | null;
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

interface Goal {
    id: number;
    goal_description: string;
    target_date: string | null;
    status: string;
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

    // Map appointments to ActionItems
    const scheduleItems: ActionItem[] = (schedule?.appointments ?? []).map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.type_label}`,
        href: a.href,
        badge: a.status,
        badgeColor: apptBadgeColor(a.status),
        sublabel: a.scheduled_start ?? undefined,
    }));

    // Combine overdue + due_soon assessments, overdue first with red badge
    const assessmentItems: ActionItem[] = [
        ...(assessments?.overdue ?? []).map(a => ({
            label: `${a.participant?.name ?? '-'} — ${a.type_label}`,
            href: a.href,
            badge: a.days_overdue != null ? `${a.days_overdue}d overdue` : 'overdue',
            badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' as const,
            sublabel: a.next_due_date ? `Due ${a.next_due_date}` : undefined,
        })),
        ...(assessments?.due_soon ?? []).map(a => ({
            label: `${a.participant?.name ?? '-'} — ${a.type_label}`,
            href: a.href,
            badge: a.next_due_date ? `Due ${a.next_due_date}` : 'due soon',
            badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' as const,
            sublabel: undefined,
        })),
    ];

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

    // Map goals to ActionItems
    const goalItems: ActionItem[] = (goals?.goals ?? []).map(g => ({
        label: `${g.participant?.name ?? '-'} — BH Goal`,
        href: g.href,
        badge: g.target_date ? `Due ${g.target_date}` : undefined,
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: g.goal_description,
    }));

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <ActionWidget
                title="Today's Sessions"
                description="Behavioral health and counseling sessions scheduled today."
                items={scheduleItems}
                emptyMessage="No BH sessions today"
                viewAllHref="/schedule"
                loading={loading}
            />

            <ActionWidget
                title="Overdue Assessments"
                description="PHQ-9, GAD-7, and MMSE assessments that are past due. Required per 42 CFR 460.68."
                items={assessmentItems}
                emptyMessage="No overdue or upcoming assessments"
                viewAllHref="/clinical/assessments"
                loading={loading}
            />

            <ActionWidget
                title="Overdue SDRs"
                description="SDRs assigned to behavioral health past their 72-hour deadline."
                items={sdrItems}
                emptyMessage="No open SDRs"
                viewAllHref="/sdrs"
                loading={loading}
            />

            <ActionWidget
                title="Goals Due"
                description="Behavioral health care plan goals with target dates within 14 days or past due."
                items={goalItems}
                emptyMessage="No active BH goals due soon"
                viewAllHref="/clinical/care-plans"
                loading={loading}
            />

        </div>
    );
}
