// ─── Schedule/Index — Department Calendar ─────────────────────────────────────
// Week-view calendar showing all appointments across the PACE program.
//
// Layout:
//   Header: week navigator (prev/next/today) + filter bar (type, provider, view)
//   Body: 7-column week grid, each column = one day, rows = hours (8am–6pm)
//   Each appointment rendered as a colored block at its time position.
//   Click appointment block → slide-over detail panel (right side)
//   "New Appointment" button → multi-step booking modal
//
// Data loading:
//   appointmentTypes + typeLabels + typeColors: passed as Inertia props (static)
//   locations: passed as Inertia props (small list, used in booking modal)
//   Actual appointments: fetched via axios GET /schedule/appointments?start_date=&end_date=
//     on mount and when week changes. Loaded client-side to keep initial page fast.
//
// Conflict detection: handled server-side. 409 response shows inline error in modal.
//
// Route: GET /schedule → Inertia::render('Schedule/Index')
// Props: appointmentTypes, typeLabels, typeColors, locations
// ──────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface LocationSummary {
    id: number;
    name: string;
    location_type: string;
}

interface ParticipantSummary {
    id: number;
    mrn: string;
    first_name: string;
    last_name: string;
}

interface ProviderSummary {
    id: number;
    first_name: string;
    last_name: string;
}

interface AppointmentItem {
    id: number;
    appointment_type: string;
    scheduled_start: string;
    scheduled_end: string;
    status: 'scheduled' | 'confirmed' | 'completed' | 'cancelled' | 'no_show';
    transport_required: boolean;
    notes: string | null;
    cancellation_reason: string | null;
    participant: ParticipantSummary | null;
    provider: ProviderSummary | null;
    location: LocationSummary | null;
}

interface SchedulePageProps extends PageProps {
    appointmentTypes: string[];
    typeLabels: Record<string, string>;
    typeColors: Record<string, string>;
    locations: LocationSummary[];
}

// ── Constants ─────────────────────────────────────────────────────────────────

// Map Tailwind color names to actual classes (Tailwind purging requires static class strings)
const COLOR_CLASS_MAP: Record<string, { bg: string; border: string; text: string }> = {
    blue:    { bg: 'bg-blue-100',    border: 'border-blue-400',    text: 'text-blue-800' },
    green:   { bg: 'bg-green-100',   border: 'border-green-400',   text: 'text-green-800' },
    emerald: { bg: 'bg-emerald-100', border: 'border-emerald-400', text: 'text-emerald-800' },
    teal:    { bg: 'bg-teal-100',    border: 'border-teal-400',    text: 'text-teal-800' },
    purple:  { bg: 'bg-purple-100',  border: 'border-purple-400',  text: 'text-purple-800' },
    violet:  { bg: 'bg-violet-100',  border: 'border-violet-400',  text: 'text-violet-800' },
    orange:  { bg: 'bg-orange-100',  border: 'border-orange-400',  text: 'text-orange-800' },
    amber:   { bg: 'bg-amber-100',   border: 'border-amber-400',   text: 'text-amber-800' },
    rose:    { bg: 'bg-rose-100',    border: 'border-rose-400',    text: 'text-rose-800' },
    pink:    { bg: 'bg-pink-100',    border: 'border-pink-400',    text: 'text-pink-800' },
    slate:   { bg: 'bg-slate-100',   border: 'border-slate-400',   text: 'text-slate-800' },
    gray:    { bg: 'bg-gray-100',    border: 'border-gray-400',    text: 'text-gray-800' },
    indigo:  { bg: 'bg-indigo-100',  border: 'border-indigo-400',  text: 'text-indigo-800' },
    cyan:    { bg: 'bg-cyan-100',    border: 'border-cyan-400',    text: 'text-cyan-800' },
    lime:    { bg: 'bg-lime-100',    border: 'border-lime-400',    text: 'text-lime-800' },
};

