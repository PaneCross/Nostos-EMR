// ─── Grievances/Show ──────────────────────────────────────────────────────────
// Grievance detail page — shows full grievance info, investigation notes,
// resolution section, notification tracker, and status actions.
//
// Data: pre-loaded via Inertia (GrievanceController::show)
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import {
    ExclamationTriangleIcon,
    CheckCircleIcon,
    BellIcon,
    ArrowLeftIcon,
    ShieldCheckIcon,
} from '@heroicons/react/24/outline';

// ── Types ─────────────────────────────────────────────────────────────────────

interface GrievanceDetail {
    id:                      number;
    participant_id:          number;
    participant_name:        string | null;
    participant_mrn:         string | null;
    filed_by_name:           string;
    filed_by_type:           string;
    filed_at:                string;
    category:                string;
    category_label:          string;
    status:                  string;
    status_label:            string;
    priority:                string;
    assigned_to:             string | null;
    received_by:             string | null;
    description:             string;
    investigation_notes:     string | null;
    resolution_text:         string | null;
    resolution_date:         string | null;
    escalation_reason:       string | null;
    escalated_to_user_id:    number | null;
    escalated_to_name:       string | null;
    participant_notified_at: string | null;
    notification_method:     string | null;
    cms_reportable:          boolean;
    is_urgent_overdue:       boolean;
}

/** Staff member available for escalation assignment (has a relevant designation) */
interface EscalationStaff {
    id:           number;
    name:         string;
    department:   string;
    designations: string[];
    label:        string; // human-readable designation list
}

interface ActivityEntry {
    action:           string;
    label:            string;
    status:           string;        // 'open' | 'under_review' | 'resolved' | 'escalated' | 'withdrawn' | 'notified'
    user_name:        string;
    department:       string | null;
    department_label: string | null;
    timestamp:        string | null;
}

interface GrievancesShowProps extends PageProps {
    grievance:            GrievanceDetail;
    activity:             ActivityEntry[];
    categories:           Record<string, string>;
    statuses:             Record<string, string>;
    isQaAdmin:            boolean;
    notificationMethods:  string[];
}

// ── Status badge ──────────────────────────────────────────────────────────────

