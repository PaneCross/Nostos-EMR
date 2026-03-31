// ─── Grievances/Show ──────────────────────────────────────────────────────────
// Grievance detail page — shows full grievance info, investigation notes,
// resolution section, notification tracker, and status actions.
//
// Data: pre-loaded via Inertia (GrievanceController::show)
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import {
    ExclamationTriangleIcon,
    CheckCircleIcon,
    BellIcon,
    ArrowLeftIcon,
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
    participant_notified_at: string | null;
    notification_method:     string | null;
    cms_reportable:          boolean;
    is_urgent_overdue:       boolean;
}

interface GrievancesShowProps extends PageProps {
    grievance:            GrievanceDetail;
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

export default function GrievancesShow() {
    const { grievance, isQaAdmin, notificationMethods } = usePage<GrievancesShowProps>().props;

    const [resolution, setResolution]     = useState({ resolution_text: '', resolution_date: '' });
    const [escalation, setEscalation]     = useState({ escalation_reason: '' });
    const [notifyMethod, setNotifyMethod] = useState('written');
    const [error, setError]               = useState<string | null>(null);
    const [loading, setLoading]           = useState(false);

    const isClosed = ['resolved', 'withdrawn'].includes(grievance.status);

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

                    {/* Escalation reason */}
                    {grievance.escalation_reason && (
                        <div className="bg-red-50 dark:bg-red-950/20 rounded-xl border border-red-200 dark:border-red-800 p-5">
                            <h3 className="text-sm font-semibold text-red-800 dark:text-red-200 mb-2">Escalation Reason</h3>
                            <p className="text-sm text-red-700 dark:text-red-300 whitespace-pre-wrap">{grievance.escalation_reason}</p>
                        </div>
                    )}
                </div>

                {/* Right: Actions sidebar */}
                {isQaAdmin && !isClosed && (
                    <div className="space-y-4">
                        {/* Resolve */}
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

                        {/* Mark under review */}
                        {grievance.status === 'open' && (
                            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                                <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-3">Start Investigation</h3>
                                <button
                                    onClick={() => action('update', { status: 'under_review' })}
                                    disabled={loading}
                                    className="w-full px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-lg disabled:opacity-50"
                                >
                                    Mark Under Review
                                </button>
                            </div>
                        )}

                        {/* Escalate */}
                        {['open', 'under_review'].includes(grievance.status) && (
                            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                                <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-3">Escalate</h3>
                                <textarea
                                    value={escalation.escalation_reason}
                                    onChange={e => setEscalation({ escalation_reason: e.target.value })}
                                    placeholder="Reason for escalation (required)…"
                                    rows={2}
                                    className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 mb-2 resize-none"
                                />
                                <button
                                    onClick={() => action('escalate', escalation)}
                                    disabled={loading || !escalation.escalation_reason}
                                    className="w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg disabled:opacity-50"
                                >
                                    Escalate Grievance
                                </button>
                            </div>
                        )}

                        {/* Notify participant */}
                        {grievance.status === 'resolved' && !grievance.participant_notified_at && (
                            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                                <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-3">
                                    <BellIcon className="w-4 h-4 inline mr-1" />
                                    Notify Participant
                                </h3>
                                <select
                                    value={notifyMethod}
                                    onChange={e => setNotifyMethod(e.target.value)}
                                    className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 mb-2"
                                >
                                    {notificationMethods.map(m => <option key={m} value={m}>{m.charAt(0).toUpperCase() + m.slice(1)}</option>)}
                                </select>
                                <button
                                    onClick={() => action('notify-participant', { notification_method: notifyMethod })}
                                    disabled={loading}
                                    className="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg disabled:opacity-50"
                                >
                                    Record Notification
                                </button>
                            </div>
                        )}

                        {/* Notification recorded */}
                        {grievance.participant_notified_at && (
                            <div className="bg-green-50 dark:bg-green-950/20 rounded-xl border border-green-200 dark:border-green-800 p-4 flex items-center gap-2">
                                <CheckCircleIcon className="w-5 h-5 text-green-600 dark:text-green-400 shrink-0" />
                                <div>
                                    <p className="text-xs font-medium text-green-800 dark:text-green-200">Participant Notified</p>
                                    <p className="text-xs text-green-600 dark:text-green-400">
                                        {new Date(grievance.participant_notified_at).toLocaleDateString()} via {grievance.notification_method}
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AppShell>
    );
}
