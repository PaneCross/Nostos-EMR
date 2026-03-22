// ─── SDR Management Page ──────────────────────────────────────────────────────
// Service Delivery Requests — 72-hour enforcement tracking.
//
// Tabs: My Department | Assigned To Me | Overdue | All (QA/Compliance only)
//
// Each SDR card shows:
//   - Participant name + MRN
//   - Request type (dropdown-first label)
//   - Priority badge (emergent / urgent / routine)
//   - Requesting dept → assigned dept arrow
//   - Time remaining (or overdue label) with color coding
//   - Status badge
//   - Update button → inline status modal
//
// Route: GET /sdrs → Inertia::render('Sdrs/Index')
// Props: myDeptSdrs, assignedToMe, overdueSdrs, allSdrs, userDept, requestTypes, departments
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps, Department } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

interface ParticipantSummary {
    id: number;
    mrn: string;
    first_name: string;
    last_name: string;
}

interface UserSummary {
    id: number;
    first_name: string;
    last_name: string;
}

interface SdrItem {
    id: number;
    request_type: string;
    description: string;
    priority: 'routine' | 'urgent' | 'emergent';
    status: 'submitted' | 'acknowledged' | 'in_progress' | 'completed' | 'cancelled';
    requesting_department: string;
    assigned_department: string;
    submitted_at: string;
    due_at: string;
    completed_at: string | null;
    escalated: boolean;
    participant: ParticipantSummary;
    requesting_user: UserSummary | null;
}

