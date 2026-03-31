// ─── Alerts/Index ────────────────────────────────────────────────────────────
// Full-page alert log for the authenticated user's department.
//
// Two views (top-level tabs):
//   Active    — all active alerts (is_active=true); supports severity + read filters
//   Dismissed — alerts dismissed in the last 30 days (is_active=false); read-only
//
// Active actions: Acknowledge per alert, deep-link to participant or chat channel.
// Dismissed view: shows when each alert was dismissed; no action buttons.
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import {
    BellIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    InformationCircleIcon,
    FunnelIcon,
    ArchiveBoxIcon,
} from '@heroicons/react/24/outline';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Participant {
    id: number;
    mrn: string;
    first_name: string;
    last_name: string;
}

interface AlertItem {
    id: number;
    title: string;
    message: string;
    severity: 'info' | 'warning' | 'critical';
    source_module: string;
    alert_type: string;
    is_active: boolean;
    acknowledged_at: string | null;
    resolved_at: string | null;
    created_at: string;
    metadata?: { channel_id?: number } | null;
    participant?: Participant | null;
}

type ViewTab      = 'active' | 'dismissed';
type SeverityFilter = 'all' | 'critical' | 'warning' | 'info';
type ReadFilter     = 'all' | 'unread';

// ── Severity config ───────────────────────────────────────────────────────────

const SEVERITY_ROW: Record<string, string> = {
    critical: 'border-l-4 border-l-red-500 bg-red-50 dark:bg-red-900/20',
    warning:  'border-l-4 border-l-amber-500 bg-amber-50 dark:bg-amber-900/20',
    info:     'border-l-4 border-l-blue-400 bg-blue-50 dark:bg-blue-900/20',
};

