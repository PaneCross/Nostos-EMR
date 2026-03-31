// ─── Care Plans Overview ──────────────────────────────────────────────────────
// Shows all active participants with their current care plan status.
// Click any row to navigate to that participant's Care Plan tab.
// Route: GET /clinical/care-plans → Inertia::render('Clinical/CarePlans')
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

interface CarePlanSummary {
    id: number;
    status: 'draft' | 'active' | 'under_review' | 'archived';
    version: number;
    goal_count: number;
    effective_date: string | null;
    review_due_date: string | null;
}

interface ParticipantRow {
    id: number;
    mrn: string;
    first_name: string;
    last_name: string;
    care_plan: CarePlanSummary | null;
}

interface CarePlansPageProps extends PageProps {
    participants: ParticipantRow[];
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const STATUS_CONFIG: Record<string, { label: string; classes: string }> = {
    active:       { label: 'Active',       classes: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300 ring-green-600/20' },
    under_review: { label: 'Under Review', classes: 'bg-purple-100 dark:bg-purple-900/60 text-purple-800 dark:text-purple-300 ring-purple-600/20' },
    draft:        { label: 'Draft',        classes: 'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300 ring-amber-600/20' },
    archived:     { label: 'Archived',     classes: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 ring-slate-500/10' },
};

function StatusBadge({ status }: { status: string }) {
    const cfg = STATUS_CONFIG[status] ?? { label: status, classes: 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 ring-slate-500/10' };
    return (
        <span className={`inline-flex items-center rounded px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${cfg.classes}`}>
            {cfg.label}
        </span>
    );
}

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function isReviewOverdue(d: string | null): boolean {
    if (!d) return false;
    return new Date(d) < new Date();
}

function isReviewDueSoon(d: string | null): boolean {
    if (!d) return false;
    const diff = new Date(d).getTime() - Date.now();
    return diff > 0 && diff < 30 * 24 * 3_600_000;
}

function reviewClass(d: string | null): string {
    if (isReviewOverdue(d)) return 'text-red-700 dark:text-red-300 font-semibold';
    if (isReviewDueSoon(d)) return 'text-amber-700 dark:text-amber-300 font-medium';
    return 'text-slate-600 dark:text-slate-400';
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function CarePlansIndex() {
    const { participants } = usePage<CarePlansPageProps>().props;

    const [statusFilter, setStatusFilter] = useState<string>('');
    const [search, setSearch] = useState('');

    const noPlan      = participants.filter(p => !p.care_plan);
    const active      = participants.filter(p => p.care_plan?.status === 'active');
    const underReview = participants.filter(p => p.care_plan?.status === 'under_review');
    const draft       = participants.filter(p => p.care_plan?.status === 'draft');
    const reviewOverdue = participants.filter(p => p.care_plan && isReviewOverdue(p.care_plan.review_due_date));

    const filtered = participants.filter(p => {
        if (statusFilter === 'none' && p.care_plan) return false;
        if (statusFilter && statusFilter !== 'none' && p.care_plan?.status !== statusFilter) return false;
        if (search) {
            const q = search.toLowerCase();
            return (
                p.first_name.toLowerCase().includes(q) ||
                p.last_name.toLowerCase().includes(q)  ||
                p.mrn.toLowerCase().includes(q)
            );
        }
        return true;
    });

    return (
        <AppShell breadcrumbs={[{ label: 'Clinical' }, { label: 'Care Plans' }]}>
            <Head title="Care Plans" />

            {/* Header */}
            <div className="flex items-center justify-between mb-5">
                <div>
                    <h1 className="text-xl font-bold text-slate-900 dark:text-slate-100">Care Plans</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        CMS-required individualized care plans · review every 6 months · click a row to view or edit
                    </p>
                </div>
            </div>

            {/* Stat chips */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                {[
                    { label: 'Active Plans',      count: active.length,      color: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300',   filter: 'active' },
                    { label: 'Under Review',      count: underReview.length, color: 'bg-purple-50 dark:bg-purple-950/60 border-purple-200 dark:border-purple-800 text-purple-800 dark:text-purple-300', filter: 'under_review' },
                    { label: 'Draft / In Progress',count: draft.length,      color: 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300',   filter: 'draft' },
                    { label: 'Review Overdue',    count: reviewOverdue.length,color: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300',        filter: '' },
                ].map(chip => (
                    <button
                        key={chip.label}
                        onClick={() => chip.filter ? setStatusFilter(f => f === chip.filter ? '' : chip.filter) : setStatusFilter('')}
                        className={`border rounded-xl px-4 py-3 text-left transition-all hover:shadow-sm ${chip.color} ${
                            statusFilter === chip.filter && chip.filter ? 'ring-2 ring-offset-1 ring-current' : ''
                        }`}
                    >
                        <p className="text-2xl font-bold">{chip.count}</p>
                        <p className="text-xs font-medium mt-0.5">{chip.label}</p>
                    </button>
                ))}
            </div>

            {/* Review overdue banner */}
            {reviewOverdue.length > 0 && (
                <div className="mb-4 flex items-center gap-3 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3">
                    <svg className="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <p className="text-sm font-semibold text-red-800 dark:text-red-300">
                        {reviewOverdue.length} care plan{reviewOverdue.length !== 1 ? 's' : ''} past their 6-month CMS review date
                    </p>
                </div>
            )}

            {/* No plan banner */}
            {noPlan.length > 0 && (
                <div className="mb-4 flex items-center gap-3 bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-xl px-4 py-3">
                    <svg className="w-5 h-5 text-blue-500 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                    </svg>
                    <p className="text-sm text-blue-800 dark:text-blue-300">
                        <span className="font-semibold">{noPlan.length} participant{noPlan.length !== 1 ? 's' : ''}</span> {noPlan.length === 1 ? 'does' : 'do'} not yet have a care plan.
                    </p>
                </div>
            )}

            {/* Filters + search */}
            <div className="flex items-center gap-3 mb-4">
                <input
                    type="text"
                    placeholder="Search by name or MRN…"
                    value={search}
                    onChange={e => setSearch(e.target.value)}
                    className="rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm py-1.5 px-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-56"
                />
                <select
                    value={statusFilter}
                    onChange={e => setStatusFilter(e.target.value)}
                    className="rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm py-1.5 px-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="under_review">Under Review</option>
                    <option value="draft">Draft</option>
                    <option value="none">No Plan Yet</option>
                </select>
                {(statusFilter || search) && (
                    <button
                        onClick={() => { setStatusFilter(''); setSearch(''); }}
                        className="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-700"
                    >
                        Clear
                    </button>
                )}
                <span className="ml-auto text-sm text-slate-500 dark:text-slate-400">{filtered.length} participant{filtered.length !== 1 ? 's' : ''}</span>
            </div>

            {/* Table */}
            <div className="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
                {filtered.length === 0 ? (
                    <div className="px-6 py-12 text-center text-sm text-slate-500 dark:text-slate-400">
                        No participants match the current filters.
                    </div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-100 dark:divide-slate-700 text-sm">
                        <thead className="bg-slate-50 dark:bg-slate-900">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Participant</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Status</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Version</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Goals</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Effective Date</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Review Due</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                            {filtered.map(p => (
                                <tr
                                    key={p.id}
                                    onClick={() => router.visit(`/participants/${p.id}?tab=careplan`)}
                                    className={`cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors border-l-4 ${
                                        !p.care_plan
                                            ? 'border-l-slate-200'
                                            : p.care_plan.status === 'active' && isReviewOverdue(p.care_plan.review_due_date)
                                                ? 'border-l-red-500'
                                                : p.care_plan.status === 'active'
                                                    ? 'border-l-green-500'
                                                    : p.care_plan.status === 'under_review'
                                                        ? 'border-l-purple-500'
                                                        : 'border-l-amber-400'
                                    }`}
                                >
                                    <td className="px-4 py-3">
                                        <div>
                                            <p className="font-medium text-slate-800 dark:text-slate-200">{p.first_name} {p.last_name}</p>
                                            <p className="text-xs text-slate-400">{p.mrn}</p>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {p.care_plan ? (
                                            <StatusBadge status={p.care_plan.status} />
                                        ) : (
                                            <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-medium bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 ring-1 ring-inset ring-slate-500/10">
                                                No Plan
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-center text-slate-600 dark:text-slate-400">
                                        {p.care_plan ? `v${p.care_plan.version}` : '-'}
                                    </td>
                                    <td className="px-4 py-3 text-center">
                                        {p.care_plan ? (
                                            <span className={`font-medium ${p.care_plan.goal_count === 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-700 dark:text-slate-300'}`}>
                                                {p.care_plan.goal_count}
                                            </span>
                                        ) : <span className="text-slate-300">-</span>}
                                    </td>
                                    <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                                        {formatDate(p.care_plan?.effective_date ?? null)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {p.care_plan?.review_due_date ? (
                                            <span className={reviewClass(p.care_plan.review_due_date)}>
                                                {formatDate(p.care_plan.review_due_date)}
                                                {isReviewOverdue(p.care_plan.review_due_date) && (
                                                    <span className="ml-1 text-[10px] text-red-600 dark:text-red-400">OVERDUE</span>
                                                )}
                                            </span>
                                        ) : <span className="text-slate-300">-</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </AppShell>
    );
}
