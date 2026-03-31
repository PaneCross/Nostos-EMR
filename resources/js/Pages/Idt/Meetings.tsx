// ─── Idt/Meetings ──────────────────────────────────────────────────────────────
// IDT Meeting Minutes list — paginated history of all IDT meetings.
// Filterable by status (scheduled, in_progress, completed).
// Clicking a row navigates to the meeting detail / run-meeting page.
//
// URL: /idt/meetings
// Backend: IdtMeetingController::meetingsList()
// ─────────────────────────────────────────────────────────────────────────────

import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import { useState } from 'react'

// ─── Types ────────────────────────────────────────────────────────────────────

interface Facilitator {
  id:         number
  first_name: string
  last_name:  string
}

interface Meeting {
  id:           number
  meeting_date: string
  meeting_time: string | null
  meeting_type: string
  status:       'scheduled' | 'in_progress' | 'completed'
  facilitator:  Facilitator | null
  site_id:      number | null
}

interface Paginator<T> {
  data:         T[]
  current_page: number
  last_page:    number
  per_page:     number
  total:        number
  links:        { url: string | null; label: string; active: boolean }[]
}

interface Props {
  meetings: Paginator<Meeting>
  filters:  { status: string }
}

// ─── Constants ────────────────────────────────────────────────────────────────

const STATUS_PILL: Record<string, string> = {
  scheduled:   'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
  in_progress: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
  completed:   'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
}

const TYPE_LABELS: Record<string, string> = {
  weekly:    'Weekly IDT',
  monthly:   'Monthly IDT',
  quarterly: 'Quarterly IDT',
  emergency: 'Emergency IDT',
  custom:    'Ad-Hoc Meeting',
}

// ─── Component ────────────────────────────────────────────────────────────────

export default function Meetings({ meetings, filters }: Props) {
  const [statusFilter, setStatusFilter] = useState(filters.status ?? '')

  const applyFilter = (status: string) => {
    setStatusFilter(status)
    router.get('/idt/meetings', { status: status || undefined }, { preserveState: true, replace: true })
  }

  return (
    <AppShell>
      <Head title="Meeting Minutes" />

      {/* Breadcrumb */}
      <div className="px-6 pt-4 pb-0 text-sm text-gray-500 dark:text-slate-400">
        <Link href="/idt" className="hover:text-blue-600 dark:hover:text-blue-400">IDT</Link>
        <span className="mx-2">/</span>
        <span className="text-gray-900 dark:text-slate-100">Meeting Minutes</span>
      </div>

      <div className="px-6 py-6 space-y-5">

        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold text-gray-900 dark:text-slate-100">IDT Meeting Minutes</h1>
            <p className="text-sm text-gray-500 dark:text-slate-400 mt-1">
              {meetings.total} meeting{meetings.total !== 1 ? 's' : ''} on record
            </p>
          </div>
          <Link
            href="/idt"
            className="inline-flex items-center gap-1.5 px-3 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg text-gray-600 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors"
          >
            IDT Dashboard
          </Link>
        </div>

        {/* Status filter pills */}
        <div className="flex gap-2 flex-wrap">
          {[
            { value: '',            label: 'All' },
            { value: 'scheduled',   label: 'Scheduled' },
            { value: 'in_progress', label: 'In Progress' },
            { value: 'completed',   label: 'Completed' },
          ].map(opt => (
            <button
              key={opt.value}
              onClick={() => applyFilter(opt.value)}
              className={`text-xs px-3 py-1.5 rounded-full border font-medium transition-colors ${
                statusFilter === opt.value
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400 dark:hover:border-blue-500'
              }`}
            >
              {opt.label}
            </button>
          ))}
        </div>

        {/* Meetings table */}
        <div className="border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden bg-white dark:bg-slate-800 shadow-sm">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-slate-700/50">
              <tr>
                {['Date', 'Time', 'Type', 'Facilitator', 'Status'].map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
              {meetings.data.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                    No meetings found{statusFilter ? ` with status "${statusFilter}"` : ''}.
                  </td>
                </tr>
              )}
              {meetings.data.map(meeting => (
                <tr
                  key={meeting.id}
                  className="bg-white dark:bg-slate-800 hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors"
                  onClick={() => router.visit(`/idt/meetings/${meeting.id}`)}
                >
                  <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">
                    {new Date(meeting.meeting_date + 'T00:00:00').toLocaleDateString('en-US', {
                      weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
                    })}
                  </td>
                  <td className="px-4 py-3 text-gray-600 dark:text-slate-400">
                    {meeting.meeting_time
                      ? new Date(`2000-01-01T${meeting.meeting_time}`).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
                      : '-'}
                  </td>
                  <td className="px-4 py-3 text-gray-700 dark:text-slate-300">
                    {TYPE_LABELS[meeting.meeting_type] ?? meeting.meeting_type}
                  </td>
                  <td className="px-4 py-3 text-gray-600 dark:text-slate-400">
                    {meeting.facilitator
                      ? `${meeting.facilitator.last_name}, ${meeting.facilitator.first_name}`
                      : <span className="text-gray-400 dark:text-slate-500">Unassigned</span>}
                  </td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${STATUS_PILL[meeting.status] ?? ''}`}>
                      {meeting.status.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {meetings.last_page > 1 && (
          <div className="flex justify-center gap-1">
            {meetings.links.map((link, i) => (
              <button
                key={i}
                disabled={!link.url}
                onClick={() => link.url && router.visit(link.url, { preserveState: true })}
                className={`px-3 py-1.5 text-xs rounded border transition-colors ${
                  link.active
                    ? 'bg-blue-600 text-white border-blue-600'
                    : link.url
                      ? 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400'
                      : 'bg-white dark:bg-slate-800 text-gray-300 dark:text-slate-600 border-gray-200 dark:border-slate-700 cursor-not-allowed'
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
