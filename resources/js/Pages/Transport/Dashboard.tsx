// ─── Transport Dashboard ───────────────────────────────────────────────────────
// Shows all active participants with their transport-relevant flags
// (wheelchair, stretcher, oxygen, behavioral) and home address.
// Click any row to navigate to that participant's profile.
// Route: GET /transport → Inertia::render('Transport/Dashboard')
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

interface TransportFlag {
    flag_type: 'wheelchair' | 'stretcher' | 'oxygen' | 'behavioral';
    severity: 'low' | 'medium' | 'high' | 'critical';
    description: string | null;
}

interface HomeAddress {
    line: string;
    city: string;
    state: string;
    zip: string;
}

interface ParticipantRow {
    id: number;
    mrn: string;
    first_name: string;
    last_name: string;
    flags: TransportFlag[];
    address: HomeAddress | null;
}

interface TransportStats {
    total_active: number;
    needs_wheelchair: number;
    needs_stretcher: number;
    needs_oxygen: number;
    has_behavioral: number;
    no_flags: number;
}

interface TransportDashboardProps extends PageProps {
    participants: ParticipantRow[];
    stats: TransportStats;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

const FLAG_CONFIG: Record<string, { label: string; classes: string; dotColor: string }> = {
    wheelchair: {
        label:    'Wheelchair',
        classes:  'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300 ring-blue-600/20',
        dotColor: 'bg-blue-500',
    },
    stretcher: {
        label:    'Stretcher',
        classes:  'bg-orange-100 text-orange-800 ring-orange-600/20',
        dotColor: 'bg-orange-500',
    },
    oxygen: {
        label:    'Oxygen',
        classes:  'bg-teal-100 text-teal-800 dark:text-teal-300 ring-teal-600/20',
        dotColor: 'bg-teal-500',
    },
    behavioral: {
        label:    'Behavioral',
        classes:  'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 ring-red-600/20',
        dotColor: 'bg-red-500',
    },
};

const SEVERITY_CLASSES: Record<string, string> = {
    low:      'bg-blue-400',
    medium:   'bg-yellow-400',
    high:     'bg-orange-500',
    critical: 'bg-red-600',
};

function FlagBadge({ flag }: { flag: TransportFlag }) {
    const cfg = FLAG_CONFIG[flag.flag_type];
    if (!cfg) return null;
    return (
        <span
            className={`inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset ${cfg.classes}`}
            title={flag.description ?? cfg.label}
        >
            <span className={`w-1.5 h-1.5 rounded-full ${SEVERITY_CLASSES[flag.severity] ?? 'bg-slate-400'}`} />
            {cfg.label}
        </span>
    );
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function TransportDashboard() {
    const { participants, stats } = usePage<TransportDashboardProps>().props;

    const [flagFilter, setFlagFilter] = useState<string>('');
    const [search, setSearch]         = useState('');

    const filtered = participants.filter(p => {
        if (flagFilter === 'none' && p.flags.length > 0) return false;
        if (flagFilter && flagFilter !== 'none' && !p.flags.some(f => f.flag_type === flagFilter)) return false;
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

    // Behavioral flags are highest priority — warn if any exist
    const behavioralParticipants = participants.filter(p => p.flags.some(f => f.flag_type === 'behavioral'));

    return (
        <AppShell breadcrumbs={[{ label: 'Transportation' }, { label: 'Dashboard' }]}>
            <Head title="Transport Dashboard" />

            {/* Header */}
            <div className="flex items-center justify-between mb-5">
                <div>
                    <h1 className="text-xl font-bold text-slate-900 dark:text-slate-100">Transport Dashboard</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        Active participant transport needs · mobility equipment · behavioral flags · click a row to view profile
                    </p>
                </div>
            </div>

            {/* Stat chips */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-5">
                {[
                    { label: 'Active Census',  count: stats.total_active,     color: 'bg-slate-50 dark:bg-slate-900 border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300',     filter: '' },
                    { label: 'Wheelchair',     count: stats.needs_wheelchair, color: 'bg-blue-50 dark:bg-blue-950/60 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300',         filter: 'wheelchair' },
                    { label: 'Stretcher',      count: stats.needs_stretcher,  color: 'bg-orange-50 border-orange-200 text-orange-800',   filter: 'stretcher' },
                    { label: 'Oxygen',         count: stats.needs_oxygen,     color: 'bg-teal-50 dark:bg-teal-950/60 border-teal-200 text-teal-800 dark:text-teal-300',         filter: 'oxygen' },
                    { label: 'Behavioral',     count: stats.has_behavioral,   color: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300',            filter: 'behavioral' },
                    { label: 'No Flags',       count: stats.no_flags,         color: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300',      filter: 'none' },
                ].map(chip => (
                    <button
                        key={chip.label}
                        onClick={() => chip.filter !== undefined ? setFlagFilter(f => f === chip.filter ? '' : chip.filter) : undefined}
                        className={`border rounded-xl px-4 py-3 text-left transition-all hover:shadow-sm ${chip.color} ${
                            flagFilter === chip.filter && chip.filter !== '' ? 'ring-2 ring-offset-1 ring-current' : ''
                        }`}
                    >
                        <p className="text-2xl font-bold">{chip.count}</p>
                        <p className="text-xs font-medium mt-0.5">{chip.label}</p>
                    </button>
                ))}
            </div>

            {/* Behavioral warning banner */}
            {behavioralParticipants.length > 0 && (
                <div className="mb-4 flex items-start gap-3 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3">
                    <svg className="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <div>
                        <p className="text-sm font-semibold text-red-800 dark:text-red-300">
                            {behavioralParticipants.length} participant{behavioralParticipants.length !== 1 ? 's' : ''} with active behavioral flag{behavioralParticipants.length !== 1 ? 's' : ''}
                        </p>
                        <p className="text-xs text-red-700 dark:text-red-300 mt-0.5">
                            {behavioralParticipants.map(p => `${p.first_name} ${p.last_name}`).join(', ')}
                        </p>
                    </div>
                </div>
            )}

            {/* Filters + search */}
            <div className="flex items-center gap-3 mb-4">
                <input
                    type="text"
                    placeholder="Search by name or MRN…"
                    value={search}
                    onChange={e => setSearch(e.target.value)}
                    className="rounded-lg border border-slate-300 text-sm py-1.5 px-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-56"
                />
                <select
                    value={flagFilter}
                    onChange={e => setFlagFilter(e.target.value)}
                    className="rounded-lg border border-slate-300 text-sm py-1.5 px-2.5 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
                    <option value="">All participants</option>
                    <option value="wheelchair">Wheelchair</option>
                    <option value="stretcher">Stretcher</option>
                    <option value="oxygen">Oxygen</option>
                    <option value="behavioral">Behavioral flag</option>
                    <option value="none">No transport flags</option>
                </select>
                {(flagFilter || search) && (
                    <button
                        onClick={() => { setFlagFilter(''); setSearch(''); }}
                        className="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-700"
                    >
                        Clear
                    </button>
                )}
                <span className="ml-auto text-sm text-slate-500 dark:text-slate-400">
                    {filtered.length} participant{filtered.length !== 1 ? 's' : ''}
                </span>
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
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Transport Flags</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Home Address</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                            {filtered.map(p => {
                                const hasBehavioral = p.flags.some(f => f.flag_type === 'behavioral');
                                const hasHighPriority = p.flags.some(f => f.severity === 'critical' || f.severity === 'high');
                                return (
                                    <tr
                                        key={p.id}
                                        onClick={() => router.visit(`/participants/${p.id}?tab=flags`)}
                                        className={`cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors border-l-4 ${
                                            hasBehavioral   ? 'border-l-red-500 bg-red-50 dark:bg-red-950/60/20'
                                            : hasHighPriority ? 'border-l-orange-400'
                                            : p.flags.length > 0 ? 'border-l-blue-400'
                                            : 'border-l-transparent'
                                        }`}
                                    >
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-slate-800 dark:text-slate-200">{p.first_name} {p.last_name}</p>
                                            <p className="text-xs text-slate-400">{p.mrn}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            {p.flags.length === 0 ? (
                                                <span className="text-slate-300 text-xs">None</span>
                                            ) : (
                                                <div className="flex flex-wrap gap-1">
                                                    {p.flags.map((f, i) => (
                                                        <FlagBadge key={i} flag={f} />
                                                    ))}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                                            {p.address ? (
                                                <div>
                                                    <p className="text-sm">{p.address.line}</p>
                                                    <p className="text-xs text-slate-400">{p.address.city}, {p.address.state} {p.address.zip}</p>
                                                </div>
                                            ) : (
                                                <span className="text-slate-300 text-xs">No address on file</span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </div>

            <p className="mt-3 text-xs text-slate-400">
                Transport flags are synced from participant profiles. Trip scheduling, dispatch map, and route management are coming in a future phase.
            </p>
        </AppShell>
    );
}
