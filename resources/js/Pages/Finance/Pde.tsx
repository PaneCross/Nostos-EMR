// ─── Finance/Pde ──────────────────────────────────────────────────────────────
// Part D Prescription Drug Event (PDE) Records page.
//
// Layout:
//   - TrOOP alert banner if any participants are near/at catastrophic threshold
//   - Tabs: PDE Records | TrOOP Summary
//   - PDE Records table: participant, drug, NDC, dispense_date, days_supply,
//     total_cost, troop_amount, status badge
//   - TrOOP tab: participant-level TrOOP accumulation summary
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface PdeRow {
    id: number;
    drug_name: string;
    ndc_code: string | null;
    dispense_date: string;
    days_supply: number;
    ingredient_cost: string;
    dispensing_fee: string;
    troop_amount: string;
    submission_status: string;
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null;
}

interface TroopRow {
    participant_id: number;
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null;
    ytd_troop: number;
    record_count: number;
    near_threshold: boolean;
    at_threshold: boolean;
}

// ── Status Badge ──────────────────────────────────────────────────────────────

const statusColors: Record<string, string> = {
    pending:  'bg-yellow-100 text-yellow-800',
    submitted:'bg-blue-100 text-blue-800',
    accepted: 'bg-green-100 text-green-800',
    rejected: 'bg-red-100 text-red-800',
};

// ── Main Component ────────────────────────────────────────────────────────────

