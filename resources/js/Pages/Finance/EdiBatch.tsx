// ─── Finance/EdiBatch ─────────────────────────────────────────────────────────
// EDI Batch File Management page for the Finance department.
//
// Layout:
//   - Header: "EDI Batch Files"
//   - Table: batch ID, type badge, record count, total charge, status badge, submitted_at
//   - Row actions: Download X12 file, Upload 277CA acknowledgement (modal)
//   - 277CA modal: paste-area for raw X12 content + submit button
//
// Status badge colors:
//   draft=gray, submitted=blue, acknowledged=green, partially_accepted=yellow, rejected=red
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface EdiBatchRow {
    id: number;
    batch_type: string;
    file_name: string | null;
    record_count: number;
    total_charge_amount: string;
    status: string;
    submitted_at: string | null;
    cms_response_code: string | null;
    created_at: string;
    created_by: { id: number; first_name: string; last_name: string } | null;
}

// ── Status / Type Helpers ─────────────────────────────────────────────────────

const statusColors: Record<string, string> = {
    draft:              'bg-gray-100 text-gray-700',
    submitted:          'bg-blue-100 text-blue-700',
    acknowledged:       'bg-green-100 text-green-700',
    partially_accepted: 'bg-yellow-100 text-yellow-700',
    rejected:           'bg-red-100 text-red-700',
};

const typeColors: Record<string, string> = {
    edr: 'bg-indigo-100 text-indigo-700',
    crr: 'bg-purple-100 text-purple-700',
    pde: 'bg-teal-100 text-teal-700',
};

const typeLabels: Record<string, string> = {
    edr: 'EDR',
    crr: 'CRR',
    pde: 'PDE',
};

// ── Main Component ────────────────────────────────────────────────────────────

export default function EdiBatch({ auth }: PageProps) {
    const [batches, setBatches]       = useState<EdiBatchRow[]>([]);
    const [loading, setLoading]       = useState(true);
    const [ackModal, setAckModal]     = useState<number | null>(null); // batch ID
    const [ackContent, setAckContent] = useState('');
    const [ackLoading, setAckLoading] = useState(false);
    const [ackMsg, setAckMsg]         = useState<string | null>(null);

    const load = () => {
        setLoading(true);
        axios.get('/billing/batches')
            .then(r => setBatches(r.data.data ?? []))
            .catch(console.error)
            .finally(() => setLoading(false));
    };

    useEffect(() => { load(); }, []);

    const downloadBatch = (batch: EdiBatchRow) => {
        window.open(`/billing/batches/${batch.id}/download`, '_blank');
    };

    const submitAck = async () => {
        if (!ackModal || !ackContent.trim()) return;
        setAckLoading(true);
        setAckMsg(null);
        try {
            const r = await axios.post(`/billing/batches/${ackModal}/acknowledge`, {
                edi_content: ackContent,
            });
            setAckMsg(`Batch updated to: ${r.data.status}`);
            setAckModal(null);
            setAckContent('');
            load();
        } catch (e: any) {
            setAckMsg(e.response?.data?.error ?? 'Acknowledgement processing failed.');
        } finally {
            setAckLoading(false);
        }
    };

    return (
        <AppShell>
            <Head title="EDI Batch Files" />
            <div className="max-w-7xl mx-auto px-4 py-8">

                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">EDI Batch Files</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            X12 5010A1 837P batches for CMS Encounter Data System submission
                        </p>
                    </div>
                    <button onClick={load} className="text-sm text-indigo-600 hover:underline">Refresh</button>
                </div>

                {ackMsg && (
                    <div className="mb-4 p-3 rounded-lg bg-blue-50 text-blue-800 text-sm">{ackMsg}</div>
                )}

                {loading ? (
                    <div className="text-center py-12 text-gray-400">Loading batches…</div>
                ) : batches.length === 0 ? (
                    <div className="text-center py-12 text-gray-400">
                        <p className="font-medium text-gray-500">No EDI batches yet.</p>
                        <p className="text-sm text-gray-400 mt-1">
                            Create batches from the Encounter Submission Queue by selecting pending encounters.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-gray-200">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-gray-600 text-xs uppercase">
                                <tr>
                                    <th className="px-3 py-2 text-left">Batch ID</th>
                                    <th className="px-3 py-2 text-left">Type</th>
                                    <th className="px-3 py-2 text-right">Records</th>
                                    <th className="px-3 py-2 text-right">Total Charge</th>
                                    <th className="px-3 py-2 text-left">Status</th>
                                    <th className="px-3 py-2 text-left">CMS Response</th>
                                    <th className="px-3 py-2 text-left">Created</th>
                                    <th className="px-3 py-2 text-left">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {batches.map(batch => (
                                    <tr key={batch.id} className="hover:bg-gray-50">
                                        <td className="px-3 py-2 font-mono text-xs text-gray-700">
                                            #{batch.id}<br />
                                            <span className="text-gray-400">{batch.file_name ?? '—'}</span>
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className={`px-2 py-0.5 rounded text-xs font-medium ${typeColors[batch.batch_type] ?? 'bg-gray-100 text-gray-700'}`}>
                                                {typeLabels[batch.batch_type] ?? batch.batch_type.toUpperCase()}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-right text-gray-700">{batch.record_count}</td>
                                        <td className="px-3 py-2 text-right text-gray-700">
                                            ${parseFloat(batch.total_charge_amount).toLocaleString('en-US', { minimumFractionDigits: 2 })}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className={`px-2 py-0.5 rounded text-xs font-medium ${statusColors[batch.status] ?? 'bg-gray-100 text-gray-700'}`}>
                                                {batch.status.replace('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 text-xs text-gray-500">{batch.cms_response_code ?? '—'}</td>
                                        <td className="px-3 py-2 text-xs text-gray-500">
                                            {new Date(batch.created_at).toLocaleDateString()}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex gap-2">
                                                <button
                                                    onClick={() => downloadBatch(batch)}
                                                    className="text-xs text-indigo-600 hover:underline"
                                                >
                                                    Download X12
                                                </button>
                                                {batch.status === 'submitted' && (
                                                    <button
                                                        onClick={() => { setAckModal(batch.id); setAckMsg(null); }}
                                                        className="text-xs text-green-600 hover:underline"
                                                    >
                                                        Upload 277CA
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* 277CA Acknowledgement Modal */}
                {ackModal !== null && (
                    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                        <div className="bg-white rounded-xl p-6 w-full max-w-xl shadow-lg">
                            <h2 className="text-lg font-bold text-gray-900 mb-2">Upload 277CA Response</h2>
                            <p className="text-sm text-gray-500 mb-4">
                                Paste the raw X12 277CA EDI file content from CMS below.
                            </p>
                            <textarea
                                rows={10}
                                value={ackContent}
                                onChange={e => setAckContent(e.target.value)}
                                className="w-full border rounded-lg px-3 py-2 text-xs font-mono"
                                placeholder="ISA*00*          *00*          *ZZ..."
                            />
                            {ackMsg && (
                                <p className="text-sm text-red-600 mt-2">{ackMsg}</p>
                            )}
                            <div className="flex justify-end gap-3 mt-4">
                                <button
                                    onClick={() => { setAckModal(null); setAckContent(''); }}
                                    className="px-4 py-2 text-sm text-gray-600 hover:text-gray-900"
                                >
                                    Cancel
                                </button>
                                <button
                                    onClick={submitAck}
                                    disabled={ackLoading || !ackContent.trim()}
                                    className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50"
                                >
                                    {ackLoading ? 'Processing…' : 'Process Acknowledgement'}
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
