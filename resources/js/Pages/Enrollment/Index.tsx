// ─── Enrollment Pipeline (Kanban) ─────────────────────────────────────────────
// CMS PACE enrollment pipeline board.
// Each column represents a stage in the enrollment state machine:
//   new → intake_scheduled → intake_in_progress → intake_complete
//   → eligibility_pending → pending_enrollment → enrolled
//
// Route:    GET /enrollment/referrals → Inertia::render('Enrollment/Index')
// Props:    pipeline (referrals grouped by status), statuses, sources, pipelineOrder
//
// Data loading: all pipeline referrals are passed as Inertia props (server-side).
//   Detail fetch (show modal) is lazy via GET /enrollment/referrals/{id}.
//
// Actions:
//   - "New Referral" button → NewReferralModal (POST /enrollment/referrals)
//   - Click card → ReferralDetailModal (GET + PUT + transition)
//   - Cards can be transitioned via dropdown in detail modal
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import {
    BuildingOffice2Icon,
    UserIcon,
    UsersIcon,
    HomeIcon,
    UserCircleIcon,
    ClipboardDocumentListIcon as ClipboardIcon,
} from '@heroicons/react/24/outline';

// ── Types ────────────────────────────────────────────────────────────────────

interface AssignedUser {
    id:         number;
    first_name: string;
    last_name:  string;
}

interface ReferralParticipant {
    id:         number;
    mrn:        string;
    first_name: string;
    last_name:  string;
}

interface Referral {
    id:                  number;
    referred_by_name:    string;
    referred_by_org:     string | null;
    referral_date:       string;
    referral_source:     string;
    status:              string;
    notes:               string | null;
    decline_reason:      string | null;
    withdrawn_reason:    string | null;
    assigned_to:         AssignedUser | null;
    participant:         ReferralParticipant | null;
    created_by:          AssignedUser | null;
}

interface EnrollmentIndexProps extends PageProps {
    pipeline:      Record<string, Referral[]>;
    statuses:      Record<string, string>;
    sources:       Record<string, string>;
    pipelineOrder: string[];
}

// ── Constants ─────────────────────────────────────────────────────────────────

/** Visual colour for each column header (Tailwind border-t class). */
const COLUMN_COLORS: Record<string, string> = {
    new:                 'border-gray-400',
    intake_scheduled:    'border-blue-400',
    intake_in_progress:  'border-indigo-500',
    intake_complete:     'border-violet-500',
    eligibility_pending: 'border-amber-500',
    pending_enrollment:  'border-orange-500',
    enrolled:            'border-green-600',
};

/** Bg colours for status badge in card footer. */
const BADGE_COLORS: Record<string, string> = {
    new:                 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300',
    intake_scheduled:    'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
    intake_in_progress:  'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300',
    intake_complete:     'bg-violet-100 dark:bg-violet-900/60 text-violet-700 dark:text-violet-300',
    eligibility_pending: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    pending_enrollment:  'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300',
    enrolled:            'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    declined:            'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    withdrawn:           'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
};

const SOURCE_ICONS: Record<string, React.ReactNode> = {
    hospital:  <BuildingOffice2Icon className="w-4 h-4 text-blue-500" />,
    physician: <UserIcon className="w-4 h-4 text-indigo-500" />,
    family:    <UsersIcon className="w-4 h-4 text-violet-500" />,
    community: <HomeIcon className="w-4 h-4 text-green-500" />,
    self:      <UserCircleIcon className="w-4 h-4 text-slate-500 dark:text-slate-400" />,
    other:     <ClipboardIcon className="w-4 h-4 text-slate-400" />,
};

// ── Sub-components ────────────────────────────────────────────────────────────

interface ReferralCardProps {
    referral: Referral;
    onClick:  (r: Referral) => void;
}

