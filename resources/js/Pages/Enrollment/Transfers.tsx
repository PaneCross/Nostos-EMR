// ─── Enrollment/Transfers ─────────────────────────────────────────────────────
// Phase 10A — Transfer Admin Page
//
// Lists all participant site transfers for the tenant with status filtering.
// Accessible by: enrollment, it_admin, super_admin.
//
// URL: /enrollment/transfers
// Backend: TransferAdminController::index()
// ─────────────────────────────────────────────────────────────────────────────

import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import { useState } from 'react'

interface Participant {
  id:   number
  name: string
  mrn:  string
}

interface Site {
  id:   number
  name: string
}

interface Transfer {
  id:                    number
  participant:           Participant
  from_site:             Site | null
  to_site:               Site | null
  transfer_reason_label: string
  requested_by:          string | null
  approved_by:           string | null
  requested_at:          string | null
  effective_date:        string | null
  status:                'pending' | 'approved' | 'completed' | 'cancelled'
}

interface PaginatorLink {
  url:    string | null
  label:  string
  active: boolean
}

interface Paginator<T> {
  data:          T[]
  current_page:  number
  last_page:     number
  per_page:      number
  total:         number
  links:         PaginatorLink[]
}

interface Props {
  transfers:       Paginator<Transfer>
  sites:           Site[]
  transferReasons: Record<string, string>
  filters:         { status: string | null }
}

const STATUS_PILL: Record<string, string> = {
  pending:   'bg-yellow-50 dark:bg-yellow-950/60 text-yellow-700 dark:text-yellow-300 border-yellow-200 dark:border-yellow-800',
  approved:  'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
  completed: 'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 border-green-200 dark:border-green-800',
  cancelled: 'bg-gray-50 dark:bg-slate-700 text-gray-400 dark:text-slate-500 border-gray-200 dark:border-slate-600',
}

export default function Transfers({ transfers, sites, transferReasons, filters }: Props) {
  const [statusFilter, setStatusFilter] = useState(filters.status ?? '')

  const applyFilter = (status: string) => {
    setStatusFilter(status)
    router.get('/enrollment/transfers', { status: status || undefined }, { preserveState: true, replace: true })
  }

  return (
    <AppShell>
      <Head title="Site Transfers: Enrollment" />

      {/* Breadcrumb */}
      <div className="px-6 pt-4 pb-0 text-sm text-gray-500 dark:text-slate-400">
        <Link href="/enrollment" className="hover:text-blue-600">Enrollment</Link>
        <span className="mx-2">/</span>
        <span className="text-gray-900 dark:text-slate-100">Site Transfers</span>
      </div>

      <div className="px-6 py-6 space-y-6">
        {/* Page header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-gray-900 dark:text-slate-100">Participant Site Transfers</h1>
            <p className="text-sm text-gray-500 dark:text-slate-400 mt-1">
              {transfers.total} transfer{transfers.total !== 1 ? 's' : ''} in total
            </p>
          </div>
        </div>

        {/* Status filter pills */}
        <div className="flex gap-2 flex-wrap">
          {['', 'pending', 'approved', 'completed', 'cancelled'].map(s => (
            <button
              key={s}
              onClick={() => applyFilter(s)}
              className={`text-xs px-3 py-1.5 rounded-full border font-medium transition-colors ${
                statusFilter === s
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400 dark:hover:border-blue-500'
              }`}
            >
              {s === '' ? 'All' : s.charAt(0).toUpperCase() + s.slice(1)}
            </button>
          ))}
        </div>

        {/* Transfers table */}
        <div className="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden bg-white dark:bg-slate-800">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-slate-700/50">
              <tr>
                {['Participant', 'From Site', 'To Site', 'Reason', 'Effective', 'Requested By', 'Approved By', 'Status'].map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
              {transfers.data.length === 0 && (
                <tr>
                  <td colSpan={8} className="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                    No transfers found{statusFilter ? ` with status "${statusFilter}"` : ''}.
                  </td>
                </tr>
              )}
              {transfers.data.map(t => (
                <tr key={t.id} className="bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700/50">
                  <td className="px-4 py-3">
                    <Link
                      href={`/participants/${t.participant.id}?tab=transfers`}
                      className="font-medium text-blue-600 dark:text-blue-400 hover:underline"
                    >
                      {t.participant.name}
                    </Link>
                    <p className="text-xs text-gray-400 dark:text-slate-500 font-mono">{t.participant.mrn}</p>
                  </td>
                  <td className="px-4 py-3 text-xs text-gray-700 dark:text-slate-300">{t.from_site?.name ?? '-'}</td>
                  <td className="px-4 py-3 text-xs text-gray-700 dark:text-slate-300">{t.to_site?.name ?? '-'}</td>
                  <td className="px-4 py-3 text-xs text-gray-600 dark:text-slate-400">{t.transfer_reason_label}</td>
                  <td className="px-4 py-3 text-xs text-gray-700 dark:text-slate-300">{t.effective_date ?? '-'}</td>
                  <td className="px-4 py-3 text-xs text-gray-500 dark:text-slate-400">{t.requested_by ?? '-'}</td>
                  <td className="px-4 py-3 text-xs text-gray-500 dark:text-slate-400">{t.approved_by ?? '-'}</td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border ${STATUS_PILL[t.status] ?? ''}`}>
                      {t.status.charAt(0).toUpperCase() + t.status.slice(1)}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {transfers.last_page > 1 && (
          <div className="flex justify-center gap-1">
            {transfers.links.map((link, i) => (
              <button
                key={i}
                disabled={!link.url}
                onClick={() => link.url && router.get(link.url)}
                className={`px-3 py-1.5 text-xs rounded border ${
                  link.active
                    ? 'bg-blue-600 text-white border-blue-600'
                    : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400 disabled:opacity-40'
                }`}
                dangerouslySetInnerHTML={{ __html: link.label }}
              />
            ))}
          </div>
        )}
      </div>
    </AppShell>
  )
}
