// ─── Scheduling/DayCenter ─────────────────────────────────────────────────────
// Day Center Attendance page — manage daily check-ins and absences for enrolled
// PACE participants attending the day center.
//
// Layout:
//   - Date picker + site selector in top bar
//   - Summary row (present / absent / excused / late counts)
//   - Attendance table: participant list with status chips + action buttons
//   - Mark Present / Mark Absent modals (for activities staff)
//
// Data loading: server-side initial load (Inertia), roster JSON on date/site change.
// URL: /scheduling/day-center
// Backend: DayCenterController::index()
// ─────────────────────────────────────────────────────────────────────────────

import { Head, router } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import { useState, useCallback } from 'react'
import axios from 'axios'

// ─── Types ────────────────────────────────────────────────────────────────────

interface RosterEntry {
  id:             number
  mrn:            string
  name:           string
  preferred_name: string | null
  attendance:     string | null  // status string if recorded, null if not yet
}

interface AttendanceRecord {
  id:              number
  participant:     { id: number; mrn: string; first_name: string; last_name: string }
  status:          string
  check_in_time:   string | null
  check_out_time:  string | null
  absent_reason:   string | null
}

interface Summary {
  total:   number
  present: number
  absent:  number
  excused: number
  late:    number
}

interface Props {
  attendance:    AttendanceRecord[]
  summary:       Summary
  selectedDate:  string
  selectedSite:  number | string
  statusLabels:  Record<string, string>
  absentReasons: Record<string, string>
  canManage:     boolean
}

// ─── Constants ────────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
  present: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
  absent:  'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
  late:    'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
  excused: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
}

// ─── AbsentModal ──────────────────────────────────────────────────────────────

