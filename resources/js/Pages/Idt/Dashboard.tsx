// ─── IDT Dashboard ────────────────────────────────────────────────────────────
// Interdisciplinary Team (IDT) meeting overview page.
//
// Layout:
//   - Today's meetings panel (in-progress / scheduled for today)
//   - Upcoming meetings list
//   - Recent completed meetings
//   - "Schedule Meeting" button → modal
//
// Route: GET /idt/meetings → Inertia::render('Idt/Dashboard')
// Props: todayMeetings, upcomingMeetings, recentMeetings
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

interface Facilitator {
    id: number;
    first_name: string;
    last_name: string;
}

interface ParticipantReview {
    id: number;
    queue_order: number;
    reviewed_at: string | null;
    participant: {
        id: number;
        mrn: string;
        first_name: string;
        last_name: string;
    };
}

interface IdtMeeting {
    id: number;
    meeting_date: string;
    meeting_time: string | null;
    meeting_type: 'daily' | 'weekly' | 'care_plan_review' | 'urgent';
    status: 'scheduled' | 'in_progress' | 'completed';
    facilitator: Facilitator | null;
    participant_reviews?: ParticipantReview[];
}

interface IdtDashboardProps extends PageProps {
    todayMeetings:    IdtMeeting[];
    upcomingMeetings: IdtMeeting[];
    recentMeetings:   IdtMeeting[];
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const TYPE_LABELS: Record<string, string> = {
    daily:            'Daily Huddle',
    weekly:           'Weekly IDT Review',
    care_plan_review: 'Care Plan Review',
    urgent:           'Urgent IDT',
};

const STATUS_BADGE: Record<string, string> = {
    scheduled:   'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 ring-blue-600/20',
    in_progress: 'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 ring-amber-600/20',
    completed:   'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 ring-green-600/20',
};

function fmt12h(time: string | null): string {
    if (!time) return '';
    const [h, m] = time.split(':').map(Number);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const hour  = h % 12 || 12;
    return `${hour}:${String(m).padStart(2, '0')} ${ampm}`;
}

// ── Schedule Modal ────────────────────────────────────────────────────────────

interface ScheduleModalProps { onClose: () => void; onSaved: (m: IdtMeeting) => void; }

function ScheduleModal({ onClose, onSaved }: ScheduleModalProps) {
    const today = new Date().toISOString().split('T')[0];
    const [form, setForm]     = useState({ meeting_date: today, meeting_time: '10:00', meeting_type: 'weekly' });
    const [saving, setSaving] = useState(false);
    const [error, setError]   = useState('');

    const save = async () => {
        setSaving(true);
        setError('');
        try {
            const { data } = await axios.post('/idt/meetings', form);
            onSaved(data);
            onClose();
        } catch (e: any) {
            setError(e.response?.data?.message ?? 'Failed to schedule meeting.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" data-testid="schedule-meeting-modal">
            <div className="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md">
                <div className="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
                    <h2 className="font-semibold text-slate-800 dark:text-slate-200">Schedule IDT Meeting</h2>
                    <button onClick={onClose} className="text-slate-400 hover:text-slate-600">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div className="px-6 py-5 space-y-4">
                    {error && <p className="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/60 rounded-lg px-3 py-2">{error}</p>}

                    <div>
                        <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Meeting Type</label>
                        <select
                            value={form.meeting_type}
                            onChange={e => setForm(f => ({ ...f, meeting_type: e.target.value }))}
                            data-testid="meeting-type-select"
                            className="block w-full rounded-lg border-slate-300 text-sm focus:ring-blue-500 focus:border-blue-500 py-2 px-3 border"
                        >
                            {Object.entries(TYPE_LABELS).map(([v, l]) => (
                                <option key={v} value={v}>{l}</option>
                            ))}
                        </select>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Date</label>
                            <input
                                type="date"
                                value={form.meeting_date}
                                min={today}
                                onChange={e => setForm(f => ({ ...f, meeting_date: e.target.value }))}
                                data-testid="meeting-date-input"
                                className="block w-full rounded-lg border-slate-300 text-sm focus:ring-blue-500 focus:border-blue-500 py-2 px-3 border"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Time</label>
                            <input
                                type="time"
                                value={form.meeting_time}
                                onChange={e => setForm(f => ({ ...f, meeting_time: e.target.value }))}
                                data-testid="meeting-time-input"
                                className="block w-full rounded-lg border-slate-300 text-sm focus:ring-blue-500 focus:border-blue-500 py-2 px-3 border"
                            />
                        </div>
                    </div>
                </div>
                <div className="px-6 py-4 border-t border-slate-100 dark:border-slate-700 flex justify-end gap-3">
                    <button onClick={onClose} className="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg border border-slate-200 dark:border-slate-700">
                        Cancel
                    </button>
                    <button
                        onClick={save}
                        disabled={saving}
                        data-testid="save-meeting-btn"
                        className="px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                    >
                        {saving ? 'Scheduling…' : 'Schedule Meeting'}
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── Meeting Card ──────────────────────────────────────────────────────────────

function MeetingCard({ meeting, showQueue = false }: { meeting: IdtMeeting; showQueue?: boolean }) {
    const reviewed   = meeting.participant_reviews?.filter(r => r.reviewed_at) ?? [];
    const total      = meeting.participant_reviews?.length ?? 0;
    const isToday    = meeting.status === 'in_progress' || meeting.status === 'scheduled';

    const startMeeting = async (id: number) => {
        try {
            await axios.post(`/idt/meetings/${id}/start`);
            router.reload({ only: ['todayMeetings'] });
        } catch { /* ignore */ }
    };

    return (
        <div
            data-testid={`meeting-card-${meeting.id}`}
            className={`rounded-xl border p-4 ${meeting.status === 'in_progress' ? 'border-amber-300 bg-amber-50 dark:bg-amber-950/60/60' : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800'}`}
        >
            <div className="flex items-start justify-between gap-3 mb-2">
                <div>
                    <div className="flex items-center gap-2">
                        <span className="font-semibold text-sm text-slate-800 dark:text-slate-200">
                            {TYPE_LABELS[meeting.meeting_type] ?? meeting.meeting_type}
                        </span>
                        <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset ${STATUS_BADGE[meeting.status] ?? ''}`}>
                            {meeting.status.replace('_', ' ').toUpperCase()}
                        </span>
                    </div>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                        {meeting.meeting_date}
                        {meeting.meeting_time ? ` · ${fmt12h(meeting.meeting_time)}` : ''}
                        {meeting.facilitator ? ` · ${meeting.facilitator.first_name} ${meeting.facilitator.last_name}` : ''}
                    </p>
                </div>

                <div className="flex items-center gap-2 shrink-0">
                    {/* Start button (scheduled → in_progress) */}
                    {meeting.status === 'scheduled' && (
                        <button
                            onClick={() => startMeeting(meeting.id)}
                            data-testid={`start-meeting-${meeting.id}`}
                            className="px-3 py-1.5 text-xs font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                        >
                            Start
                        </button>
                    )}

                    {/* Enter meeting button */}
                    {(meeting.status === 'in_progress' || isToday) && (
                        <Link
                            href={`/idt/meetings/${meeting.id}`}
                            data-testid={`enter-meeting-${meeting.id}`}
                            className="px-3 py-1.5 text-xs font-medium bg-amber-600 text-white rounded-lg hover:bg-amber-700"
                        >
                            {meeting.status === 'in_progress' ? 'Resume' : 'View'}
                        </Link>
                    )}
                </div>
            </div>

            {/* Participant review progress */}
            {showQueue && total > 0 && (
                <div className="mt-2">
                    <div className="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-400 mb-1">
                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                        {reviewed.length} / {total} participants reviewed
                    </div>
                    <div className="h-1.5 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                        <div
                            className="h-full bg-green-500 rounded-full transition-all"
                            style={{ width: `${(reviewed.length / total) * 100}%` }}
                        />
                    </div>
                </div>
            )}
        </div>
    );
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function IdtDashboard() {
    const { todayMeetings, upcomingMeetings, recentMeetings } = usePage<IdtDashboardProps>().props;
    const [showSchedule, setShowSchedule] = useState(false);

    const handleSaved = () => {
        router.reload({ only: ['todayMeetings', 'upcomingMeetings'] });
    };

    return (
        <AppShell breadcrumbs={[{ label: 'IDT Dashboard' }]}>
            <Head title="IDT Dashboard" />

            {/* ── Page header ─────────────────────────────────────────────── */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-xl font-bold text-slate-900 dark:text-slate-100">IDT Dashboard</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Interdisciplinary Team meetings and participant review queue</p>
                </div>
                <button
                    onClick={() => setShowSchedule(true)}
                    data-testid="schedule-meeting-trigger"
                    className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 shadow-sm"
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Schedule Meeting
                </button>
            </div>

            <div className="space-y-8">
                {/* ── Today's Meetings ─────────────────────────────────────── */}
                <section data-testid="today-meetings-section">
                    <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-3">
                        Today's Meetings
                    </h2>

                    {todayMeetings.length === 0 ? (
                        <div className="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-6 py-8 text-center">
                            <svg className="w-8 h-8 mx-auto text-slate-300 mb-2" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                            </svg>
                            <p className="text-sm text-slate-500 dark:text-slate-400">No meetings scheduled for today.</p>
                            <button
                                onClick={() => setShowSchedule(true)}
                                className="mt-2 text-sm text-blue-600 dark:text-blue-400 hover:underline"
                            >
                                Schedule one now →
                            </button>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {todayMeetings.map(m => (
                                <MeetingCard key={m.id} meeting={m} showQueue />
                            ))}
                        </div>
                    )}
                </section>

                {/* ── Two-column: Upcoming + Recent ────────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Upcoming */}
                    <section data-testid="upcoming-meetings-section">
                        <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-3">
                            Upcoming Meetings
                        </h2>
                        {upcomingMeetings.length === 0 ? (
                            <div className="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                                No upcoming meetings scheduled.
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {upcomingMeetings.map(m => (
                                    <div
                                        key={m.id}
                                        data-testid={`upcoming-meeting-${m.id}`}
                                        className="flex items-center justify-between rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-3"
                                    >
                                        <div>
                                            <p className="text-sm font-medium text-slate-800 dark:text-slate-200">
                                                {TYPE_LABELS[m.meeting_type] ?? m.meeting_type}
                                            </p>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                                {m.meeting_date}{m.meeting_time ? ` · ${fmt12h(m.meeting_time)}` : ''}
                                            </p>
                                        </div>
                                        <Link
                                            href={`/idt/meetings/${m.id}`}
                                            className="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium"
                                        >
                                            View
                                        </Link>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>

                    {/* Recent */}
                    <section data-testid="recent-meetings-section">
                        <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-3">
                            Recent Meetings
                        </h2>
                        {recentMeetings.length === 0 ? (
                            <div className="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                                No completed meetings yet.
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {recentMeetings.map(m => (
                                    <div
                                        key={m.id}
                                        data-testid={`recent-meeting-${m.id}`}
                                        className="flex items-center justify-between rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-4 py-3"
                                    >
                                        <div>
                                            <p className="text-sm font-medium text-slate-800 dark:text-slate-200">
                                                {TYPE_LABELS[m.meeting_type] ?? m.meeting_type}
                                            </p>
                                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                                {m.meeting_date}
                                                {m.facilitator ? ` · ${m.facilitator.first_name} ${m.facilitator.last_name}` : ''}
                                            </p>
                                        </div>
                                        <Link
                                            href={`/idt/meetings/${m.id}`}
                                            className="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium"
                                        >
                                            Minutes
                                        </Link>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>
                </div>
            </div>

            {/* ── Schedule modal ────────────────────────────────────────────── */}
            {showSchedule && (
                <ScheduleModal
                    onClose={() => setShowSchedule(false)}
                    onSaved={handleSaved}
                />
            )}
        </AppShell>
    );
}
