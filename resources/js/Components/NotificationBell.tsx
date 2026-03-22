// ─── NotificationBell ────────────────────────────────────────────────────────
// Real-time notification bell for the TopBar.
// Polls /alerts/unread-count every 60s and subscribes to the Reverb
// `tenant.{id}` channel for instant alert.created events.
//
// Features:
//   - Unread badge count (animated pulse on new alert)
//   - Dropdown panel with recent alerts (last 10)
//   - Critical-severity banner at top of panel
//   - Acknowledge button per alert
//   - Dismiss (close panel) on outside click / Escape
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect, useRef, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { PageProps } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

interface AlertItem {
    id: number;
    title: string;
    message: string;
    severity: 'info' | 'warning' | 'critical';
    source_module: string;
    alert_type: string;
    is_active: boolean;
    acknowledged_at: string | null;
    created_at: string;
    participant?: {
        id: number;
        mrn: string;
        first_name: string;
        last_name: string;
    } | null;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const SEVERITY_CLASSES: Record<string, string> = {
    critical: 'bg-red-50 border-l-4 border-l-red-500 text-red-900',
    warning:  'bg-amber-50 border-l-4 border-l-amber-500 text-amber-900',
    info:     'bg-blue-50 border-l-4 border-l-blue-400 text-blue-900',
};

const SEVERITY_BADGE: Record<string, string> = {
    critical: 'bg-red-100 text-red-700 ring-red-600/20',
    warning:  'bg-amber-100 text-amber-700 ring-amber-600/20',
    info:     'bg-blue-100 text-blue-700 ring-blue-600/20',
};

function timeAgo(dateStr: string): string {
    const diff = Date.now() - new Date(dateStr).getTime();
    const mins = Math.floor(diff / 60_000);
    if (mins < 1)  return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24)  return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}

// ── Component ────────────────────────────────────────────────────────────────

