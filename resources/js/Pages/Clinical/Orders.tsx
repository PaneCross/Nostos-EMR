// ─── Clinical/Orders.tsx ──────────────────────────────────────────────────────
// W4-7: Cross-participant CPOE order worklist. 42 CFR §460.90.
// Replaces the old care-plan-goals stub with a real clinical order management UI.
//
// Layout: 3 filter tabs (Pending / My Dept / All Active), KPI row, orders table
// with stat=red/urgent=amber row coloring.
//
// Data loading: Inertia props (server-side rendered).
//   orders: Order[] — filtered by dept unless super_admin / primary_care / it_admin
//   allCount: number — total active order count across all depts
//   pending: number  — orders with status='pending' in caller's dept context
//   userDept: string — current user's department
//
// Actions:
//   Acknowledge: POST /participants/{id}/orders/{id}/acknowledge
//   Result:      POST /participants/{id}/orders/{id}/result  (opens modal)
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useMemo } from 'react'
import { Head, Link } from '@inertiajs/react'
import axios from 'axios'
import AppShell from '@/Layouts/AppShell'
import {
    ClipboardDocumentListIcon,
    ExclamationTriangleIcon,
    CheckCircleIcon,
    BellAlertIcon,
    FunnelIcon,
} from '@heroicons/react/24/outline'

// ── Types ─────────────────────────────────────────────────────────────────────

interface Order {
    id: number
    participant_id: number
    participant_first_name: string
    participant_last_name: string
    participant_mrn: string
    order_type: string
    order_type_label: string
    priority: 'routine' | 'urgent' | 'stat'
    status: string
    instructions: string
    clinical_indication: string | null
    target_department: string
    due_date: string | null
    ordered_at: string
    is_overdue: boolean
    alert_severity: string
}

interface Props {
    orders: Order[]
    allCount: number
    pending: number
    userDept: string
}

// ── Constants ─────────────────────────────────────────────────────────────────

const DEPT_LABELS: Record<string, string> = {
    primary_care:      'Primary Care',
    therapies:         'Therapies',
    social_work:       'Social Work',
    behavioral_health: 'Behavioral Health',
    dietary:           'Dietary',
    activities:        'Activities',
    home_care:         'Home Care',
    transportation:    'Transportation',
    pharmacy:          'Pharmacy',
    idt:               'IDT',
    enrollment:        'Enrollment',
    finance:           'Finance',
    qa_compliance:     'QA/Compliance',
    it_admin:          'IT Admin',
}

const PRIORITY_BADGE: Record<string, string> = {
    stat:    'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 font-bold',
    urgent:  'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 font-semibold',
    routine: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
}

const STATUS_BADGE: Record<string, string> = {
    pending:     'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300',
    acknowledged:'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300',
    in_progress: 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300',
    resulted:    'bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300',
    completed:   'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
    cancelled:   'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400',
}