export default function Pde({ auth }: PageProps) {
    const [tab, setTab]           = useState<'pde' | 'troop'>('pde');
    const [pdeRows, setPdeRows]   = useState<PdeRow[]>([]);
    const [troopRows, setTroopRows] = useState<TroopRow[]>([]);
    const [threshold, setThreshold] = useState(7400);
    const [loading, setLoading]   = useState(true);

    useEffect(() => {
        if (tab === 'pde') {
            setLoading(true);
            axios.get('/billing/pde')
                .then(r => setPdeRows(r.data.data ?? []))
                .finally(() => setLoading(false));
        } else {
            setLoading(true);
            axios.get('/billing/pde/troop')
                .then(r => {
                    setTroopRows(r.data.summary ?? []);
                    setThreshold(r.data.threshold ?? 7400);
                })
                .finally(() => setLoading(false));
        }
    }, [tab]);

    const atOrNearThreshold = troopRows.filter(r => r.at_threshold || r.near_threshold).length;

    return (
        <AppShell>
            <Head title="Part D PDE Records" />
            <div className="max-w-7xl mx-auto px-4 py-8">

                {/* TrOOP Alert Banner */}
                {atOrNearThreshold > 0 && (
                    <div className="mb-6 p-4 rounded-lg bg-amber-50 border border-amber-200 flex items-start gap-3">
                        <span className="text-amber-600 text-lg mt-0.5">!</span>
                        <div>
                            <p className="text-sm font-medium text-amber-800">
                                {atOrNearThreshold} participant{atOrNearThreshold !== 1 ? 's' : ''} at or near the Part D TrOOP catastrophic threshold (${threshold.toLocaleString()})
                            </p>
                            <p className="text-xs text-amber-600 mt-0.5">
                                Switch to the TrOOP Summary tab to review individual accumulations.
                            </p>
                        </div>
                    </div>
                )}

                <h1 className="text-2xl font-bold text-gray-900 mb-6">Part D PDE Records</h1>

                {/* Tabs */}
                <div className="flex gap-1 mb-6 border-b border-gray-200">
                    {(['pde', 'troop'] as const).map(t => (
                        <button
                            key={t}
                            onClick={() => setTab(t)}
                            className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px ${
                                tab === t
                                    ? 'border-indigo-600 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700'
                            }`}
                        >
                            {t === 'pde' ? 'PDE Records' : 'TrOOP Summary'}
                        </button>
                    ))}
                </div>

                {loading ? (
                    <div className="text-center py-12 text-gray-400">Loading…</div>
                ) : tab === 'pde' ? (
                    <div className="overflow-x-auto rounded-lg border border-gray-200">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-gray-600 text-xs uppercase">
                                <tr>
                                    <th className="px-3 py-2 text-left">Participant</th>
                                    <th className="px-3 py-2 text-left">Drug</th>
                                    <th className="px-3 py-2 text-left">NDC</th>
                                    <th className="px-3 py-2 text-left">Dispense Date</th>
                                    <th className="px-3 py-2 text-right">Days Supply</th>
                                    <th className="px-3 py-2 text-right">Total Cost</th>
                                    <th className="px-3 py-2 text-right">TrOOP</th>
                                    <th className="px-3 py-2 text-left">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {pdeRows.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="px-3 py-8 text-center text-gray-400">
                                            No PDE records found.
                                        </td>
                                    </tr>
                                ) : pdeRows.map(row => (
                                    <tr key={row.id} className="hover:bg-gray-50">
                                        <td className="px-3 py-2">
                                            {row.participant
                                                ? `${row.participant.first_name} ${row.participant.last_name}`
                                                : '—'}
                                        </td>
                                        <td className="px-3 py-2 font-medium text-gray-800">{row.drug_name}</td>
                                        <td className="px-3 py-2 font-mono text-xs text-gray-500">{row.ndc_code ?? '—'}</td>
                                        <td className="px-3 py-2 text-gray-600">{row.dispense_date}</td>
                                        <td className="px-3 py-2 text-right text-gray-600">{row.days_supply}</td>
                                        <td className="px-3 py-2 text-right text-gray-700">
                                            ${(parseFloat(row.ingredient_cost) + parseFloat(row.dispensing_fee)).toFixed(2)}
                                        </td>
                                        <td className="px-3 py-2 text-right text-gray-700">
                                            ${parseFloat(row.troop_amount).toFixed(2)}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className={`px-2 py-0.5 rounded text-xs font-medium ${statusColors[row.submission_status] ?? 'bg-gray-100 text-gray-700'}`}>
                                                {row.submission_status}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    /* TrOOP Tab */
                    <div className="overflow-x-auto rounded-lg border border-gray-200">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-gray-600 text-xs uppercase">
                                <tr>
                                    <th className="px-3 py-2 text-left">Participant</th>
                                    <th className="px-3 py-2 text-right">YTD TrOOP</th>
                                    <th className="px-3 py-2 text-right">PDEs</th>
                                    <th className="px-3 py-2 text-left">Threshold Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {troopRows.length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="px-3 py-8 text-center text-gray-400">
                                            No TrOOP data found for current year.
                                        </td>
                                    </tr>
                                ) : troopRows.map(row => (
                                    <tr key={row.participant_id} className={`${row.at_threshold ? 'bg-red-50' : row.near_threshold ? 'bg-amber-50' : 'hover:bg-gray-50'}`}>
                                        <td className="px-3 py-2 font-medium text-gray-900">
                                            {row.participant
                                                ? `${row.participant.first_name} ${row.participant.last_name}`
                                                : `ID ${row.participant_id}`}
                                        </td>
                                        <td className="px-3 py-2 text-right font-medium">
                                            ${row.ytd_troop.toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                        </td>
                                        <td className="px-3 py-2 text-right text-gray-600">{row.record_count}</td>
                                        <td className="px-3 py-2">
                                            {row.at_threshold ? (
                                                <span className="text-xs font-medium text-red-700 bg-red-100 px-2 py-0.5 rounded">At Catastrophic Threshold</span>
                                            ) : row.near_threshold ? (
                                                <span className="text-xs font-medium text-amber-700 bg-amber-100 px-2 py-0.5 rounded">Near Threshold (80%+)</span>
                                            ) : (
                                                <span className="text-xs text-gray-400">Below threshold</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
