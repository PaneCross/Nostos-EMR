// ─── Finance/Encounters ───────────────────────────────────────────────────────
// Encounter Submission Queue — billing-side view of EncounterLog records.
//
// Layout:
//   - Filter bar: participant search, service type, submission status, date range
//   - Table: participant, service_date, service_type, procedure_code, diagnosis count,
//     charge amount, submission status badge
//   - Bulk select: checkboxes + "Create 837P Batch" button
//   - Empty state: descriptive guidance for Finance staff
//
// Data loading: Inertia prop-less page — encounters loaded via axios on mount.
// Batch creation: POST /billing/encounters/batch with selected encounter IDs.
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect, useCallback } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Participant {
    id: number;
    mrn: string;
    first_name: string;
    last_name: string;
}

interface EncounterRow {
    id: number;
    service_date: string;
    service_type: string;
    procedure_code: string | null;
    diagnosis_codes: string[] | null;
    charge_amount: string;
    submission_status: string;
    participant: Participant | null;
    billing_provider_npi: string | null;
}

interface Filters {
    participant_id: string;
    service_type: string;
    submission_status: string;
    date_from: string;
    date_to: string;
}

// ── Status Badge ──────────────────────────────────────────────────────────────

const statusColors: Record<string, string> = {
    pending:   'bg-yellow-100 text-yellow-800',
    submitted: 'bg-blue-100 text-blue-800',
    accepted:  'bg-green-100 text-green-800',
    rejected:  'bg-red-100 text-red-800',
    void:      'bg-gray-100 text-gray-600',
};

function StatusBadge({ status }: { status: string }) {
    return (
        <span className={`px-2 py-0.5 rounded text-xs font-medium ${statusColors[status] ?? 'bg-gray-100 text-gray-700'}`}>
            {status.charAt(0).toUpperCase() + status.slice(1)}
        </span>
    );
}

// ── Main Component ────────────────────────────────────────────────────────────

