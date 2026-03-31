// ─── Finance/ComplianceChecklist ──────────────────────────────────────────────
// Billing Compliance Checklist page for the Finance department.
//
// Data flow:
//   - Initial props: checklist (server-rendered via Inertia — BillingComplianceController)
//   - Refresh: GET /billing/compliance-checklist/data (axios, on demand)
//
// Checklist structure:
//   { generated_at, overall_status: 'pass'|'warn'|'fail', categories: {...} }
//   Each category has { label, checks: [{label, status, value, detail}] }
//
// Five categories: Encounter Data, Risk Adjustment, Capitation, HPMS, Part D
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react'
import { Head } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import axios from 'axios'
import { CheckCircleIcon, ExclamationTriangleIcon, XCircleIcon } from '@heroicons/react/24/solid'

// ── Types ─────────────────────────────────────────────────────────────────────

type CheckStatus = 'pass' | 'warn' | 'fail'

interface CheckItem {
    label: string
    status: CheckStatus
    value: string
    detail: string
}

interface CheckCategory {
    label: string
    checks: CheckItem[]
}

interface Checklist {
    generated_at: string
    overall_status: CheckStatus
    categories: Record<string, CheckCategory>
}

interface Props {
    checklist: Checklist
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const statusColors: Record<CheckStatus, string> = {
    pass: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800 text-green-700 dark:text-green-300',
    warn: 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300',
    fail: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800 text-red-700 dark:text-red-300',
}

const statusBadge: Record<CheckStatus, string> = {
    pass: 'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
    warn: 'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300',
    fail: 'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300',
}

const statusIcon: Record<CheckStatus, React.ReactNode> = {
    pass: <CheckCircleIcon className="w-4 h-4" />,
    warn: <ExclamationTriangleIcon className="w-4 h-4" />,
    fail: <XCircleIcon className="w-4 h-4" />,
}

const overallBanner: Record<CheckStatus, { bg: string; text: string; msg: string }> = {
    pass: { bg: 'bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800', text: 'text-green-800 dark:text-green-300', msg: 'All billing compliance checks passed.' },
    warn: { bg: 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800', text: 'text-amber-800 dark:text-amber-300', msg: 'Some checks require attention. Review warnings below.' },
    fail: { bg: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800', text: 'text-red-800 dark:text-red-300', msg: 'Critical compliance issues detected. Immediate action required.' },
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function ComplianceChecklist({ checklist: initial }: Props) {
    const [checklist, setChecklist] = useState<Checklist>(initial)
    const [refreshing, setRefreshing]  = useState(false)

    const refresh = async () => {
        setRefreshing(true)
        try {
            const r = await axios.get('/billing/compliance-checklist/data')
            setChecklist(r.data)
        } finally {
            setRefreshing(false)
        }
    }

    const banner = overallBanner[checklist.overall_status]
    const generatedAt = new Date(checklist.generated_at).toLocaleString()

    return (
        <AppShell>
            <Head title="Billing Compliance Checklist" />

            <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-slate-100">Billing Compliance Checklist</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                            CMS PACE billing compliance across 5 categories. Generated {generatedAt}.
                        </p>
                    </div>
                    <button
                        onClick={refresh}
                        disabled={refreshing}
                        className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-50 whitespace-nowrap"
                    >
                        {refreshing ? 'Refreshing…' : 'Refresh Checklist'}
                    </button>
                </div>

                {/* Overall Status Banner */}
                <div className={`border rounded-xl p-4 ${banner.bg}`}>
                    <p className={`font-semibold ${banner.text}`}>
                        {statusIcon[checklist.overall_status]} {banner.msg}
                    </p>
                </div>

                {/* Category Sections */}
                {Object.entries(checklist.categories).map(([key, category]) => {
                    const catStatus = category.checks.some(c => c.status === 'fail') ? 'fail'
                        : category.checks.some(c => c.status === 'warn') ? 'warn' : 'pass'

                    return (
                        <div key={key} className="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                            {/* Category Header */}
                            <div className={`px-5 py-3 border-b flex items-center justify-between ${
                                catStatus === 'fail' ? 'bg-red-50 dark:bg-red-950/60 border-red-100 dark:border-red-800' :
                                catStatus === 'warn' ? 'bg-amber-50 dark:bg-amber-950/60 border-amber-100 dark:border-amber-800' :
                                'bg-green-50 dark:bg-green-950/60 border-green-100 dark:border-green-800'
                            }`}>
                                <h2 className="font-semibold text-slate-800 dark:text-slate-200">{category.label}</h2>
                                <span className={`text-xs font-bold px-2.5 py-1 rounded-full ${statusBadge[catStatus]}`}>
                                    {statusIcon[catStatus]} {catStatus.toUpperCase()}
                                </span>
                            </div>

                            {/* Checks */}
                            <div className="divide-y divide-slate-100 dark:divide-slate-700">
                                {category.checks.map((check, idx) => (
                                    <div key={idx} className="px-5 py-4 flex items-start gap-4">
                                        {/* Status icon */}
                                        <div className={`flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold border ${statusColors[check.status]}`}>
                                            {statusIcon[check.status]}
                                        </div>

                                        {/* Check content */}
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-3 flex-wrap">
                                                <p className="font-medium text-slate-900 dark:text-slate-100 text-sm">{check.label}</p>
                                                <span className={`text-xs font-semibold px-2 py-0.5 rounded ${statusBadge[check.status]}`}>
                                                    {check.value}
                                                </span>
                                            </div>
                                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">{check.detail}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )
                })}
            </div>
        </AppShell>
    )
}
