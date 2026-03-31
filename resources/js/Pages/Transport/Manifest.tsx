// ─── Transport Manifest ────────────────────────────────────────────────────────
// Daily run-sheet for the transportation team. Shows all scheduled transport
// requests for a selected date/site with real-time status updates via Reverb.
//
// Layout:
//   - Date picker + site filter (top bar)
//   - Tab: Run Sheet | Add-On Queue
//   - Run Sheet: table (participant, flags, times, locations, status, driver notes)
//   - Add-On Queue: pending add_on trip requests awaiting transport approval
//   - Row colors by status (green = completed, red = no_show, amber = en_route, etc.)
//   - Export PDF: window.print() with @media print hidden nav
//
// Data loading:
//   - Initial page render: receives `sites` prop from controller
//   - Run sheet rows: fetched via axios GET /transport/manifest/runs?date=&site_id=
//   - Reverb subscription: tenant channel, listens for .transport.status_updated
//   - Add-On queue: fetched via axios GET /transport/add-ons/pending
//
// Key interactions:
//   - Date or site change → re-fetch runs
//   - Status badge click → quick status update (transport team only)
//   - "New Add-On" button → AddOnModal (participant search + time/locations)
//   - "Approve" on pending add-on → PUT /transport/add-ons/{id} { status: 'scheduled' }
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect, useCallback, useRef } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import ComingSoonBanner from '@/Components/ComingSoonBanner';
import { PageProps } from '@/types';
import {
    UserIcon,
    MinusSmallIcon,
    BeakerIcon,
    ExclamationTriangleIcon,
    ClipboardDocumentListIcon,
} from '@heroicons/react/24/outline';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Site {
    id: number;
    name: string;
}

interface ManifestFlag {
    flag_type: string;
    severity: string;
    description: string | null;
}

interface ManifestRun {
    id: number;
    participant_id: number;
    participant_name: string;
    mrn: string;
    trip_type: string;
    requested_pickup_time: string;
    scheduled_pickup_time: string | null;
    actual_pickup_time: string | null;
    actual_dropoff_time: string | null;
    pickup_location: string;
    dropoff_location: string;
    status: string;
    special_instructions: string | null;
    driver_notes: string | null;
    mobility_flags: ManifestFlag[];
    transport_trip_id: number | null;
}

interface PendingAddOn {
    id: number;
    participant_id: number;
    participant_name: string;
    mrn: string;
    requested_pickup_time: string;
    pickup_location: string;
    dropoff_location: string;
    special_instructions: string | null;
    mobility_flags: ManifestFlag[];
    requesting_user: string;
    requesting_department: string;
    created_at: string;
}

interface AddOnForm {
    participant_id: number | null;
    participant_name: string;
    requested_pickup_time: string;
    pickup_location_id: number | '';
    dropoff_location_id: number | '';
    special_instructions: string;
}

interface Location {
    id: number;
    name: string;
    label: string | null;
    location_type: string;
}

interface ManifestProps extends PageProps {
    sites: Site[];
}

// ── Constants ─────────────────────────────────────────────────────────────────

