// ─── Finance/Capitation ───────────────────────────────────────────────────────
// Capitation Record Management page with HCC risk adjustment support.
//
// Layout:
//   - KPI row: current month total, avg HCC RAF score, participant count
//   - Table: month_year, participant name, total_capitation, hcc_risk_score,
//     adjustment_type, eligibility_category
//   - Actions: Add record (modal), Bulk Import CSV (file upload modal)
//
// Props: kpis, records, currentMonthYear
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Kpis {
    current_month_total: number;
    participant_count:   number;
    avg_raf_score:       number | null;
}

interface CapRecord {
    id: number;
    month_year: string;
    total_capitation: string;
    hcc_risk_score: string | null;
    adjustment_type: string | null;
    eligibility_category: string | null;
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null;
}

interface Props extends PageProps {
    kpis: Kpis;
    records: CapRecord[];
    currentMonthYear: string;
}

// ── KPI Card ──────────────────────────────────────────────────────────────────

function KpiCard({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-5">
            <div className="text-xs font-medium text-gray-500 uppercase tracking-wide">{label}</div>
            <div className="text-2xl font-bold text-gray-900 mt-1">{value}</div>
            {sub && <div className="text-xs text-gray-400 mt-0.5">{sub}</div>}
        </div>
    );
}

// ── Main Component ────────────────────────────────────────────────────────────

export default function Capitation({ auth, kpis, records, currentMonthYear }: Props) {
    const [showImport, setShowImport]     = useState(false);
    const [importing, setImporting]       = useState(false);
    const [importMsg, setImportMsg]       = useState<string | null>(null);
    const [importFile, setImportFile]     = useState<File | null>(null);

    const handleImport = async () => {
        if (!importFile) return;
        setImporting(true);
        setImportMsg(null);
        const form = new FormData();
        form.append('csv_file', importFile);
        try {
            const r = await axios.post('/billing/capitation/bulk-import', form, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            setImportMsg(`Imported ${r.data.created} records.` + (r.data.errors.length ? ` ${r.data.errors.length} errors.` : ''));
            setShowImport(false);
            router.reload({ only: ['records', 'kpis'] });
        } catch (e: any) {
            setImportMsg(e.response?.data?.error ?? 'Import failed.');
        } finally {
            setImporting(false);
        }
    };

    return (
        <AppShell>
            <Head title="Capitation Records" />
            <div className="max-w-7xl mx-auto px-4 py-8">

                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Capitation Records</h1>
                        <p className="text-sm text-gray-500 mt-1">CMS monthly capitation with HCC risk adjustment</p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={() => setShowImport(true)}
                            className="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50"
                        >
                            Bulk Import CSV
                        </button>
                    </div>
                </div>

                {importMsg && (
                    <div className="mb-4 p-3 rounded-lg bg-blue-50 text-blue-800 text-sm">{importMsg}</div>
                )}

                {/* KPI Row */}
                <div className="grid grid-cols-3 gap-4 mb-8">
                    <KpiCard
                        label={`${currentMonthYear} Capitation`}
                        value={`$${kpis.current_month_total.toLocaleString('en-US', { minimumFractionDigits: 2 })}`}
                    />
                    <KpiCard
                        label="Enrolled Participants"
                        value={String(kpis.participant_count)}
                    />
                    <KpiCard
                        label="Avg HCC RAF Score"
                        value={kpis.avg_raf_score !== null ? kpis.avg_raf_score.toFixed(4) : 'N/A'}
                        sub="Current month average"
                    />
                </div>

                {/* Records Table */}
                <div className="overflow-x-auto rounded-lg border border-gray-200">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-gray-600 text-xs uppercase">
                            <tr>
                                <th className="px-3 py-2 text-left">Month</th>
                                <th className="px-3 py-2 text-left">Participant</th>
                                <th className="px-3 py-2 text-right">Total Capitation</th>
                                <th className="px-3 py-2 text-right">HCC RAF</th>
                                <th className="px-3 py-2 text-left">Adj. Type</th>
                                <th className="px-3 py-2 text-left">Eligibility</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {records.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-3 py-8 text-center text-gray-400">
                                        No capitation records found.
                                    </td>
                                </tr>
                            ) : records.map(rec => (
                                <tr key={rec.id} className="hover:bg-gray-50">
                                    <td className="px-3 py-2 font-medium text-gray-700">{rec.month_year}</td>
                                    <td className="px-3 py-2">
                                        {rec.participant
                                            ? `${rec.participant.first_name} ${rec.participant.last_name}`
                                            : '—'}
                                        {rec.participant && (
                                            <div className="text-xs text-gray-400">{rec.participant.mrn}</div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-right text-gray-700">
                                        ${parseFloat(rec.total_capitation).toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                    </td>
                                    <td className="px-3 py-2 text-right text-gray-600">
                                        {rec.hcc_risk_score ? parseFloat(rec.hcc_risk_score).toFixed(4) : '—'}
                                    </td>
                                    <td className="px-3 py-2 text-gray-500 capitalize">
                                        {rec.adjustment_type?.replace('_', ' ') ?? '—'}
                                    </td>
                                    <td className="px-3 py-2 text-gray-500">
                                        {rec.eligibility_category ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Bulk Import Modal */}
                {showImport && (
                    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                        <div className="bg-white rounded-xl p-6 w-full max-w-lg shadow-lg">
                            <h2 className="text-lg font-bold text-gray-900 mb-2">Bulk Import Capitation CSV</h2>
                            <p className="text-sm text-gray-500 mb-4">
                                CSV columns: <code className="text-xs bg-gray-100 px-1 rounded">participant_id</code> or{' '}
                                <code className="text-xs bg-gray-100 px-1 rounded">medicare_id</code>,{' '}
                                <code className="text-xs bg-gray-100 px-1 rounded">month_year</code>,{' '}
                                <code className="text-xs bg-gray-100 px-1 rounded">total_capitation</code>,{' '}
                                <code className="text-xs bg-gray-100 px-1 rounded">hcc_risk_score</code> (optional),{' '}
                                <code className="text-xs bg-gray-100 px-1 rounded">adjustment_type</code> (optional)
                            </p>
                            <input
                                type="file"
                                accept=".csv,text/csv"
                                onChange={e => setImportFile(e.target.files?.[0] ?? null)}
                                className="block w-full text-sm border rounded-lg px-3 py-2 mb-4"
                            />
                            {importMsg && <p className="text-sm text-red-600 mb-3">{importMsg}</p>}
                            <div className="flex justify-end gap-3">
                                <button
                                    onClick={() => { setShowImport(false); setImportMsg(null); }}
                                    className="px-4 py-2 text-sm text-gray-600 hover:text-gray-900"
                                >
                                    Cancel
                                </button>
                                <button
                                    onClick={handleImport}
                                    disabled={importing || !importFile}
                                    className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {importing ? 'Importing…' : 'Import'}
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
