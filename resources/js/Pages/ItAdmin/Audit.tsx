// ─── IT Admin: Audit Log Viewer ───────────────────────────────────────────────
// HIPAA-compliant audit log viewer for IT Admins.
//
// Layout:
//   - Filter bar: action keyword, user_id, resource_type, date range
//   - Paginated audit log table (100 per page)
//   - CSV export button
//   - Entry count displayed in header
//
// Data loading strategy:
//   - initialCount pre-loaded (Inertia props) for header display
//   - Audit log: lazy-loaded via JSON endpoint on mount + filter change
//   - CSV export: direct download GET /it-admin/audit/export
//
// Props: initialCount
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface AuditLogEntry {
    id:            number;
    action:        string;
    resource_type: string | null;
    resource_id:   number | null;
    user:          { id: number; first_name: string; last_name: string } | null;
    ip_address:    string | null;
    created_at:    string;
}

interface Pagination {
    data:          AuditLogEntry[];
    current_page:  number;
    last_page:     number;
    total:         number;
    per_page:      number;
}

interface Props extends PageProps {
    initialCount: number;
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function Audit({ initialCount }: Props) {
    const [entries, setEntries]       = useState<AuditLogEntry[]>([]);
    const [pagination, setPagination] = useState<Omit<Pagination, 'data'> | null>(null);
    const [loading, setLoading]       = useState(false);
    const [page, setPage]             = useState(1);

    // Filter state
    const [filterAction, setFilterAction]     = useState('');
    const [filterResType, setFilterResType]   = useState('');
    const [filterDateFrom, setFilterDateFrom] = useState('');
    const [filterDateTo, setFilterDateTo]     = useState('');

    const loadLog = useCallback(async (currentPage: number) => {
        setLoading(true);
        const params = new URLSearchParams({ page: String(currentPage) });
        if (filterAction)   params.set('action', filterAction);
        if (filterResType)  params.set('resource_type', filterResType);
        if (filterDateFrom) params.set('date_from', filterDateFrom);
        if (filterDateTo)   params.set('date_to', filterDateTo);

        try {
            const res = await axios.get<Pagination>(`/it-admin/audit/log?${params}`);
            const { data, ...meta } = res.data;
            setEntries(data);
            setPagination(meta);
        } finally {
            setLoading(false);
        }
    }, [filterAction, filterResType, filterDateFrom, filterDateTo]);

    // Load on mount and whenever filters change
    useEffect(() => {
        setPage(1);
        loadLog(1);
    }, [filterAction, filterResType, filterDateFrom, filterDateTo]);

    // Load when page changes
    useEffect(() => {
        loadLog(page);
    }, [page]);

    const handleExport = () => {
        window.location.href = '/it-admin/audit/export';
    };

    return (
        <AppShell>
            <Head title="IT Admin — Audit Log" />

            <div className="max-w-7xl mx-auto px-4 py-8">
                {/* ── Header ─────────────────────────────────────────────────── */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Audit Log</h1>
                        <p className="text-sm text-gray-500 mt-0.5">{initialCount.toLocaleString()} total entries</p>
                    </div>
                    <button
                        onClick={handleExport}
                        className="bg-gray-800 text-white text-sm px-4 py-2 rounded hover:bg-gray-700"
                        data-testid="export-csv-btn"
                    >
                        Export CSV
                    </button>
                </div>

                {/* ── Filter Bar ─────────────────────────────────────────────── */}
                <div className="flex flex-wrap gap-3 mb-6 bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <input
                        type="text"
                        placeholder="Filter by action…"
                        value={filterAction}
                        onChange={e => setFilterAction(e.target.value)}
                        className="border border-gray-300 rounded px-3 py-1.5 text-sm w-48"
                        data-testid="filter-action"
                    />
                    <input
                        type="text"
                        placeholder="Resource type…"
                        value={filterResType}
                        onChange={e => setFilterResType(e.target.value)}
                        className="border border-gray-300 rounded px-3 py-1.5 text-sm w-40"
                        data-testid="filter-resource-type"
                    />
                    <input
                        type="date"
                        value={filterDateFrom}
                        onChange={e => setFilterDateFrom(e.target.value)}
                        className="border border-gray-300 rounded px-2 py-1.5 text-sm"
                        data-testid="filter-date-from"
                    />
                    <span className="self-center text-gray-400 text-sm">to</span>
                    <input
                        type="date"
                        value={filterDateTo}
                        onChange={e => setFilterDateTo(e.target.value)}
                        className="border border-gray-300 rounded px-2 py-1.5 text-sm"
                        data-testid="filter-date-to"
                    />
                    {pagination && (
                        <span className="ml-auto self-center text-sm text-gray-500">
                            {pagination.total.toLocaleString()} results
                        </span>
                    )}
                </div>

                {/* ── Audit Log Table ─────────────────────────────────────────── */}
                <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    {loading && (
                        <div className="py-8 text-center text-gray-400 text-sm">Loading…</div>
                    )}
                    {!loading && (
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
                                <tr>
                                    <th className="text-left px-4 py-3">Action</th>
                                    <th className="text-left px-4 py-3">Resource</th>
                                    <th className="text-left px-4 py-3">User</th>
                                    <th className="text-left px-4 py-3">IP</th>
                                    <th className="text-left px-4 py-3">Time</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {entries.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-center py-8 text-gray-400">
                                            No entries found.
                                        </td>
                                    </tr>
                                )}
                                {entries.map(entry => (
                                    <tr key={entry.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-2 font-mono text-xs text-gray-700">
                                            {entry.action}
                                        </td>
                                        <td className="px-4 py-2 text-gray-600">
                                            {entry.resource_type
                                                ? `${entry.resource_type} #${entry.resource_id}`
                                                : '—'
                                            }
                                        </td>
                                        <td className="px-4 py-2 text-gray-600">
                                            {entry.user
                                                ? `${entry.user.first_name} ${entry.user.last_name}`
                                                : 'System'
                                            }
                                        </td>
                                        <td className="px-4 py-2 text-gray-400 text-xs">
                                            {entry.ip_address ?? '—'}
                                        </td>
                                        <td className="px-4 py-2 text-gray-500 text-xs">
                                            {new Date(entry.created_at).toLocaleString()}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                {/* ── Pagination ─────────────────────────────────────────────── */}
                {pagination && pagination.last_page > 1 && (
                    <div className="flex items-center justify-between mt-4 text-sm">
                        <button
                            onClick={() => setPage(p => Math.max(1, p - 1))}
                            disabled={page <= 1}
                            className="px-3 py-1.5 border rounded disabled:opacity-40 hover:bg-gray-50"
                        >
                            Previous
                        </button>
                        <span className="text-gray-500">
                            Page {page} of {pagination.last_page}
                        </span>
                        <button
                            onClick={() => setPage(p => Math.min(pagination.last_page, p + 1))}
                            disabled={page >= pagination.last_page}
                            className="px-3 py-1.5 border rounded disabled:opacity-40 hover:bg-gray-50"
                        >
                            Next
                        </button>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
