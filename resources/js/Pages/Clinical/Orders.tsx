// ─── Clinical/Orders.tsx ─────────────────────────────────────────────────────
// Clinical orders worklist — shows active care plan goals across all enrolled
// participants, ordered by target date (soonest first).
//
// This page functions as a population-level care plan goal tracker until a
// full CPOE module is built (DEBT-CPOE / POST-11 in roadmap).
//
// Data loaded server-side (Inertia props):
//   - kpis: active_goals, overdue_goals, met_this_month
//   - goals: [{id, participant_id, participant_name, mrn, domain, goal_description,
//              target_date, is_overdue, progress_notes}]
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react'
import { Head, Link } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import {
    ClipboardDocumentCheckIcon,
    ExclamationCircleIcon,
    CheckCircleIcon,
    FunnelIcon,
} from '@heroicons/react/24/outline'

interface Goal {
    id: number
    participant_id: number | null
    participant_name: string
    mrn: string
    domain: string
    goal_description: string
    target_date: string | null
    is_overdue: boolean
    progress_notes: string | null
}

interface Kpis {
    active_goals: number
    overdue_goals: number
    met_this_month: number
}

interface Props {
    kpis: Kpis
    goals: Goal[]
}

const DOMAIN_LABELS: Record<string, string> = {
    medical:        'Medical',
    nursing:        'Nursing',
    social:         'Social Work',
    behavioral:     'Behavioral Health',
    therapy_pt:     'PT',
    therapy_ot:     'OT',
    therapy_st:     'Speech',
    dietary:        'Dietary',
    activities:     'Activities',
    home_care:      'Home Care',
    transportation: 'Transportation',
    pharmacy:       'Pharmacy',
}

const DOMAIN_COLORS: Record<string, string> = {
    medical:        'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
    nursing:        'bg-cyan-100 dark:bg-cyan-900/40 text-cyan-700 dark:text-cyan-300',
    social:         'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
    behavioral:     'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300',
    therapy_pt:     'bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300',
    therapy_ot:     'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300',
    therapy_st:     'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300',
    dietary:        'bg-lime-100 dark:bg-lime-900/40 text-lime-700 dark:text-lime-300',
    activities:     'bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300',
    home_care:      'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300',
    transportation: 'bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300',
    pharmacy:       'bg-pink-100 dark:bg-pink-900/40 text-pink-700 dark:text-pink-300',
}

export default function ClinicalOrders({ kpis, goals }: Props) {
    const [domainFilter, setDomainFilter] = useState<string>('all')
    const [overdueOnly,  setOverdueOnly]  = useState(false)

    const filtered = goals.filter(g => {
        if (overdueOnly && !g.is_overdue) return false
        if (domainFilter !== 'all' && g.domain !== domainFilter) return false
        return true
    })

    return (
        <>
            <Head title="Clinical Orders" />
            <AppShell breadcrumbs={[{ label: 'Clinical', href: '/clinical/notes' }, { label: 'Clinical Orders' }]}>
                <div className="p-6 space-y-6">
                    {/* Header */}
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">
                            Clinical Orders
                        </h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
                            Active care plan goals across all enrolled participants. Per-participant goals are managed in each participant's Care Plan tab.
                        </p>
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-3 gap-4">
                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <div className="flex items-center gap-2 text-blue-600 dark:text-blue-400 mb-1">
                                <ClipboardDocumentCheckIcon className="w-5 h-5" />
                                <span className="text-xs font-medium uppercase tracking-wide">Active Goals</span>
                            </div>
                            <p className="text-3xl font-bold text-gray-900 dark:text-slate-100">{kpis.active_goals}</p>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1">across all care plans</p>
                        </div>

                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <div className="flex items-center gap-2 text-red-600 dark:text-red-400 mb-1">
                                <ExclamationCircleIcon className="w-5 h-5" />
                                <span className="text-xs font-medium uppercase tracking-wide">Overdue Goals</span>
                            </div>
                            <p className={`text-3xl font-bold ${kpis.overdue_goals > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-slate-100'}`}>
                                {kpis.overdue_goals}
                            </p>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1">past target date</p>
                        </div>

                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <div className="flex items-center gap-2 text-green-600 dark:text-green-400 mb-1">
                                <CheckCircleIcon className="w-5 h-5" />
                                <span className="text-xs font-medium uppercase tracking-wide">Met This Month</span>
                            </div>
                            <p className="text-3xl font-bold text-gray-900 dark:text-slate-100">{kpis.met_this_month}</p>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1">goals achieved</p>
                        </div>
                    </div>

                    {/* Filters */}
                    <div className="flex flex-wrap items-center gap-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3">
                        <FunnelIcon className="w-4 h-4 text-gray-400 dark:text-slate-500 shrink-0" />

                        <select
                            value={domainFilter}
                            onChange={e => setDomainFilter(e.target.value)}
                            className="text-sm border border-gray-300 dark:border-slate-600 rounded-md bg-white dark:bg-slate-700 text-gray-700 dark:text-slate-300 px-2 py-1"
                        >
                            <option value="all">All Domains</option>
                            {Object.entries(DOMAIN_LABELS).map(([key, label]) => (
                                <option key={key} value={key}>{label}</option>
                            ))}
                        </select>

                        <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-slate-400 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={overdueOnly}
                                onChange={e => setOverdueOnly(e.target.checked)}
                                className="rounded border-gray-300 dark:border-slate-600"
                            />
                            Overdue only
                        </label>

                        <span className="ml-auto text-xs text-gray-400 dark:text-slate-500">
                            {filtered.length} goal{filtered.length !== 1 ? 's' : ''}
                        </span>
                    </div>

                    {/* Goals Table */}
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-gray-50 dark:bg-slate-700/50 text-left">
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Participant</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Domain</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Goal</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Target Date</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
                                    {filtered.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="px-4 py-8 text-center text-gray-400 dark:text-slate-500">
                                                No active goals match the current filters.
                                            </td>
                                        </tr>
                                    ) : filtered.map(goal => (
                                        <tr key={goal.id} className={`hover:bg-gray-50 dark:hover:bg-slate-700/50 ${goal.is_overdue ? 'bg-red-50/40 dark:bg-red-900/10' : ''}`}>
                                            <td className="px-4 py-2">
                                                <p className="font-medium text-gray-700 dark:text-slate-300">{goal.participant_name}</p>
                                                <p className="text-xs text-gray-400 dark:text-slate-500 font-mono">{goal.mrn}</p>
                                            </td>
                                            <td className="px-4 py-2">
                                                <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${DOMAIN_COLORS[goal.domain] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300'}`}>
                                                    {DOMAIN_LABELS[goal.domain] ?? goal.domain}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 text-gray-700 dark:text-slate-300 max-w-sm">
                                                <p className="line-clamp-2">{goal.goal_description}</p>
                                            </td>
                                            <td className="px-4 py-2">
                                                {goal.target_date ? (
                                                    <span className={`text-sm ${goal.is_overdue ? 'text-red-600 dark:text-red-400 font-medium' : 'text-gray-600 dark:text-slate-400'}`}>
                                                        {goal.is_overdue && (
                                                            <ExclamationCircleIcon className="w-3.5 h-3.5 inline mr-1" />
                                                        )}
                                                        {goal.target_date}
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-400 dark:text-slate-500">-</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                {goal.participant_id && (
                                                    <Link
                                                        href={`/participants/${goal.participant_id}?tab=careplan`}
                                                        className="text-blue-600 dark:text-blue-400 hover:underline text-xs"
                                                    >
                                                        View
                                                    </Link>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </AppShell>
        </>
    )
}