const STATUS_LABELS: Record<string, string> = {
    pending:     'Pending',
    acknowledged:'Acknowledged',
    in_progress: 'In Progress',
    resulted:    'Resulted',
    completed:   'Completed',
    cancelled:   'Cancelled',
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function rowBg(order: Order): string {
    if (order.priority === 'stat')   return 'bg-red-50/60 dark:bg-red-950/30'
    if (order.priority === 'urgent') return 'bg-amber-50/60 dark:bg-amber-950/20'
    return ''
}

function fmtDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

// ── ResultModal ───────────────────────────────────────────────────────────────

interface ResultModalProps {
    order: Order | null
    onClose: () => void
    onSuccess: () => void
}

function ResultModal({ order, onClose, onSuccess }: ResultModalProps) {
    const [summary, setSummary] = useState('')
    const [saving, setSaving]   = useState(false)
    const [error, setError]     = useState('')

    if (!order) return null

    async function submit() {
        if (!summary.trim()) { setError('Result summary is required.'); return }
        setSaving(true); setError('')
        try {
            await axios.post(`/participants/${order.participant_id}/orders/${order.id}/result`, {
                result_summary: summary,
            })
            onSuccess()
        } catch {
            setError('Failed to save result. Please try again.')
        } finally {
            setSaving(false)
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div className="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-md mx-4 border border-gray-200 dark:border-slate-700">
                <div className="flex items-center justify-between px-5 py-4 border-b dark:border-slate-700">
                    <h2 className="text-base font-semibold text-gray-900 dark:text-slate-100">
                        Record Result
                    </h2>
                    <button onClick={onClose} className="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300 text-lg">&#x2715;</button>
                </div>
                <div className="px-5 py-4 space-y-3">
                    <p className="text-sm text-gray-600 dark:text-slate-400">
                        Order: <span className="font-medium text-gray-800 dark:text-slate-200">{order.order_type_label}</span>
                        {' '}for {order.participant_first_name} {order.participant_last_name}
                    </p>
                    <div>
                        <label className="block text-xs font-medium text-gray-600 dark:text-slate-400 mb-1">
                            Result Summary <span className="text-red-500">*</span>
                        </label>
                        <textarea
                            rows={4}
                            value={summary}
                            onChange={e => setSummary(e.target.value)}
                            placeholder="Describe the result or finding..."
                            className="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 px-3 py-2 resize-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        />
                    </div>
                    {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
                </div>
                <div className="flex justify-end gap-2 px-5 py-4 border-t dark:border-slate-700">
                    <button
                        onClick={onClose}
                        className="px-3 py-1.5 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
                    >
                        Cancel
                    </button>
                    <button
                        onClick={submit}
                        disabled={saving}
                        className="px-4 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg disabled:opacity-50"
                    >
                        {saving ? 'Saving...' : 'Save Result'}
                    </button>
                </div>
            </div>
        </div>
    )
}

// ── Main Component ─────────────────────────────────────────────────────────────

export default function ClinicalOrders({ orders, allCount, pending, userDept }: Props) {
    const [activeTab, setActiveTab]     = useState<'pending' | 'my_dept' | 'all'>('pending')
    const [resultTarget, setResultTarget] = useState<Order | null>(null)
    const [actioning, setActioning]     = useState<number | null>(null)
    const [actionError, setActionError] = useState('')

    // Filter orders based on active tab
    const displayed = useMemo(() => {
        switch (activeTab) {
            case 'pending':  return orders.filter(o => o.status === 'pending')
            case 'my_dept':  return orders.filter(o => o.target_department === userDept)
            case 'all':      return orders
        }
    }, [orders, activeTab, userDept])

    const overdueCount  = orders.filter(o => o.is_overdue).length
    const myDeptCount   = orders.filter(o => o.target_department === userDept).length
    const deptLabel     = DEPT_LABELS[userDept] ?? userDept

    async function acknowledge(order: Order) {
        setActioning(order.id); setActionError('')
        try {
            await axios.post(`/participants/${order.participant_id}/orders/${order.id}/acknowledge`)
            window.location.reload()
        } catch {
            setActionError('Failed to acknowledge order.')
            setActioning(null)
        }
    }

    function handleResultSuccess() {
        setResultTarget(null)
        window.location.reload()
    }

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
                            Computerized provider order entry. Per-participant orders are also accessible from the Orders tab on each participant's profile. 42 CFR §460.90.
                        </p>
                    </div>

                    {/* KPI Cards */}
                    <div className="grid grid-cols-3 gap-4">
                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <div className="flex items-center gap-2 text-blue-600 dark:text-blue-400 mb-1">
                                <ClipboardDocumentListIcon className="w-5 h-5" />
                                <span className="text-xs font-medium uppercase tracking-wide">Active Orders</span>
                            </div>
                            <p className="text-3xl font-bold text-gray-900 dark:text-slate-100">{allCount}</p>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1">across all departments</p>
                        </div>

                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <div className="flex items-center gap-2 mb-1" style={{ color: pending > 0 ? '#d97706' : undefined }}>
                                {pending > 0
                                    ? <BellAlertIcon className="w-5 h-5 text-amber-500" />
                                    : <CheckCircleIcon className="w-5 h-5 text-gray-400 dark:text-slate-500" />
                                }
                                <span className={`text-xs font-medium uppercase tracking-wide ${pending > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-500 dark:text-slate-400'}`}>
                                    Pending Acknowledgment
                                </span>
                            </div>
                            <p className={`text-3xl font-bold ${pending > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-900 dark:text-slate-100'}`}>
                                {pending}
                            </p>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1">awaiting acknowledgment</p>
                        </div>

                        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                            <div className="flex items-center gap-2 text-purple-600 dark:text-purple-400 mb-1">
                                <FunnelIcon className="w-5 h-5" />
                                <span className="text-xs font-medium uppercase tracking-wide">My Dept</span>
                            </div>
                            <p className="text-3xl font-bold text-gray-900 dark:text-slate-100">{myDeptCount}</p>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1">{deptLabel} orders</p>
                        </div>
                    </div>

                    {/* Overdue alert banner */}
                    {overdueCount > 0 && (
                        <div className="flex items-center gap-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-4 py-2.5">
                            <ExclamationTriangleIcon className="w-4 h-4 text-red-600 dark:text-red-400 shrink-0" />
                            <span className="text-sm text-red-700 dark:text-red-300 font-medium">
                                {overdueCount} order{overdueCount !== 1 ? 's are' : ' is'} overdue and require immediate attention.
                            </span>
                        </div>
                    )}

                    {/* Tabs */}
                    <div className="border-b border-gray-200 dark:border-slate-700">
                        <nav className="flex gap-1">
                            {([
                                { key: 'pending',  label: `Pending (${orders.filter(o => o.status === 'pending').length})` },
                                { key: 'my_dept',  label: `${deptLabel} (${myDeptCount})` },
                                { key: 'all',      label: `All Active (${orders.length})` },
                            ] as const).map(tab => (
                                <button
                                    key={tab.key}
                                    onClick={() => setActiveTab(tab.key)}
                                    className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
                                        activeTab === tab.key
                                            ? 'border-blue-600 text-blue-600 dark:text-blue-400 dark:border-blue-400'
                                            : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200'
                                    }`}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </nav>
                    </div>

                    {/* Error message */}
                    {actionError && (
                        <p className="text-sm text-red-600 dark:text-red-400">{actionError}</p>
                    )}

                    {/* Orders Table */}
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-gray-50 dark:bg-slate-700/50 text-left">
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Participant</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Order Type</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Priority</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Status</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Dept</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400 max-w-[200px]">Instructions</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400">Ordered</th>
                                        <th className="px-4 py-2 font-medium text-gray-600 dark:text-slate-400"></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
                                    {displayed.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="px-4 py-8 text-center text-gray-400 dark:text-slate-500">
                                                No orders match the current filter.
                                            </td>
                                        </tr>
                                    ) : displayed.map(order => (
                                        <tr
                                            key={order.id}
                                            className={`hover:bg-gray-50 dark:hover:bg-slate-700/50 ${rowBg(order)}`}
                                        >
                                            {/* Participant */}
                                            <td className="px-4 py-2">
                                                <Link
                                                    href={`/participants/${order.participant_id}?tab=orders`}
                                                    className="font-medium text-blue-600 dark:text-blue-400 hover:underline"
                                                >
                                                    {order.participant_first_name} {order.participant_last_name}
                                                </Link>
                                                <p className="text-xs text-gray-400 dark:text-slate-500 font-mono">{order.participant_mrn}</p>
                                            </td>

                                            {/* Order type + overdue indicator */}
                                            <td className="px-4 py-2 text-gray-700 dark:text-slate-300">
                                                {order.order_type_label}
                                                {order.is_overdue && (
                                                    <span className="ml-1.5 text-xs text-red-600 dark:text-red-400 font-semibold">OD</span>
                                                )}
                                            </td>

                                            {/* Priority badge */}
                                            <td className="px-4 py-2">
                                                <span className={`inline-block px-2 py-0.5 rounded text-xs ${PRIORITY_BADGE[order.priority] ?? ''}`}>
                                                    {order.priority.toUpperCase()}
                                                </span>
                                            </td>

                                            {/* Status badge */}
                                            <td className="px-4 py-2">
                                                <span className={`inline-block px-2 py-0.5 rounded text-xs ${STATUS_BADGE[order.status] ?? ''}`}>
                                                    {STATUS_LABELS[order.status] ?? order.status}
                                                </span>
                                            </td>

                                            {/* Target department */}
                                            <td className="px-4 py-2 text-gray-600 dark:text-slate-400 text-xs">
                                                {DEPT_LABELS[order.target_department] ?? order.target_department}
                                            </td>

                                            {/* Instructions (truncated) */}
                                            <td className="px-4 py-2 text-gray-700 dark:text-slate-300 max-w-[200px]">
                                                <p className="line-clamp-2 text-xs">{order.instructions}</p>
                                            </td>

                                            {/* Ordered at */}
                                            <td className="px-4 py-2 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">
                                                {fmtDate(order.ordered_at)}
                                            </td>

                                            {/* Action buttons */}
                                            <td className="px-4 py-2">
                                                <div className="flex items-center gap-2 justify-end">
                                                    {/* Acknowledge: pending orders only */}
                                                    {order.status === 'pending' && (
                                                        <button
                                                            onClick={() => acknowledge(order)}
                                                            disabled={actioning === order.id}
                                                            className="text-xs px-2 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded disabled:opacity-50 whitespace-nowrap"
                                                        >
                                                            Ack
                                                        </button>
                                                    )}

                                                    {/* Result: pending / acknowledged / in_progress */}
                                                    {['pending', 'acknowledged', 'in_progress'].includes(order.status) && (
                                                        <button
                                                            onClick={() => setResultTarget(order)}
                                                            className="text-xs px-2 py-1 bg-teal-600 hover:bg-teal-700 text-white rounded whitespace-nowrap"
                                                        >
                                                            Result
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </AppShell>

            {/* Result modal */}
            <ResultModal
                order={resultTarget}
                onClose={() => setResultTarget(null)}
                onSuccess={handleResultSuccess}
            />
        </>
    )
}