function AbsentModal({
  participantId,
  siteId,
  date,
  absentReasons,
  onClose,
  onSaved,
}: {
  participantId: number
  siteId: number | string
  date: string
  absentReasons: Record<string, string>
  onClose: () => void
  onSaved: () => void
}) {
  const [status, setStatus]   = useState<'absent' | 'excused'>('absent')
  const [reason, setReason]   = useState('')
  const [notes, setNotes]     = useState('')
  const [saving, setSaving]   = useState(false)
  const [error, setError]     = useState('')

  const submit = async () => {
    if (!reason) { setError('Please select a reason.'); return }
    setSaving(true)
    try {
      await axios.post('/scheduling/day-center/absent', {
        participant_id:  participantId,
        site_id:         siteId,
        attendance_date: date,
        status,
        absent_reason:   reason,
        notes:           notes || null,
      })
      onSaved()
    } catch {
      setError('Failed to save. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md mx-4 p-6">
        <h2 className="text-base font-semibold text-gray-900 dark:text-slate-100 mb-4">Mark Absence</h2>

        <div className="space-y-4">
          {/* Status toggle */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Type</label>
            <div className="flex gap-2">
              {(['absent', 'excused'] as const).map(s => (
                <button
                  key={s}
                  onClick={() => setStatus(s)}
                  className={`flex-1 py-1.5 text-sm rounded-lg border transition-colors ${
                    status === s
                      ? 'bg-red-600 text-white border-red-600'
                      : 'bg-white dark:bg-slate-700 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600'
                  }`}
                >
                  {s.charAt(0).toUpperCase() + s.slice(1)}
                </button>
              ))}
            </div>
          </div>

          {/* Reason select */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Reason</label>
            <select
              value={reason}
              onChange={e => { setReason(e.target.value); setError('') }}
              className="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700 dark:text-slate-100"
            >
              <option value="">Select reason...</option>
              {Object.entries(absentReasons).map(([val, label]) => (
                <option key={val} value={val}>{label}</option>
              ))}
            </select>
          </div>

          {/* Notes */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
              Notes <span className="text-gray-400 dark:text-slate-500 font-normal">(optional)</span>
            </label>
            <textarea
              value={notes}
              onChange={e => setNotes(e.target.value)}
              rows={2}
              className="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700 dark:text-slate-100"
            />
          </div>

          {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}
        </div>

        <div className="flex justify-end gap-2 mt-5">
          <button
            onClick={onClose}
            className="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
          >
            Cancel
          </button>
          <button
            onClick={submit}
            disabled={saving}
            className="px-4 py-2 text-sm bg-red-600 hover:bg-red-700 text-white rounded-lg disabled:opacity-50"
          >
            {saving ? 'Saving...' : 'Mark Absent'}
          </button>
        </div>
      </div>
    </div>
  )
}

// ─── Component ────────────────────────────────────────────────────────────────

export default function DayCenter({
  attendance: initialAttendance,
  summary: initialSummary,
  selectedDate,
  selectedSite,
  statusLabels,
  absentReasons,
  canManage,
}: Props) {
  const [date, setDate]               = useState(selectedDate)
  const [attendance, setAttendance]   = useState(initialAttendance)
  const [summary, setSummary]         = useState(initialSummary)
  const [absentFor, setAbsentFor]     = useState<number | null>(null)
  const [savingId, setSavingId]       = useState<number | null>(null)

  // Navigate to a different date (re-fetches via Inertia)
  const handleDateChange = (newDate: string) => {
    setDate(newDate)
    router.get('/scheduling/day-center', { date: newDate, site_id: selectedSite }, { preserveState: true, replace: true })
  }

  // Mark a participant present
  const markPresent = useCallback(async (participantId: number) => {
    setSavingId(participantId)
    try {
      await axios.post('/scheduling/day-center/check-in', {
        participant_id:  participantId,
        site_id:         selectedSite,
        attendance_date: date,
        status:          'present',
      })
      router.reload({ only: ['attendance', 'summary'] })
    } finally {
      setSavingId(null)
    }
  }, [date, selectedSite])

  const handleAbsentSaved = () => {
    setAbsentFor(null)
    router.reload({ only: ['attendance', 'summary'] })
  }

  const summaryItems = [
    { label: 'Present',  count: summary.present, color: 'text-green-600 dark:text-green-400' },
    { label: 'Absent',   count: summary.absent,  color: 'text-red-600 dark:text-red-400' },
    { label: 'Excused',  count: summary.excused, color: 'text-blue-600 dark:text-blue-400' },
    { label: 'Late',     count: summary.late,    color: 'text-amber-600 dark:text-amber-400' },
  ]

  return (
    <AppShell>
      <Head title="Day Center Attendance" />

      <div className="px-6 py-6 space-y-5">

        {/* Header */}
        <div className="flex items-start justify-between">
          <div>
            <h1 className="text-xl font-bold text-gray-900 dark:text-slate-100">Day Center Attendance</h1>
            <p className="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
              Track participant check-ins and absences for the day center.
            </p>
          </div>

          {/* Date picker */}
          <div className="flex items-center gap-2">
            <label className="text-sm text-gray-600 dark:text-slate-400 font-medium">Date:</label>
            <input
              type="date"
              value={date}
              onChange={e => handleDateChange(e.target.value)}
              className="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-1.5 dark:bg-slate-700 dark:text-slate-100"
            />
          </div>
        </div>

        {/* Summary row */}
        <div className="grid grid-cols-4 gap-3">
          {summaryItems.map(({ label, count, color }) => (
            <div key={label} className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 text-center shadow-sm">
              <p className={`text-2xl font-bold ${color}`}>{count}</p>
              <p className="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{label}</p>
            </div>
          ))}
        </div>

        {/* Attendance table */}
        <div className="border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden bg-white dark:bg-slate-800 shadow-sm">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-slate-700/50">
              <tr>
                {['Participant', 'MRN', 'Check-In', 'Check-Out', 'Status', canManage ? 'Actions' : ''].filter(Boolean).map(h => (
                  <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
              {attendance.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                    No attendance records for this date. Use the roster to start checking participants in.
                  </td>
                </tr>
              ) : (
                attendance.map(record => (
                  <tr key={record.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors">
                    <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">
                      {record.participant.last_name}, {record.participant.first_name}
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-600 dark:text-slate-400">
                      {record.participant.mrn}
                    </td>
                    <td className="px-4 py-3 text-gray-600 dark:text-slate-400">
                      {record.check_in_time ?? '-'}
                    </td>
                    <td className="px-4 py-3 text-gray-600 dark:text-slate-400">
                      {record.check_out_time ?? '-'}
                    </td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${STATUS_COLORS[record.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'}`}>
                        {statusLabels[record.status] ?? record.status}
                      </span>
                      {record.absent_reason && (
                        <span className="ml-2 text-xs text-gray-400 dark:text-slate-500">
                          ({record.absent_reason})
                        </span>
                      )}
                    </td>
                    {canManage && (
                      <td className="px-4 py-3">
                        <div className="flex gap-2">
                          {record.status !== 'present' && (
                            <button
                              onClick={() => markPresent(record.participant.id)}
                              disabled={savingId === record.participant.id}
                              className="text-xs px-2.5 py-1 bg-green-600 hover:bg-green-700 text-white rounded disabled:opacity-50"
                            >
                              Present
                            </button>
                          )}
                          {record.status !== 'absent' && record.status !== 'excused' && (
                            <button
                              onClick={() => setAbsentFor(record.participant.id)}
                              className="text-xs px-2.5 py-1 bg-red-100 dark:bg-red-900/40 hover:bg-red-200 dark:hover:bg-red-900/60 text-red-700 dark:text-red-300 rounded"
                            >
                              Absent
                            </button>
                          )}
                        </div>
                      </td>
                    )}
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Quick info */}
        <p className="text-xs text-gray-400 dark:text-slate-500">
          Showing participants with attendance recorded for {new Date(date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}.
          Use the roster endpoint to pre-populate all enrolled participants.
        </p>
      </div>

      {/* Absent modal */}
      {absentFor !== null && (
        <AbsentModal
          participantId={absentFor}
          siteId={selectedSite}
          date={date}
          absentReasons={absentReasons}
          onClose={() => setAbsentFor(null)}
          onSaved={handleAbsentSaved}
        />
      )}
    </AppShell>
  )
}
