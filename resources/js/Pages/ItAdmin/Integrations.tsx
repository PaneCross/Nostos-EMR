// ─── IT Admin: Integration Monitoring ─────────────────────────────────────────
// Shows the health/status of all integration connectors + a paginated message log.
//
// Layout:
//   - 4 connector status cards (HL7 ADT, Lab Results, Pharmacy NCPDP, Other)
//   - Message log table with filter bar (connector_type, status)
//   - Retry button for failed entries
//
// Data loading strategy:
//   - Summary cards and recent 20 log entries: pre-loaded (Inertia props)
//   - Full paginated log: lazy-loaded via JSON endpoint on filter or pagination
//   - Retry: POST /it-admin/integrations/{id}/retry, optimistic status update
//
// Props: summary, recentLog, connectorTypes
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface ConnectorSummary {
    last_received:  string | null;
    last_status:    string | null;
    failed_count:   number;
}

interface LogEntry {
    id:             number;
    connector_type: string;
    direction:      string;
    status:         string;
    error_message:  string | null;
    retry_count:    number;
    created_at:     string;
    processed_at:   string | null;
}

interface Props extends PageProps {
    summary:        Record<string, ConnectorSummary>;
    recentLog:      LogEntry[];
    connectorTypes: string[];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Color-code status badges. */
function statusColor(status: string): string {
    return {
        pending:   'bg-yellow-100 text-yellow-800',
        processed: 'bg-green-100 text-green-800',
        failed:    'bg-red-100 text-red-800',
        retried:   'bg-blue-100 text-blue-800',
    }[status] ?? 'bg-gray-100 text-gray-700';
}

/** Human-readable connector type labels. */
const CONNECTOR_LABELS: Record<string, string> = {
    hl7_adt:          'HL7 ADT (Hospital)',
    lab_results:      'Lab Results',
    pharmacy_ncpdp:   'Pharmacy (NCPDP)',
    other:            'Other',
};

// ── Component ─────────────────────────────────────────────────────────────────

export default function Integrations({ summary, recentLog, connectorTypes }: Props) {
    const [log, setLog]           = useState<LogEntry[]>(recentLog);
    const [filterType, setFilterType] = useState('');
    const [filterStatus, setFilterStatus] = useState('');
    const [retrying, setRetrying] = useState<number | null>(null);

    // Load filtered log entries from the JSON endpoint
    const loadLog = useCallback(async (type: string, status: string) => {
        const params = new URLSearchParams();
        if (type)   params.set('connector_type', type);
        if (status) params.set('status', status);
        const res = await axios.get(`/it-admin/integrations/log?${params}`);
        setLog(res.data.data ?? []);
    }, []);

    const handleFilterChange = (type: string, status: string) => {
        setFilterType(type);
        setFilterStatus(status);
        loadLog(type, status);
    };

    // Retry a failed entry and optimistically update its status in the table
    const handleRetry = async (entry: LogEntry) => {
        setRetrying(entry.id);
        try {
            await axios.post(`/it-admin/integrations/${entry.id}/retry`);
            setLog(prev =>
                prev.map(e => e.id === entry.id ? { ...e, status: 'retried', retry_count: e.retry_count + 1 } : e)
            );
        } catch {
            alert('Retry failed. Check console for details.');
        } finally {
            setRetrying(null);
        }
    };

    return (
        <AppShell>
            <Head title="IT Admin — Integrations" />

            <div className="max-w-7xl mx-auto px-4 py-8">
                <h1 className="text-2xl font-bold text-gray-900 mb-6">Integration Monitoring</h1>

                {/* ── Connector Status Cards ──────────────────────────────────── */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                    {connectorTypes.map(type => {
                        const s = summary[type];
                        return (
                            <div key={type}
                                className={`rounded-lg border p-4 ${s?.failed_count > 0 ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white'}`}
                                data-testid={`connector-card-${type}`}
                            >
                                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                    {CONNECTOR_LABELS[type] ?? type}
                                </p>
                                <p className="mt-1 text-sm text-gray-700">
                                    {s?.last_status
                                        ? <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${statusColor(s.last_status)}`}>{s.last_status}</span>
                                        : <span className="text-gray-400">No messages</span>
                                    }
                                </p>
                                {s?.failed_count > 0 && (
                                    <p className="mt-1 text-sm font-semibold text-red-600">
                                        {s.failed_count} failed
                                    </p>
                                )}
                                {s?.last_received && (
                                    <p className="mt-1 text-xs text-gray-400">
                                        Last: {new Date(s.last_received).toLocaleString()}
                                    </p>
                                )}
                            </div>
                        );
                    })}
                </div>

                {/* ── Message Log ─────────────────────────────────────────────── */}
                <div className="bg-white rounded-lg border border-gray-200">
                    <div className="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center gap-3">
                        <h2 className="text-base font-semibold text-gray-800 mr-auto">Message Log</h2>

                        {/* Filter by connector type */}
                        <select
                            value={filterType}
                            onChange={e => handleFilterChange(e.target.value, filterStatus)}
                            className="text-sm border border-gray-300 rounded px-2 py-1"
                            data-testid="filter-connector-type"
                        >
                            <option value="">All Connectors</option>
                            {connectorTypes.map(t => (
                                <option key={t} value={t}>{CONNECTOR_LABELS[t] ?? t}</option>
                            ))}
                        </select>

                        {/* Filter by status */}
                        <select
                            value={filterStatus}
                            onChange={e => handleFilterChange(filterType, e.target.value)}
                            className="text-sm border border-gray-300 rounded px-2 py-1"
                            data-testid="filter-status"
                        >
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="processed">Processed</option>
                            <option value="failed">Failed</option>
                            <option value="retried">Retried</option>
                        </select>
                    </div>

                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
                            <tr>
                                <th className="text-left px-4 py-2">ID</th>
                                <th className="text-left px-4 py-2">Connector</th>
                                <th className="text-left px-4 py-2">Status</th>
                                <th className="text-left px-4 py-2">Retries</th>
                                <th className="text-left px-4 py-2">Received</th>
                                <th className="text-left px-4 py-2">Error</th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {log.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-center py-8 text-gray-400">
                                        No messages found.
                                    </td>
                                </tr>
                            )}
                            {log.map(entry => (
                                <tr key={entry.id} className="hover:bg-gray-50">
                                    <td className="px-4 py-2 text-gray-500">#{entry.id}</td>
                                    <td className="px-4 py-2">{CONNECTOR_LABELS[entry.connector_type] ?? entry.connector_type}</td>
                                    <td className="px-4 py-2">
                                        <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${statusColor(entry.status)}`}>
                                            {entry.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2 text-gray-600">{entry.retry_count}</td>
                                    <td className="px-4 py-2 text-gray-500">
                                        {new Date(entry.created_at).toLocaleString()}
                                    </td>
                                    <td className="px-4 py-2 text-red-600 text-xs max-w-xs truncate">
                                        {entry.error_message ?? '—'}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        {entry.status === 'failed' && (
                                            <button
                                                onClick={() => handleRetry(entry)}
                                                disabled={retrying === entry.id}
                                                className="text-xs text-blue-600 hover:text-blue-800 font-medium disabled:opacity-50"
                                                data-testid={`retry-btn-${entry.id}`}
                                            >
                                                {retrying === entry.id ? 'Retrying…' : 'Retry'}
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppShell>
    );
}
