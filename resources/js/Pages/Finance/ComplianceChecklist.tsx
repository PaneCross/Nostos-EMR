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
    pass: 'bg-green-50 border-green-200 text-green-700',
    warn: 'bg-amber-50 border-amber-200 text-amber-700',
    fail: 'bg-red-50 border-red-200 text-red-700',
}

const statusBadge: Record<CheckStatus, string> = {
    pass: 'bg-green-100 text-green-800',
    warn: 'bg-amber-100 text-amber-800',
    fail: 'bg-red-100 text-red-800',
}

const statusIcon: Record<CheckStatus, string> = {
    pass: '✓',
    warn: '⚠',
    fail: '✗',
}

const overallBanner: Record<CheckStatus, { bg: string; text: string; msg: string }> = {
    pass: { bg: 'bg-green-50 border-green-200', text: 'text-green-800', msg: 'All billing compliance checks passed.' },
    warn: { bg: 'bg-amber-50 border-amber-200', text: 'text-amber-800', msg: 'Some checks require attention. Review warnings below.' },
    fail: { bg: 'bg-red-50 border-red-200', text: 'text-red-800', msg: 'Critical compliance issues detected. Immediate action required.' },
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
                        <h1 className="text-2xl font-bold text-slate-900">Billing Compliance Checklist</h1>
                        <p className="text-sm text-slate-500 mt-1">
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
                        <div key={key} className="bg-white rounded-xl border border-slate-200 overflow-hidden">
                            {/* Category Header */}
                            <div className={`px-5 py-3 border-b flex items-center justify-between ${
                                catStatus === 'fail' ? 'bg-red-50 border-red-100' :
                                catStatus === 'warn' ? 'bg-amber-50 border-amber-100' :
                                'bg-green-50 border-green-100'
                            }`}>
                                <h2 className="font-semibold text-slate-800">{category.label}</h2>
                                <span className={`text-xs font-bold px-2.5 py-1 rounded-full ${statusBadge[catStatus]}`}>
                                    {statusIcon[catStatus]} {catStatus.toUpperCase()}
                                </span>
                            </div>

                            {/* Checks */}
                            <div className="divide-y divide-slate-100">
                                {category.checks.map((check, idx) => (
                                    <div key={idx} className="px-5 py-4 flex items-start gap-4">
                                        {/* Status icon */}
                                        <div className={`flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold border ${statusColors[check.status]}`}>
                                            {statusIcon[check.status]}
                                        </div>

                                        {/* Check content */}
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-3 flex-wrap">
                                                <p className="font-medium text-slate-900 text-sm">{check.label}</p>
                                                <span className={`text-xs font-semibold px-2 py-0.5 rounded ${statusBadge[check.status]}`}>
                                                    {check.value}
                                                </span>
                                            </div>
                                            <p className="text-xs text-slate-500 mt-1">{check.detail}</p>
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