const HOUR_START = 8;  // 8 AM
const HOUR_END   = 18; // 6 PM
const HOURS      = Array.from({ length: HOUR_END - HOUR_START }, (_, i) => HOUR_START + i);
const DAY_NAMES  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// ── Helpers ───────────────────────────────────────────────────────────────────

function getWeekStart(date: Date): Date {
    const d = new Date(date);
    const day = d.getDay(); // 0 = Sunday
    d.setDate(d.getDate() - day);
    d.setHours(0, 0, 0, 0);
    return d;
}

function addDays(date: Date, days: number): Date {
    const d = new Date(date);
    d.setDate(d.getDate() + days);
    return d;
}

function formatDateParam(date: Date): string {
    return date.toISOString().split('T')[0];
}

function formatDisplayDate(date: Date): string {
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function parseTime(isoString: string): Date {
    return new Date(isoString);
}

/**
 * Calculate top offset (%) and height (%) for an appointment block
 * within a single day column (HOUR_START to HOUR_END).
 */
function getTimePosition(start: Date, end: Date): { top: number; height: number } {
    const totalMinutes = (HOUR_END - HOUR_START) * 60;
    // Use UTC hours/minutes: appointment datetimes are stored as UTC wall-clock
    // times (e.g. "10:00Z" means "10 AM" regardless of the client's local timezone).
    const startMinutes = (start.getUTCHours() - HOUR_START) * 60 + start.getUTCMinutes();
    const endMinutes   = (end.getUTCHours()   - HOUR_START) * 60 + end.getUTCMinutes();

    const clampedStart = Math.max(0, startMinutes);
    const clampedEnd   = Math.min(totalMinutes, endMinutes);

    return {
        top:    (clampedStart / totalMinutes) * 100,
        height: Math.max(2, ((clampedEnd - clampedStart) / totalMinutes) * 100),
    };
}

/**
 * Compute side-by-side column layout for appointments that overlap in time.
 * Returns each appointment annotated with colIndex (0-based) and colCount
 * (total overlapping columns in its cluster) so blocks render side by side
 * instead of stacked on top of each other.
 */
function layoutDayAppointments(
    appts: AppointmentItem[],
): Array<AppointmentItem & { colIndex: number; colCount: number }> {
    const sorted = [...appts].sort(
        (a, b) => new Date(a.scheduled_start).getTime() - new Date(b.scheduled_start).getTime(),
    );

    // Greedy column assignment: put each appointment in the first column whose
    // last occupant has already ended.
    const colEnds: Date[] = [];
    const withCol = sorted.map(appt => {
        const start = new Date(appt.scheduled_start);
        const end   = new Date(appt.scheduled_end);
        let col = colEnds.findIndex(colEnd => colEnd <= start);
        if (col === -1) { col = colEnds.length; colEnds.push(end); }
        else { colEnds[col] = end; }
        return { ...appt, colIndex: col, colCount: 0 };
    });

    // Second pass: colCount = widest overlap cluster that includes this appointment.
    return withCol.map(appt => {
        const s = new Date(appt.scheduled_start);
        const e = new Date(appt.scheduled_end);
        let maxCol = appt.colIndex;
        withCol.forEach(other => {
            if (new Date(other.scheduled_start) < e && new Date(other.scheduled_end) > s) {
                maxCol = Math.max(maxCol, other.colIndex);
            }
        });
        return { ...appt, colCount: maxCol + 1 };
    });
}

// ── Booking Modal ─────────────────────────────────────────────────────────────

interface BookingModalProps {
    onClose: () => void;
    onCreated: (appt: AppointmentItem) => void;
    appointmentTypes: string[];
    typeLabels: Record<string, string>;
    locations: LocationSummary[];
    defaultDate?: string;
}

function BookingModal({ onClose, onCreated, appointmentTypes, typeLabels, locations, defaultDate }: BookingModalProps) {
    const [step, setStep] = useState<1 | 2>(1);
    const [participantSearch, setParticipantSearch] = useState('');
    const [participantResults, setParticipantResults] = useState<ParticipantSummary[]>([]);
    const [selectedParticipant, setSelectedParticipant] = useState<ParticipantSummary | null>(null);
    const [form, setForm] = useState({
        appointment_type:  appointmentTypes[0] || 'clinic_visit',
        scheduled_start:   defaultDate ? `${defaultDate}T09:00` : '',
        scheduled_end:     defaultDate ? `${defaultDate}T10:00` : '',
        location_id:       '',
        transport_required: false,
        notes:             '',
    });
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // Search participants by name/MRN
    useEffect(() => {
        if (participantSearch.length < 2) { setParticipantResults([]); return; }
        const timeout = setTimeout(() => {
            axios.get('/participants/search', { params: { q: participantSearch } })
                .then(r => setParticipantResults(r.data))
                .catch(() => {});
        }, 300);
        return () => clearTimeout(timeout);
    }, [participantSearch]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!selectedParticipant) { setError('Please select a participant first.'); return; }
        setSaving(true);
        setError(null);

        axios.post(`/participants/${selectedParticipant.id}/appointments`, form)
            .then(r => { onCreated(r.data); onClose(); })
            .catch(err => {
                if (err.response?.status === 409) {
                    setError(err.response.data.message);
                } else {
                    setError('Failed to create appointment. Please check all fields.');
                }
                setSaving(false);
            });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4" role="dialog" aria-modal="true">
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                    <h2 className="text-lg font-semibold text-gray-900">New Appointment</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl font-bold" aria-label="Close">×</button>
                </div>

                <form onSubmit={handleSubmit} className="p-6 space-y-4">
                    {/* Step 1: Participant Search */}
                    {step === 1 && (
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Search Participant</label>
                            <input
                                type="text"
                                value={participantSearch}
                                onChange={e => setParticipantSearch(e.target.value)}
                                placeholder="Name or MRN..."
                                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                data-testid="participant-search"
                                autoFocus
                            />
                            {participantResults.length > 0 && (
                                <ul className="mt-1 border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-48 overflow-y-auto">
                                    {participantResults.map(p => (
                                        <li key={p.id}>
                                            <button
                                                type="button"
                                                className="w-full text-left px-3 py-2 text-sm hover:bg-blue-50"
                                                onClick={() => { setSelectedParticipant(p); setStep(2); }}
                                                data-testid={`participant-result-${p.id}`}
                                            >
                                                <span className="font-medium">{p.first_name} {p.last_name}</span>
                                                <span className="text-gray-500 ml-2 text-xs">{p.mrn}</span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}

                    {/* Step 2: Appointment Details */}
                    {step === 2 && selectedParticipant && (
                        <>
                            <div className="flex items-center gap-2 p-2 bg-blue-50 rounded-lg">
                                <span className="text-sm font-medium text-blue-800">
                                    {selectedParticipant.first_name} {selectedParticipant.last_name}
                                </span>
                                <span className="text-xs text-blue-600">{selectedParticipant.mrn}</span>
                                <button type="button" onClick={() => { setSelectedParticipant(null); setStep(1); }}
                                    className="ml-auto text-xs text-blue-600 hover:underline">Change</button>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Appointment Type</label>
                                <select
                                    value={form.appointment_type}
                                    onChange={e => setForm(f => ({ ...f, appointment_type: e.target.value }))}
                                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                                    data-testid="appointment-type-select"
                                >
                                    {appointmentTypes.map(t => (
                                        <option key={t} value={t}>{typeLabels[t] || t}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">Start</label>
                                    <input type="datetime-local" value={form.scheduled_start}
                                        onChange={e => setForm(f => ({ ...f, scheduled_start: e.target.value }))}
                                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                                        data-testid="scheduled-start" required />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">End</label>
                                    <input type="datetime-local" value={form.scheduled_end}
                                        onChange={e => setForm(f => ({ ...f, scheduled_end: e.target.value }))}
                                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                                        data-testid="scheduled-end" required />
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Location</label>
                                <select value={form.location_id}
                                    onChange={e => setForm(f => ({ ...f, location_id: e.target.value }))}
                                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                                    data-testid="location-select">
                                    <option value="">— No location —</option>
                                    {locations.map(l => (
                                        <option key={l.id} value={l.id}>{l.name}</option>
                                    ))}
                                </select>
                            </div>

                            {/* Transport toggle — visually prominent for scheduler */}
                            <div className="flex items-center gap-3 p-3 rounded-lg border border-gray-200">
                                <input
                                    id="transport_required"
                                    type="checkbox"
                                    checked={form.transport_required}
                                    onChange={e => setForm(f => ({ ...f, transport_required: e.target.checked }))}
                                    className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    data-testid="transport-required"
                                />
                                <label htmlFor="transport_required" className="text-sm font-medium text-gray-700">
                                    Transport Required
                                    <span className="block text-xs text-gray-500 font-normal">Check if participant needs a ride</span>
                                </label>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                                <textarea value={form.notes}
                                    onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
                                    rows={2}
                                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none"
                                    placeholder="Optional notes for this appointment..."
                                    data-testid="appointment-notes" />
                            </div>
                        </>
                    )}

                    {error && (
                        <div className="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700" data-testid="booking-error">
                            {error}
                        </div>
                    )}

                    <div className="flex justify-end gap-3 pt-2">
                        <button type="button" onClick={onClose}
                            className="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                            Cancel
                        </button>
                        {step === 2 && (
                            <button type="submit" disabled={saving}
                                className="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
                                data-testid="submit-appointment">
                                {saving ? 'Saving…' : 'Create Appointment'}
                            </button>
                        )}
                    </div>
                </form>
            </div>
        </div>
    );
}

// ── Appointment Detail Slide-Over ─────────────────────────────────────────────

interface DetailPanelProps {
    appointment: AppointmentItem;
    typeLabels: Record<string, string>;
    typeColors: Record<string, string>;
    onClose: () => void;
    onStatusChange: (appt: AppointmentItem) => void;
}

function DetailPanel({ appointment: appt, typeLabels, typeColors, onClose, onStatusChange }: DetailPanelProps) {
    const color = COLOR_CLASS_MAP[typeColors[appt.appointment_type]] ?? COLOR_CLASS_MAP.gray;
    const [cancelling, setCancelling] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const isEditable = appt.status === 'scheduled' || appt.status === 'confirmed';

    function handleComplete() {
        if (!appt.participant) return;
        setSaving(true);
        axios.patch(`/participants/${appt.participant.id}/appointments/${appt.id}/complete`)
            .then(r => onStatusChange(r.data))
            .catch(() => setError('Could not complete appointment.'))
            .finally(() => setSaving(false));
    }

    function handleCancel(e: React.FormEvent) {
        e.preventDefault();
        if (!appt.participant || !cancelReason.trim()) return;
        setSaving(true);
        axios.patch(`/participants/${appt.participant.id}/appointments/${appt.id}/cancel`, {
            cancellation_reason: cancelReason,
        })
            .then(r => onStatusChange(r.data))
            .catch(() => setError('Could not cancel appointment.'))
            .finally(() => setSaving(false));
    }

    function handleNoShow() {
        if (!appt.participant) return;
        setSaving(true);
        axios.patch(`/participants/${appt.participant.id}/appointments/${appt.id}/no-show`)
            .then(r => onStatusChange(r.data))
            .catch(() => setError('Could not mark no-show.'))
            .finally(() => setSaving(false));
    }

    const start = new Date(appt.scheduled_start);
    const end   = new Date(appt.scheduled_end);

    const STATUS_LABELS: Record<string, string> = {
        scheduled: 'Scheduled', confirmed: 'Confirmed', completed: 'Completed',
        cancelled: 'Cancelled', no_show: 'No Show',
    };
    const STATUS_COLORS: Record<string, string> = {
        scheduled: 'bg-blue-50 text-blue-700', confirmed: 'bg-green-50 text-green-700',
        completed: 'bg-gray-100 text-gray-700', cancelled: 'bg-red-50 text-red-700',
        no_show:   'bg-amber-50 text-amber-700',
    };

    return (
        <div className="fixed inset-y-0 right-0 z-40 w-96 bg-white shadow-2xl flex flex-col" data-testid="detail-panel">
            {/* Header */}
            <div className={`px-5 py-4 border-b border-gray-200 ${color.bg}`}>
                <div className="flex items-start justify-between">
                    <div>
                        <span className={`inline-block text-xs font-semibold px-2 py-0.5 rounded-full ${color.text} border ${color.border} mb-1`}>
                            {typeLabels[appt.appointment_type] || appt.appointment_type}
                        </span>
                        <h3 className="text-base font-semibold text-gray-900">
                            {appt.participant ? `${appt.participant.first_name} ${appt.participant.last_name}` : 'Unknown'}
                        </h3>
                        {appt.participant && <p className="text-xs text-gray-500">{appt.participant.mrn}</p>}
                    </div>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-lg" aria-label="Close panel">×</button>
                </div>
            </div>

            {/* Body */}
            <div className="flex-1 overflow-y-auto p-5 space-y-4">
                <div className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div>
                        <p className="text-gray-500 text-xs">Status</p>
                        <span className={`inline-block mt-0.5 px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLORS[appt.status] || ''}`}>
                            {STATUS_LABELS[appt.status] || appt.status}
                        </span>
                    </div>
                    <div>
                        <p className="text-gray-500 text-xs">Transport</p>
                        <p className="font-medium mt-0.5">{appt.transport_required ? '🚐 Required' : 'Not needed'}</p>
                    </div>
                    <div className="col-span-2">
                        <p className="text-gray-500 text-xs">Date &amp; Time</p>
                        <p className="font-medium mt-0.5">
                            {start.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })}
                            {' · '}
                            {start.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}
                            {' – '}
                            {end.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}
                        </p>
                    </div>
                    {appt.provider && (
                        <div className="col-span-2">
                            <p className="text-gray-500 text-xs">Provider</p>
                            <p className="font-medium mt-0.5">{appt.provider.first_name} {appt.provider.last_name}</p>
                        </div>
                    )}
                    {appt.location && (
                        <div className="col-span-2">
                            <p className="text-gray-500 text-xs">Location</p>
                            <p className="font-medium mt-0.5">{appt.location.name}</p>
                        </div>
                    )}
                    {appt.notes && (
                        <div className="col-span-2">
                            <p className="text-gray-500 text-xs">Notes</p>
                            <p className="mt-0.5 text-gray-700">{appt.notes}</p>
                        </div>
                    )}
                    {appt.cancellation_reason && (
                        <div className="col-span-2">
                            <p className="text-gray-500 text-xs">Cancellation Reason</p>
                            <p className="mt-0.5 text-red-700">{appt.cancellation_reason}</p>
                        </div>
                    )}
                </div>

                {error && <p className="text-sm text-red-600 bg-red-50 rounded p-2">{error}</p>}

                {/* Status action buttons — only shown for editable appointments */}
                {isEditable && !cancelling && (
                    <div className="flex flex-col gap-2 pt-2">
                        <button onClick={handleComplete} disabled={saving}
                            className="w-full py-2 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700 disabled:opacity-50"
                            data-testid="mark-complete">
                            Mark Complete
                        </button>
                        <button onClick={() => setCancelling(true)}
                            className="w-full py-2 rounded-lg bg-white border border-red-300 text-red-700 text-sm font-medium hover:bg-red-50"
                            data-testid="cancel-appointment">
                            Cancel Appointment
                        </button>
                        <button onClick={handleNoShow} disabled={saving}
                            className="w-full py-2 rounded-lg bg-white border border-amber-300 text-amber-700 text-sm font-medium hover:bg-amber-50"
                            data-testid="mark-no-show">
                            Mark No-Show
                        </button>
                    </div>
                )}

                {/* Cancellation reason form */}
                {isEditable && cancelling && (
                    <form onSubmit={handleCancel} className="space-y-3">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Cancellation Reason</label>
                            <select value={cancelReason} onChange={e => setCancelReason(e.target.value)}
                                className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                                data-testid="cancel-reason" required>
                                <option value="">— Select reason —</option>
                                <option value="Participant declined">Participant declined</option>
                                <option value="Participant hospitalized">Participant hospitalized</option>
                                <option value="Provider unavailable">Provider unavailable</option>
                                <option value="Participant requested reschedule">Participant requested reschedule</option>
                                <option value="Weather cancellation">Weather cancellation</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div className="flex gap-2">
                            <button type="button" onClick={() => setCancelling(false)}
                                className="flex-1 py-2 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                                Back
                            </button>
                            <button type="submit" disabled={saving || !cancelReason}
                                className="flex-1 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 disabled:opacity-50"
                                data-testid="confirm-cancel">
                                {saving ? 'Cancelling…' : 'Confirm Cancel'}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}

// ── Main Calendar Component ───────────────────────────────────────────────────

export default function ScheduleIndex({ appointmentTypes, typeLabels, typeColors, locations }: SchedulePageProps) {
    const [weekStart, setWeekStart] = useState<Date>(() => getWeekStart(new Date()));
    const [appointments, setAppointments] = useState<AppointmentItem[]>([]);
    const [loading, setLoading] = useState(false);
    const [selectedAppt, setSelectedAppt] = useState<AppointmentItem | null>(null);
    const [showBooking, setShowBooking] = useState(false);
    const [filterType, setFilterType] = useState('');

    // ── Current time indicator ────────────────────────────────────────────────
    // Tracks "now" so the red indicator line moves through the day in real time.
    // Uses LOCAL hours/minutes (getHours/getMinutes) — NOT UTC — because
    // appointment datetimes are stored as "local time in UTC format" (e.g. a
    // 9:30 AM appointment is stored as "09:30Z", so getUTCHours() returns 9).
    // getTimePosition() also uses getUTCHours(), which equals the local hour for
    // stored appointments. The "now" Date object, however, has getUTCHours() =
    // actual UTC, so we must use getHours() to land on the same axis.
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const id = setInterval(() => setNow(new Date()), 60_000);
        return () => clearInterval(id);
    }, []);
    const nowLocalMinutes = (now.getHours() - HOUR_START) * 60 + now.getMinutes();
    const nowPct          = (nowLocalMinutes / ((HOUR_END - HOUR_START) * 60)) * 100;
    const showNowLine     = now.getHours() >= HOUR_START && now.getHours() < HOUR_END;

    // Derive the 7 days in the current week view
    const weekDays = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
    const weekEnd  = addDays(weekStart, 6);

    // Fetch appointments whenever the displayed week changes
    const fetchAppointments = useCallback(() => {
        setLoading(true);
        axios.get('/schedule/appointments', {
            params: {
                start_date: formatDateParam(weekStart),
                end_date:   formatDateParam(weekEnd),
                ...(filterType ? { type: filterType } : {}),
            },
        })
            .then(r => setAppointments(r.data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [weekStart, filterType]);

    // Re-fetch on week change or filter change
    // Effect runs when weekStart or filterType changes (tracked in fetchAppointments dependency)
    useEffect(() => { fetchAppointments(); }, [fetchAppointments]);

    function handlePrevWeek() { setWeekStart(d => addDays(d, -7)); }
    function handleNextWeek() { setWeekStart(d => addDays(d, 7)); }
    function handleToday()    { setWeekStart(getWeekStart(new Date())); }

    /** Return appointments for a specific day. */
    function appointmentsForDay(day: Date): AppointmentItem[] {
        const dayStr = formatDateParam(day);
        return appointments.filter(a => a.scheduled_start.startsWith(dayStr));
    }

    function handleStatusChange(updated: AppointmentItem) {
        setAppointments(prev => prev.map(a => a.id === updated.id ? updated : a));
        setSelectedAppt(updated);
    }

    const today = formatDateParam(new Date());

    return (
        <>
            <Head title="Schedule" />
            <AppShell>
                {/* Page header */}
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-white">
                    <div className="flex items-center gap-3">
                        <h1 className="text-xl font-semibold text-gray-900">Schedule</h1>
                        <span className="text-sm text-gray-500">
                            {formatDisplayDate(weekStart)} – {formatDisplayDate(weekEnd)}
                        </span>
                        {loading && <span className="text-xs text-gray-400 animate-pulse">Loading…</span>}
                    </div>

                    <div className="flex items-center gap-2">
                        {/* Week navigation */}
                        <button onClick={handlePrevWeek}
                            className="px-3 py-1.5 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50"
                            data-testid="prev-week">
                            ‹ Prev
                        </button>
                        <button onClick={handleToday}
                            className="px-3 py-1.5 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50"
                            data-testid="today-btn">
                            Today
                        </button>
                        <button onClick={handleNextWeek}
                            className="px-3 py-1.5 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50"
                            data-testid="next-week">
                            Next ›
                        </button>

                        {/* Type filter */}
                        <select value={filterType} onChange={e => setFilterType(e.target.value)}
                            className="border border-gray-300 rounded-lg px-3 py-1.5 text-sm text-gray-700"
                            data-testid="filter-type">
                            <option value="">All Types</option>
                            {appointmentTypes.map(t => (
                                <option key={t} value={t}>{typeLabels[t] || t}</option>
                            ))}
                        </select>

                        {/* New Appointment button */}
                        <button
                            onClick={() => setShowBooking(true)}
                            className="ml-2 px-4 py-1.5 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700"
                            data-testid="new-appointment-btn">
                            + New Appointment
                        </button>
                    </div>
                </div>

                {/* Calendar grid */}
                <div className="flex flex-1 overflow-hidden">
                    {/* Time gutter */}
                    <div className="w-14 flex-shrink-0 border-r border-gray-200 bg-gray-50">
                        {/* Day header spacer */}
                        <div className="h-12 border-b border-gray-200" />
                        {/* Hour labels */}
                        {HOURS.map(h => (
                            <div key={h} className="h-16 border-b border-gray-100 flex items-start justify-end pr-2 pt-1">
                                <span className="text-xs text-gray-400">
                                    {h === 12 ? '12 PM' : h > 12 ? `${h - 12} PM` : `${h} AM`}
                                </span>
                            </div>
                        ))}
                    </div>

                    {/* Day columns */}
                    <div className="flex-1 overflow-x-auto overflow-y-auto">
                        <div className="flex min-w-max">
                            {weekDays.map((day, dayIdx) => {
                                const dayStr  = formatDateParam(day);
                                const isToday = dayStr === today;
                                const dayAppts = appointmentsForDay(day);

                                return (
                                    <div key={dayIdx} className="flex-1 min-w-[140px] border-r border-gray-200 last:border-r-0">
                                        {/* Day header */}
                                        <div className={`h-12 border-b border-gray-200 flex flex-col items-center justify-center sticky top-0 z-10 ${isToday ? 'bg-blue-50' : 'bg-white'}`}>
                                            <span className={`text-xs font-medium ${isToday ? 'text-blue-600' : 'text-gray-500'}`}>
                                                {DAY_NAMES[day.getDay()]}
                                            </span>
                                            <span className={`text-lg font-bold ${isToday ? 'text-blue-700' : 'text-gray-900'}`}>
                                                {day.getDate()}
                                            </span>
                                        </div>

                                        {/* Hour rows + appointments */}
                                        <div
                                            className="relative"
                                            style={{
                                                height: `${HOURS.length * 64}px`,
                                                // Stronger blue tint on today's column — blue-100 at 70% so "today"
                                                // clearly stands out from other days without overwhelming the
                                                // appointment block colors (which sit on top as solid *-100 elements).
                                                ...(isToday ? { backgroundColor: 'rgba(219, 234, 254, 0.70)' } : {}),
                                            }}
                                        >
                                            {/* Hour grid lines */}
                                            {HOURS.map((h, hi) => (
                                                <div key={h} className="absolute w-full border-b border-gray-100"
                                                    style={{ top: `${hi * 64}px`, height: '64px' }} />
                                            ))}

                                            {/* Current time indicator — red line + circle showing right now.
                                                Only rendered on today's column and only while within calendar hours.
                                                pointer-events-none so it never blocks appointment clicks. */}
                                            {isToday && showNowLine && (
                                                <div
                                                    className="absolute left-0 right-0 z-20 pointer-events-none"
                                                    style={{ top: `${nowPct}%` }}
                                                >
                                                    {/* Horizontal "now" line */}
                                                    <div className="w-full border-t-2 border-red-400" />
                                                    {/* Dot at the left edge of the line */}
                                                    <div
                                                        className="absolute rounded-full bg-red-500 border-2 border-white"
                                                        style={{ width: 10, height: 10, top: -6, left: 0 }}
                                                    />
                                                </div>
                                            )}

                                            {/* Appointment blocks — laid out side-by-side when time slots overlap */}
                                            {layoutDayAppointments(dayAppts).map(appt => {
                                                const start = parseTime(appt.scheduled_start);
                                                const end   = parseTime(appt.scheduled_end);
                                                const { top, height } = getTimePosition(start, end);
                                                const colorKey = typeColors[appt.appointment_type] || 'gray';
                                                const color    = COLOR_CLASS_MAP[colorKey] ?? COLOR_CLASS_MAP.gray;
                                                const isCancelled = appt.status === 'cancelled' || appt.status === 'no_show';

                                                // Divide the column width evenly among overlapping appointments.
                                                const leftPct  = (appt.colIndex / appt.colCount) * 100;
                                                const widthPct = (1 / appt.colCount) * 100;

                                                return (
                                                    <button
                                                        key={appt.id}
                                                        onClick={() => setSelectedAppt(appt)}
                                                        className={`absolute rounded px-1 py-0.5 text-left border transition-opacity hover:opacity-90
                                                            ${isCancelled ? 'opacity-40 line-through' : ''}
                                                            ${color.bg} ${color.border} ${color.text}`}
                                                        style={{
                                                            top:       `${top}%`,
                                                            height:    `${height}%`,
                                                            minHeight: '20px',
                                                            left:      `calc(${leftPct}% + 2px)`,
                                                            width:     `calc(${widthPct}% - 4px)`,
                                                        }}
                                                        data-testid={`appt-block-${appt.id}`}
                                                    >
                                                        <p className="text-xs font-semibold leading-tight truncate">
                                                            {typeLabels[appt.appointment_type] || appt.appointment_type}
                                                        </p>
                                                        {appt.participant && (
                                                            <p className="text-xs leading-tight truncate opacity-80">
                                                                {appt.participant.first_name} {appt.participant.last_name}
                                                            </p>
                                                        )}
                                                        {appt.transport_required && (
                                                            <span className="text-xs">🚐</span>
                                                        )}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {/* Slide-over detail panel */}
                {selectedAppt && (
                    <DetailPanel
                        appointment={selectedAppt}
                        typeLabels={typeLabels}
                        typeColors={typeColors}
                        onClose={() => setSelectedAppt(null)}
                        onStatusChange={handleStatusChange}
                    />
                )}

                {/* Booking modal */}
                {showBooking && (
                    <BookingModal
                        onClose={() => setShowBooking(false)}
                        onCreated={appt => {
                            setAppointments(prev => [...prev, appt]);
                            setShowBooking(false);
                        }}
                        appointmentTypes={appointmentTypes}
                        typeLabels={typeLabels}
                        locations={locations}
                        defaultDate={today}
                    />
                )}
            </AppShell>
        </>
    );
}