const STATUS_CONFIG: Record<string, { label: string; rowClass: string; badgeClass: string }> = {
    requested:  { label: 'Requested',  rowClass: 'border-l-slate-300',  badgeClass: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400' },
    scheduled:  { label: 'Scheduled',  rowClass: 'border-l-blue-400',   badgeClass: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300' },
    dispatched: { label: 'Dispatched', rowClass: 'border-l-indigo-400', badgeClass: 'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300' },
    en_route:   { label: 'En Route',   rowClass: 'border-l-amber-400',  badgeClass: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' },
    arrived:    { label: 'Arrived',    rowClass: 'border-l-teal-400',   badgeClass: 'bg-teal-100 text-teal-700 dark:text-teal-300' },
    completed:  { label: 'Completed',  rowClass: 'border-l-green-500',  badgeClass: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' },
    no_show:    { label: 'No Show',    rowClass: 'border-l-red-500 bg-red-50 dark:bg-red-950/60/40', badgeClass: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' },
    cancelled:  { label: 'Cancelled',  rowClass: 'border-l-slate-200 opacity-60', badgeClass: 'bg-slate-100 dark:bg-slate-800 text-slate-400' },
};

const TRIP_TYPE_LABELS: Record<string, string> = {
    to_center:     'To Center',
    from_center:   'From Center',
    external_appt: 'External Appt',
    will_call:     'Will Call',
    add_on:        'Add-On',
};

const FLAG_ICONS: Record<string, React.ReactNode> = {
    wheelchair: <UserIcon className="w-3 h-3" />,
    stretcher:  <MinusSmallIcon className="w-3 h-3" />,
    oxygen:     <BeakerIcon className="w-3 h-3" />,
    behavioral: <ExclamationTriangleIcon className="w-3 h-3" />,
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function todayISO(): string {
    return new Date().toISOString().slice(0, 10);
}

function fmtTime(iso: string | null): string {
    if (!iso) return '-';
    const d = new Date(iso);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function FlagPills({ flags }: { flags: ManifestFlag[] }) {
    if (!flags.length) return <span className="text-slate-300 text-xs">-</span>;
    return (
        <div className="flex flex-wrap gap-1">
            {flags.map((f, i) => (
                <span
                    key={i}
                    title={f.description ?? f.flag_type}
                    className={`inline-flex items-center gap-0.5 text-[11px] px-1.5 py-0.5 rounded font-medium ring-1 ring-inset ${
                        f.flag_type === 'behavioral'
                            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 ring-red-600/20'
                            : 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 ring-blue-600/20'
                    }`}
                >
                    {FLAG_ICONS[f.flag_type] ?? null} {f.flag_type}
                </span>
            ))}
        </div>
    );
}

function StatusBadge({ status }: { status: string }) {
    const cfg = STATUS_CONFIG[status] ?? STATUS_CONFIG['requested'];
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${cfg.badgeClass}`}>
            {cfg.label}
        </span>
    );
}

// ── Add-On Modal ──────────────────────────────────────────────────────────────

function AddOnModal({
    onClose,
    onSubmitted,
}: {
    onClose: () => void;
    onSubmitted: () => void;
}) {
    const [form, setForm] = useState<AddOnForm>({
        participant_id: null,
        participant_name: '',
        requested_pickup_time: '',
        pickup_location_id: '',
        dropoff_location_id: '',
        special_instructions: '',
    });
    const [participantSearch, setParticipantSearch] = useState('');
    const [participantResults, setParticipantResults] = useState<{ id: number; name: string; mrn: string }[]>([]);
    const [locations, setLocations] = useState<Location[]>([]);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');

    // Load locations for pickup/dropoff selects
    useEffect(() => {
        axios.get('/locations').then(r => setLocations(r.data.data ?? r.data)).catch(() => {});
    }, []);

    // Participant typeahead — debounced 300ms
    useEffect(() => {
        if (participantSearch.length < 2) { setParticipantResults([]); return; }
        const t = setTimeout(() => {
            axios.get(`/participants/search?q=${encodeURIComponent(participantSearch)}&limit=8`)
                .then(r => setParticipantResults((r.data.data ?? r.data).map((p: { id: number; first_name: string; last_name: string; mrn: string }) => ({
                    id: p.id,
                    name: `${p.first_name} ${p.last_name}`,
                    mrn: p.mrn,
                }))))
                .catch(() => {});
        }, 300);
        return () => clearTimeout(t);
    }, [participantSearch]);

    function selectParticipant(p: { id: number; name: string; mrn: string }) {
        setForm(f => ({ ...f, participant_id: p.id, participant_name: p.name }));
        setParticipantSearch(p.name);
        setParticipantResults([]);
    }

    async function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!form.participant_id || !form.pickup_location_id || !form.dropoff_location_id || !form.requested_pickup_time) {
            setError('Please fill in all required fields.');
            return;
        }
        setSubmitting(true);
        setError('');
        try {
            await axios.post('/transport/add-ons', {
                participant_id: form.participant_id,
                pickup_location_id: form.pickup_location_id,
                dropoff_location_id: form.dropoff_location_id,
                requested_pickup_time: form.requested_pickup_time,
                trip_type: 'add_on',
                special_instructions: form.special_instructions || null,
            });
            onSubmitted();
            onClose();
        } catch (err: unknown) {
            const e = err as { response?: { data?: { message?: string; error?: string } } };
            setError(e.response?.data?.message ?? e.response?.data?.error ?? 'Failed to submit add-on request.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div
            className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
            onClick={e => { if (e.target === e.currentTarget) onClose(); }}
        >
            <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-lg p-6" data-testid="addon-modal">
                <div className="flex items-center justify-between mb-5">
                    <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">New Add-On Transport Request</h2>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-600 text-xl leading-none">×</button>
                </div>

                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Participant search */}
                    <div className="relative">
                        <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Participant *</label>
                        <input
                            type="text"
                            value={participantSearch}
                            onChange={e => { setParticipantSearch(e.target.value); setForm(f => ({ ...f, participant_id: null })); }}
                            placeholder="Search by name or MRN…"
                            className="w-full rounded-lg border border-slate-300 text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            data-testid="addon-participant-search"
                        />
                        {participantResults.length > 0 && (
                            <div className="absolute z-10 top-full left-0 right-0 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto">
                                {participantResults.map(p => (
                                    <button
                                        key={p.id}
                                        type="button"
                                        onClick={() => selectParticipant(p)}
                                        className="w-full text-left px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-700 flex items-center gap-2"
                                    >
                                        <span className="font-medium text-slate-800 dark:text-slate-200">{p.name}</span>
                                        <span className="text-xs text-slate-400">{p.mrn}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Pickup time */}
                    <div>
                        <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Requested Pickup Time *</label>
                        <input
                            type="datetime-local"
                            value={form.requested_pickup_time}
                            onChange={e => setForm(f => ({ ...f, requested_pickup_time: e.target.value }))}
                            className="w-full rounded-lg border border-slate-300 text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            data-testid="addon-pickup-time"
                        />
                    </div>

                    {/* Locations */}
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Pickup Location *</label>
                            <select
                                value={form.pickup_location_id}
                                onChange={e => setForm(f => ({ ...f, pickup_location_id: parseInt(e.target.value) || '' }))}
                                className="w-full rounded-lg border border-slate-300 text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500"
                                data-testid="addon-pickup-location"
                            >
                                <option value="">Select…</option>
                                {locations.map(l => (
                                    <option key={l.id} value={l.id}>{l.label ?? l.name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Drop-off Location *</label>
                            <select
                                value={form.dropoff_location_id}
                                onChange={e => setForm(f => ({ ...f, dropoff_location_id: parseInt(e.target.value) || '' }))}
                                className="w-full rounded-lg border border-slate-300 text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500"
                                data-testid="addon-dropoff-location"
                            >
                                <option value="">Select…</option>
                                {locations.map(l => (
                                    <option key={l.id} value={l.id}>{l.label ?? l.name}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {/* Special instructions */}
                    <div>
                        <label className="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Special Instructions</label>
                        <textarea
                            value={form.special_instructions}
                            onChange={e => setForm(f => ({ ...f, special_instructions: e.target.value }))}
                            rows={2}
                            placeholder="Mobility needs, timing requirements, etc."
                            className="w-full rounded-lg border border-slate-300 text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500 resize-none"
                            data-testid="addon-instructions"
                        />
                    </div>

                    {error && (
                        <p className="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/60 rounded-lg px-3 py-2">{error}</p>
                    )}

                    <div className="flex justify-end gap-2 pt-1">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            className="px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                            data-testid="addon-submit"
                        >
                            {submitting ? 'Submitting…' : 'Submit Request'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ── Run Sheet Tab ─────────────────────────────────────────────────────────────

function RunSheetTab({ runs, loading }: { runs: ManifestRun[]; loading: boolean }) {
    if (loading) {
        return (
            <div className="py-16 text-center text-sm text-slate-400">Loading run sheet…</div>
        );
    }

    const active = runs.filter(r => r.status !== 'cancelled');

    if (active.length === 0) {
        return (
            <div className="py-16 text-center text-sm text-slate-400">
                No transport runs scheduled for this date and site.
            </div>
        );
    }

    return (
        <div className="overflow-x-auto" data-testid="run-sheet-table">
            <table className="min-w-full divide-y divide-slate-100 dark:divide-slate-700 text-sm">
                <thead className="bg-slate-50 dark:bg-slate-900">
                    <tr>
                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Participant</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Mobility Flags</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Type</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Pickup Time</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Pickup Location</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Drop-off Location</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Status</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Driver Notes</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-700 bg-white dark:bg-slate-800">
                    {active.map(run => {
                        const cfg = STATUS_CONFIG[run.status] ?? STATUS_CONFIG['requested'];
                        return (
                            <tr
                                key={run.id}
                                className={`border-l-4 ${cfg.rowClass} hover:bg-slate-50 dark:hover:bg-slate-700/60 transition-colors`}
                                data-testid={`run-row-${run.id}`}
                            >
                                <td className="px-4 py-3">
                                    <p className="font-medium text-slate-800 dark:text-slate-200">{run.participant_name}</p>
                                    <p className="text-xs text-slate-400">{run.mrn}</p>
                                </td>
                                <td className="px-4 py-3">
                                    {/* Snapshot flags shown as-of request time (not current flags) */}
                                    <FlagPills flags={run.mobility_flags} />
                                </td>
                                <td className="px-4 py-3 text-slate-600 dark:text-slate-400 text-xs">
                                    {TRIP_TYPE_LABELS[run.trip_type] ?? run.trip_type}
                                </td>
                                <td className="px-4 py-3 text-slate-700 dark:text-slate-300 font-mono text-xs">
                                    {/* Show scheduled time if set, otherwise requested time */}
                                    {run.scheduled_pickup_time
                                        ? <span title={`Requested: ${fmtTime(run.requested_pickup_time)}`}>{fmtTime(run.scheduled_pickup_time)}</span>
                                        : <span className="text-slate-400" title="Not yet scheduled">{fmtTime(run.requested_pickup_time)} *</span>
                                    }
                                    {run.actual_pickup_time && (
                                        <p className="text-[10px] text-green-600 dark:text-green-400">Actual: {fmtTime(run.actual_pickup_time)}</p>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-slate-600 dark:text-slate-400 text-xs">{run.pickup_location}</td>
                                <td className="px-4 py-3 text-slate-600 dark:text-slate-400 text-xs">{run.dropoff_location}</td>
                                <td className="px-4 py-3">
                                    <StatusBadge status={run.status} />
                                </td>
                                <td className="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 max-w-[200px] truncate" title={run.driver_notes ?? ''}>
                                    {run.special_instructions && (
                                        <p className="text-slate-400 mb-0.5" title={run.special_instructions}>
                                            <ClipboardDocumentListIcon className="w-3 h-3 inline-block mr-0.5" />{run.special_instructions.slice(0, 60)}{run.special_instructions.length > 60 ? '…' : ''}
                                        </p>
                                    )}
                                    {run.driver_notes ?? '-'}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
            <p className="px-4 py-2 text-[11px] text-slate-400 bg-slate-50 dark:bg-slate-900 border-t border-slate-100 dark:border-slate-700">
                * Asterisk indicates unconfirmed requested time. Mobility flags shown as captured at time of request.
            </p>
        </div>
    );
}

// ── Add-On Queue Tab ──────────────────────────────────────────────────────────

function AddOnQueueTab({
    queue,
    loading,
    isTransportTeam,
    onApprove,
    onDeny,
}: {
    queue: PendingAddOn[];
    loading: boolean;
    isTransportTeam: boolean;
    onApprove: (id: number) => void;
    onDeny: (id: number) => void;
}) {
    if (loading) {
        return <div className="py-16 text-center text-sm text-slate-400">Loading add-on queue…</div>;
    }

    if (queue.length === 0) {
        return (
            <div className="py-16 text-center text-sm text-slate-400">
                No pending add-on requests.
            </div>
        );
    }

    return (
        <div className="divide-y divide-slate-100 dark:divide-slate-700" data-testid="addon-queue">
            {queue.map(item => (
                <div key={item.id} className="px-5 py-4 flex items-start gap-4 hover:bg-slate-50 dark:hover:bg-slate-700/60">
                    <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-1">
                            <p className="font-medium text-slate-800 dark:text-slate-200">{item.participant_name}</p>
                            <span className="text-xs text-slate-400">{item.mrn}</span>
                            <span className="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300">Add-On</span>
                        </div>
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400 mb-1.5">
                            <span>Pickup: <strong className="text-slate-700 dark:text-slate-300">{fmtTime(item.requested_pickup_time)}</strong></span>
                            <span>From: <strong className="text-slate-700 dark:text-slate-300">{item.pickup_location}</strong></span>
                            <span>To: <strong className="text-slate-700 dark:text-slate-300">{item.dropoff_location}</strong></span>
                        </div>
                        <FlagPills flags={item.mobility_flags} />
                        {item.special_instructions && (
                            <p className="mt-1.5 text-xs text-slate-400 italic">"{item.special_instructions}"</p>
                        )}
                        <p className="mt-1 text-[11px] text-slate-400">
                            Requested by {item.requesting_user} ({item.requesting_department.replace('_', ' ')})
                        </p>
                    </div>
                    {/* Only transportation team can approve/deny add-on requests */}
                    {isTransportTeam && (
                        <div className="flex gap-2 shrink-0">
                            <button
                                onClick={() => onApprove(item.id)}
                                className="px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
                                data-testid={`approve-addon-${item.id}`}
                            >
                                Approve
                            </button>
                            <button
                                onClick={() => onDeny(item.id)}
                                className="px-3 py-1.5 text-xs font-medium border border-red-300 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-50 transition-colors"
                                data-testid={`deny-addon-${item.id}`}
                            >
                                Deny
                            </button>
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function Manifest() {
    const { sites, auth } = usePage<ManifestProps>().props;

    // Determine if current user is transportation team
    const isTransportTeam = auth.user?.department === 'transportation';

    // ── Filter state ────────────────────────────────────────────────────────
    const [date, setDate]       = useState<string>(todayISO());
    const [siteId, setSiteId]   = useState<number | ''>(sites[0]?.id ?? '');
    const [activeTab, setActiveTab] = useState<'runsheet' | 'addons'>('runsheet');

    // ── Data state ──────────────────────────────────────────────────────────
    const [runs, setRuns]     = useState<ManifestRun[]>([]);
    const [queue, setQueue]   = useState<PendingAddOn[]>([]);
    const [runsLoading, setRunsLoading]   = useState(false);
    const [queueLoading, setQueueLoading] = useState(false);
    const [showAddOnModal, setShowAddOnModal] = useState(false);

    // ── Fetch run sheet ─────────────────────────────────────────────────────
    // Called on mount and whenever date/siteId changes
    const fetchRuns = useCallback(() => {
        if (!date || !siteId) return;
        setRunsLoading(true);
        axios.get('/transport/manifest/runs', { params: { date, site_id: siteId } })
            .then(r => setRuns(r.data.data ?? r.data))
            .catch(() => setRuns([]))
            .finally(() => setRunsLoading(false));
    }, [date, siteId]);

    useEffect(() => {
        fetchRuns();
    }, [fetchRuns]);

    // ── Fetch add-on queue ──────────────────────────────────────────────────
    // Called when the add-ons tab is first activated, or after an approval/denial
    const fetchQueue = useCallback(() => {
        setQueueLoading(true);
        axios.get('/transport/add-ons/pending')
            .then(r => setQueue(r.data.data ?? r.data))
            .catch(() => setQueue([]))
            .finally(() => setQueueLoading(false));
    }, []);

    // Load queue when tab activates for the first time
    const queueLoadedRef = useRef(false);
    useEffect(() => {
        if (activeTab === 'addons' && !queueLoadedRef.current) {
            queueLoadedRef.current = true;
            fetchQueue();
        }
    }, [activeTab, fetchQueue]);

    // ── Reverb real-time subscription ───────────────────────────────────────
    // Listen for transport status updates on the tenant channel.
    // When a status update arrives, refresh the run sheet in-place
    // (update the matching row without full re-fetch where possible).
    useEffect(() => {
        if (!window.Echo || !auth.user?.tenant?.id) return;

        const tenantId = auth.user.tenant.id;
        const channel = window.Echo.channel(`tenant.${tenantId}`);

        channel.listen('.transport.status_updated', (raw: unknown) => {
            const payload = raw as { transport_trip_id: number; status: string };
            // Update the matching run in-place
            setRuns(prev =>
                prev.map(r =>
                    r.transport_trip_id === payload.transport_trip_id
                        ? { ...r, status: payload.status }
                        : r
                )
            );
        });

        return () => {
            window.Echo?.leaveChannel(`tenant.${tenantId}`);
        };
    }, [auth.user?.tenant?.id]);

    // ── Add-On queue actions ─────────────────────────────────────────────────

    async function handleApprove(id: number) {
        try {
            await axios.put(`/transport/add-ons/${id}`, { status: 'scheduled' });
            // Remove from queue; refresh run sheet (it now appears as a scheduled run)
            setQueue(prev => prev.filter(q => q.id !== id));
            fetchRuns();
        } catch {
            // Could show toast here; for now silently log
        }
    }

    async function handleDeny(id: number) {
        try {
            await axios.post(`/transport/add-ons/${id}/cancel`, { cancellation_reason: 'Denied by transportation team' });
            setQueue(prev => prev.filter(q => q.id !== id));
        } catch {
            // silently ignore
        }
    }

    // ── Export PDF ──────────────────────────────────────────────────────────
    function handlePrint() {
        window.print();
    }

    // ── Summary stats for run sheet ─────────────────────────────────────────
    const stats = {
        total:     runs.filter(r => r.status !== 'cancelled').length,
        completed: runs.filter(r => r.status === 'completed').length,
        en_route:  runs.filter(r => r.status === 'en_route' || r.status === 'arrived').length,
        no_show:   runs.filter(r => r.status === 'no_show').length,
        pending:   queue.length,
    };

    return (
        <AppShell breadcrumbs={[{ label: 'Transportation' }, { label: 'Manifest' }]}>
            <Head title="Transport Manifest" />

            {/* ── Nostos Transport integration pending notice ──────────────────
                Live run-sheet data requires the Nostos transport platform
                integration. PACE staff can still submit transport requests via
                the participant profile (emr_transport_requests CRUD is active).
                ──────────────────────────────────────────────────────────────── */}
            <ComingSoonBanner
                title="Transport Manifest"
                section="Live Dispatch Sync: Nostos Integration Required"
                message="The transport manifest run-sheet requires a live connection to the Nostos transportation platform. A PACE-specific deployment of Nostos transport is required before real-time manifest data is available here. PACE staff can still submit transport requests from the participant profile and those requests will be queued for dispatch once the integration is active."
            />

            {/* Print-only styles — hides AppShell nav and shows only table */}
            <style>{`
                @media print {
                    [data-print-hide] { display: none !important; }
                    [data-print-show] { display: block !important; }
                }
            `}</style>

            {/* Header */}
            <div className="flex items-center justify-between mb-5" data-print-hide>
                <div>
                    <h1 className="text-xl font-bold text-slate-900 dark:text-slate-100">Transport Manifest</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        Daily run sheet · real-time status via Reverb
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {/* New Add-On button — any dept can submit */}
                    <button
                        onClick={() => setShowAddOnModal(true)}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                        data-testid="new-addon-btn"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        New Add-On
                    </button>
                    <button
                        onClick={handlePrint}
                        className="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium border border-slate-300 text-slate-700 dark:text-slate-300 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors"
                        data-testid="print-manifest-btn"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.056 48.056 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659" />
                        </svg>
                        Export PDF
                    </button>
                </div>
            </div>

            {/* Filters */}
            <div className="flex flex-wrap items-center gap-3 mb-5" data-print-hide>
                <div className="flex items-center gap-2">
                    <label className="text-xs font-medium text-slate-600 dark:text-slate-400">Date</label>
                    <input
                        type="date"
                        value={date}
                        onChange={e => setDate(e.target.value)}
                        className="rounded-lg border border-slate-300 text-sm py-1.5 px-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        data-testid="manifest-date-picker"
                    />
                </div>
                {sites.length > 1 && (
                    <div className="flex items-center gap-2">
                        <label className="text-xs font-medium text-slate-600 dark:text-slate-400">Site</label>
                        <select
                            value={siteId}
                            onChange={e => setSiteId(parseInt(e.target.value) || '')}
                            className="rounded-lg border border-slate-300 text-sm py-1.5 px-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            data-testid="manifest-site-filter"
                        >
                            {sites.map(s => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </select>
                    </div>
                )}
                <div className="ml-auto flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
                    <span><strong className="text-slate-700 dark:text-slate-300">{stats.total}</strong> trips</span>
                    <span><strong className="text-green-600 dark:text-green-400">{stats.completed}</strong> completed</span>
                    {stats.en_route > 0 && <span><strong className="text-amber-600 dark:text-amber-400">{stats.en_route}</strong> en route</span>}
                    {stats.no_show > 0 && <span><strong className="text-red-600 dark:text-red-400">{stats.no_show}</strong> no-show</span>}
                </div>
            </div>

            {/* Print header (shown only in print) */}
            <div className="hidden mb-4" data-print-show>
                <h1 className="text-lg font-bold">Transport Manifest: {date}</h1>
                <p className="text-sm text-slate-500 dark:text-slate-400">{sites.find(s => s.id === siteId)?.name ?? 'All Sites'}</p>
            </div>

            {/* Tabs */}
            <div className="border-b border-slate-200 dark:border-slate-700 mb-0" data-print-hide>
                <nav className="flex gap-1">
                    {[
                        { key: 'runsheet', label: 'Run Sheet', count: stats.total },
                        { key: 'addons',   label: 'Add-On Queue', count: stats.pending },
                    ].map(tab => (
                        <button
                            key={tab.key}
                            onClick={() => setActiveTab(tab.key as 'runsheet' | 'addons')}
                            className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
                                activeTab === tab.key
                                    ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                                    : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 hover:border-slate-300'
                            }`}
                            data-testid={`tab-${tab.key}`}
                        >
                            {tab.label}
                            {tab.count > 0 && (
                                <span className={`ml-1.5 inline-flex items-center justify-center rounded-full px-1.5 text-[10px] font-bold ${
                                    activeTab === tab.key ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'
                                }`}>
                                    {tab.count}
                                </span>
                            )}
                        </button>
                    ))}
                </nav>
            </div>

            {/* Tab content */}
            <div className="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 border-t-0 rounded-b-xl overflow-hidden">
                {activeTab === 'runsheet' ? (
                    <RunSheetTab runs={runs} loading={runsLoading} />
                ) : (
                    <AddOnQueueTab
                        queue={queue}
                        loading={queueLoading}
                        isTransportTeam={isTransportTeam}
                        onApprove={handleApprove}
                        onDeny={handleDeny}
                    />
                )}
            </div>

            {/* Add-On Modal */}
            {showAddOnModal && (
                <AddOnModal
                    onClose={() => setShowAddOnModal(false)}
                    onSubmitted={() => {
                        // Refresh queue count; if transport team, also refresh runs
                        fetchQueue();
                        if (isTransportTeam) fetchRuns();
                    }}
                />
            )}
        </AppShell>
    );
}
