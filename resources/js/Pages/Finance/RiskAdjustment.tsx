// ─── Finance/RiskAdjustment ────────────────────────────────────────────────────
// CMS-HCC Risk Adjustment tracking page for the Finance department.
//
// Data flow:
//   - Initial props: gapSummary, riskScores, year (server-rendered via Inertia)
//   - Refresh: GET /billing/risk-adjustment/data (axios, on demand)
//   - Participant drill-down: GET /billing/risk-adjustment/participant/{id}
//   - Recalculate: POST /billing/risk-adjustment/recalculate/{id}
//
// Key concepts for PACE finance staff:
//   - RAF score (Risk Adjustment Factor) × county base rate = monthly capitation
//   - HCC gap = active ICD-10 diagnosis in chart NOT submitted in encounter data
//   - Each HCC gap can mean hundreds of dollars/month in lost capitation
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react'
import { Head } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import axios from 'axios'

// ── Types ─────────────────────────────────────────────────────────────────────

interface GapSummary {
    total_participants: number
    participants_with_gaps: number
    total_gap_count: number
    estimated_monthly_revenue_at_risk: number
    top_gaps: Record<string, number>
}

interface RiskScore {
    id: number
    participant_id: number
    payment_year: number
    risk_score: string | null
    frailty_score: string | null
    hcc_categories: string[]
    diagnoses_submitted: number
    diagnoses_accepted: number
    score_source: string
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null
}

interface Props {
    gapSummary: GapSummary
    riskScores: RiskScore[]
    year: number
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function RiskAdjustment({ gapSummary: initialGapSummary, riskScores: initialScores, year }: Props) {
    const [gapSummary, setGapSummary] = useState(initialGapSummary)
    const [riskScores, setRiskScores] = useState(initialScores)
    const [refreshing, setRefreshing] = useState(false)
    const [recalculating, setRecalculating] = useState<number | null>(null)

    // Refresh summary and score list from server
    const refresh = async () => {
        setRefreshing(true)
        try {
            const r = await axios.get('/billing/risk-adjustment/data', { params: { year } })
            setGapSummary(r.data.gap_summary)
            setRiskScores(r.data.risk_scores)
        } finally {
            setRefreshing(false)
        }
    }

    // Trigger RAF recalculation for a single participant
    const recalculate = async (participantId: number) => {
        setRecalculating(participantId)
        try {
            await axios.post(`/billing/risk-adjustment/recalculate/${participantId}`, { year })
            await refresh()
        } finally {
            setRecalculating(null)
        }
    }

    const fmt = (n: number) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

    return (
        <AppShell>
            <Head title="Risk Adjustment" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-slate-100">HCC Risk Adjustment — {year}</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                            CMS-HCC gap analysis and RAF score tracking. Each gap represents lost capitation revenue.
                        </p>
                    </div>
                    <button
                        onClick={refresh}
                        disabled={refreshing}
                        className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-50"
                    >
                        {refreshing ? 'Refreshing…' : 'Refresh'}
                    </button>
                </div>

                {/* KPI Cards */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <p className="text-xs text-slate-500 dark:text-slate-400 font-medium uppercase tracking-wide">Participants Scored</p>
                        <p className="text-2xl font-bold text-slate-900 dark:text-slate-100 mt-1">{gapSummary.total_participants}</p>
                    </div>
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <p className="text-xs text-slate-500 dark:text-slate-400 font-medium uppercase tracking-wide">With HCC Gaps</p>
                        <p className="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1">{gapSummary.participants_with_gaps}</p>
                    </div>
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <p className="text-xs text-slate-500 dark:text-slate-400 font-medium uppercase tracking-wide">Total Gap Count</p>
                        <p className="text-2xl font-bold text-red-600 dark:text-red-400 mt-1">{gapSummary.total_gap_count}</p>
                    </div>
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                        <p className="text-xs text-slate-500 dark:text-slate-400 font-medium uppercase tracking-wide">Est. Monthly $ at Risk</p>
                        <p className="text-2xl font-bold text-red-700 dark:text-red-300 mt-1">
                            ${fmt(gapSummary.estimated_monthly_revenue_at_risk)}
                        </p>
                    </div>
                </div>

                {/* Top Gap Categories */}
                {Object.keys(gapSummary.top_gaps).length > 0 && (
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
                        <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">Top HCC Gap Categories</h2>
                        <div className="flex flex-wrap gap-2">
                            {Object.entries(gapSummary.top_gaps).map(([cat, count]) => (
                                <span key={cat} className="px-3 py-1 bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800 rounded-full text-xs font-medium">
                                    {cat} — {count} participant{count !== 1 ? 's' : ''}
                                </span>
                            ))}
                        </div>
                    </div>
                )}

                {/* Risk Score Table */}
                <div className="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                    <div className="px-5 py-4 border-b border-slate-100 dark:border-slate-700">
                        <h2 className="text-sm font-semibold text-slate-700 dark:text-slate-300">Participant RAF Scores</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 dark:bg-slate-900 text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                                <tr>
                                    <th className="px-4 py-3 text-left">Participant</th>
                                    <th className="px-4 py-3 text-left">MRN</th>
                                    <th className="px-4 py-3 text-right">RAF Score</th>
                                    <th className="px-4 py-3 text-right">Frailty</th>
                                    <th className="px-4 py-3 text-center">HCCs</th>
                                    <th className="px-4 py-3 text-center">Dx Submitted</th>
                                    <th className="px-4 py-3 text-center">Source</th>
                                    <th className="px-4 py-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                                {riskScores.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-8 text-center text-slate-400">
                                            No risk scores for {year}. Use "Recalculate" to generate scores from clinical data.
                                        </td>
                                    </tr>
                                )}
                                {riskScores.map(score => (
                                    <tr key={score.id} className="hover:bg-slate-50 dark:hover:bg-slate-700">
                                        <td className="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">
                                            {score.participant
                                                ? `${score.participant.first_name} ${score.participant.last_name}`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-slate-500 dark:text-slate-400 font-mono text-xs">
                                            {score.participant?.mrn ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right font-semibold text-slate-900 dark:text-slate-100">
                                            {score.risk_score ? parseFloat(score.risk_score).toFixed(4) : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-right text-slate-600 dark:text-slate-400">
                                            {score.frailty_score ? parseFloat(score.frailty_score).toFixed(4) : '—'}
                                        </td>
                                        <td className="px-4 py-3 text-center text-slate-600 dark:text-slate-400">
                                            {score.hcc_categories.length}
                                        </td>
                                        <td className="px-4 py-3 text-center text-slate-600 dark:text-slate-400">
                                            {score.diagnoses_submitted}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className={`px-2 py-0.5 text-xs rounded-full font-medium ${
                                                score.score_source === 'cms_import'
                                                    ? 'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300'
                                                    : score.score_source === 'manual'
                                                    ? 'bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300'
                                                    : 'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300'
                                            }`}>
                                                {score.score_source === 'cms_import' ? 'CMS' :
                                                 score.score_source === 'manual' ? 'Manual' : 'Calculated'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <button
                                                onClick={() => recalculate(score.participant_id)}
                                                disabled={recalculating === score.participant_id}
                                                className="text-xs text-blue-600 dark:text-blue-400 hover:text-blue-800 disabled:opacity-40"
                                            >
                                                {recalculating === score.participant_id ? 'Recalculating…' : 'Recalculate'}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppShell>
    )
}
