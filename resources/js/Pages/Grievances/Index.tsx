// ─── Grievances/Index ─────────────────────────────────────────────────────────
// Grievance management list page per 42 CFR §460.120–§460.121.
//
// Layout: 3-tab view (Open | Resolved | CMS Reportable)
// Open tab: urgent rows highlighted in amber
// New Grievance modal: participant search + form fields
//
// Data: pre-loaded via Inertia (GrievanceController::index)
// Write access: any authenticated user may file; QA admin may update/resolve
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useRef, useEffect } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import {
    ExclamationTriangleIcon,
    PlusIcon,
    CheckCircleIcon,
    FlagIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline';

// ── Types ─────────────────────────────────────────────────────────────────────

interface GrievanceRow {
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
    cms_reportable:          boolean;
    participant_notified_at: string | null;
    resolution_date:         string | null;
    is_urgent_overdue:       boolean;
    is_standard_overdue:     boolean;
    created_at:              string;
}

interface ParticipantHit {
    id:   number;
    mrn:  string;
    name: string;
    dob:  string;
}

interface GrievancesIndexProps extends PageProps {
    openGrievances:     GrievanceRow[];
    resolvedGrievances: GrievanceRow[];
    cmsGrievances:      GrievanceRow[];
    categories:         Record<string, string>;
    statuses:           Record<string, string>;
    priorities:         string[];
    isQaAdmin:          boolean;
}

type Tab = 'open' | 'resolved' | 'cms';

// ── Status badge ──────────────────────────────────────────────────────────────

function StatusBadge({ status, label }: { status: string; label: string }) {
    const colors: Record<string, string> = {
        open:         'bg-blue-50 dark:bg-blue-950/50 text-blue-700 dark:text-blue-300',
        under_review: 'bg-amber-50 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300',
        escalated:    'bg-red-50 dark:bg-red-950/50 text-red-700 dark:text-red-300',
        resolved:     'bg-green-50 dark:bg-green-950/50 text-green-700 dark:text-green-300',
        withdrawn:    'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
    };
    return (
        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${colors[status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'}`}>
            {label}
        </span>
    );
}

// ── Priority badge ────────────────────────────────────────────────────────────

function PriorityBadge({ priority }: { priority: string }) {
    if (priority === 'urgent') {
        return (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-semibold bg-red-100 dark:bg-red-950/50 text-red-700 dark:text-red-300">
                <ExclamationTriangleIcon className="w-3 h-3" />
                Urgent
            </span>
        );
    }
    return (
        <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300">
            Standard
        </span>
    );
}

// ── Grievance table ───────────────────────────────────────────────────────────

function GrievanceTable({ grievances, onRowClick }: { grievances: GrievanceRow[]; onRowClick: (id: number) => void }) {
    if (grievances.length === 0) {
        return (
            <div className="text-center py-16 text-gray-500 dark:text-slate-400">
                <CheckCircleIcon className="w-10 h-10 mx-auto mb-2 opacity-30" />
                <p className="text-sm">No grievances in this category.</p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                <thead className="bg-gray-50 dark:bg-slate-700/50">
                    <tr>
                        {['Participant', 'Category', 'Filed By', 'Date Filed', 'Priority', 'Assigned', 'Status'].map(h => (
                            <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-slate-300 uppercase tracking-wide">{h}</th>
                        ))}
                    </tr>
                </thead>
                <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-100 dark:divide-slate-700">
                    {grievances.map(g => (
                        <tr
                            key={g.id}
                            onClick={() => onRowClick(g.id)}
                            className={`cursor-pointer transition-colors ${
                                g.priority === 'urgent'
                                    ? 'bg-amber-50 dark:bg-amber-900/20 hover:bg-amber-100 dark:hover:bg-amber-900/30'
                                    : 'hover:bg-gray-50 dark:hover:bg-slate-700/50'
                            }`}
                        >
                            <td className="px-4 py-3">
                                <span className="font-medium text-gray-900 dark:text-slate-100">{g.participant_name ?? '-'}</span>
                                {g.participant_mrn && <span className="block text-xs text-gray-400 dark:text-slate-500 font-mono">{g.participant_mrn}</span>}
                            </td>
                            <td className="px-4 py-3 text-gray-700 dark:text-slate-300">{g.category_label}</td>
                            <td className="px-4 py-3">
                                <span className="text-gray-700 dark:text-slate-300">{g.filed_by_name}</span>
                                <span className="block text-xs text-gray-400 dark:text-slate-500 capitalize">{g.filed_by_type.replace(/_/g, ' ')}</span>
                            </td>
                            <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                {new Date(g.filed_at).toLocaleDateString()}
                                {(g.is_urgent_overdue || g.is_standard_overdue) && (
                                    <span className="block text-xs font-semibold text-red-600 dark:text-red-400 mt-0.5">Overdue</span>
                                )}
                            </td>
                            <td className="px-4 py-3"><PriorityBadge priority={g.priority} /></td>
                            <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{g.assigned_to ?? <span className="text-gray-300 dark:text-slate-600">Unassigned</span>}</td>
                            <td className="px-4 py-3"><StatusBadge status={g.status} label={g.status_label} /></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ── New Grievance Modal ───────────────────────────────────────────────────────

interface NewGrievanceModalProps {
    categories: Record<string, string>;
    onClose: () => void;
}

function NewGrievanceModal({ categories, onClose }: NewGrievanceModalProps) {
    const [search, setSearch]       = useState('');
    const [results, setResults]     = useState<ParticipantHit[]>([]);
    const [selected, setSelected]   = useState<ParticipantHit | null>(null);
    const [searching, setSearching] = useState(false);
    const [form, setForm]           = useState({
        filed_by_name: '',
        filed_by_type: 'participant',
        category:      'quality_of_care',
        description:   '',
        priority:      'standard',
        filed_at:      new Date().toISOString().slice(0, 10),
    });
    const [submitting, setSubmitting] = useState(false);
    const [error, setError]           = useState<string | null>(null);
    const searchRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Participant search with debounce
    useEffect(() => {
        if (search.trim().length < 2) { setResults([]); return; }
        if (searchRef.current) clearTimeout(searchRef.current);
        searchRef.current = setTimeout(async () => {
            setSearching(true);
            try {
                const res = await axios.get('/participants/search', { params: { q: search } });
                setResults(res.data);
            } finally {
                setSearching(false);
            }
        }, 280);
    }, [search]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!selected) { setError('Please select a participant.'); return; }
        setSubmitting(true);
        setError(null);
        try {
            await axios.post('/grievances', { ...form, participant_id: selected.id });
            router.reload({ only: ['openGrievances', 'resolvedGrievances', 'cmsGrievances'] });
            onClose();
        } catch (err: unknown) {
            const axiosErr = err as { response?: { data?: { message?: string } } };
            setError(axiosErr.response?.data?.message ?? 'Failed to file grievance.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center px-4" onClick={e => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="absolute inset-0 bg-black/40 backdrop-blur-sm pointer-events-none" />
            <div className="relative bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg p-6 overflow-y-auto max-h-[90vh]">
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-slate-100">File New Grievance</h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 dark:hover:text-slate-200"><XMarkIcon className="w-5 h-5" /></button>
                </div>

                {error && <p className="mb-3 text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/30 rounded p-2">{error}</p>}

                <form onSubmit={handleSubmit} className="space-y-4">
                    {/* Participant search */}
                    <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Participant</label>
                        {selected ? (
                            <div className="flex items-center justify-between bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 rounded-lg px-3 py-2">
                                <span className="text-sm font-medium text-blue-800 dark:text-blue-200">{selected.name} <span className="font-mono text-xs text-blue-500">{selected.mrn}</span></span>
                                <button type="button" onClick={() => setSelected(null)} className="text-blue-400 hover:text-blue-600"><XMarkIcon className="w-4 h-4" /></button>
                            </div>
                        ) : (
                            <div className="relative">
                                <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search by name or MRN…" className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:bg-slate-700" />
                                {searching && <span className="absolute right-3 top-2.5 text-xs text-gray-400">Searching…</span>}
                                {results.length > 0 && (
                                    <ul className="absolute z-10 w-full bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto">
                                        {results.map(r => (
                                            <li key={r.id} onClick={() => { setSelected(r); setSearch(''); setResults([]); }}
                                                className="px-3 py-2 cursor-pointer hover:bg-blue-50 dark:hover:bg-slate-600 text-sm">
                                                <span className="font-medium text-gray-900 dark:text-slate-100">{r.name}</span>
                                                <span className="ml-2 font-mono text-xs text-gray-400">{r.mrn}</span>
                                                <span className="ml-2 text-xs text-gray-400">DOB {r.dob}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Filed By (Name)</label>
                            <input required value={form.filed_by_name} onChange={e => setForm(f => ({ ...f, filed_by_name: e.target.value }))} className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700" />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Filed By (Type)</label>
                            <select value={form.filed_by_type} onChange={e => setForm(f => ({ ...f, filed_by_type: e.target.value }))} className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700">
                                {['participant','family_member','caregiver','legal_representative','staff','anonymous'].map(t => (
                                    <option key={t} value={t}>{t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Category</label>
                            <select value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))} className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700">
                                {Object.entries(categories).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Priority</label>
                            <select value={form.priority} onChange={e => setForm(f => ({ ...f, priority: e.target.value }))} className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700">
                                <option value="standard">Standard (30 days)</option>
                                <option value="urgent">Urgent (72 hours)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Date Filed</label>
                        <input type="date" value={form.filed_at} onChange={e => setForm(f => ({ ...f, filed_at: e.target.value }))} className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700" />
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Description <span className="text-red-500">*</span></label>
                        <textarea required rows={4} value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))} placeholder="Describe the grievance in detail…" className="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-700 resize-none" />
                    </div>

                    <div className="flex gap-3 pt-2">
                        <button type="button" onClick={onClose} className="flex-1 px-4 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg text-gray-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700">
                            Cancel
                        </button>
                        <button type="submit" disabled={submitting} className="flex-1 px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium disabled:opacity-50">
                            {submitting ? 'Filing…' : 'File Grievance'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ── Main Component ────────────────────────────────────────────────────────────

export default function GrievancesIndex() {
    const { openGrievances, resolvedGrievances, cmsGrievances, categories, isQaAdmin } =
        usePage<GrievancesIndexProps>().props;

    const [activeTab, setActiveTab] = useState<Tab>('open');
    const [showModal, setShowModal]  = useState(false);

    const navigate = (id: number) => router.visit(`/grievances/${id}`);

    const tabs: [Tab, string, number][] = [
        ['open',     'Open',           openGrievances.length],
        ['resolved', 'Resolved',       resolvedGrievances.length],
        ['cms',      'CMS Reportable', cmsGrievances.length],
    ];

    const currentRows = activeTab === 'open' ? openGrievances
        : activeTab === 'resolved' ? resolvedGrievances
        : cmsGrievances;

    return (
        <AppShell>
            <Head title="Grievances" />

            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">Grievances</h1>
                    <p className="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
                        42 CFR §460.120: Standard resolution within 30 days. Urgent within 72 hours.
                    </p>
                </div>
                <button
                    onClick={() => setShowModal(true)}
                    className="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg"
                >
                    <PlusIcon className="w-4 h-4" />
                    New Grievance
                </button>
            </div>

            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm overflow-hidden">
                {/* Tab bar */}
                <div className="border-b border-gray-200 dark:border-slate-700 px-4 flex gap-1 pt-1">
                    {tabs.map(([key, label, count]) => (
                        <button
                            key={key}
                            onClick={() => setActiveTab(key)}
                            className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
                                activeTab === key
                                    ? 'border-blue-600 text-blue-700 dark:text-blue-300'
                                    : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200'
                            }`}
                        >
                            {label}
                            {count > 0 && (
                                <span className="ml-1.5 bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 text-xs px-1.5 py-0.5 rounded-full">
                                    {count}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                <GrievanceTable grievances={currentRows} onRowClick={navigate} />
            </div>

            {showModal && <NewGrievanceModal categories={categories} onClose={() => setShowModal(false)} />}
        </AppShell>
    );
}