const SEVERITY_BADGE: Record<string, string> = {
    critical: 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 ring-red-600/20',
    warning:  'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 ring-amber-600/20',
    info:     'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 ring-blue-600/20',
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function alertHref(alert: AlertItem): string | null {
    if (alert.source_module === 'chat' && alert.metadata?.channel_id) {
        return `/chat?channel=${alert.metadata.channel_id}`;
    }
    if (alert.participant?.id) {
        return `/participants/${alert.participant.id}`;
    }
    return null;
}

function timeAgo(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60_000);
    if (mins < 1)  return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24)  return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function AlertsIndex() {
    const [alerts, setAlerts]           = useState<AlertItem[]>([]);
    const [loading, setLoading]         = useState(true);
    const [viewTab, setViewTab]         = useState<ViewTab>('active');
    const [severityFilter, setSeverity] = useState<SeverityFilter>('all');
    const [readFilter, setReadFilter]   = useState<ReadFilter>('all');

    // Reset sub-filters when switching view tabs
    const switchView = (tab: ViewTab) => {
        setViewTab(tab);
        setSeverity('all');
        setReadFilter('all');
    };

    // ── Load alerts ───────────────────────────────────────────────────────

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const params: Record<string, unknown> = {
                status:   viewTab,
                per_page: 100,
            };
            if (severityFilter !== 'all') params.severity = severityFilter;
            if (viewTab === 'active' && readFilter === 'unread') params.unread_only = 1;

            const { data } = await axios.get('/alerts', { params });
            setAlerts(data.data ?? data);
        } catch {
            // ignore network errors silently
        } finally {
            setLoading(false);
        }
    }, [viewTab, severityFilter, readFilter]);

    useEffect(() => { load(); }, [load]);

    // ── Acknowledge (active tab only) ─────────────────────────────────────

    const acknowledge = async (alertId: number) => {
        try {
            await axios.patch(`/alerts/${alertId}/acknowledge`);
            setAlerts(prev => prev.map(a =>
                a.id === alertId
                    ? { ...a, acknowledged_at: new Date().toISOString() }
                    : a
            ));
        } catch (err) {
            console.error('[Alerts] acknowledge failed:', err);
        }
    };

    // ── Dismiss (active tab only) ─────────────────────────────────────────
    // Resolves the alert (is_active=false). Removes it from the active list
    // immediately; it will appear in the Dismissed tab for the next 30 days.

    const dismiss = async (alertId: number) => {
        try {
            await axios.patch(`/alerts/${alertId}/resolve`);
            setAlerts(prev => prev.filter(a => a.id !== alertId));
        } catch (err) {
            console.error('[Alerts] dismiss failed:', err);
        }
    };

    // ── Counts for filter badges (active tab only) ────────────────────────

    const unreadCount   = alerts.filter(a => !a.acknowledged_at).length;
    const criticalCount = alerts.filter(a => a.severity === 'critical').length;
    const warningCount  = alerts.filter(a => a.severity === 'warning').length;
    const infoCount     = alerts.filter(a => a.severity === 'info').length;

    return (
        <AppShell>
            <Head title="Alerts" />

            <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

                {/* ── Page header ─────────────────────────────────────────── */}
                <div className="flex items-center gap-3 mb-6">
                    <div className="p-2 rounded-lg bg-slate-100 dark:bg-slate-700">
                        <BellIcon className="w-5 h-5 text-slate-600 dark:text-slate-300" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-100">
                            Alerts
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            {viewTab === 'active'
                                ? 'Active alerts for your department'
                                : 'Alerts dismissed in the last 30 days'}
                        </p>
                    </div>
                    {viewTab === 'active' && unreadCount > 0 && (
                        <span className="ml-auto inline-flex items-center rounded-full bg-red-100 dark:bg-red-900/40 px-2.5 py-0.5 text-xs font-semibold text-red-700 dark:text-red-300">
                            {unreadCount} unread
                        </span>
                    )}
                </div>

                {/* ── View tabs (Active / Dismissed) ───────────────────────── */}
                <div className="flex rounded-lg overflow-hidden border border-slate-200 dark:border-slate-600 text-xs mb-5 w-fit">
                    <button
                        onClick={() => switchView('active')}
                        className={`flex items-center gap-1.5 px-4 py-2 font-medium transition-colors ${
                            viewTab === 'active'
                                ? 'bg-slate-700 dark:bg-slate-500 text-white'
                                : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700'
                        }`}
                    >
                        <BellIcon className="w-3.5 h-3.5" />
                        Active
                    </button>
                    <button
                        onClick={() => switchView('dismissed')}
                        className={`flex items-center gap-1.5 px-4 py-2 font-medium transition-colors ${
                            viewTab === 'dismissed'
                                ? 'bg-slate-700 dark:bg-slate-500 text-white'
                                : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700'
                        }`}
                    >
                        <ArchiveBoxIcon className="w-3.5 h-3.5" />
                        Dismissed (last 30 days)
                    </button>
                </div>

                {/* ── Filters ──────────────────────────────────────────────── */}
                <div className="flex flex-wrap items-center gap-3 mb-5">
                    <div className="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                        <FunnelIcon className="w-3.5 h-3.5" />
                        Filter:
                    </div>

                    {/* Severity filter — available on both tabs */}
                    <div className="flex rounded-lg overflow-hidden border border-slate-200 dark:border-slate-600 text-xs">
                        {(['all', 'critical', 'warning', 'info'] as SeverityFilter[]).map(s => (
                            <button
                                key={s}
                                onClick={() => setSeverity(s)}
                                className={`px-3 py-1.5 font-medium transition-colors ${
                                    severityFilter === s
                                        ? 'bg-slate-700 dark:bg-slate-500 text-white'
                                        : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700'
                                }`}
                            >
                                {s === 'all'      ? `All${alerts.length ? ` (${alerts.length})` : ''}` : null}
                                {s === 'critical' && criticalCount > 0 ? `Critical (${criticalCount})` : s === 'critical' ? 'Critical' : null}
                                {s === 'warning'  && warningCount  > 0 ? `Warning (${warningCount})`  : s === 'warning'  ? 'Warning'  : null}
                                {s === 'info'     && infoCount     > 0 ? `Info (${infoCount})`        : s === 'info'     ? 'Info'     : null}
                            </button>
                        ))}
                    </div>

                    {/* Read status filter — active tab only (dismissed alerts have no read state) */}
                    {viewTab === 'active' && (
                        <div className="flex rounded-lg overflow-hidden border border-slate-200 dark:border-slate-600 text-xs">
                            {(['all', 'unread'] as ReadFilter[]).map(r => (
                                <button
                                    key={r}
                                    onClick={() => setReadFilter(r)}
                                    className={`px-3 py-1.5 font-medium transition-colors capitalize ${
                                        readFilter === r
                                            ? 'bg-slate-700 dark:bg-slate-500 text-white'
                                            : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700'
                                    }`}
                                >
                                    {r === 'unread' ? `Unread${unreadCount > 0 ? ` (${unreadCount})` : ''}` : 'All'}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* ── Alert list ──────────────────────────────────────────── */}
                <div className="rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden bg-white dark:bg-slate-800 shadow-sm">

                    {loading && (
                        <div className="px-6 py-12 text-center text-sm text-slate-500 dark:text-slate-400">
                            Loading alerts…
                        </div>
                    )}

                    {!loading && alerts.length === 0 && viewTab === 'active' && (
                        <div className="px-6 py-12 text-center">
                            <CheckCircleIcon className="w-10 h-10 text-green-400 mx-auto mb-3" />
                            <p className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                All caught up
                            </p>
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                No active alerts for your department
                            </p>
                        </div>
                    )}

                    {!loading && alerts.length === 0 && viewTab === 'dismissed' && (
                        <div className="px-6 py-12 text-center">
                            <ArchiveBoxIcon className="w-10 h-10 text-slate-300 dark:text-slate-600 mx-auto mb-3" />
                            <p className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                No dismissed alerts
                            </p>
                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                Alerts you dismiss will appear here for 30 days
                            </p>
                        </div>
                    )}

                    {!loading && alerts.map((alert, idx) => {
                        const href = alertHref(alert);
                        return (
                            <div
                                key={alert.id}
                                className={`px-5 py-4 ${SEVERITY_ROW[alert.severity] ?? ''} ${
                                    idx < alerts.length - 1 ? 'border-b border-slate-100 dark:border-slate-700' : ''
                                } ${(alert.acknowledged_at || viewTab === 'dismissed') ? 'opacity-60' : ''}`}
                            >
                                <div className="flex items-start justify-between gap-4">
                                    {/* Left: severity icon + content */}
                                    <div className="flex items-start gap-3 flex-1 min-w-0">
                                        <div className="mt-0.5 shrink-0">
                                            {alert.severity === 'critical' && (
                                                <ExclamationTriangleIcon className="w-4 h-4 text-red-500 dark:text-red-400" />
                                            )}
                                            {alert.severity === 'warning' && (
                                                <ExclamationTriangleIcon className="w-4 h-4 text-amber-500 dark:text-amber-400" />
                                            )}
                                            {alert.severity === 'info' && (
                                                <InformationCircleIcon className="w-4 h-4 text-blue-500 dark:text-blue-400" />
                                            )}
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            {/* Badge row */}
                                            <div className="flex items-center gap-2 mb-1">
                                                <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset ${SEVERITY_BADGE[alert.severity] ?? ''}`}>
                                                    {alert.severity.toUpperCase()}
                                                </span>
                                                <span className="text-[11px] text-slate-500 dark:text-slate-400 capitalize">
                                                    {alert.alert_type.replace(/_/g, ' ')}
                                                </span>
                                                {alert.participant && (
                                                    <span className="text-[11px] text-slate-500 dark:text-slate-400">
                                                        · {alert.participant.first_name} {alert.participant.last_name}
                                                        <span className="ml-1 text-slate-400 dark:text-slate-500">
                                                            ({alert.participant.mrn})
                                                        </span>
                                                    </span>
                                                )}
                                            </div>
                                            {/* Title + message */}
                                            <p className="text-sm font-semibold text-slate-800 dark:text-slate-100 leading-snug">
                                                {alert.title}
                                            </p>
                                            <p className="text-xs text-slate-600 dark:text-slate-400 mt-0.5 line-clamp-2">
                                                {alert.message}
                                            </p>
                                            {/* Action links */}
                                            <div className="flex items-center gap-4 mt-2">
                                                {href && (
                                                    <a
                                                        href={href}
                                                        className="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline"
                                                    >
                                                        {alert.source_module === 'chat' ? 'Go to chat' : 'View participant'}
                                                    </a>
                                                )}

                                                {/* Active tab: acknowledge / acknowledged state */}
                                                {viewTab === 'active' && !alert.acknowledged_at && alert.is_active && (
                                                    <button
                                                        onClick={() => acknowledge(alert.id)}
                                                        className="text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 underline"
                                                    >
                                                        Acknowledge
                                                    </button>
                                                )}
                                                {viewTab === 'active' && alert.acknowledged_at && (
                                                    <span className="flex items-center gap-1 text-xs text-slate-400 dark:text-slate-500 italic">
                                                        <CheckCircleIcon className="w-3.5 h-3.5" />
                                                        Acknowledged {timeAgo(alert.acknowledged_at)}
                                                    </span>
                                                )}
                                                {/* Dismiss button — available on all active alerts */}
                                                {viewTab === 'active' && alert.is_active && (
                                                    <button
                                                        onClick={() => dismiss(alert.id)}
                                                        className="text-xs font-medium text-slate-400 dark:text-slate-500 hover:text-red-600 dark:hover:text-red-400 underline"
                                                    >
                                                        Dismiss
                                                    </button>
                                                )}

                                                {/* Dismissed tab: when it was dismissed */}
                                                {viewTab === 'dismissed' && alert.resolved_at && (
                                                    <span className="flex items-center gap-1 text-xs text-slate-400 dark:text-slate-500 italic">
                                                        <ArchiveBoxIcon className="w-3.5 h-3.5" />
                                                        Dismissed {timeAgo(alert.resolved_at)}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Right: timestamp */}
                                    <span className="text-xs text-slate-400 dark:text-slate-500 whitespace-nowrap shrink-0 mt-0.5">
                                        {timeAgo(alert.created_at)}
                                    </span>
                                </div>
                            </div>
                        );
                    })}
                </div>

                {/* ── Footer note ─────────────────────────────────────────── */}
                {!loading && alerts.length > 0 && (
                    <p className="mt-4 text-xs text-center text-slate-400 dark:text-slate-500">
                        {viewTab === 'active'
                            ? 'Showing active alerts for your department. Dismissed alerts are not displayed.'
                            : 'Showing alerts dismissed in the last 30 days. Older dismissed alerts are automatically removed.'}
                    </p>
                )}
            </div>
        </AppShell>
    );
}