export default function Encounters({ auth }: PageProps) {
    const [encounters, setEncounters] = useState<EncounterRow[]>([]);
    const [loading, setLoading]       = useState(true);
    const [selected, setSelected]     = useState<Set<number>>(new Set());
    const [batching, setBatching]     = useState(false);
    const [batchMsg, setBatchMsg]     = useState<string | null>(null);
    const [filters, setFilters]       = useState<Filters>({
        participant_id: '',
        service_type:   '',
        submission_status: '',
        date_from: '',
        date_to: '',
    });

    const load = useCallback(() => {
        setLoading(true);
        const params = Object.fromEntries(
            Object.entries(filters).filter(([, v]) => v !== '')
        );
        axios.get('/billing/encounters', { params })
            .then(r => setEncounters(r.data.data ?? []))
            .catch(console.error)
            .finally(() => setLoading(false));
    }, [filters]);

    useEffect(() => { load(); }, [load]);

    const toggleSelect = (id: number) => {
        setSelected(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const toggleAll = () => {
        if (selected.size === encounters.length) {
            setSelected(new Set());
        } else {
            setSelected(new Set(encounters.map(e => e.id)));
        }
    };

    const createBatch = async () => {
        if (selected.size === 0) return;
        setBatching(true);
        setBatchMsg(null);
        try {
            const r = await axios.post('/billing/encounters/batch', {
                encounter_ids: Array.from(selected),
            });
            setBatchMsg(`Batch created: ${r.data.file_name} (${r.data.record_count} encounters)`);
            setSelected(new Set());
            load();
        } catch (e: any) {
            setBatchMsg(e.response?.data?.error ?? 'Batch creation failed.');
        } finally {
            setBatching(false);
        }
    };

    return (
        <AppShell>
            <Head title="Encounter Submission Queue" />
            <div className="max-w-7xl mx-auto px-4 py-8">

                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Encounter Submission Queue</h1>
                        <p className="text-sm text-gray-500 mt-1">
                            837P billing encounters for CMS Encounter Data submission
                        </p>
                    </div>
                    {selected.size > 0 && (
                        <button
                            onClick={createBatch}
                            disabled={batching}
                            className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
                        >
                            {batching ? 'Creating Batch…' : `Create 837P Batch (${selected.size})`}
                        </button>
                    )}
                </div>

                {batchMsg && (
                    <div className="mb-4 p-3 rounded-lg bg-blue-50 text-blue-800 text-sm">{batchMsg}</div>
                )}

                {/* Filter Bar */}
                <div className="flex flex-wrap gap-2 mb-4">
                    <input
                        type="text"
                        placeholder="Participant ID"
                        value={filters.participant_id}
                        onChange={e => setFilters(f => ({ ...f, participant_id: e.target.value }))}
                        className="border rounded px-3 py-1.5 text-sm w-36"
                    />
                    <select
                        value={filters.service_type}
                        onChange={e => setFilters(f => ({ ...f, service_type: e.target.value }))}
                        className="border rounded px-3 py-1.5 text-sm"
                    >
                        <option value="">All Service Types</option>
                        <option value="primary_care">Primary Care</option>
                        <option value="specialist">Specialist</option>
                        <option value="therapy">Therapy</option>
                        <option value="home_care">Home Care</option>
                        <option value="day_center">Day Center</option>
                    </select>
                    <select
                        value={filters.submission_status}
                        onChange={e => setFilters(f => ({ ...f, submission_status: e.target.value }))}
                        className="border rounded px-3 py-1.5 text-sm"
                    >
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="submitted">Submitted</option>
                        <option value="accepted">Accepted</option>
                        <option value="rejected">Rejected</option>
                    </select>
                    <input
                        type="date"
                        value={filters.date_from}
                        onChange={e => setFilters(f => ({ ...f, date_from: e.target.value }))}
                        className="border rounded px-3 py-1.5 text-sm"
                    />
                    <input
                        type="date"
                        value={filters.date_to}
                        onChange={e => setFilters(f => ({ ...f, date_to: e.target.value }))}
                        className="border rounded px-3 py-1.5 text-sm"
                    />
                    <button
                        onClick={load}
                        className="px-3 py-1.5 bg-gray-100 rounded text-sm text-gray-700 hover:bg-gray-200"
                    >
                        Filter
                    </button>
                </div>

                {/* Table */}
                {loading ? (
                    <div className="text-center py-12 text-gray-400">Loading encounters…</div>
                ) : encounters.length === 0 ? (
                    <div className="text-center py-12 text-gray-400">
                        <p className="text-base font-medium text-gray-500">No encounters found.</p>
                        <p className="text-sm text-gray-400 mt-1">
                            Encounters are created automatically from clinical activity and integration events.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-gray-200">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-gray-600 text-xs uppercase">
                                <tr>
                                    <th className="px-3 py-2 text-left">
                                        <input
                                            type="checkbox"
                                            checked={selected.size === encounters.length}
                                            onChange={toggleAll}
                                        />
                                    </th>
                                    <th className="px-3 py-2 text-left">Participant</th>
                                    <th className="px-3 py-2 text-left">Service Date</th>
                                    <th className="px-3 py-2 text-left">Type</th>
                                    <th className="px-3 py-2 text-left">CPT</th>
                                    <th className="px-3 py-2 text-left">Dx Codes</th>
                                    <th className="px-3 py-2 text-right">Charge</th>
                                    <th className="px-3 py-2 text-left">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {encounters.map(enc => (
                                    <tr key={enc.id} className="hover:bg-gray-50">
                                        <td className="px-3 py-2">
                                            <input
                                                type="checkbox"
                                                checked={selected.has(enc.id)}
                                                onChange={() => toggleSelect(enc.id)}
                                            />
                                        </td>
                                        <td className="px-3 py-2 font-medium text-gray-900">
                                            {enc.participant
                                                ? `${enc.participant.first_name} ${enc.participant.last_name}`
                                                : `ID ${enc.id}`}
                                            {enc.participant && (
                                                <div className="text-xs text-gray-400">{enc.participant.mrn}</div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-gray-600">{enc.service_date}</td>
                                        <td className="px-3 py-2 text-gray-600 capitalize">{enc.service_type.replace('_', ' ')}</td>
                                        <td className="px-3 py-2 text-gray-500 font-mono text-xs">{enc.procedure_code ?? '—'}</td>
                                        <td className="px-3 py-2">
                                            {enc.diagnosis_codes && enc.diagnosis_codes.length > 0 ? (
                                                <span className="text-xs text-green-700 font-medium">
                                                    {enc.diagnosis_codes.length} code{enc.diagnosis_codes.length !== 1 ? 's' : ''}
                                                </span>
                                            ) : (
                                                <span className="text-xs text-red-500">Missing</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right text-gray-700">
                                            ${parseFloat(enc.charge_amount).toFixed(2)}
                                        </td>
                                        <td className="px-3 py-2">
                                            <StatusBadge status={enc.submission_status} />
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