function StatusBadge({ status, label }: { status: string; label: string }) {
    const colors: Record<string, string> = {
        open:         'bg-blue-100 dark:bg-blue-950/50 text-blue-700 dark:text-blue-300',
        under_review: 'bg-amber-100 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300',
        escalated:    'bg-red-100 dark:bg-red-950/50 text-red-700 dark:text-red-300',
        resolved:     'bg-green-100 dark:bg-green-950/50 text-green-700 dark:text-green-300',
        withdrawn:    'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
    };
    return (
        <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${colors[status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'}`}>
            {label}
        </span>
    );
}

// ── Main Component ────────────────────────────────────────────────────────────

// ── Activity timeline dot/color config ───────────────────────────────────────

const ACTIVITY_CONFIG: Record<string, { dot: string; line: string; text: string }> = {
    open:         { dot: 'bg-blue-500',   line: 'border-blue-200 dark:border-blue-800',   text: 'text-blue-700 dark:text-blue-300' },
    under_review: { dot: 'bg-amber-500',  line: 'border-amber-200 dark:border-amber-800', text: 'text-amber-700 dark:text-amber-300' },
    resolved:     { dot: 'bg-green-500',  line: 'border-green-200 dark:border-green-800', text: 'text-green-700 dark:text-green-300' },
    escalated:    { dot: 'bg-red-500',    line: 'border-red-200 dark:border-red-800',     text: 'text-red-700 dark:text-red-300' },
    withdrawn:    { dot: 'bg-gray-400',   line: 'border-gray-200 dark:border-slate-700',  text: 'text-gray-500 dark:text-slate-400' },
    notified:     { dot: 'bg-purple-500', line: 'border-purple-200 dark:border-purple-800', text: 'text-purple-700 dark:text-purple-300' },
};

export default function GrievancesShow() {
    const { grievance, activity, isQaAdmin, notificationMethods } = usePage<GrievancesShowProps>().props;

    const [resolution, setResolution]         = useState({ resolution_text: '', resolution_date: '' });
    const [escalation, setEscalation]         = useState({ escalation_reason: '', escalated_to_user_id: '' });
    const [withdrawal, setWithdrawal]         = useState({ reason: '', confirming: false });
    const [notifyMethod, setNotifyMethod]     = useState('written');
    const [error, setError]                   = useState<string | null>(null);
    const [loading, setLoading]               = useState(false);
    const [escalationStaff, setEscalationStaff] = useState<EscalationStaff[]>([]);
    const [staffLoading, setStaffLoading]     = useState(false);

    const isClosed = ['resolved', 'withdrawn'].includes(grievance.status);

    // Load escalation staff when the escalate panel would be shown (QA admin, not closed)
    useEffect(() => {
        if (!isQaAdmin || isClosed) return;
        if (!['open', 'under_review'].includes(grievance.status)) return;
        setStaffLoading(true);
        axios.get('/grievances/escalation-staff')
            .then(res => setEscalationStaff(res.data.staff ?? []))
            .catch(() => {}) // non-blocking — escalation still works without staff list
            .finally(() => setStaffLoading(false));
    }, [isQaAdmin, isClosed, grievance.status]);

    const action = async (endpoint: string, data: object) => {
        setLoading(true); setError(null);
        try {
            await axios.post(`/grievances/${grievance.id}/${endpoint}`, data);
            router.reload();
        } catch (err: unknown) {
            const e = err as { response?: { data?: { message?: string } } };
            setError(e.response?.data?.message ?? 'Action failed.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <AppShell>
            <Head title={`Grievance #${grievance.id}`} />

            {/* Back link */}
            <button onClick={() => router.visit('/grievances')} className="flex items-center gap-1 text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 mb-4">
                <ArrowLeftIcon className="w-4 h-4" /> Back to Grievances
            </button>

            {/* Urgent overdue banner */}
            {grievance.is_urgent_overdue && (
                <div className="mb-4 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-lg p-3 flex items-center gap-2">
                    <ExclamationTriangleIcon className="w-5 h-5 text-red-600 dark:text-red-400 shrink-0" />
                    <p className="text-sm font-semibold text-red-700 dark:text-red-300">
                        This urgent grievance is past the 72-hour CMS resolution deadline (42 CFR §460.120(c)).
                    </p>
                </div>
            )}

            {error && (
                <div className="mb-4 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-lg p-3 text-sm text-red-700 dark:text-red-300">
                    {error}
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Left: Main grievance info */}
                <div className="lg:col-span-2 space-y-5">
                    {/* Header card */}
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5">
                        <div className="flex items-start justify-between mb-4">
                            <div>
                                <h1 className="text-xl font-bold text-gray-900 dark:text-slate-100">
                                    Grievance #{grievance.id}
                                </h1>
                                <p className="text-sm text-gray-500 dark:text-slate-400 mt-0.5">{grievance.category_label}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                {grievance.priority === 'urgent' && (
                                    <span className="flex items-center gap-1 px-2 py-1 bg-red-100 dark:bg-red-950/50 text-red-700 dark:text-red-300 text-xs font-semibold rounded">
                                        <ExclamationTriangleIcon className="w-3.5 h-3.5" /> Urgent
                                    </span>
                                )}
                                <StatusBadge status={grievance.status} label={grievance.status_label} />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4 text-sm">
                            <div><span className="text-gray-500 dark:text-slate-400">Participant:</span> <span className="font-medium text-gray-900 dark:text-slate-100 ml-1">{grievance.participant_name ?? '-'}</span> <span className="font-mono text-xs text-gray-400">{grievance.participant_mrn}</span></div>
                            <div><span className="text-gray-500 dark:text-slate-400">Filed By:</span> <span className="font-medium text-gray-900 dark:text-slate-100 ml-1">{grievance.filed_by_name}</span> <span className="text-gray-400 text-xs capitalize">({grievance.filed_by_type.replace(/_/g, ' ')})</span></div>
                            <div><span className="text-gray-500 dark:text-slate-400">Filed At:</span> <span className="ml-1 text-gray-700 dark:text-slate-300">{new Date(grievance.filed_at).toLocaleDateString()}</span></div>
                            <div><span className="text-gray-500 dark:text-slate-400">Received By:</span> <span className="ml-1 text-gray-700 dark:text-slate-300">{grievance.received_by ?? '-'}</span></div>
                            <div><span className="text-gray-500 dark:text-slate-400">Assigned To:</span> <span className="ml-1 text-gray-700 dark:text-slate-300">{grievance.assigned_to ?? 'Unassigned'}</span></div>
                            {grievance.cms_reportable && (
                                <div><span className="px-2 py-0.5 bg-orange-100 dark:bg-orange-950/50 text-orange-700 dark:text-orange-300 text-xs rounded font-medium">CMS Reportable</span></div>
                            )}
                        </div>
                    </div>

                    {/* Description */}
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5">
                        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Description</h3>
                        <p className="text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap">{grievance.description}</p>
                    </div>

                    {/* Investigation notes */}
                    {grievance.investigation_notes && (
                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5">
                            <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Investigation Notes</h3>
                            <p className="text-sm text-gray-700 dark:text-slate-300 whitespace-pre-wrap">{grievance.investigation_notes}</p>
                        </div>
                    )}

                    {/* Resolution */}
                    {grievance.resolution_text && (
                        <div className="bg-green-50 dark:bg-green-950/20 rounded-xl border border-green-200 dark:border-green-800 p-5">
                            <div className="flex items-center gap-2 mb-2">
                                <CheckCircleIcon className="w-5 h-5 text-green-600 dark:text-green-400" />
                                <h3 className="text-sm font-semibold text-green-800 dark:text-green-200">Resolution</h3>
                                <span className="text-xs text-green-600 dark:text-green-400">{grievance.resolution_date}</span>
                            </div>
                            <p className="text-sm text-green-800 dark:text-green-200 whitespace-pre-wrap">{grievance.resolution_text}</p>
                        </div>
                    )}

                    {/* Escalation reason + named assignee */}
                    {grievance.escalation_reason && (
                        <div className="bg-red-50 dark:bg-red-950/20 rounded-xl border border-red-200 dark:border-red-800 p-5">
                            <div className="flex items-start justify-between mb-2">
                                <h3 className="text-sm font-semibold text-red-800 dark:text-red-200">Escalation Reason</h3>
                                {/* Named escalation assignee badge — satisfies CMS named-reviewer requirement */}
                                {grievance.escalated_to_name && (
                                    <span className="inline-flex items-center gap-1 px-2 py-0.5 bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 text-xs rounded font-medium">
                                        <ShieldCheckIcon className="w-3 h-3" />
                                        Assigned: {grievance.escalated_to_name}
                                    </span>
                                )}
                            </div>
                            <p className="text-sm text-red-700 dark:text-red-300 whitespace-pre-wrap">{grievance.escalation_reason}</p>
                        </div>
                    )}
                </div>

                {/* Right: Actions + Activity timeline */}
                <div className="space-y-4">

                    {/* ── Active workflow actions (hidden once resolved/withdrawn) ── */}
                    {isQaAdmin && !isClosed && (<>

                        {/* Start Investigation — open only */}
                        {grievance.status === 'open' && (
                            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                                <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Start Investigation</h3>
                                <p className="text-xs text-gray-500 dark:text-slate-400 mb-3">
                                    Move this grievance into active investigation. Once under review, you can resolve or escalate it.
                                </p>
                                <button
                                    onClick={() => action('start-review', {})}
                                    disabled={loading}
                                    className="w-full px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-lg disabled:opacity-50"
                                >
                                    Mark Under Review
                                </button>
                            </div>
                        )}

                        {/* Resolve — under_review only */}
                        {grievance.status === 'under_review' && (
                            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                                <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-3">Resolve Grievance</h3>
                                <textarea
                                    value={resolution.resolution_text}
                                    onChange={e => setResolution(r => ({ ...r, resolution_text: e.target.value }))}
                                    placeholder="Resolution summary (required)…"
                                    rows={3}
                                    className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 mb-2 resize-none"
                                />
                                <input
                                    type="date"
                                    value={resolution.resolution_date}
                                    onChange={e => setResolution(r => ({ ...r, resolution_date: e.target.value }))}
                                    className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 mb-2"
                                />
                                <button
                                    onClick={() => action('resolve', resolution)}
                                    disabled={loading || !resolution.resolution_text || !resolution.resolution_date}
                                    className="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg disabled:opacity-50"
                                >
                                    Mark Resolved
                                </button>
                            </div>
                        )}

                        {/* Escalate — under_review only (open→escalated is not a valid transition) */}
                        {grievance.status === 'under_review' && (
                            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                                <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-3">Escalate</h3>
                                <textarea
                                    value={escalation.escalation_reason}
                                    onChange={e => setEscalation(s => ({ ...s, escalation_reason: e.target.value }))}
                                    placeholder="Reason for escalation (required)..."
                                    rows={2}
                                    className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 mb-2 resize-none"
                                />
                                <div className="mb-2">
                                    <label className="block text-xs text-gray-500 dark:text-slate-400 mb-1">Assign to (optional)</label>
                                    {staffLoading ? (
                                        <p className="text-xs text-gray-400 dark:text-slate-500">Loading staff...</p>
                                    ) : escalationStaff.length > 0 ? (
                                        <select
                                            value={escalation.escalated_to_user_id}
                                            onChange={e => setEscalation(s => ({ ...s, escalated_to_user_id: e.target.value }))}
                                            className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700"
                                        >
                                            <option value="">-- No specific assignee --</option>
                                            {escalationStaff.map(s => (
                                                <option key={s.id} value={s.id}>
                                                    {s.name}{s.label ? ` (${s.label})` : ''}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <p className="text-xs text-gray-400 dark:text-slate-500 italic">
                                            No designated compliance officers or medical directors found.
                                            Assign designations in IT Admin to enable this.
                                        </p>
                                    )}
                                </div>
                                <button
                                    onClick={() => action('escalate', {
                                        escalation_reason:    escalation.escalation_reason,
                                        escalated_to_user_id: escalation.escalated_to_user_id || null,
                                    })}
                                    disabled={loading || !escalation.escalation_reason}
                                    className="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg disabled:opacity-50"
                                >
                                    Escalate Grievance
                                </button>
                            </div>
                        )}

                        {/* Withdraw — any active status; two-step to prevent accidents */}
                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Withdraw Grievance</h3>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mb-3">
                                Mark this grievance as withdrawn — typically when the participant retracts their complaint.
                                This is a terminal action and cannot be undone.
                            </p>
                            {!withdrawal.confirming ? (
                                <button
                                    onClick={() => setWithdrawal(w => ({ ...w, confirming: true }))}
                                    disabled={loading}
                                    className="w-full px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-200 text-sm font-medium rounded-lg disabled:opacity-50"
                                >
                                    Withdraw Grievance
                                </button>
                            ) : (
                                <div className="space-y-2">
                                    <textarea
                                        value={withdrawal.reason}
                                        onChange={e => setWithdrawal(w => ({ ...w, reason: e.target.value }))}
                                        placeholder="Reason for withdrawal (optional)..."
                                        rows={2}
                                        className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 resize-none"
                                    />
                                    <div className="flex gap-2">
                                        <button
                                            onClick={() => setWithdrawal({ reason: '', confirming: false })}
                                            className="flex-1 px-3 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            onClick={() => action('withdraw', { withdrawal_reason: withdrawal.reason || undefined })}
                                            disabled={loading}
                                            className="flex-1 px-3 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium rounded-lg disabled:opacity-50"
                                        >
                                            Confirm Withdraw
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </>)}

                    {/* ── Notify Participant — shown after resolution, outside isClosed gate ──
                        CMS §460.120(d) requires participants be notified of the outcome.
                        Deliberately separated from active workflow so it remains accessible
                        on resolved grievances even though they are otherwise closed.
                    ── */}
                    {isQaAdmin && grievance.status === 'resolved' && (
                        !grievance.participant_notified_at ? (
                            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                                <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-1 flex items-center gap-1.5">
                                    <BellIcon className="w-4 h-4" /> Notify Participant
                                </h3>
                                <p className="text-xs text-gray-500 dark:text-slate-400 mb-3">
                                    Record how the participant was informed of the resolution outcome (CMS §460.120(d)).
                                </p>
                                <select
                                    value={notifyMethod}
                                    onChange={e => setNotifyMethod(e.target.value)}
                                    className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 mb-2"
                                >
                                    {notificationMethods.map(m => (
                                        <option key={m} value={m}>{m.charAt(0).toUpperCase() + m.slice(1)}</option>
                                    ))}
                                </select>
                                <button
                                    onClick={() => action('notify-participant', { notification_method: notifyMethod })}
                                    disabled={loading}
                                    className="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg disabled:opacity-50"
                                >
                                    Record Notification
                                </button>
                            </div>
                        ) : (
                            <div className="bg-green-50 dark:bg-green-950/20 rounded-xl border border-green-200 dark:border-green-800 p-4 flex items-center gap-2">
                                <CheckCircleIcon className="w-5 h-5 text-green-600 dark:text-green-400 shrink-0" />
                                <div>
                                    <p className="text-xs font-semibold text-green-800 dark:text-green-200">Participant Notified</p>
                                    <p className="text-xs text-green-600 dark:text-green-400">
                                        {new Date(grievance.participant_notified_at).toLocaleDateString()} via {grievance.notification_method}
                                    </p>
                                </div>
                            </div>
                        )
                    )}

                    {/* ── Activity Timeline ─────────────────────────────────
                        Visible to all users who can view this grievance.
                        Shows each meaningful audit event in chronological order
                        with who took the action, from which department, and when.
                    ─────────────────────────────────────────────────────── */}
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Activity</h3>
                        {activity.length === 0 ? (
                            <p className="text-xs text-gray-400 dark:text-slate-500 italic">No activity recorded yet.</p>
                        ) : (
                            <ol className="relative border-l border-gray-200 dark:border-slate-700 ml-2 space-y-5">
                                {activity.map((entry, i) => {
                                    const cfg = ACTIVITY_CONFIG[entry.status] ?? ACTIVITY_CONFIG['withdrawn'];
                                    const isLast = i === activity.length - 1;
                                    return (
                                        <li key={i} className="ml-4">
                                            {/* Dot on the timeline spine */}
                                            <span className={`absolute -left-[9px] flex items-center justify-center w-[17px] h-[17px] rounded-full ring-2 ring-white dark:ring-slate-800 ${cfg.dot}`} />

                                            {/* Event label */}
                                            <p className={`text-xs font-semibold ${cfg.text} ${isLast ? '' : 'mb-0.5'}`}>
                                                {entry.label}
                                            </p>

                                            {/* Who + department */}
                                            <p className="text-xs text-gray-600 dark:text-slate-400">
                                                {entry.user_name}
                                                {entry.department_label && (
                                                    <span className="text-gray-400 dark:text-slate-500"> ({entry.department_label})</span>
                                                )}
                                            </p>

                                            {/* Timestamp */}
                                            {entry.timestamp && (
                                                <p className="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                                                    {new Date(entry.timestamp).toLocaleString([], {
                                                        month: 'short', day: 'numeric', year: 'numeric',
                                                        hour: '2-digit', minute: '2-digit',
                                                    })}
                                                </p>
                                            )}
                                        </li>
                                    );
                                })}
                            </ol>
                        )}
                    </div>
                </div>
            </div>
        </AppShell>
    );
}