export default function NotificationBell() {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user!;

    const [open, setOpen]         = useState(false);
    const [count, setCount]       = useState(0);
    const [alerts, setAlerts]     = useState<AlertItem[]>([]);
    const [loading, setLoading]   = useState(false);
    const [pulse, setPulse]       = useState(false);
    const panelRef = useRef<HTMLDivElement>(null);
    const prevCount = useRef(0);

    // ── Fetch unread count ──────────────────────────────────────────────────

    const fetchCount = useCallback(async () => {
        try {
            const { data } = await axios.get('/alerts/unread-count');
            const newCount: number = data.count ?? 0;

            // Animate bell briefly when count increases
            if (newCount > prevCount.current) {
                setPulse(true);
                setTimeout(() => setPulse(false), 2000);
            }
            prevCount.current = newCount;
            setCount(newCount);
        } catch {
            // Silently ignore — network errors shouldn't break the UI
        }
    }, []);

    // ── Fetch full alert list (when panel opens) ────────────────────────────

    const fetchAlerts = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get('/alerts', {
                params: { per_page: 10 },
            });
            setAlerts(data.data ?? data);
        } catch {
            // ignore
        } finally {
            setLoading(false);
        }
    }, []);

    // ── Polling: every 60 seconds ───────────────────────────────────────────

    useEffect(() => {
        fetchCount();
        const interval = setInterval(fetchCount, 60_000);
        return () => clearInterval(interval);
    }, [fetchCount]);

    // ── Reverb real-time subscription ──────────────────────────────────────

    useEffect(() => {
        if (!window.Echo || !user.tenant?.id) return;

        const channel = window.Echo.channel(`tenant.${user.tenant.id}`);
        channel.listen('.alert.created', (_payload: unknown) => {
            fetchCount();
            // If panel is already open, refresh the list
            if (open) fetchAlerts();
        });

        return () => {
            window.Echo?.leaveChannel(`tenant.${user.tenant!.id}`);
        };
    }, [user.tenant?.id, open, fetchCount, fetchAlerts]);

    // ── Panel open/close ────────────────────────────────────────────────────

    const handleOpen = () => {
        setOpen(prev => {
            if (!prev) fetchAlerts();
            return !prev;
        });
    };

    // Close on outside click / Escape
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
        const onClickOutside = (e: MouseEvent) => {
            if (panelRef.current && !panelRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onClickOutside);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onClickOutside);
        };
    }, [open]);

    // ── Acknowledge ─────────────────────────────────────────────────────────

    const acknowledge = async (alertId: number) => {
        try {
            await axios.patch(`/alerts/${alertId}/acknowledge`);
            setAlerts(prev => prev.map(a =>
                a.id === alertId
                    ? { ...a, acknowledged_at: new Date().toISOString() }
                    : a
            ));
            setCount(prev => Math.max(0, prev - 1));
        } catch {
            // ignore
        }
    };

    const criticals = alerts.filter(a => a.severity === 'critical' && !a.acknowledged_at);

    return (
        <div className="relative" ref={panelRef}>
            {/* ── Bell button ─────────────────────────────────────────────── */}
            <button
                onClick={handleOpen}
                data-testid="notification-bell"
                aria-label={`Notifications${count > 0 ? ` (${count} unread)` : ''}`}
                className={`relative p-1.5 rounded-lg hover:bg-slate-100 transition-colors ${
                    open ? 'bg-slate-100 text-slate-700' : 'text-slate-500'
                }`}
            >
                <svg
                    className={`w-5 h-5 ${pulse ? 'animate-bounce' : ''}`}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={1.6}
                    viewBox="0 0 24 24"
                >
                    <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                </svg>

                {count > 0 && (
                    <span
                        data-testid="notification-badge"
                        className="absolute -top-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white ring-2 ring-white"
                    >
                        {count > 9 ? '9+' : count}
                    </span>
                )}
            </button>

            {/* ── Dropdown panel ──────────────────────────────────────────── */}
            {open && (
                <div
                    data-testid="notification-panel"
                    className="absolute right-0 mt-2 w-96 max-h-[70vh] overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-slate-200 flex flex-col z-50"
                >
                    {/* Header */}
                    <div className="flex items-center justify-between px-4 py-3 border-b border-slate-100">
                        <h3 className="text-sm font-semibold text-slate-800">Notifications</h3>
                        <span className="text-xs text-slate-500">
                            {count > 0 ? `${count} unread` : 'All caught up'}
                        </span>
                    </div>

                    {/* Critical banner (if any unacknowledged criticals) */}
                    {criticals.length > 0 && (
                        <div className="bg-red-600 px-4 py-2 flex items-center gap-2 text-white text-xs font-semibold">
                            <svg className="w-4 h-4 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                            {criticals.length} critical alert{criticals.length !== 1 ? 's' : ''} require attention
                        </div>
                    )}

                    {/* Alert list */}
                    <div className="overflow-y-auto flex-1">
                        {loading && (
                            <div className="px-4 py-8 text-center text-sm text-slate-500">
                                Loading…
                            </div>
                        )}

                        {!loading && alerts.length === 0 && (
                            <div className="px-4 py-8 text-center text-sm text-slate-500">
                                No alerts at this time
                            </div>
                        )}

                        {!loading && alerts.map(alert => (
                            <div
                                key={alert.id}
                                data-testid={`alert-item-${alert.id}`}
                                className={`px-4 py-3 border-b border-slate-100 ${SEVERITY_CLASSES[alert.severity] ?? ''} ${
                                    alert.acknowledged_at ? 'opacity-60' : ''
                                }`}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-1.5 mb-0.5">
                                            <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset ${SEVERITY_BADGE[alert.severity] ?? ''}`}>
                                                {alert.severity.toUpperCase()}
                                            </span>
                                            {alert.participant && (
                                                <span className="text-[11px] text-slate-500 truncate">
                                                    {alert.participant.first_name} {alert.participant.last_name}
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-xs font-semibold leading-snug">{alert.title}</p>
                                        <p className="text-[11px] text-slate-600 mt-0.5 line-clamp-2">{alert.message}</p>
                                    </div>
                                    <span className="text-[10px] text-slate-400 whitespace-nowrap shrink-0 mt-0.5">
                                        {timeAgo(alert.created_at)}
                                    </span>
                                </div>

                                {/* Acknowledge button (only if unacknowledged and active) */}
                                {!alert.acknowledged_at && alert.is_active && (
                                    <button
                                        onClick={() => acknowledge(alert.id)}
                                        data-testid={`ack-alert-${alert.id}`}
                                        className="mt-1.5 text-[11px] font-medium underline text-slate-500 hover:text-slate-700"
                                    >
                                        Acknowledge
                                    </button>
                                )}
                                {alert.acknowledged_at && (
                                    <p className="mt-1 text-[10px] text-slate-400 italic">
                                        Acknowledged {timeAgo(alert.acknowledged_at)}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>

                    {/* Footer */}
                    <div className="border-t border-slate-100 px-4 py-2">
                        <a
                            href="/alerts"
                            className="text-xs text-blue-600 hover:text-blue-800 font-medium"
                        >
                            View all alerts →
                        </a>
                    </div>
                </div>
            )}
        </div>
    );
}
