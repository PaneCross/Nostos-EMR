// ─── Clinical Assessments Worklist ───────────────────────────────────────────
// Cross-participant worklist showing overdue, due-soon, and recently completed
// assessments. Click a row to navigate to that participant's Assessments tab.
// Route: GET /clinical/assessments → Inertia::render('Clinical/Assessments')
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

interface ParticipantSummary {
    id: number;
    mrn: string;
    first_name: string;
    last_name: string;
}

interface AssessmentItem {
    id: number;
    assessment_type: string;
    score: number | null;
    completed_at: string | null;
    next_due_date: string | null;
    department: string;
    participant: ParticipantSummary | null;
}

interface AssessmentsPageProps extends PageProps {
    overdue: AssessmentItem[];
    dueSoon: AssessmentItem[];
    recent:  AssessmentItem[];
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const ASSESSMENT_LABELS: Record<string, string> = {
    initial_comprehensive: 'Initial Comprehensive',
    adl_functional:        'ADL Functional',
    mmse_cognitive:        'MMSE Cognitive',
    phq9_depression:       'PHQ-9 Depression',
    gad7_anxiety:          'GAD-7 Anxiety',
    nutritional:           'Nutritional',
    fall_risk_morse:       'Fall Risk (Morse)',
    pain_scale:            'Pain Scale',
    annual_reassessment:   'Annual Reassessment',
    custom:                'Custom',
};

const DEPT_LABELS: Record<string, string> = {
    primary_care:      'Primary Care',
    therapies:         'Therapies',
    social_work:       'Social Work',
    behavioral_health: 'Behavioral Health',
    dietary:           'Dietary',
    home_care:         'Home Care',
    idt:               'IDT',
};

// Score thresholds for visual color coding
function scoreContext(type: string, score: number | null): string | null {
    if (score === null) return null;
    if (type === 'phq9_depression') {
        if (score >= 20) return 'Severe';
        if (score >= 15) return 'Mod-Severe';
        if (score >= 10) return 'Moderate';
        if (score >= 5)  return 'Mild';
        return 'Minimal';
    }
    if (type === 'gad7_anxiety') {
        if (score >= 15) return 'Severe';
        if (score >= 10) return 'Moderate';
        if (score >= 5)  return 'Mild';
        return 'Minimal';
    }
    if (type === 'mmse_cognitive') {
        if (score <= 9)  return 'Severe';
        if (score <= 18) return 'Moderate';
        if (score <= 23) return 'Mild';
        return 'Normal';
    }
    return null;
}

function scoreClass(type: string, score: number | null): string {
    const ctx = scoreContext(type, score);
    if (!ctx) return 'text-slate-700 dark:text-slate-300';
    if (ctx === 'Severe' || ctx === 'Mod-Severe') return 'font-semibold text-red-700 dark:text-red-300';
    if (ctx === 'Moderate') return 'font-semibold text-amber-700 dark:text-amber-300';
    return 'text-slate-700 dark:text-slate-300';
}

function daysOverdue(dueDate: string): number {
    return Math.floor((Date.now() - new Date(dueDate).getTime()) / 86_400_000);
}

function daysUntil(dueDate: string): number {
    return Math.floor((new Date(dueDate).getTime() - Date.now()) / 86_400_000);
}

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// ── Assessment Row ────────────────────────────────────────────────────────────

function AssessmentRow({ item, context }: { item: AssessmentItem; context: 'overdue' | 'due_soon' | 'recent' }) {
    const participantUrl = `/participants/${item.participant?.id}?tab=assessments`;

    return (
        <tr
            onClick={() => router.visit(participantUrl)}
            className="cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors"
        >
            <td className="px-4 py-3">
                {item.participant ? (
                    <div>
                        <p className="text-sm font-medium text-slate-800 dark:text-slate-200">
                            {item.participant.first_name} {item.participant.last_name}
                        </p>
                        <p className="text-xs text-slate-400">{item.participant.mrn}</p>
                    </div>
                ) : <span className="text-slate-300 text-sm">-</span>}
            </td>
            <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
                {ASSESSMENT_LABELS[item.assessment_type] ?? item.assessment_type}
            </td>
            <td className="px-4 py-3 text-sm text-slate-600 dark:text-slate-400">
                {DEPT_LABELS[item.department] ?? item.department}
            </td>
            <td className="px-4 py-3 text-sm text-center">
                {item.score !== null ? (
                    <span className={scoreClass(item.assessment_type, item.score)}>
                        {item.score}
                        {scoreContext(item.assessment_type, item.score) && (
                            <span className="ml-1 text-[10px] font-medium opacity-70">
                                ({scoreContext(item.assessment_type, item.score)})
                            </span>
                        )}
                    </span>
                ) : <span className="text-slate-300">-</span>}
            </td>
            <td className="px-4 py-3 text-sm">
                {context === 'overdue' && item.next_due_date && (
                    <span className="text-red-700 dark:text-red-300 font-semibold">
                        {daysOverdue(item.next_due_date)}d overdue
                    </span>
                )}
                {context === 'due_soon' && item.next_due_date && (
                    <span className="text-amber-700 dark:text-amber-300">
                        Due in {daysUntil(item.next_due_date)}d ({formatDate(item.next_due_date)})
                    </span>
                )}
                {context === 'recent' && (
                    <span className="text-slate-600 dark:text-slate-400">{formatDate(item.completed_at)}</span>
                )}
            </td>
        </tr>
    );
}

// ── Section ───────────────────────────────────────────────────────────────────

function Section({
    title,
    count,
    items,
    context,
    headerClass,
    emptyLabel,
}: {
    title: string;
    count: number;
    items: AssessmentItem[];
    context: 'overdue' | 'due_soon' | 'recent';
    headerClass: string;
    emptyLabel: string;
}) {
    const [expanded, setExpanded] = useState(true);

    return (
        <div className="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden mb-4">
            <button
                onClick={() => setExpanded(e => !e)}
                className={`w-full flex items-center justify-between px-4 py-3 text-left ${headerClass}`}
            >
                <div className="flex items-center gap-2">
                    <span className="text-sm font-semibold">{title}</span>
                    <span className={`inline-flex items-center justify-center rounded-full w-5 h-5 text-[10px] font-bold bg-white dark:bg-slate-800 bg-opacity-60 ${count > 0 ? 'text-inherit' : 'text-slate-400'}`}>
                        {count}
                    </span>
                </div>
                <svg className={`w-4 h-4 transition-transform ${expanded ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            {expanded && (
                items.length === 0 ? (
                    <div className="px-4 py-6 text-center text-sm text-slate-400">{emptyLabel}</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-100 dark:divide-slate-700 text-sm">
                            <thead className="bg-slate-50 dark:bg-slate-900">
                                <tr>
                                    <th className="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Participant</th>
                                    <th className="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Assessment</th>
                                    <th className="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Department</th>
                                    <th className="px-4 py-2.5 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Score</th>
                                    <th className="px-4 py-2.5 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                                        {context === 'recent' ? 'Completed' : 'Due Date'}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                                {items.map(item => (
                                    <AssessmentRow key={item.id} item={item} context={context} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )
            )}
        </div>
    );
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function ClinicalAssessments() {
    const { overdue, dueSoon, recent } = usePage<AssessmentsPageProps>().props;

    return (
        <AppShell breadcrumbs={[{ label: 'Clinical' }, { label: 'Assessments Worklist' }]}>
            <Head title="Assessments Worklist" />

            {/* Header */}
            <div className="flex items-center justify-between mb-5">
                <div>
                    <h1 className="text-xl font-bold text-slate-900 dark:text-slate-100">Assessments Worklist</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        Overdue and upcoming assessments across all participants · Click a row to start or review
                    </p>
                </div>
            </div>

            {/* Stat chips */}
            <div className="grid grid-cols-3 gap-3 mb-5">
                {[
                    { label: 'Overdue',      count: overdue.length,  color: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300' },
                    { label: 'Due This Week',count: dueSoon.length,  color: 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300' },
                    { label: 'Completed (30d)',count: recent.length, color: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300' },
                ].map(chip => (
                    <div key={chip.label} className={`border rounded-xl px-4 py-3 ${chip.color}`}>
                        <p className="text-2xl font-bold">{chip.count}</p>
                        <p className="text-xs font-medium mt-0.5">{chip.label}</p>
                    </div>
                ))}
            </div>

            {/* Overdue banner */}
            {overdue.length > 0 && (
                <div className="mb-4 flex items-center gap-3 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3">
                    <svg className="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <p className="text-sm font-semibold text-red-800 dark:text-red-300">
                        {overdue.length} assessment{overdue.length !== 1 ? 's' : ''} are past due. CMS compliance may be affected.
                    </p>
                </div>
            )}

            {/* Sections */}
            <Section
                title="Overdue Assessments"
                count={overdue.length}
                items={overdue}
                context="overdue"
                headerClass="bg-red-50 dark:bg-red-950/60 text-red-800 dark:text-red-300 border-b border-red-100 dark:border-red-800"
                emptyLabel="No overdue assessments. Great work!"
            />
            <Section
                title="Due Within 14 Days"
                count={dueSoon.length}
                items={dueSoon}
                context="due_soon"
                headerClass="bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 border-b border-amber-100 dark:border-amber-800"
                emptyLabel="No assessments due in the next 14 days."
            />
            <Section
                title="Completed (Last 30 Days)"
                count={recent.length}
                items={recent}
                context="recent"
                headerClass="bg-green-50 dark:bg-green-950/60 text-green-800 dark:text-green-300 border-b border-green-100 dark:border-green-800"
                emptyLabel="No assessments completed in the last 30 days."
            />
        </AppShell>
    );
}
