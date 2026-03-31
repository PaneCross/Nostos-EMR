// ─── PharmacyDashboard ─────────────────────────────────────────────────────────
// Real-data dashboard for the Pharmacy department.
// Rendered from Dashboard/Index.tsx when department === 'pharmacy'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/pharmacy/med-changes    — meds created/discontinued today
//   GET /dashboards/pharmacy/interactions   — unacknowledged drug interaction alerts
//   GET /dashboards/pharmacy/controlled     — controlled substance eMAR records today
//   GET /dashboards/pharmacy/refills        — medications requiring refill attention
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Participant { id: number; name: string; mrn?: string }

interface MedChange {
    id: number;
    participant: Participant | null;
    drug_name: string;
    dose_label: string;
    prescriber: string | null;
    is_controlled?: boolean;
    discontinued_reason?: string;
}

interface InteractionAlert {
    id: number;
    participant: Participant | null;
    drug_name_1: string;
    drug_name_2: string;
    severity: string;
    severity_color: string;
    description: string | null;
    created_at: string;
    href: string;
}

interface ControlledRecord {
    id: number;
    participant: Participant | null;
    drug_name: string | null;
    controlled_schedule: string | null;
    dose_label: string | null;
    status: string;
    scheduled_time: string | null;
    administered_by: string | null;
    witness: string | null;
    needs_witness: boolean;
    href: string;
}

interface RefillItem {
    id: number;
    participant: Participant | null;
    drug_name: string;
    dose_label: string;
    refills_remaining: number | null;
    last_filled_date: string | null;
    days_since_filled: number | null;
    reason: 'no_refills' | 'overdue_refill';
    href: string;
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function PharmacyDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]           = useState(true);
    const [medChanges, setMedChanges]     = useState<{
        new_orders: MedChange[]; new_orders_count: number;
        discontinued: MedChange[]; discontinued_count: number;
    } | null>(null);
    const [interactions, setInteractions] = useState<{ alerts: InteractionAlert[]; total_count: number } | null>(null);
    const [controlled, setControlled]     = useState<{ records: ControlledRecord[]; count: number } | null>(null);
    const [refills, setRefills]           = useState<{ medications: RefillItem[]; count: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/pharmacy/med-changes'),
            axios.get('/dashboards/pharmacy/interactions'),
            axios.get('/dashboards/pharmacy/controlled'),
            axios.get('/dashboards/pharmacy/refills'),
        ]).then(([meds, inter, ctrl, ref]) => {
            setMedChanges(meds.data);
            setInteractions(inter.data);
            setControlled(ctrl.data);
            setRefills(ref.data);
        }).finally(() => setLoading(false));
    }, []);

    // Build ActionItems for Med Changes — flatten new orders + discontinued into one list
    const medChangeItems: ActionItem[] = medChanges
        ? [
            ...medChanges.new_orders.map(m => ({
                label: `${m.participant?.name ?? '-'} : ${m.drug_name}`,
                href: m.participant ? `/participants/${m.participant.id}?tab=medications` : '/clinical/medications',
                badge: 'New',
                badgeColor: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
                sublabel: m.prescriber ?? undefined,
            })),
            ...medChanges.discontinued.map(m => ({
                label: `${m.participant?.name ?? '-'} : ${m.drug_name}`,
                href: m.participant ? `/participants/${m.participant.id}?tab=medications` : '/clinical/medications',
                badge: 'D/C',
                badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
                sublabel: m.discontinued_reason ?? undefined,
            })),
          ]
        : [];

    // Build ActionItems for Drug Interactions
    const interactionItems: ActionItem[] = (interactions?.alerts ?? []).map(a => ({
        label: `${a.drug_name_1} : ${a.drug_name_2}`,
        href: a.href ?? (a.participant ? `/participants/${a.participant.id}?tab=medications` : '/clinical/medications'),
        badge: a.severity,
        badgeColor: a.severity === 'contraindicated'
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : a.severity === 'major'
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        sublabel: `${a.participant?.name ?? '-'} | ${a.created_at}`,
    }));

    // Build ActionItems for Controlled Substances
    const controlledItems: ActionItem[] = (controlled?.records ?? []).map(r => ({
        label: `${r.participant?.name ?? '-'} : ${r.drug_name ?? '-'}`,
        href: r.href ?? (r.participant ? `/participants/${r.participant.id}?tab=emar` : '/clinical/medications'),
        badge: r.needs_witness ? 'Missing Witness' : r.controlled_schedule ?? undefined,
        badgeColor: r.needs_witness
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        sublabel: `${r.controlled_schedule ?? '?'} | ${r.status}${r.scheduled_time ? ` | ${r.scheduled_time}` : ''}`,
    }));

    // Build ActionItems for Refills
    const refillItems: ActionItem[] = (refills?.medications ?? []).map(m => ({
        label: `${m.participant?.name ?? '-'} : ${m.drug_name}`,
        href: m.href ?? (m.participant ? `/participants/${m.participant.id}?tab=medications` : '/clinical/medications'),
        badge: m.refills_remaining === 0 ? '0 refills' : m.days_since_filled != null ? `${m.days_since_filled}d` : undefined,
        badgeColor: m.refills_remaining === 0
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: m.last_filled_date ? `Last filled: ${m.last_filled_date}` : 'Never filled',
    }));

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <ActionWidget
                title="Medication Changes Today"
                description="New medication orders and discontinuations today. Review for interactions and formulary compliance."
                items={medChangeItems}
                emptyMessage="No medication changes today"
                viewAllHref="/clinical/medications"
                loading={loading}
            />

            <ActionWidget
                title="Drug Interaction Alerts"
                description="Unacknowledged drug interaction alerts. Contraindicated = red (immediate review). Major = amber."
                items={interactionItems}
                emptyMessage="No unacknowledged interaction alerts"
                viewAllHref="/clinical/medications"
                loading={loading}
            />

            <ActionWidget
                title="Controlled Substance Log: Today"
                description="Controlled substance administrations recorded today. Verify witness documentation for Schedule II/III."
                items={controlledItems}
                emptyMessage="No controlled substance records today"
                viewAllHref="/clinical/medications"
                loading={loading}
            />

            <ActionWidget
                title="Refill Attention Required"
                description="Medications with 0 refills remaining or last filled more than 28 days ago."
                items={refillItems}
                emptyMessage="No refills require attention"
                viewAllHref="/clinical/medications"
                loading={loading}
            />

        </div>
    );
}