/** Single Kanban card for a referral. */
function ReferralCard({ referral, onClick }: ReferralCardProps) {
    const icon = SOURCE_ICONS[referral.referral_source] ?? <ClipboardIcon className="w-4 h-4 text-slate-400" />;
    return (
        <div
            className="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 shadow-sm p-3 cursor-pointer hover:shadow-md transition-shadow"
            onClick={() => onClick(referral)}
            data-testid="referral-card"
        >
            <div className="flex items-start justify-between gap-2">
                <p className="text-sm font-semibold text-gray-900 dark:text-slate-100 leading-tight">
                    {referral.referred_by_name}
                </p>
                <span className="shrink-0" title={referral.referral_source}>{icon}</span>
            </div>
            {referral.referred_by_org && (
                <p className="text-xs text-gray-500 dark:text-slate-400 mt-0.5 truncate">{referral.referred_by_org}</p>
            )}
            {referral.participant && (
                <p className="text-xs text-indigo-600 dark:text-indigo-400 mt-1 font-medium">
                    {referral.participant.first_name} {referral.participant.last_name} ({referral.participant.mrn})
                </p>
            )}
            <div className="mt-2 flex items-center justify-between gap-1">
                <span className="text-xs text-gray-400 dark:text-slate-500">{referral.referral_date}</span>
                {referral.assigned_to ? (
                    <span className="text-xs text-gray-600 dark:text-slate-300 bg-gray-100 dark:bg-slate-700 px-1.5 py-0.5 rounded">
                        {referral.assigned_to.first_name[0]}. {referral.assigned_to.last_name}
                    </span>
                ) : (
                    <span className="text-xs text-amber-500">Unassigned</span>
                )}
            </div>
        </div>
    );
}

interface KanbanColumnProps {
    status:    string;
    label:     string;
    referrals: Referral[];
    onClick:   (r: Referral) => void;
    onNew:     () => void;
    isFirst:   boolean;
}

/** A single Kanban column. */
function KanbanColumn({ status, label, referrals, onClick, onNew, isFirst }: KanbanColumnProps) {
    const borderColor = COLUMN_COLORS[status] ?? 'border-gray-400';
    return (
        <div className="flex-shrink-0 w-56 flex flex-col">
            {/* Column header */}
            <div className={`border-t-4 ${borderColor} bg-gray-50 dark:bg-slate-700/50 rounded-t px-3 pt-2 pb-1`}>
                <div className="flex items-center justify-between">
                    <span className="text-xs font-semibold text-gray-700 dark:text-slate-300 uppercase tracking-wide truncate">
                        {label}
                    </span>
                    <span className="ml-1 text-xs text-gray-500 dark:text-slate-400 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-full px-1.5 py-0.5">
                        {referrals.length}
                    </span>
                </div>
                {/* Only the first column gets a quick "+" button */}
                {isFirst && (
                    <button
                        className="mt-1 text-xs text-blue-600 dark:text-blue-400 hover:text-blue-800"
                        onClick={onNew}
                        data-testid="new-referral-button"
                    >
                        + New Referral
                    </button>
                )}
            </div>

            {/* Card list */}
            <div className="flex-1 bg-gray-100 dark:bg-slate-700/50 rounded-b p-2 space-y-2 min-h-32 overflow-y-auto max-h-[calc(100vh-220px)]">
                {referrals.length === 0 ? (
                    <p className="text-xs text-gray-400 dark:text-slate-500 text-center py-4">No referrals</p>
                ) : (
                    referrals.map((ref) => (
                        <ReferralCard key={ref.id} referral={ref} onClick={onClick} />
                    ))
                )}
            </div>
        </div>
    );
}

// ── New Referral Modal ────────────────────────────────────────────────────────

interface NewReferralModalProps {
    sources:  Record<string, string>;
    onClose:  () => void;
    onSaved:  () => void;
}

