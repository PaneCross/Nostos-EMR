// ─── Finance/Hpms ─────────────────────────────────────────────────────────────
// HPMS File Submissions page for Finance department.
//
// Layout:
//   - Generate section: type selector + period picker + Generate button
//   - Submissions table: type, period, record_count, status badge, submitted_at, Download
//   - Submit confirmation modal before marking status = 'submitted'
//
// Props: submissions (list), submissionTypes (label map)
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface HpmsRow {
    id: number;
    submission_type: string;
    record_count: number;
    period_start: string;
    period_end: string;
    status: string;
    submitted_at: string | null;
    created_at: string;
}

interface Props extends PageProps {
    submissions: HpmsRow[];
    submissionTypes: Record<string, string>;
}

// ── Status Badge ──────────────────────────────────────────────────────────────

const statusColors: Record<string, string> = {
    draft:     'bg-gray-100 text-gray-700',
    submitted: 'bg-blue-100 text-blue-700',
    confirmed: 'bg-green-100 text-green-700',
};

// ── Main Component ────────────────────────────────────────────────────────────

export default function Hpms({ auth, submissions, submissionTypes }: Props) {
    const [genType, setGenType]       = useState<string>('enrollment');
    const [genMonth, setGenMonth]     = useState<string>(
        new Date().toISOString().slice(0, 7)
    );
    const [genYear, setGenYear]       = useState<number>(new Date().getFullYear());
    const [genQuarter, setGenQuarter] = useState<number>(Math.ceil((new Date().getMonth() + 1) / 3));
    const [generating, setGenerating] = useState(false);
    const [genMsg, setGenMsg]         = useState<string | null>(null);
    const [confirmSubmit, setConfirmSubmit] = useState<HpmsRow | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const generate = async () => {
        setGenerating(true);
        setGenMsg(null);
        const body: Record<string, any> = { type: genType };
        if (genType === 'enrollment' || genType === 'disenrollment') {
            body.month = genMonth;
        } else {
            body.year    = genYear;
            body.quarter = genType === 'quality_data' ? genQuarter : undefined;
        }
        try {
            const r = await axios.post('/billing/hpms/generate', body);
            setGenMsg(`Generated ${r.data.submission_type} file with ${r.data.record_count} records.`);
            router.reload({ only: ['submissions'] });
        } catch (e: any) {
            setGenMsg(e.response?.data?.error ?? 'Generation failed.');
        } finally {
            setGenerating(false);
        }
    };

    const markSubmitted = async (row: HpmsRow) => {
        setSubmitting(true);
        try {
            await axios.patch(`/billing/hpms/${row.id}/submit`);
            setConfirmSubmit(null);
            router.reload({ only: ['submissions'] });
        } catch (e: any) {
            alert(e.response?.data?.message ?? 'Submit failed.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <AppShell>
            <Head title="HPMS Submissions" />
            <div className="max-w-7xl mx-auto px-4 py-8">

                <h1 className="text-2xl font-bold text-gray-900 mb-6">HPMS File Submissions</h1>

                {/* Generate Section */}
                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-8">
                    <h2 className="text-sm font-semibold text-gray-700 mb-4 uppercase tracking-wide">Generate New Submission</h2>
                    <div className="flex flex-wrap gap-3 items-end">
                        <div>
                            <label className="block text-xs text-gray-500 mb-1">Type</label>
                            <select
                                value={genType}
                                onChange={e => setGenType(e.target.value)}
                                className="border rounded-lg px-3 py-2 text-sm min-w-40"
                            >
                                {Object.entries(submissionTypes).map(([k, v]) => (
                                    <option key={k} value={k}>{v}</option>
                                ))}
                            </select>
                        </div>

                        {(genType === 'enrollment' || genType === 'disenrollment') && (
                            <div>
                                <label className="block text-xs text-gray-500 mb-1">Month</label>
                                <input
                                    type="month"
                                    value={genMonth}
                                    onChange={e => setGenMonth(e.target.value)}
                                    className="border rounded-lg px-3 py-2 text-sm"
                                />
                            </div>
                        )}

                        {(genType === 'quality_data' || genType === 'hos_m') && (
                            <>
                                <div>
                                    <label className="block text-xs text-gray-500 mb-1">Year</label>
                                    <input
                                        type="number"
                                        value={genYear}
                                        min={2020}
                                        max={2035}
                                        onChange={e => setGenYear(parseInt(e.target.value))}
                                        className="border rounded-lg px-3 py-2 text-sm w-24"
                                    />
                                </div>
                                {genType === 'quality_data' && (
                                    <div>
                                        <label className="block text-xs text-gray-500 mb-1">Quarter</label>
                                        <select
                                            value={genQuarter}
                                            onChange={e => setGenQuarter(parseInt(e.target.value))}
                                            className="border rounded-lg px-3 py-2 text-sm"
                                        >
                                            <option value={1}>Q1</option>
                                            <option value={2}>Q2</option>
                                            <option value={3}>Q3</option>
                                            <option value={4}>Q4</option>
                                        </select>
                                    </div>
                                )}
                            </>
                        )}

                        <button
                            onClick={generate}
                            disabled={generating}
                            className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
                        >
                            {generating ? 'Generating…' : 'Generate File'}
                        </button>
                    </div>
                    {genMsg && (
                        <p className="text-sm text-green-700 mt-3">{genMsg}</p>
                    )}
                </div>

                {/* Submissions Table */}
                <div className="overflow-x-auto rounded-lg border border-gray-200">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-gray-600 text-xs uppercase">
                            <tr>
                                <th className="px-3 py-2 text-left">Type</th>
                                <th className="px-3 py-2 text-left">Period</th>
                                <th className="px-3 py-2 text-right">Records</th>
                                <th className="px-3 py-2 text-left">Status</th>
                                <th className="px-3 py-2 text-left">Submitted At</th>
                                <th className="px-3 py-2 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {submissions.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-3 py-8 text-center text-gray-400">
                                        No submissions yet. Generate a file above to get started.
                                    </td>
                                </tr>
                            ) : submissions.map(row => (
                                <tr key={row.id} className="hover:bg-gray-50">
                                    <td className="px-3 py-2 font-medium text-gray-800">
                                        {submissionTypes[row.submission_type] ?? row.submission_type}
                                    </td>
                                    <td className="px-3 py-2 text-gray-600 text-xs">
                                        {row.period_start} — {row.period_end}
                                    </td>
                                    <td className="px-3 py-2 text-right text-gray-700">{row.record_count}</td>
                                    <td className="px-3 py-2">
                                        <span className={`px-2 py-0.5 rounded text-xs font-medium ${statusColors[row.status] ?? 'bg-gray-100 text-gray-700'}`}>
                                            {row.status}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-xs text-gray-500">
                                        {row.submitted_at ? new Date(row.submitted_at).toLocaleDateString() : '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="flex gap-2">
                                            <a
                                                href={`/billing/hpms/${row.id}/download`}
                                                className="text-xs text-indigo-600 hover:underline"
                                            >
                                                Download
                                            </a>
                                            {row.status === 'draft' && (
                                                <button
                                                    onClick={() => setConfirmSubmit(row)}
                                                    className="text-xs text-green-600 hover:underline"
                                                >
                                                    Mark Submitted
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Submit Confirmation Modal */}
                {confirmSubmit && (
                    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                        <div className="bg-white rounded-xl p-6 w-full max-w-md shadow-lg">
                            <h2 className="text-lg font-bold text-gray-900 mb-2">Confirm Submission</h2>
                            <p className="text-sm text-gray-600 mb-4">
                                Mark this {submissionTypes[confirmSubmit.submission_type]} file as submitted to CMS HPMS?
                                This action cannot be undone.
                            </p>
                            <div className="flex justify-end gap-3">
                                <button
                                    onClick={() => setConfirmSubmit(null)}
                                    className="px-4 py-2 text-sm text-gray-600 hover:text-gray-900"
                                >
                                    Cancel
                                </button>
                                <button
                                    onClick={() => markSubmitted(confirmSubmit)}
                                    disabled={submitting}
                                    className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50"
                                >
                                    {submitting ? 'Submitting…' : 'Confirm'}
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
