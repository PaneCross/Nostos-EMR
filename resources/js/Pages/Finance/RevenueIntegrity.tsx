// ─── Finance/RevenueIntegrity ─────────────────────────────────────────────────
// Revenue Integrity Dashboard for the Finance department.
//
// Layout:
//   - 6 KPI cards (live-refreshed via GET /billing/revenue-integrity/data):
//       1. Current Month Capitation Total
//       2. Encounter Submission Rate (30-day)
//       3. Rejection Rate (%)
//       4. TrOOP Threshold Alerts (participants at/near $7,400)
//       5. HOS-M Completion Rate (current year)
//       6. Encounter Completeness (% with diagnosis codes)
//   - HCC Gap Analysis panel: participant-level list of HCC coding opportunities
//     with estimated monthly impact per gap
//   - Pending Encounters panel: encounters missing required fields before 837P batching
//   - Refresh button: re-fetches all data from the JSON endpoint
//
// Data loading: initial Inertia render with kpis/gaps/pending; live-refresh via axios.
// Access: finance + it_admin + super_admin
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Kpis {
    capitation_total: number;
    submission_rate_30d: number;
    rejection_rate: number;
    troop_alerts: number;
    hos_m_completion_rate: number;
    encounter_completeness: number;
}

interface HccGap {
    participant_id: number;
    participant_name: string;
    mrn: string;
    icd10_code: string;
    hcc_category: string;
    hcc_label: string;
    estimated_monthly_impact: number;
}

interface PendingEncounter {
    id: number;
    participant_name: string;
    service_date: string;
    service_type: string;
    missing_fields: string[];
}

interface Props extends PageProps {
    kpis: Kpis;
    gaps: HccGap[];
    pending: PendingEncounter[];
}

// ── KPI Card ──────────────────────────────────────────────────────────────────