interface SdrsPageProps extends PageProps {
    myDeptSdrs:   SdrItem[];
    assignedToMe: SdrItem[];
    overdueSdrs:  SdrItem[];
    allSdrs:      { data: SdrItem[] } | null;
    userDept:     Department;
    requestTypes: string[];
    departments:  string[];
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const TYPE_LABELS: Record<string, string> = {
    lab_order: 'Lab Order', referral: 'Referral', home_care_visit: 'Home Care Visit',
    transport_request: 'Transport Request', equipment_dme: 'Equipment / DME',
    pharmacy_change: 'Pharmacy Change', assessment_request: 'Assessment Request',
    care_plan_update: 'Care Plan Update', other: 'Other',
};

const PRIORITY_CLASSES: Record<string, string> = {
    emergent: 'bg-red-50 text-red-700 ring-red-600/20',
    urgent:   'bg-amber-50 text-amber-700 ring-amber-600/20',
    routine:  'bg-gray-50 text-gray-600 ring-gray-500/10',
};

const STATUS_CLASSES: Record<string, string> = {
    submitted:    'bg-blue-50 text-blue-700',
    acknowledged: 'bg-purple-50 text-purple-700',
    in_progress:  'bg-amber-50 text-amber-700',
    completed:    'bg-green-50 text-green-700',
    cancelled:    'bg-gray-50 text-gray-400 line-through',
};

function deptLabel(dept: string): string {
    return dept.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function hoursRemaining(dueAt: string): number {
    return (new Date(dueAt).getTime() - Date.now()) / 3_600_000;
}

function urgencyColor(hrs: number): string {
    if (hrs < 0)  return 'text-red-700 bg-red-50';
    if (hrs <= 8)  return 'text-orange-700 bg-orange-50';
    if (hrs <= 24) return 'text-amber-700 bg-amber-50';
    return 'text-slate-600 bg-slate-50';
}

function formatHours(hrs: number): string {
    if (hrs < 0) {
        const over = Math.abs(hrs);
        return `${over.toFixed(0)}h overdue`;
    }
    if (hrs < 1) return `${Math.round(hrs * 60)}m left`;
    return `${hrs.toFixed(0)}h left`;
}

// ── New SDR Modal ─────────────────────────────────────────────────────────────

interface NewSdrModalProps {
    requestTypes: string[];
    departments:  string[];
    onClose: () => void;
    onSaved: () => void;
}

function NewSdrModal({ requestTypes, departments, onClose, onSaved }: NewSdrModalProps) {
    const { auth } = usePage<PageProps>().props;
    const [form, setForm] = useState({
        participant_id: '',
        request_type:   requestTypes[0] ?? 'lab_order',
        priority:       'routine',
        assigned_department: departments[0] ?? 'primary_care',
        description:    '',
    });
    const [saving, setSaving] = useState(false);
    const [error, setError]   = useState('');

    const field = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }));

    const save = async () => {
        if (!form.participant_id || !form.description.trim()) {
            setError('Participant ID and description are required.');
            return;
        }
        setSaving(true); setError('');
        try {
            await axios.post('/sdrs', form);
            onSaved();
            onClose();
        } catch (e: any) {
            setError(e.response?.data?.message ?? 'Failed to submit SDR.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl shadow-xl w-full max-w-lg" data-testid="new-sdr-modal">
                <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h2 className="font-semibold text-slate-800">Submit Service Delivery Request</h2>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div className="px-6 py-5 space-y-4">
                    {error && <p className="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{error}</p>}

                    <div>
                        <label className="block text-xs font-medium text-slate-700 mb-1">
                            Participant ID <span className="text-red-500">*</span>
                        </label>
                        <input
                            type="number"
                            value={form.participant_id}
                            onChange={e => field('participant_id', e.target.value)}
                            placeholder="Enter participant ID"
                            data-testid="sdr-participant-input"
                            className="block w-full rounded-lg border-slate-300 text-sm focus:ring-blue-500 focus:border-blue-500 py-2 px-3 border"
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">Request Type</label>
                            <select
                                value={form.request_type}
                                onChange={e => field('request_type', e.target.value)}
                                data-testid="sdr-type-select"
                                className="block w-full rounded-lg border-slate-300 text-sm focus:ring-blue-500 focus:border-blue-500 py-2 px-3 border"
                            >
                                {requestTypes.map(t => (
                                    <option key={t} value={t}>{TYPE_LABELS[t] ?? t}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-slate-700 mb-1">Priority</label>
                            <select
                                value={form.priority}
                                onChange={e => field('priority', e.target.value)}
                                data-testid="sdr-priority-select"
                                className="block w-full rounded-lg border-slate-300 text-sm focus:ring-blue-500 focus:border-blue-500 py-2 px-3 border"
                            >
                                <option value="routine">Routine</option>
                                <option value="urgent">Urgent</option>
                                <option value="emergent">Emergent</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-slate-700 mb-1">Assign To Department</label>
                        <select
                            value={form.assigned_department}
                            onChange={e => field('assigned_department', e.target.value)}
                            data-testid="sdr-dept-select"
                            className="block w-full rounded-lg border-slate-300 text-sm focus:ring-blue-500 focus:border-blue-500 py-2 px-3 border"
                        >
                            {departments.map(d => (
                                <option key={d} value={d}>{deptLabel(d)}</option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-slate-700 mb-1">
                            Description <span className="text-red-500">*</span>
                        </label>
                        <textarea
                            value={form.description}
                            onChange={e => field('description', e.target.value)}
                            rows={4}
                            data-testid="sdr-description-textarea"
                            placeholder="Describe the service request, clinical context, and any urgency details…"
                            className="block w-full rounded-lg border-slate-300 text-sm focus:ring-blue-500 focus:border-blue-500 py-2 px-3 border resize-none"
                        />
                        <p className="text-[10px] text-slate-400 mt-0.5">
                            This request will be due within 72 hours of submission (CMS requirement).
                        </p>
                    </div>
                </div>
                <div className="px-6 py-4 border-t border-slate-100 flex justify-end gap-3">
                    <button onClick={onClose} className="px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 rounded-lg border border-slate-200">
                        Cancel
                    </button>
                    <button
                        onClick={save}
                        disabled={saving}
                        data-testid="submit-sdr-btn"
                        className="px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                    >
                        {saving ? 'Submitting…' : 'Submit SDR'}
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── SDR Card ─────────────────────────────────────────────────────────────────

function SdrCard({ sdr, onUpdated }: { sdr: SdrItem; onUpdated: () => void }) {
    const [updating, setUpdating] = useState(false);
    const hrs = hoursRemaining(sdr.due_at);

    const updateStatus = async (status: string) => {
        setUpdating(true);
        try {
            await axios.patch(`/sdrs/${sdr.id}`, { status });
            onUpdated();
        } catch { /* ignore */ } finally {
            setUpdating(false);
        }
    };

    return (
        <div
            data-testid={`sdr-card-${sdr.id}`}
            className={`rounded-xl border bg-white p-4 space-y-3 ${sdr.escalated ? 'border-red-300' : 'border-slate-200'}`}
        >
            {/* Top row */}
            <div className="flex items-start justify-between gap-2">
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <span className="font-semibold text-sm text-slate-800 truncate">
                            {sdr.participant.first_name} {sdr.participant.last_name}
                        </span>
                        <span className="text-xs text-slate-400">· {sdr.participant.mrn}</span>
                        {sdr.escalated && (
                            <span className="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-red-100 text-red-700 rounded text-[10px] font-bold ring-1 ring-red-300">
                                ⚠ ESCALATED
                            </span>
                        )}
                    </div>
                    <p className="text-xs text-slate-500 mt-0.5">
                        {TYPE_LABELS[sdr.request_type] ?? sdr.request_type}
                        {' · '}
                        <span className="text-slate-400">
                            {deptLabel(sdr.requesting_department)} → {deptLabel(sdr.assigned_department)}
                        </span>
                    </p>
                </div>

                {/* Priority + time */}
                <div className="flex flex-col items-end gap-1 shrink-0">
                    <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset ${PRIORITY_CLASSES[sdr.priority] ?? ''}`}>
                        {sdr.priority.toUpperCase()}
                    </span>
                    {!['completed', 'cancelled'].includes(sdr.status) && (
                        <span className={`inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold ${urgencyColor(hrs)}`}>
                            {formatHours(hrs)}
                        </span>
                    )}
                </div>
            </div>

            {/* Description */}
            <p className="text-xs text-slate-600 line-clamp-2">{sdr.description}</p>

            {/* Footer row */}
            <div className="flex items-center justify-between gap-2">
                <span className={`inline-flex items-center rounded px-2 py-0.5 text-[11px] font-medium ${STATUS_CLASSES[sdr.status] ?? ''}`}>
                    {sdr.status.replace('_', ' ')}
                </span>

                {!['completed', 'cancelled'].includes(sdr.status) && (
                    <div className="flex items-center gap-1.5">
                        {sdr.status === 'submitted' && (
                            <button
                                onClick={() => updateStatus('acknowledged')}
                                disabled={updating}
                                data-testid={`ack-sdr-${sdr.id}`}
                                className="px-2 py-1 text-[11px] font-medium border border-slate-300 text-slate-700 rounded hover:bg-slate-50 disabled:opacity-50"
                            >
                                Acknowledge
                            </button>
                        )}
                        {['submitted', 'acknowledged'].includes(sdr.status) && (
                            <button
                                onClick={() => updateStatus('in_progress')}
                                disabled={updating}
                                data-testid={`progress-sdr-${sdr.id}`}
                                className="px-2 py-1 text-[11px] font-medium bg-amber-600 text-white rounded hover:bg-amber-700 disabled:opacity-50"
                            >
                                In Progress
                            </button>
                        )}
                        {sdr.status === 'in_progress' && (
                            <button
                                onClick={() => updateStatus('completed')}
                                disabled={updating}
                                data-testid={`complete-sdr-${sdr.id}`}
                                className="px-2 py-1 text-[11px] font-medium bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50"
                            >
                                Complete
                            </button>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

// ── SDR List (empty state or grid of cards) ───────────────────────────────────

function SdrList({ sdrs, label, onUpdated }: { sdrs: SdrItem[]; label: string; onUpdated: () => void }) {
    if (sdrs.length === 0) {
        return (
            <div className="rounded-xl border border-slate-200 bg-white px-6 py-10 text-center text-sm text-slate-500">
                No {label.toLowerCase()} at this time.
            </div>
        );
    }
    return (
        <div className="grid grid-cols-1 xl:grid-cols-2 gap-3">
            {sdrs.map(sdr => (
                <SdrCard key={sdr.id} sdr={sdr} onUpdated={onUpdated} />
            ))}
        </div>
    );
}

// ── Tabs ─────────────────────────────────────────────────────────────────────

type TabKey = 'my_dept' | 'assigned_to_me' | 'overdue' | 'all';

// ── Page ─────────────────────────────────────────────────────────────────────

export default function SdrsIndex() {
    const {
        myDeptSdrs, assignedToMe, overdueSdrs, allSdrs,
        userDept, requestTypes, departments,
    } = usePage<SdrsPageProps>().props;

    const [tab, setTab]           = useState<TabKey>('my_dept');
    const [showNew, setShowNew]   = useState(false);

    const reload = () => router.reload();

    const TABS: Array<{ key: TabKey; label: string; count: number | null; visible: boolean }> = [
        { key: 'my_dept',       label: 'My Department', count: myDeptSdrs.length,   visible: true },
        { key: 'assigned_to_me',label: 'Assigned to Me',count: assignedToMe.length, visible: true },
        { key: 'overdue',       label: 'Overdue',       count: overdueSdrs.length,  visible: true },
        { key: 'all',           label: 'All SDRs',      count: allSdrs ? (allSdrs.data?.length ?? 0) : null, visible: userDept === 'qa_compliance' },
    ];

    const activeSdrs: SdrItem[] = (() => {
        if (tab === 'my_dept')        return myDeptSdrs;
        if (tab === 'assigned_to_me') return assignedToMe;
        if (tab === 'overdue')        return overdueSdrs;
        if (tab === 'all')            return allSdrs?.data ?? [];
        return [];
    })();

    return (
        <AppShell breadcrumbs={[{ label: 'Service Delivery Requests' }]}>
            <Head title="SDRs — Service Delivery Requests" />

            {/* ── Header ──────────────────────────────────────────────────── */}
            <div className="flex items-center justify-between mb-5">
                <div>
                    <h1 className="text-xl font-bold text-slate-900">Service Delivery Requests</h1>
                    <p className="text-sm text-slate-500 mt-0.5">
                        72-hour completion window · Requests are escalated automatically when overdue
                    </p>
                </div>
                <button
                    onClick={() => setShowNew(true)}
                    data-testid="new-sdr-trigger"
                    className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-sm"
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New SDR
                </button>
            </div>

            {/* Overdue banner */}
            {overdueSdrs.length > 0 && tab !== 'overdue' && (
                <div
                    data-testid="overdue-banner"
                    className="mb-4 flex items-center gap-3 bg-red-50 border border-red-200 rounded-xl px-4 py-3"
                >
                    <svg className="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <div className="flex-1">
                        <p className="text-sm font-semibold text-red-800">
                            {overdueSdrs.length} SDR{overdueSdrs.length !== 1 ? 's' : ''} past the 72-hour window
                        </p>
                        <p className="text-xs text-red-700">These have been escalated and flagged for QA review.</p>
                    </div>
                    <button
                        onClick={() => setTab('overdue')}
                        className="text-xs font-medium text-red-700 hover:text-red-900 underline shrink-0"
                    >
                        View overdue →
                    </button>
                </div>
            )}

            {/* ── Tabs ──────────────────────────────────────────────────────── */}
            <div className="border-b border-slate-200 mb-5" data-testid="sdr-tabs">
                <nav className="flex gap-1" aria-label="SDR tabs">
                    {TABS.filter(t => t.visible).map(t => (
                        <button
                            key={t.key}
                            onClick={() => setTab(t.key)}
                            data-testid={`tab-${t.key}`}
                            className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors -mb-px ${
                                tab === t.key
                                    ? 'border-blue-600 text-blue-700'
                                    : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'
                            }`}
                        >
                            {t.label}
                            {t.count !== null && t.count > 0 && (
                                <span className={`ml-1.5 inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold min-w-[18px] ${
                                    t.key === 'overdue'
                                        ? 'bg-red-100 text-red-700'
                                        : 'bg-slate-100 text-slate-600'
                                }`}>
                                    {t.count}
                                </span>
                            )}
                        </button>
                    ))}
                </nav>
            </div>

            {/* ── SDR list ──────────────────────────────────────────────────── */}
            <SdrList
                sdrs={activeSdrs}
                label={TABS.find(t => t.key === tab)?.label ?? ''}
                onUpdated={reload}
            />

            {/* ── New SDR modal ─────────────────────────────────────────────── */}
            {showNew && (
                <NewSdrModal
                    requestTypes={requestTypes ?? Object.keys(TYPE_LABELS)}
                    departments={departments ?? ['primary_care', 'social_work', 'therapies', 'pharmacy', 'idt']}
                    onClose={() => setShowNew(false)}
                    onSaved={reload}
                />
            )}
        </AppShell>
    );
}
