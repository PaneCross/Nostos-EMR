// ─── Clinical Notes — Cross-Participant Feed ──────────────────────────────────
// Shows recent clinical notes across all participants in the tenant.
// Filter by department, note type, or status.
// Click any row to jump to that participant's chart tab.
// Route: GET /clinical/notes → Inertia::render('Clinical/Notes')
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

interface AuthorSummary {
    id: number;
    first_name: string;
    last_name: string;
    department: string;
}

interface NoteItem {
    id: number;
    note_type: string;
    status: 'draft' | 'signed' | 'amended';
    visit_date: string | null;
    visit_type: string | null;
    department: string;
    author: AuthorSummary | null;
    participant: ParticipantSummary | null;
}

interface PaginatorLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface NotesPaginator {
    data: NoteItem[];
    links: PaginatorLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

interface NotesPageProps extends PageProps {
    notes: NotesPaginator;
    filters: { department?: string; note_type?: string; status?: string };
    noteTypes: string[];
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const NOTE_TYPE_LABELS: Record<string, string> = {
    soap:               'Primary Care SOAP',
    progress_nursing:   'Nursing Progress',
    therapy_pt:         'PT Therapy',
    therapy_ot:         'OT Therapy',
    therapy_st:         'ST Therapy',
    social_work:        'Social Work',
    behavioral_health:  'Behavioral Health',
    dietary:            'Dietary / Nutrition',
    home_visit:         'Home Visit',
    telehealth:         'Telehealth',
    idt_summary:        'IDT Meeting Summary',
    incident:           'Incident Report',
    addendum:           'Addendum',
};

const DEPT_LABELS: Record<string, string> = {
    primary_care:      'Primary Care',
    therapies:         'Therapies',
    social_work:       'Social Work',
    behavioral_health: 'Behavioral Health',
    dietary:           'Dietary',
    home_care:         'Home Care',
    idt:               'IDT / Care Coord.',
    activities:        'Activities',
    pharmacy:          'Pharmacy',
    transportation:    'Transportation',
    qa_compliance:     'QA / Compliance',
    it_admin:          'IT / Admin',
};

const VISIT_TYPE_LABELS: Record<string, string> = {
    in_center:   'In-Center',
    home_visit:  'Home Visit',
    telehealth:  'Telehealth',
    phone:       'Phone',
};

const STATUS_CLASSES: Record<string, string> = {
    draft:   'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 ring-amber-600/20',
    signed:  'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 ring-green-600/20',
    amended: 'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 ring-blue-600/20',
};

const DEPT_COLORS: Record<string, string> = {
    primary_care:      'border-l-blue-500',
    therapies:         'border-l-purple-500',
    social_work:       'border-l-teal-500',
    behavioral_health: 'border-l-rose-500',
    dietary:           'border-l-orange-500',
    home_care:         'border-l-emerald-500',
    idt:               'border-l-indigo-500',
};

function deptColor(dept: string): string {
    return DEPT_COLORS[dept] ?? 'border-l-slate-400';
}

function formatDate(d: string | null): string {
    if (!d) return '-';
    const date = new Date(d + 'T00:00:00');
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// ── Filter Bar ────────────────────────────────────────────────────────────────

function FilterBar({
    filters,
    noteTypes,
}: {
    filters: NotesPageProps['filters'];
    noteTypes: string[];
}) {
    const [dept, setDept]   = useState(filters.department ?? '');
    const [type, setType]   = useState(filters.note_type  ?? '');
    const [status, setStatus] = useState(filters.status   ?? '');

    const apply = () => {
        router.get('/clinical/notes', {
            ...(dept   ? { department: dept }   : {}),
            ...(type   ? { note_type: type }    : {}),
            ...(status ? { status }             : {}),
        }, { preserveState: false });
    };

    const clear = () => {
        setDept(''); setType(''); setStatus('');
        router.get('/clinical/notes', {}, { preserveState: false });
    };

    const hasFilters = !!(dept || type || status);

    return (
        <div className="flex flex-wrap items-end gap-3 mb-5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl px-4 py-3">
            <div>
                <label className="block text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Department</label>
                <select
                    value={dept}
                    onChange={e => setDept(e.target.value)}
                    className="rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm py-1.5 px-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="">All departments</option>
                    {Object.entries(DEPT_LABELS).map(([k, v]) => (
                        <option key={k} value={k}>{v}</option>
                    ))}
                </select>
            </div>
            <div>
                <label className="block text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Note Type</label>
                <select
                    value={type}
                    onChange={e => setType(e.target.value)}
                    className="rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm py-1.5 px-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="">All types</option>
                    {noteTypes.map(t => (
                        <option key={t} value={t}>{NOTE_TYPE_LABELS[t] ?? t}</option>
                    ))}
                </select>
            </div>
            <div>
                <label className="block text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Status</label>
                <select
                    value={status}
                    onChange={e => setStatus(e.target.value)}
                    className="rounded-lg border border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm py-1.5 px-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="signed">Signed</option>
                    <option value="amended">Amended</option>
                </select>
            </div>
            <div className="flex gap-2 items-center">
                <button
                    onClick={apply}
                    className="px-3 py-1.5 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                >
                    Apply
                </button>
                {hasFilters && (
                    <button
                        onClick={clear}
                        className="px-3 py-1.5 text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700"
                    >
                        Clear
                    </button>
                )}
            </div>
        </div>
    );
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function ClinicalNotesIndex() {
    const { notes, filters, noteTypes } = usePage<NotesPageProps>().props;

    return (
        <AppShell breadcrumbs={[{ label: 'Clinical' }, { label: 'Clinical Notes' }]}>
            <Head title="Clinical Notes" />

            {/* Header */}
            <div className="flex items-center justify-between mb-5">
                <div>
                    <h1 className="text-xl font-bold text-slate-900 dark:text-slate-100">Clinical Notes</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        All participant notes · {notes.total.toLocaleString()} total · select a participant row to open their chart
                    </p>
                </div>
            </div>

            {/* Filter bar */}
            <FilterBar filters={filters} noteTypes={noteTypes} />

            {/* Table */}
            <div className="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden">
                {notes.data.length === 0 ? (
                    <div className="px-6 py-12 text-center text-sm text-slate-500 dark:text-slate-400">
                        No clinical notes match the current filters.
                    </div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-100 dark:divide-slate-700">
                        <thead className="bg-slate-50 dark:bg-slate-900">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Participant</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Note Type</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Author</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Visit Date</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Visit Type</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                            {notes.data.map(note => (
                                <tr
                                    key={note.id}
                                    onClick={() => router.visit(`/participants/${note.participant?.id}?tab=chart`)}
                                    className={`border-l-4 ${deptColor(note.department)} hover:bg-slate-50 dark:hover:bg-slate-700 cursor-pointer transition-colors`}
                                >
                                    <td className="px-4 py-3">
                                        {note.participant ? (
                                            <div>
                                                <p className="text-sm font-medium text-slate-800 dark:text-slate-200">
                                                    {note.participant.first_name} {note.participant.last_name}
                                                </p>
                                                <p className="text-xs text-slate-400">{note.participant.mrn}</p>
                                            </div>
                                        ) : (
                                            <span className="text-slate-400 text-sm">-</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div>
                                            <p className="text-sm text-slate-800 dark:text-slate-200">
                                                {NOTE_TYPE_LABELS[note.note_type] ?? note.note_type}
                                            </p>
                                            <p className="text-xs text-slate-400">
                                                {DEPT_LABELS[note.department] ?? note.department}
                                            </p>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        {note.author ? (
                                            <p className="text-sm text-slate-700 dark:text-slate-300">
                                                {note.author.first_name} {note.author.last_name}
                                            </p>
                                        ) : (
                                            <span className="text-slate-400 text-sm">-</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-300 whitespace-nowrap">
                                        {formatDate(note.visit_date)}
                                    </td>
                                    <td className="px-4 py-3 text-sm text-slate-600 dark:text-slate-400">
                                        {note.visit_type ? (VISIT_TYPE_LABELS[note.visit_type] ?? note.visit_type) : '-'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className={`inline-flex items-center rounded px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset capitalize ${STATUS_CLASSES[note.status] ?? ''}`}>
                                            {note.status}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* Pagination */}
            {notes.last_page > 1 && (
                <div className="flex items-center justify-between mt-4 text-sm text-slate-500 dark:text-slate-400">
                    <span>
                        Showing {notes.from}–{notes.to} of {notes.total.toLocaleString()} notes
                    </span>
                    <div className="flex gap-1">
                        {notes.links.map((link, i) => (
                            <button
                                key={i}
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url, { preserveState: false })}
                                className={`px-3 py-1 rounded-lg text-xs border transition-colors ${
                                    link.active
                                        ? 'bg-blue-600 text-white border-blue-600'
                                        : link.url
                                            ? 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700'
                                            : 'border-slate-100 dark:border-slate-700 text-slate-300 cursor-default'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                </div>
            )}
        </AppShell>
    );
}