function KpiCard({
    label,
    value,
    sub,
    alert = false,
    format = 'plain',
}: {
    label: string;
    value: number;
    sub?: string;
    alert?: boolean;
    format?: 'plain' | 'currency' | 'percent';
}) {
    let displayValue: string;
    if (format === 'currency') {
        displayValue = `$${value.toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
    } else if (format === 'percent') {
        displayValue = `${value.toFixed(1)}%`;
    } else {
        displayValue = String(value);
    }

    return (
        <div className={`rounded-xl border p-5 ${alert && value > 0 ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-white'}`}>
            <div className="text-xs font-medium text-gray-500 uppercase tracking-wide">{label}</div>
            <div className={`text-2xl font-bold mt-1 ${alert && value > 0 ? 'text-amber-700' : 'text-gray-900'}`}>
                {displayValue}
            </div>
            {sub && <div className="text-xs text-gray-400 mt-0.5">{sub}</div>}
        </div>
    );
}

// ── Main Component ────────────────────────────────────────────────────────────

export default function RevenueIntegrity({ auth, kpis: initialKpis, gaps: initialGaps, pending: initialPending }: Props) {
    const [kpis, setKpis]         = useState<Kpis>(initialKpis);
    const [gaps, setGaps]         = useState<HccGap[]>(initialGaps);
    const [pending, setPending]   = useState<PendingEncounter[]>(initialPending);
    const [loading, setLoading]   = useState(false);
    const [lastRefresh, setLastRefresh] = useState<Date>(new Date());

    const refresh = useCallback(async () => {
        setLoading(true);
        try {
            const r = await axios.get('/billing/revenue-integrity/data');
            setKpis(r.data.kpis ?? initialKpis);
            setGaps(r.data.gaps ?? []);
            setPending(r.data.pending ?? []);
            setLastRefresh(new Date());
        } catch (e) {
            console.error('Revenue integrity refresh failed', e);
        } finally {
            setLoading(false);
        }
    }, [initialKpis]);

    // Refresh once on mount to get live data
    useEffect(() => { refresh(); }, []);

    const totalGapImpact = gaps.reduce((sum, g) => sum + g.estimated_monthly_impact, 0);

    return (
        <AppShell>
            <Head title="Revenue Integrity" />
            <div className="max-w-7xl mx-auto px-4 py-8">

                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Revenue Integrity Dashboard</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            CMS encounter data quality, HCC risk capture, and capitation reconciliation
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="text-xs text-gray-400">
                            Updated {lastRefresh.toLocaleTimeString()}
                        </span>
                        <button
                            onClick={refresh}
                            disabled={loading}
                            className="px-3 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                        >
                            {loading ? 'Refreshing…' : 'Refresh'}
                        </button>
                    </div>
                </div>

                {/* KPI Cards */}
                <div className="grid grid-cols-3 gap-4 mb-8">
                    <KpiCard
                        label="Current Month Capitation"
                        value={kpis.capitation_total}
                        format="currency"
                        sub="Total across all enrolled participants"
                    />
                    <KpiCard
                        label="Submission Rate (30-day)"
                        value={kpis.submission_rate_30d}
                        format="percent"
                        sub="Encounters submitted to CMS EDS"
                        alert={kpis.submission_rate_30d < 80}
                    />
                    <KpiCard
                        label="Rejection Rate"
                        value={kpis.rejection_rate}
                        format="percent"
                        sub="CMS rejections of submitted encounters"
                        alert={kpis.rejection_rate > 5}
                    />
                    <KpiCard
                        label="TrOOP Threshold Alerts"
                        value={kpis.troop_alerts}
                        sub="Participants at/near $7,400 catastrophic limit"
                        alert={kpis.troop_alerts > 0}
                    />
                    <KpiCard
                        label="HOS-M Completion"
                        value={kpis.hos_m_completion_rate}
                        format="percent"
                        sub="Annual survey completion rate"
                        alert={kpis.hos_m_completion_rate < 60}
                    />
                    <KpiCard
                        label="Encounter Completeness"
                        value={kpis.encounter_completeness}
                        format="percent"
                        sub="Encounters with ICD-10 diagnosis codes"
                        alert={kpis.encounter_completeness < 90}
                    />
                </div>

                {/* HCC Gap Analysis */}
                <div className="mb-8">
                    <div className="flex items-center justify-between mb-3">
                        <div>
                            <h2 className="text-base font-semibold text-gray-800">HCC Coding Opportunities</h2>
                            <p className="text-xs text-gray-500 mt-0.5">
                                Active clinical problems not submitted as encounter diagnoses — each represents potential capitation uplift
                            </p>
                        </div>
                        {gaps.length > 0 && (
                            <div className="text-right">
                                <div className="text-sm font-medium text-gray-700">Est. Monthly Impact</div>
                                <div className="text-lg font-bold text-green-700">
                                    +${totalGapImpact.toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="overflow-x-auto rounded-lg border border-gray-200">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-gray-600 text-xs uppercase">
                                <tr>
                                    <th className="px-3 py-2 text-left">Participant</th>
                                    <th className="px-3 py-2 text-left">ICD-10</th>
                                    <th className="px-3 py-2 text-left">HCC Category</th>
                                    <th className="px-3 py-2 text-right">Est. Monthly Impact</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {gaps.length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="px-3 py-8 text-center text-gray-400">
                                            No HCC coding gaps identified. All active diagnoses are captured in recent encounters.
                                        </td>
                                    </tr>
                                ) : gaps.map((gap, i) => (
                                    <tr key={i} className="hover:bg-gray-50">
                                        <td className="px-3 py-2 font-medium text-gray-900">
                                            {gap.participant_name}
                                            <div className="text-xs text-gray-400">{gap.mrn}</div>
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs text-gray-700">{gap.icd10_code}</td>
                                        <td className="px-3 py-2">
                                            <div className="text-xs font-medium text-gray-800">HCC {gap.hcc_category}</div>
                                            <div className="text-xs text-gray-500">{gap.hcc_label}</div>
                                        </td>
                                        <td className="px-3 py-2 text-right text-green-700 font-medium text-xs">
                                            +${gap.estimated_monthly_impact.toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Pending Encounters */}
                <div>
                    <div className="mb-3">
                        <h2 className="text-base font-semibold text-gray-800">Encounters Missing Required Fields</h2>
                        <p className="text-xs text-gray-500 mt-0.5">
                            These encounters cannot be included in an 837P batch until missing fields are resolved
                        </p>
                    </div>

                    <div className="overflow-x-auto rounded-lg border border-gray-200">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-gray-600 text-xs uppercase">
                                <tr>
                                    <th className="px-3 py-2 text-left">Participant</th>
                                    <th className="px-3 py-2 text-left">Service Date</th>
                                    <th className="px-3 py-2 text-left">Service Type</th>
                                    <th className="px-3 py-2 text-left">Missing Fields</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {pending.length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="px-3 py-8 text-center text-gray-400">
                                            No incomplete encounters. All pending encounters are ready for 837P batching.
                                        </td>
                                    </tr>
                                ) : pending.map(enc => (
                                    <tr key={enc.id} className="hover:bg-gray-50">
                                        <td className="px-3 py-2 font-medium text-gray-900">{enc.participant_name}</td>
                                        <td className="px-3 py-2 text-gray-600">{enc.service_date}</td>
                                        <td className="px-3 py-2 text-gray-600 capitalize">{enc.service_type.replace('_', ' ')}</td>
                                        <td className="px-3 py-2">
                                            <div className="flex flex-wrap gap-1">
                                                {enc.missing_fields.map(f => (
                                                    <span key={f} className="px-1.5 py-0.5 bg-red-100 text-red-700 text-xs rounded">
                                                        {f.replace('_', ' ')}
                                                    </span>
                                                ))}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppShell>
    );
}
