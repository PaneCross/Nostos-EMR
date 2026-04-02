// ─── ItAdmin/BreakGlass ───────────────────────────────────────────────────────
// IT Admin break-the-glass emergency access log.
// Lists all emr_break_glass_events for the tenant, most recent first.
// Supervisors acknowledge they have reviewed each event.
// Unacknowledged events older than 24 hours are highlighted in amber.
//
// Data loaded server-side via BreakGlassController::adminIndex() (Inertia).
// Acknowledge action: POST /it-admin/break-glass/{id}/acknowledge (axios).
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';
import {
    ShieldExclamationIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import axios from 'axios';

interface BreakGlassUser {
    id: number;
    name: string;
    department: string;
}

interface BreakGlassParticipant {
    id: number;
    name: string;
    mrn: string;
}

interface BreakGlassEventItem {
    id: number;
    user: BreakGlassUser | null;
    participant: BreakGlassParticipant | null;
    justification: string;
    access_granted_at: string;
    access_expires_at: string;
    is_active: boolean;
    is_acknowledged: boolean;
    acknowledged_by: string | null;
    acknowledged_at: string | null;
    ip_address: string | null;
    created_at: string;
}

interface Props {
    events: BreakGlassEventItem[];
    unacknowledged_count: number;
}

export default function BreakGlass() {
    const { events: initialEvents, unacknowledged_count } = usePage<Props>().props;
    const [events, setEvents] = useState<BreakGlassEventItem[]>(initialEvents);
    const [acknowledging, setAcknowledging] = useState<number | null>(null);
    const [filter, setFilter] = useState<'all' | 'unacknowledged'>('all');

    const filtered = filter === 'unacknowledged'
        ? events.filter(e => !e.is_acknowledged)
        : events;

    // Amber highlight: unacknowledged and granted > 24 hours ago
    const isOverdue = (e: BreakGlassEventItem): boolean => {
        if (e.is_acknowledged) return false;
        const grantedAt = new Date(e.access_granted_at);
        const hoursSince = (Date.now() - grantedAt.getTime()) / 3_600_000;
        return hoursSince > 24;
    };

    const handleAcknowledge = async (eventId: number) => {
        setAcknowledging(eventId);
        try {
            await axios.post(`/it-admin/break-glass/${eventId}/acknowledge`);
            setEvents(prev => prev.map(e =>
                e.id === eventId ? { ...e, is_acknowledged: true, acknowledged_at: new Date().toISOString() } : e
            ));
        } catch {
            // silently handle — user can retry
        } finally {
            setAcknowledging(null);
        }
    };

    const formatDate = (iso: string) =>
        new Date(iso).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });

    return (
        <AppShell>
            <Head title="Break-the-Glass Log" />

            <div className="max-w-6xl mx-auto px-6 py-8">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div className="flex items-center gap-3">
                        <ShieldExclamationIcon className="w-7 h-7 text-red-600 dark:text-red-400" />
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">
                                Emergency Access Log
                            </h1>
                            <p className="text-sm text-gray-500 dark:text-slate-400">
                                HIPAA break-the-glass events require supervisor review
                            </p>
                        </div>
                    </div>
                    {unacknowledged_count > 0 && (
                        <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300">
                            <ExclamationTriangleIcon className="w-4 h-4" />
                            {unacknowledged_count} unreviewed
                        </span>
                    )}
                </div>

                {/* Filter tabs */}
                <div className="flex gap-2 mb-5">
                    {(['all', 'unacknowledged'] as const).map(f => (
                        <button
                            key={f}
                            onClick={() => setFilter(f)}
                            className={`px-4 py-1.5 rounded-full text-sm font-medium transition-colors ${
                                filter === f
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600'
                            }`}
                        >
                            {f === 'all' ? 'All Events' : 'Unreviewed'}
                        </button>
                    ))}
                </div>

                {/* Table */}
                {filtered.length === 0 ? (
                    <div className="text-center py-16 text-gray-500 dark:text-slate-400">
                        No break-the-glass events found.
                    </div>
                ) : (
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
                        <table className="w-full text-sm">
                            <thead className="bg-gray-50 dark:bg-slate-700/50">
                                <tr>
                                    <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">User</th>
                                    <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Participant</th>
                                    <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Justification</th>
                                    <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Granted</th>
                                    <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Expires</th>
                                    <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Status</th>
                                    <th className="text-left px-4 py-3 font-semibold text-gray-700 dark:text-slate-300">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
                                {filtered.map(event => (
                                    <tr
                                        key={event.id}
                                        className={`${
                                            isOverdue(event)
                                                ? 'bg-amber-50 dark:bg-amber-900/10'
                                                : 'hover:bg-gray-50 dark:hover:bg-slate-700/50'
                                        }`}
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium text-gray-900 dark:text-slate-100">
                                                {event.user?.name ?? '-'}
                                            </div>
                                            <div className="text-xs text-gray-500 dark:text-slate-400 capitalize">
                                                {event.user?.department?.replace('_', ' ') ?? ''}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            {event.participant ? (
                                                <a
                                                    href={`/participants/${event.participant.id}`}
                                                    className="font-medium text-blue-600 dark:text-blue-400 hover:underline"
                                                >
                                                    {event.participant.name}
                                                </a>
                                            ) : '-'}
                                            <div className="text-xs text-gray-500 dark:text-slate-400">
                                                {event.participant?.mrn}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 max-w-xs">
                                            <p className="text-gray-700 dark:text-slate-300 line-clamp-2">
                                                {event.justification}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-slate-400">
                                            {formatDate(event.access_granted_at)}
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <span className={`text-sm ${event.is_active ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-slate-500'}`}>
                                                {formatDate(event.access_expires_at)}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            {event.is_acknowledged ? (
                                                <span className="inline-flex items-center gap-1 text-green-600 dark:text-green-400 text-xs">
                                                    <CheckCircleIcon className="w-4 h-4" />
                                                    Reviewed
                                                </span>
                                            ) : isOverdue(event) ? (
                                                <span className="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400 text-xs font-medium">
                                                    <ExclamationTriangleIcon className="w-4 h-4" />
                                                    Overdue
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 text-gray-500 dark:text-slate-400 text-xs">
                                                    <ClockIcon className="w-4 h-4" />
                                                    Pending
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            {!event.is_acknowledged && (
                                                <button
                                                    onClick={() => handleAcknowledge(event.id)}
                                                    disabled={acknowledging === event.id}
                                                    className="px-3 py-1 text-xs rounded-lg bg-blue-600 hover:bg-blue-700 text-white disabled:opacity-50 transition-colors"
                                                >
                                                    {acknowledging === event.id ? 'Saving...' : 'Acknowledge'}
                                                </button>
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
