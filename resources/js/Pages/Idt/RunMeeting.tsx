// ─── RunMeeting ───────────────────────────────────────────────────────────────
// Live IDT meeting runner page.
//
// Layout:
//   - Left rail: ordered participant review queue (drag-order preserved server-side)
//   - Right panel: selected participant detail (notes, action items, mark reviewed)
//   - Bottom: minutes textarea (auto-saved every 30s) + decisions list
//   - Header: meeting info + "Complete Meeting" button (when all participants reviewed)
//
// Route: GET /idt/meetings/{meeting} → Inertia::render('Idt/RunMeeting')
// Props: meeting (with participantReviews loaded)
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect, useRef, useCallback } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

interface ParticipantSummary {
    id: number;
    mrn: string;
    first_name: string;
    last_name: string;
    date_of_birth?: string;
}

interface ActionItem {
    description: string;
    assigned_to_dept: string;
    due_date: string;
}

interface ParticipantReview {
    id: number;
    queue_order: number;
    summary_text: string | null;
    action_items: ActionItem[];
    reviewed_at: string | null;
    reviewed_by_user_id: number | null;
    participant: ParticipantSummary;
}

interface Decision {
    decision: string;
    owner: string;
    due_date: string;
}

interface IdtMeeting {
    id: number;
    meeting_date: string;
    meeting_time: string | null;
    meeting_type: string;
    status: 'scheduled' | 'in_progress' | 'completed';
    facilitator: { id: number; first_name: string; last_name: string } | null;
    minutes_text: string | null;
    decisions: Decision[];
    attendees: unknown[];
    participant_reviews: ParticipantReview[];
}

