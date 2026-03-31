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
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant { id: number; name: string }

interface AssessmentItem {
    id: number;
    participant: Participant | null;
    next_due_date: string | null;
    days_overdue: number | null;
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

interface FoodAllergy {
    id: number;
    allergen: string;
    reaction: string;
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

    // Combine overdue + due_soon assessments into ActionItems
    const assessmentItems: ActionItem[] = [
        ...(assessments?.overdue ?? []).map(a => ({
            label: `${a.participant?.name ?? '-'} — Nutritional Assessment`,
            href: a.href,
            badge: a.days_overdue != null ? `${a.days_overdue}d overdue` : 'overdue',
            badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' as const,
            sublabel: a.next_due_date ? `Due ${a.next_due_date}` : undefined,
        })),
        ...(assessments?.due_soon ?? []).map(a => ({
            label: `${a.participant?.name ?? '-'} — Nutritional Assessment`,
            href: a.href,
            badge: a.next_due_date ? `Due ${a.next_due_date}` : 'due soon',
            badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' as const,
            sublabel: undefined,
        })),
    ];

    // Map goals to ActionItems
    const goalItems: ActionItem[] = (goals?.goals ?? []).map(g => ({
        label: `${g.participant?.name ?? '-'} — Dietary Goal`,
        href: g.href,
        badge: g.target_date ? `Due ${g.target_date}` : undefined,
        badgeColor: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: g.goal_description,
    }));

    // Map critical food allergies to ActionItems — life-threatening allergies in red
    const restrictionItems: ActionItem[] = (restrictions?.critical_food_allergies ?? []).map(a => ({
        label: `${a.participant?.name ?? '-'} — ${a.allergen}`,
        href: a.href,
        badge: 'Life-threatening',
        badgeColor: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        sublabel: a.reaction,
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
                title="Overdue Assessments"
                description="Nutritional assessments past their due date. Required annually per care plan."
                items={assessmentItems}
                emptyMessage="No overdue or upcoming assessments"
                viewAllHref="/clinical/assessments"
                loading={loading}
            />

            <ActionWidget
                title="Goals Due"
                description="Dietary care plan goals with target dates within 14 days or past due."
                items={goalItems}
                emptyMessage="No active dietary goals due soon"
                viewAllHref="/clinical/care-plans"
                loading={loading}
            />

            <ActionWidget
                title="Dietary Restrictions"
                description="Active food allergies and dietary restrictions across enrolled participants."
                items={restrictionItems}
                emptyMessage="No critical food allergies on record"
                viewAllHref="/participants"
                loading={loading}
            />

            <ActionWidget
                title="Overdue SDRs"
                description="SDRs assigned to dietary past their 72-hour deadline."
                items={sdrItems}
                emptyMessage="No open SDRs"
                viewAllHref="/sdrs"
                loading={loading}
            />

        </div>
    );
}
