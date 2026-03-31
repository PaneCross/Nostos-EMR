// ─── Clinical/Medications.tsx ─────────────────────────────────────────────────
// Cross-participant medication population overview page.
//
// Data loaded server-side (Inertia props):
//   - kpis: total_active, total_prn, active_interaction_alerts, participants_with_meds
//   - participants: [{id, name, mrn, active_count, prn_count, controlled_count, open_alerts}]
//
// Layout: KPI row + participant medication summary table with drill-down links
// ─────────────────────────────────────────────────────────────────────────────

import React from 'react'
import { Head, Link } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import {
    ExclamationTriangleIcon,
    BeakerIcon,
    UserGroupIcon,
    ClipboardDocumentListIcon,
} from '@heroicons/react/24/outline'

interface MedParticipant {
    id: number
    name: string
    mrn: string
    active_count: number
    prn_count: number
    controlled_count: number
    open_alerts: number
}

interface Kpis {
    total_active: number
    total_prn: number
    active_interaction_alerts: number
    participants_with_meds: number
}

interface Props {
    kpis: Kpis
    participants: MedParticipant[]
}

export default function ClinicalMedications({ kpis, participants }: Props) {
    return (
        <>
            <Head title="Medications Overview" />
            <AppShell breadcrumbs={[{ label: 'Clinical', href: '/clinical/notes' }, { label: 'Medications Overview' }]}>
                <div className="p-6 space-y-6">
                    {/* Header */}
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">
                            Medications Overview
                        </h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
                            Active medications across all enrolled participants. Per-participant details are in each participant's Medications tab.
                        </p>
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <div className="flex items-center gap-2 text-blue-600 dark:text-blue-400 mb-1">
                                <BeakerIcon className="w-5 h-5" />
                                <span className="text-xs font-medium uppercase tracking-wide">Active Medications</span>
                            </div>
                            <p className="text-3xl font-bold text-gray-900 dark:text-slate-100">{kpis.total_active}</p>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1">across all participants</p>
                        </div>

                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <div className="flex items-center gap-2 text-purple-600 dark:text-purple-400 mb-1">
                                <ClipboardDocumentListIcon className="w-5 h-5" />
                                <span className="text-xs font-medium uppercase tracking-wide">PRN Medications</span>
                            </div>
                            <p className="text-3xl font-bold text-gray-900 dark:text-slate-100">{kpis.total_prn}</p>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1">as-needed orders</p>
                        </div>

                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <div className="flex items-center gap-2 text-amber-600 dark:text-amber-400 mb-1">
                                <ExclamationTriangleIcon className="w-5 h-5" />
                                <span className="text-xs font-medium uppercase tracking-wide">Interaction Alerts</span>
                            </div>
                            <p className={`text-3xl font-bold ${kpis.active_interaction_alerts > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-slate-100'}`}>
                                {kpis.active_interaction_alerts}
                            </p>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1">unacknowledged</p>
                        </div>

                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <div className="flex items-center gap-2 text-green-600 dark:text-green-400 mb-1">
                                <UserGroupIcon className="w-5 h-5" />
                                <span className="text-xs font-medium uppercase tracking-wide">Participants</span>
                            </div>
                            <p className="text-3xl font-bold text-gray-900 dark:text-slate-100">{kpis.participants_with_meds}</p>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1">with active medications</p>
                        </div>
                    </div>

                    {/* Participant Table */}
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
                        <div className="px-4 py-3 border-b border-gray-200 dark:border-slate-700">
                            <h2 className="text-sm font-semibold text-gray-700 dark:text-slate-300">
                                Participant Medication Summary
                            </h2>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-gray-50 dark:bg-slate-700/50 text-left">
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Participant</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">MRN</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400 text-right">Active</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400 text-right">PRN</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400 text-right">Controlled</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400 text-right">Interactions</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
                                    {participants.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="px-4 py-8 text-center text-gray-400 dark:text-slate-500">
                                                No participants with active medications.
                                            </td>
                                        </tr>
                                    ) : participants.map(p => (
                                        <tr key={p.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                                            <td className="px-4 py-2 font-medium text-gray-700 dark:text-slate-300">{p.name}</td>
                                            <td className="px-4 py-2 text-gray-500 dark:text-slate-400 font-mono text-xs">{p.mrn}</td>
                                            <td className="px-4 py-2 text-right text-gray-700 dark:text-slate-300">{p.active_count}</td>
                                            <td className="px-4 py-2 text-right text-gray-500 dark:text-slate-400">{p.prn_count}</td>
                                            <td className="px-4 py-2 text-right text-gray-500 dark:text-slate-400">{p.controlled_count}</td>
                                            <td className="px-4 py-2 text-right">
                                                {p.open_alerts > 0 ? (
                                                    <span className="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400 font-medium">
                                                        <ExclamationTriangleIcon className="w-3.5 h-3.5" />
                                                        {p.open_alerts}
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-400 dark:text-slate-500">-</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                <Link
                                                    href={`/participants/${p.id}?tab=medications`}
                                                    className="text-blue-600 dark:text-blue-400 hover:underline text-xs"
                                                >
                                                    View
                                                </Link>
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