interface RunMeetingProps extends PageProps {
    meeting: IdtMeeting;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const TYPE_LABELS: Record<string, string> = {
    daily: 'Daily Huddle', weekly: 'Weekly IDT Review',
    care_plan_review: 'Care Plan Review', urgent: 'Urgent IDT',
};

const DEPT_OPTIONS = [
    'primary_care', 'social_work', 'therapies', 'behavioral_health',
    'dietary', 'activities', 'home_care', 'transportation', 'pharmacy', 'idt',
];

function deptLabel(dept: string): string {
    return dept.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

// ── Action Item Row ───────────────────────────────────────────────────────────

function ActionItemRow({
    item, onChange, onRemove
}: {
    item: ActionItem;
    onChange: (patch: Partial<ActionItem>) => void;
    onRemove: () => void;
}) {
    return (
        <div className="flex items-center gap-2 text-xs">
            <input
                type="text"
                placeholder="Task description"
                value={item.description}
                onChange={e => onChange({ description: e.target.value })}
                className="flex-1 border border-slate-300 rounded px-2 py-1 text-xs focus:ring-blue-500 focus:border-blue-500 min-w-0"
            />
            <select
                value={item.assigned_to_dept}
                onChange={e => onChange({ assigned_to_dept: e.target.value })}
                className="border border-slate-300 rounded px-2 py-1 text-xs focus:ring-blue-500 focus:border-blue-500"
            >
                <option value="">Dept</option>
                {DEPT_OPTIONS.map(d => <option key={d} value={d}>{deptLabel(d)}</option>)}
            </select>
            <input
                type="date"
                value={item.due_date}
                onChange={e => onChange({ due_date: e.target.value })}
                className="border border-slate-300 rounded px-2 py-1 text-xs focus:ring-blue-500 focus:border-blue-500"
            />
            <button onClick={onRemove} className="text-red-400 hover:text-red-600">
                <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    );
}

// ── Participant Review Panel ───────────────────────────────────────────────────

interface ReviewPanelProps {
    review: ParticipantReview;
    meetingId: number;
    locked: boolean;
    onReviewed: (updatedReview: ParticipantReview) => void;
}

function ReviewPanel({ review, meetingId, locked, onReviewed }: ReviewPanelProps) {
    const [notes, setNotes]         = useState(review.summary_text ?? '');
    const [items, setItems]         = useState<ActionItem[]>(review.action_items ?? []);
    const [saving, setSaving]       = useState(false);
    const [marking, setMarking]     = useState(false);
    const saveTimeout = useRef<ReturnType<typeof setTimeout>>();

    // Auto-save notes after 2s of no typing
    const scheduleSave = useCallback(() => {
        clearTimeout(saveTimeout.current);
        saveTimeout.current = setTimeout(async () => {
            try {
                await axios.patch(
                    `/idt/meetings/${meetingId}/participants/${review.id}`,
                    { summary_text: notes, action_items: items }
                );
            } catch { /* ignore */ }
        }, 2000);
    }, [meetingId, review.id, notes, items]);

    useEffect(() => {
        if (!locked) scheduleSave();
        return () => clearTimeout(saveTimeout.current);
    }, [notes, items, locked, scheduleSave]);

    const addItem = () => setItems(prev => [
        ...prev,
        { description: '', assigned_to_dept: '', due_date: new Date().toISOString().split('T')[0] }
    ]);

    const updateItem = (idx: number, patch: Partial<ActionItem>) =>
        setItems(prev => prev.map((it, i) => i === idx ? { ...it, ...patch } : it));

    const removeItem = (idx: number) =>
        setItems(prev => prev.filter((_, i) => i !== idx));

    const saveNow = async () => {
        clearTimeout(saveTimeout.current);
        setSaving(true);
        try {
            await axios.patch(
                `/idt/meetings/${meetingId}/participants/${review.id}`,
                { summary_text: notes, action_items: items }
            );
        } catch { /* ignore */ } finally {
            setSaving(false);
        }
    };

    const markReviewed = async () => {
        await saveNow();
        setMarking(true);
        try {
            const { data } = await axios.post(
                `/idt/meetings/${meetingId}/participants/${review.id}/reviewed`
            );
            onReviewed({ ...review, ...data });
        } catch { /* ignore */ } finally {
            setMarking(false);
        }
    };

    const p = review.participant;
    const isReviewed = !!review.reviewed_at;

    return (
        <div className="flex-1 p-5 space-y-5" data-testid={`review-panel-${review.id}`}>
            {/* Participant header */}
            <div className="flex items-center justify-between">
                <div>
                    <h3 className="font-semibold text-slate-900 text-base">
                        {p.first_name} {p.last_name}
                    </h3>
                    <p className="text-xs text-slate-500 mt-0.5">
                        MRN: {p.mrn}
                        {p.date_of_birth ? ` · DOB: ${p.date_of_birth}` : ''}
                    </p>
                </div>
                <Link
                    href={`/participants/${p.id}`}
                    target="_blank"
                    className="text-xs text-blue-600 hover:underline"
                    data-testid={`view-participant-${p.id}`}
                >
                    Open Chart →
                </Link>
            </div>

            {/* Status chip */}
            {isReviewed && (
                <div className="inline-flex items-center gap-1 px-2 py-1 bg-green-50 text-green-700 rounded-full text-xs font-medium">
                    <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                    </svg>
                    Reviewed
                </div>
            )}

            {/* Summary notes */}
            <div>
                <label className="block text-xs font-medium text-slate-700 mb-1">
                    Summary / Notes
                </label>
                <textarea
                    value={notes}
                    onChange={e => setNotes(e.target.value)}
                    readOnly={locked || isReviewed}
                    rows={5}
                    data-testid={`review-notes-${review.id}`}
                    placeholder="Document participant status, changes, concerns discussed in the IDT meeting…"
                    className="w-full rounded-lg border border-slate-300 text-sm px-3 py-2 focus:ring-blue-500 focus:border-blue-500 resize-none disabled:bg-slate-50 disabled:text-slate-500"
                />
            </div>

            {/* Action items */}
            <div>
                <div className="flex items-center justify-between mb-2">
                    <label className="text-xs font-medium text-slate-700">Action Items</label>
                    {!locked && !isReviewed && (
                        <button
                            onClick={addItem}
                            data-testid={`add-action-item-${review.id}`}
                            className="text-xs text-blue-600 hover:underline font-medium"
                        >
                            + Add item
                        </button>
                    )}
                </div>
                <div className="space-y-1.5">
                    {items.map((item, idx) => (
                        locked || isReviewed ? (
                            <div key={idx} className="flex items-center gap-2 text-xs text-slate-700 bg-slate-50 rounded px-2 py-1">
                                <span className="flex-1">{item.description}</span>
                                <span className="text-slate-500">{deptLabel(item.assigned_to_dept)}</span>
                                <span className="text-slate-400">{item.due_date}</span>
                            </div>
                        ) : (
                            <ActionItemRow
                                key={idx}
                                item={item}
                                onChange={patch => updateItem(idx, patch)}
                                onRemove={() => removeItem(idx)}
                            />
                        )
                    ))}
                    {items.length === 0 && (
                        <p className="text-xs text-slate-400 italic">No action items recorded.</p>
                    )}
                </div>
            </div>

            {/* Footer buttons */}
            {!locked && !isReviewed && (
                <div className="flex items-center gap-3 pt-2 border-t border-slate-100">
                    <button
                        onClick={saveNow}
                        disabled={saving}
                        data-testid={`save-review-${review.id}`}
                        className="px-3 py-1.5 text-xs font-medium border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 disabled:opacity-50"
                    >
                        {saving ? 'Saving…' : 'Save Notes'}
                    </button>
                    <button
                        onClick={markReviewed}
                        disabled={marking}
                        data-testid={`mark-reviewed-${review.id}`}
                        className="px-4 py-1.5 text-xs font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                    >
                        {marking ? 'Marking…' : 'Mark Reviewed ✓'}
                    </button>
                </div>
            )}
        </div>
    );
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function RunMeeting() {
    const { meeting: initialMeeting } = usePage<RunMeetingProps>().props;
    const [meeting, setMeeting]       = useState(initialMeeting);
    const [activeReviewId, setActiveReviewId] = useState<number | null>(
        initialMeeting.participant_reviews?.[0]?.id ?? null
    );
    const [completing, setCompleting] = useState(false);
    const [minutes, setMinutes]       = useState(meeting.minutes_text ?? '');
    const minutesTimeout = useRef<ReturnType<typeof setTimeout>>();

    const locked = meeting.status === 'completed';
    const sortedQueue = [...meeting.participant_reviews].sort((a, b) => a.queue_order - b.queue_order);
    const reviewedCount = sortedQueue.filter(r => r.reviewed_at).length;
    const allReviewed   = reviewedCount === sortedQueue.length && sortedQueue.length > 0;
    const activeReview  = sortedQueue.find(r => r.id === activeReviewId);

    // Auto-save minutes
    const scheduleMinutesSave = useCallback(() => {
        clearTimeout(minutesTimeout.current);
        minutesTimeout.current = setTimeout(async () => {
            try {
                await axios.patch(`/idt/meetings/${meeting.id}`, { minutes_text: minutes });
            } catch { /* ignore */ }
        }, 3000);
    }, [meeting.id, minutes]);

    useEffect(() => {
        if (!locked) scheduleMinutesSave();
        return () => clearTimeout(minutesTimeout.current);
    }, [minutes, locked, scheduleMinutesSave]);

    const handleReviewed = (updated: ParticipantReview) => {
        setMeeting(prev => ({
            ...prev,
            participant_reviews: prev.participant_reviews.map(r =>
                r.id === updated.id ? updated : r
            )
        }));
        // Auto-advance to next unreviewed
        const remaining = sortedQueue.filter(r => !r.reviewed_at && r.id !== updated.id);
        if (remaining.length > 0) setActiveReviewId(remaining[0].id);
    };

    const completeMeeting = async () => {
        if (!window.confirm('Complete and lock this meeting? This action cannot be undone.')) return;
        setCompleting(true);
        try {
            await axios.post(`/idt/meetings/${meeting.id}/complete`);
            router.reload();
        } catch {
            setCompleting(false);
        }
    };

    return (
        <AppShell breadcrumbs={[
            { label: 'IDT Dashboard', href: '/idt/meetings' },
            { label: `${TYPE_LABELS[meeting.meeting_type] ?? 'Meeting'} — ${meeting.meeting_date}` }
        ]}>
            <Head title={`IDT Meeting — ${meeting.meeting_date}`} />

            {/* ── Meeting header ───────────────────────────────────────────── */}
            <div className="flex items-center justify-between mb-5">
                <div>
                    <div className="flex items-center gap-2">
                        <h1 className="text-xl font-bold text-slate-900">
                            {TYPE_LABELS[meeting.meeting_type] ?? meeting.meeting_type}
                        </h1>
                        <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${
                            meeting.status === 'in_progress' ? 'bg-amber-50 text-amber-700 ring-amber-600/20' :
                            meeting.status === 'completed'   ? 'bg-green-50 text-green-700 ring-green-600/20' :
                            'bg-blue-50 text-blue-700 ring-blue-600/20'
                        }`}>
                            {meeting.status.replace('_', ' ').toUpperCase()}
                        </span>
                    </div>
                    <p className="text-sm text-slate-500 mt-0.5">
                        {meeting.meeting_date}
                        {meeting.meeting_time ? ` · ${meeting.meeting_time}` : ''}
                        {meeting.facilitator ? ` · Facilitator: ${meeting.facilitator.first_name} ${meeting.facilitator.last_name}` : ''}
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <span className="text-sm text-slate-500">
                        {reviewedCount} / {sortedQueue.length} reviewed
                    </span>
                    {!locked && allReviewed && (
                        <button
                            onClick={completeMeeting}
                            disabled={completing}
                            data-testid="complete-meeting-btn"
                            className="px-4 py-2 text-sm font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                        >
                            {completing ? 'Completing…' : 'Complete Meeting'}
                        </button>
                    )}
                    {locked && (
                        <span className="inline-flex items-center gap-1 px-3 py-1.5 bg-green-50 text-green-700 rounded-lg text-sm font-medium">
                            <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clipRule="evenodd" />
                            </svg>
                            Locked
                        </span>
                    )}
                </div>
            </div>

            {/* ── Main layout: queue + review panel ───────────────────────── */}
            <div className="flex gap-5 min-h-0" style={{ height: 'calc(100vh - 14rem)' }}>
                {/* Left: participant queue */}
                <div className="w-64 shrink-0 flex flex-col gap-2 overflow-y-auto" data-testid="participant-queue">
                    <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wider px-1">
                        Participant Queue
                    </p>
                    {sortedQueue.length === 0 && (
                        <p className="text-xs text-slate-400 text-center py-4">No participants queued.</p>
                    )}
                    {sortedQueue.map(review => (
                        <button
                            key={review.id}
                            onClick={() => setActiveReviewId(review.id)}
                            data-testid={`queue-item-${review.id}`}
                            className={`w-full text-left rounded-xl px-3 py-2.5 border transition-colors ${
                                activeReviewId === review.id
                                    ? 'border-blue-400 bg-blue-50 ring-1 ring-blue-300'
                                    : 'border-slate-200 bg-white hover:bg-slate-50'
                            }`}
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-medium text-slate-800 truncate">
                                    {review.participant.first_name} {review.participant.last_name}
                                </span>
                                {review.reviewed_at ? (
                                    <svg className="w-4 h-4 text-green-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                    </svg>
                                ) : (
                                    <span className="text-[10px] text-slate-400">#{review.queue_order}</span>
                                )}
                            </div>
                            <p className="text-[10px] text-slate-500 mt-0.5">{review.participant.mrn}</p>
                        </button>
                    ))}
                </div>

                {/* Right: review panel + minutes */}
                <div className="flex-1 flex flex-col gap-4 min-w-0 overflow-hidden">
                    {/* Participant review area */}
                    <div className="flex-1 rounded-xl border border-slate-200 bg-white overflow-y-auto">
                        {activeReview ? (
                            <ReviewPanel
                                review={activeReview}
                                meetingId={meeting.id}
                                locked={locked}
                                onReviewed={handleReviewed}
                            />
                        ) : (
                            <div className="flex items-center justify-center h-full text-sm text-slate-400">
                                Select a participant from the queue to begin review.
                            </div>
                        )}
                    </div>

                    {/* Minutes textarea */}
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shrink-0">
                        <div className="flex items-center justify-between mb-2">
                            <label className="text-xs font-semibold text-slate-700">Meeting Minutes</label>
                            {!locked && (
                                <span className="text-[10px] text-slate-400 italic">Auto-saved</span>
                            )}
                        </div>
                        <textarea
                            value={minutes}
                            onChange={e => setMinutes(e.target.value)}
                            readOnly={locked}
                            rows={4}
                            data-testid="meeting-minutes-textarea"
                            placeholder="Record meeting minutes, key discussion points, and overall decisions here…"
                            className="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:ring-blue-500 focus:border-blue-500 resize-none disabled:bg-slate-50 disabled:text-slate-500"
                        />
                    </div>
                </div>
            </div>
        </AppShell>
    );
}
