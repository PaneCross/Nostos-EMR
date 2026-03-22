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
}

// ── Widget shell ────────────────────────────────────────────────────────────────

function WidgetCard({ title, badge, children }: {
    title: string;
    badge?: { label: string; color: string };
    children: React.ReactNode;
}) {
    return (
        <div className="card p-5 flex flex-col">
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold text-slate-700">{title}</h3>
                {badge && (
                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${badge.color}`}>
                        {badge.label}
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}

function Skeleton() {
    return (
        <div className="space-y-2 animate-pulse">
            {[1, 2, 3].map(i => <div key={i} className="h-8 bg-slate-100 rounded" />)}
        </div>
    );
}

function Empty({ message }: { message: string }) {
    return <p className="text-xs text-slate-400 py-4 text-center">{message}</p>;
}

function SeverityBadge({ severity }: { severity: string }) {
    const cls = severity === 'contraindicated' ? 'bg-red-100 text-red-700'
        : severity === 'major'   ? 'bg-orange-100 text-orange-700'
        : severity === 'moderate'? 'bg-amber-100 text-amber-700'
        : 'bg-yellow-100 text-yellow-700';
    return (
        <span className={`inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase ${cls}`}>
            {severity}
        </span>
    );
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

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Medication Changes Today */}
            <WidgetCard
                title="Medication Changes Today"
                badge={medChanges ? {
                    label: `${(medChanges.new_orders_count || 0) + (medChanges.discontinued_count || 0)} changes`,
                    color: 'bg-blue-100 text-blue-700',
                } : undefined}
            >
                {loading ? <Skeleton /> : (
                    <div className="space-y-3">
                        <div>
                            <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">
                                New Orders <span className="text-blue-600">({medChanges?.new_orders_count ?? 0})</span>
                            </p>
                            {!medChanges?.new_orders.length
                                ? <p className="text-xs text-slate-400">None today</p>
                                : medChanges.new_orders.map(m => (
                                    <div key={m.id} className="flex items-center justify-between py-1 border-b border-slate-50 last:border-0">
                                        <span className="text-xs font-medium text-slate-800 truncate">{m.drug_name}</span>
                                        <span className="text-[10px] text-slate-500 ml-2 shrink-0">{m.participant?.name ?? '—'}</span>
                                    </div>
                                ))
                            }
                        </div>
                        <div>
                            <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">
                                Discontinued <span className="text-red-600">({medChanges?.discontinued_count ?? 0})</span>
                            </p>
                            {!medChanges?.discontinued.length
                                ? <p className="text-xs text-slate-400">None today</p>
                                : medChanges.discontinued.map(m => (
                                    <div key={m.id} className="flex items-center justify-between py-1 border-b border-slate-50 last:border-0">
                                        <span className="text-xs font-medium text-slate-700 truncate line-through">{m.drug_name}</span>
                                        <span className="text-[10px] text-slate-500 ml-2 shrink-0">{m.participant?.name ?? '—'}</span>
                                    </div>
                                ))
                            }
                        </div>
                    </div>
                )}
            </WidgetCard>

            {/* Drug Interaction Alerts */}
            <WidgetCard
                title="Drug Interaction Alerts"
                badge={interactions?.total_count
                    ? { label: `${interactions.total_count} unreviewed`, color: 'bg-red-100 text-red-700' }
                    : undefined}
            >
                {loading ? <Skeleton /> : !interactions?.alerts.length ? <Empty message="No unacknowledged interaction alerts" /> : (
                    <div className="space-y-2">
                        {interactions.alerts.map(a => (
                            <div key={a.id} className="flex items-start gap-2 p-2 rounded-lg border border-slate-200 bg-slate-50">
                                <SeverityBadge severity={a.severity} />
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-medium text-slate-800">
                                        {a.drug_name_1} ↔ {a.drug_name_2}
                                    </p>
                                    <p className="text-[10px] text-slate-500">
                                        {a.participant?.name ?? '—'} · {a.created_at}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>

            {/* Controlled Substance Log */}
            <WidgetCard
                title="Controlled Substance Log — Today"
                badge={controlled?.count ? { label: `${controlled.count} records`, color: 'bg-purple-100 text-purple-700' } : undefined}
            >
                {loading ? <Skeleton /> : !controlled?.records.length ? <Empty message="No controlled substance records today" /> : (
                    <div className="overflow-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left py-1 font-medium text-slate-500">Participant</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Drug</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Sch</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Status</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Witness</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {controlled.records.map(r => (
                                    <tr key={r.id} className={r.needs_witness ? 'bg-red-50' : 'hover:bg-slate-50'}>
                                        <td className="py-1.5 font-medium text-slate-800">{r.participant?.name ?? '—'}</td>
                                        <td className="py-1.5 text-slate-700">{r.drug_name ?? '—'}</td>
                                        <td className="py-1.5 text-slate-500">{r.controlled_schedule ?? '?'}</td>
                                        <td className="py-1.5 text-slate-600">{r.status}</td>
                                        <td className="py-1.5">
                                            {r.needs_witness
                                                ? <span className="text-red-600 font-medium text-[10px]">MISSING</span>
                                                : <span className="text-slate-500">{r.witness ?? '—'}</span>
                                            }
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </WidgetCard>

            {/* Refill Tracking */}
            <WidgetCard
                title="Refill Attention Required"
                badge={refills?.count ? { label: `${refills.count} items`, color: 'bg-amber-100 text-amber-700' } : undefined}
            >
                {loading ? <Skeleton /> : !refills?.medications.length ? <Empty message="No refills require attention" /> : (
                    <div className="overflow-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left py-1 font-medium text-slate-500">Participant</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Medication</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Refills</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Last Filled</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {refills.medications.map(m => (
                                    <tr key={m.id} className="hover:bg-slate-50">
                                        <td className="py-1.5 font-medium text-slate-800">{m.participant?.name ?? '—'}</td>
                                        <td className="py-1.5 text-slate-700">{m.drug_name}</td>
                                        <td className={`py-1.5 font-semibold ${m.refills_remaining === 0 ? 'text-red-600' : 'text-slate-600'}`}>
                                            {m.refills_remaining ?? '—'}
                                        </td>
                                        <td className="py-1.5 text-slate-500">
                                            {m.last_filled_date ?? 'Never'}
                                            {m.days_since_filled != null && m.days_since_filled > 28 && (
                                                <span className="text-amber-600 ml-1">({m.days_since_filled}d)</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </WidgetCard>

        </div>
    );
}