function NewReferralModal({ sources, onClose, onSaved }: NewReferralModalProps) {
    const { auth } = usePage<PageProps>().props;
    const [form, setForm] = useState({
        referred_by_name: '',
        referred_by_org:  '',
        referral_date:    new Date().toISOString().slice(0, 10),
        referral_source:  'physician',
        notes:            '',
    });
    const [errors, setErrors]   = useState<Record<string, string>>({});
    const [saving, setSaving]   = useState(false);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await axios.post('/enrollment/referrals', {
                ...form,
                site_id: (auth.user as any).site_id,
            });
            onSaved();
        } catch (err: any) {
            if (err.response?.status === 422) {
                setErrors(err.response.data.errors ?? {});
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" data-testid="new-referral-modal">
            <div className="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md mx-4">
                <div className="flex items-center justify-between px-6 py-4 border-b dark:border-slate-700">
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-slate-100">New Referral</h2>
                    <button onClick={onClose} className="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300 text-xl">✕</button>
                </div>
                <form onSubmit={handleSubmit} className="px-6 py-4 space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Referred By <span className="text-red-500">*</span></label>
                        <input
                            className="w-full border border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value={form.referred_by_name}
                            onChange={e => setForm(f => ({ ...f, referred_by_name: e.target.value }))}
                            placeholder="Dr. Jane Smith"
                            data-testid="referred-by-name-input"
                        />
                        {errors.referred_by_name && <p className="text-red-500 text-xs mt-1">{errors.referred_by_name}</p>}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Organization</label>
                        <input
                            className="w-full border border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100 rounded-md px-3 py-2 text-sm"
                            value={form.referred_by_org}
                            onChange={e => setForm(f => ({ ...f, referred_by_org: e.target.value }))}
                            placeholder="Memorial Hospital"
                        />
                    </div>
                    <div className="flex gap-3">
                        <div className="flex-1">
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Referral Date <span className="text-red-500">*</span></label>
                            <input
                                type="date"
                                className="w-full border border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100 rounded-md px-3 py-2 text-sm"
                                value={form.referral_date}
                                onChange={e => setForm(f => ({ ...f, referral_date: e.target.value }))}
                                data-testid="referral-date-input"
                            />
                        </div>
                        <div className="flex-1">
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Source <span className="text-red-500">*</span></label>
                            <select
                                className="w-full border border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100 rounded-md px-3 py-2 text-sm"
                                value={form.referral_source}
                                onChange={e => setForm(f => ({ ...f, referral_source: e.target.value }))}
                                data-testid="referral-source-select"
                            >
                                {Object.entries(sources).map(([val, label]) => (
                                    <option key={val} value={val}>{label}</option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Notes</label>
                        <textarea
                            className="w-full border border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100 rounded-md px-3 py-2 text-sm resize-none"
                            rows={3}
                            value={form.notes}
                            onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
                        />
                    </div>
                    <div className="flex justify-end gap-3 pt-2">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200">Cancel</button>
                        <button
                            type="submit"
                            disabled={saving}
                            className="px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                            data-testid="save-referral-button"
                        >
                            {saving ? 'Saving…' : 'Create Referral'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ── Referral Detail Modal ─────────────────────────────────────────────────────

interface ReferralDetailModalProps {
    referral:  Referral;
    statuses:  Record<string, string>;
    sources:   Record<string, string>;
    onClose:   () => void;
    onUpdated: () => void;
}

function ReferralDetailModal({ referral, statuses, sources, onClose, onUpdated }: ReferralDetailModalProps) {
    const [transitioning, setTransitioning] = useState(false);
    const [transError, setTransError]       = useState('');
    const [newStatus, setNewStatus]         = useState('');
    const [declineReason, setDeclineReason] = useState('');
    const [withdrawnReason, setWithdrawnReason] = useState('');

    const isTerminal = ['enrolled', 'declined', 'withdrawn'].includes(referral.status);

    // Determine which transitions are available from current status
    const VALID_TRANSITIONS: Record<string, string[]> = {
        new:                 ['intake_scheduled', 'declined', 'withdrawn'],
        intake_scheduled:    ['intake_in_progress', 'declined', 'withdrawn'],
        intake_in_progress:  ['intake_complete', 'declined', 'withdrawn'],
        intake_complete:     ['eligibility_pending', 'declined', 'withdrawn'],
        eligibility_pending: ['pending_enrollment', 'declined', 'withdrawn'],
        pending_enrollment:  ['enrolled', 'declined', 'withdrawn'],
    };

    const availableNext = VALID_TRANSITIONS[referral.status] ?? [];

    const handleTransition = async () => {
        if (!newStatus) return;
        if (newStatus === 'declined' && !declineReason.trim()) {
            setTransError('Decline reason is required.');
            return;
        }
        setTransitioning(true);
        setTransError('');
        try {
            await axios.post(`/enrollment/referrals/${referral.id}/transition`, {
                new_status:       newStatus,
                decline_reason:   newStatus === 'declined'  ? declineReason   : undefined,
                withdrawn_reason: newStatus === 'withdrawn' ? withdrawnReason : undefined,
            });
            onUpdated();
        } catch (err: any) {
            setTransError(err.response?.data?.message ?? 'Transition failed.');
        } finally {
            setTransitioning(false);
        }
    };

    const badgeClass = BADGE_COLORS[referral.status] ?? 'bg-gray-100 text-gray-600';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40" data-testid="referral-detail-modal">
            <div className="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                {/* Header */}
                <div className="flex items-start justify-between px-6 py-4 border-b dark:border-slate-700">
                    <div>
                        <h2 className="text-lg font-semibold text-gray-900 dark:text-slate-100">{referral.referred_by_name}</h2>
                        {referral.referred_by_org && (
                            <p className="text-sm text-gray-500 dark:text-slate-400">{referral.referred_by_org}</p>
                        )}
                    </div>
                    <button onClick={onClose} className="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300 text-xl mt-1">✕</button>
                </div>

                <div className="px-6 py-4 space-y-4">
                    {/* Meta row */}
                    <div className="flex flex-wrap gap-3">
                        <span className={`inline-block text-xs font-semibold px-2 py-1 rounded-full ${badgeClass}`}>
                            {statuses[referral.status] ?? referral.status}
                        </span>
                        <span className="text-xs text-gray-500 dark:text-slate-400">
                            {sources[referral.referral_source] ?? referral.referral_source}
                        </span>
                        <span className="text-xs text-gray-500 dark:text-slate-400">Referral date: {referral.referral_date}</span>
                    </div>

                    {/* Linked participant */}
                    {referral.participant && (
                        <div className="bg-indigo-50 dark:bg-indigo-950/60 rounded-lg px-4 py-2 text-sm">
                            <span className="font-medium text-indigo-700 dark:text-indigo-300">Participant: </span>
                            <a
                                href={`/participants/${referral.participant.id}`}
                                className="text-indigo-600 hover:underline"
                            >
                                {referral.participant.first_name} {referral.participant.last_name} ({referral.participant.mrn})
                            </a>
                        </div>
                    )}

                    {/* Notes */}
                    {referral.notes && (
                        <div>
                            <p className="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-1">Notes</p>
                            <p className="text-sm text-gray-700 dark:text-slate-300 whitespace-pre-line">{referral.notes}</p>
                        </div>
                    )}

                    {/* Assigned to */}
                    <p className="text-sm text-gray-600 dark:text-slate-400">
                        <span className="font-medium">Assigned to: </span>
                        {referral.assigned_to
                            ? `${referral.assigned_to.first_name} ${referral.assigned_to.last_name}`
                            : <span className="text-amber-600 dark:text-amber-400">Unassigned</span>
                        }
                    </p>

                    {/* Terminal reason */}
                    {referral.status === 'declined' && referral.decline_reason && (
                        <div className="bg-red-50 dark:bg-red-950/60 rounded p-3 text-sm text-red-700 dark:text-red-300">
                            <span className="font-medium">Declined: </span>{referral.decline_reason.replace(/_/g, ' ')}
                        </div>
                    )}
                    {referral.status === 'withdrawn' && referral.withdrawn_reason && (
                        <div className="bg-slate-50 dark:bg-slate-900 rounded p-3 text-sm text-slate-700 dark:text-slate-300">
                            <span className="font-medium">Withdrawn: </span>{referral.withdrawn_reason}
                        </div>
                    )}

                    {/* Transition controls (only for non-terminal referrals) */}
                    {!isTerminal && availableNext.length > 0 && (
                        <div className="border-t dark:border-slate-700 pt-4">
                            <p className="text-sm font-medium text-gray-700 dark:text-slate-300 mb-2">Move to next stage</p>
                            <div className="flex gap-2 flex-wrap">
                                {availableNext.map(s => (
                                    <button
                                        key={s}
                                        onClick={() => setNewStatus(s === newStatus ? '' : s)}
                                        className={`text-xs px-3 py-1.5 rounded-full border transition-colors ${
                                            newStatus === s
                                                ? 'bg-blue-600 text-white border-blue-600'
                                                : 'bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700/50'
                                        }`}
                                        data-testid={`transition-btn-${s}`}
                                    >
                                        {statuses[s] ?? s}
                                    </button>
                                ))}
                            </div>

                            {newStatus === 'declined' && (
                                <div className="mt-3">
                                    <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Decline Reason <span className="text-red-500">*</span></label>
                                    <select
                                        className="mt-1 w-full border border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100 rounded-md px-3 py-1.5 text-sm"
                                        value={declineReason}
                                        onChange={e => setDeclineReason(e.target.value)}
                                        data-testid="decline-reason-select"
                                    >
                                        <option value="">Select a reason…</option>
                                        <option value="not_eligible_medicaid">Not eligible – Medicaid</option>
                                        <option value="not_eligible_medicare">Not eligible – Medicare</option>
                                        <option value="outside_service_area">Outside service area</option>
                                        <option value="declined_by_participant">Declined by participant</option>
                                        <option value="medical_ineligibility">Medical ineligibility</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            )}

                            {newStatus === 'withdrawn' && (
                                <div className="mt-3">
                                    <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Withdrawal Reason <span className="text-red-500">*</span></label>
                                    <input
                                        className="mt-1 w-full border border-gray-300 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100 rounded-md px-3 py-1.5 text-sm"
                                        value={withdrawnReason}
                                        onChange={e => setWithdrawnReason(e.target.value)}
                                        placeholder="Describe the reason for withdrawal…"
                                        data-testid="withdrawn-reason-input"
                                    />
                                </div>
                            )}

                            {transError && (
                                <p className="text-red-500 text-xs mt-2">{transError}</p>
                            )}

                            {newStatus && (
                                <button
                                    onClick={handleTransition}
                                    disabled={transitioning}
                                    className="mt-3 w-full px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                                    data-testid="confirm-transition-button"
                                >
                                    {transitioning ? 'Moving…' : `Move to ${statuses[newStatus] ?? newStatus}`}
                                </button>
                            )}
                        </div>
                    )}
                </div>

                <div className="px-6 py-3 bg-gray-50 dark:bg-slate-700/50 rounded-b-xl flex justify-end">
                    <button onClick={onClose} className="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200">
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function EnrollmentIndex({ pipeline, statuses, sources, pipelineOrder }: EnrollmentIndexProps) {
    const [selectedReferral, setSelectedReferral] = useState<Referral | null>(null);
    const [showNewModal, setShowNewModal]         = useState(false);

    // Reload the pipeline from the server after any mutation
    const reload = () => {
        router.reload({ only: ['pipeline'] });
        setSelectedReferral(null);
        setShowNewModal(false);
    };

    const totalActive = pipelineOrder
        .filter(s => s !== 'enrolled')
        .reduce((sum, s) => sum + (pipeline[s]?.length ?? 0), 0);

    return (
        <AppShell>
            <Head title="Enrollment Pipeline" />

            {/* ── Page header ──────────────────────────────────────────────── */}
            <div className="flex items-center justify-between px-6 py-4 border-b bg-white dark:bg-slate-800">
                <div>
                    <h1 className="text-xl font-bold text-gray-900 dark:text-slate-100">Enrollment Pipeline</h1>
                    <p className="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                        {totalActive} active referral{totalActive !== 1 ? 's' : ''} in progress
                        {pipeline['enrolled']?.length
                            ? ` · ${pipeline['enrolled'].length} enrolled this cycle`
                            : ''}
                    </p>
                </div>
                <button
                    onClick={() => setShowNewModal(true)}
                    className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
                    data-testid="new-referral-header-button"
                >
                    <span className="text-base leading-none">+</span> New Referral
                </button>
            </div>

            {/* ── Kanban board ─────────────────────────────────────────────── */}
            <div className="flex gap-4 px-4 py-4 overflow-x-auto items-start">
                {pipelineOrder.map((status, idx) => (
                    <KanbanColumn
                        key={status}
                        status={status}
                        label={statuses[status] ?? status}
                        referrals={pipeline[status] ?? []}
                        onClick={setSelectedReferral}
                        onNew={() => setShowNewModal(true)}
                        isFirst={idx === 0}
                    />
                ))}
            </div>

            {/* ── Modals ───────────────────────────────────────────────────── */}
            {showNewModal && (
                <NewReferralModal
                    sources={sources}
                    onClose={() => setShowNewModal(false)}
                    onSaved={reload}
                />
            )}

            {selectedReferral && (
                <ReferralDetailModal
                    referral={selectedReferral}
                    statuses={statuses}
                    sources={sources}
                    onClose={() => setSelectedReferral(null)}
                    onUpdated={reload}
                />
            )}
        </AppShell>
    );
}
