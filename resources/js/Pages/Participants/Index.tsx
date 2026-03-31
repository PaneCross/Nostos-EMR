import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import { useState, useCallback } from 'react'

// ─── Types ────────────────────────────────────────────────────────────────────

interface Flag {
  flag_type: string
  label:     string
  severity:  'low' | 'medium' | 'high' | 'critical'
}

interface Participant {
  id:                number
  mrn:               string
  first_name:        string
  last_name:         string
  preferred_name:    string | null
  dob:               string
  enrollment_status: string
  is_active:         boolean
  site:              { id: number; name: string }
  active_flags:      Flag[]
}

interface Paginator<T> {
  data:          T[]
  current_page:  number
  last_page:     number
  per_page:      number
  total:         number
  links:         { url: string | null; label: string; active: boolean }[]
}

interface Props {
  participants: Paginator<Participant>
  sites:        { id: number; name: string }[]
  filters:      { search?: string; status?: string; site_id?: string; has_flags?: string }
  canCreate:    boolean
}

// ─── Constants ────────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
  enrolled:    'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
  referred:    'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
  intake:      'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-800 dark:text-indigo-300',
  pending:     'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300',
  disenrolled: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
  deceased:    'bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500',
}

const FLAG_COLORS: Record<string, string> = {
  low:      'bg-blue-500 text-white',
  medium:   'bg-amber-500 text-white',
  high:     'bg-orange-500 text-white',
  critical: 'bg-red-600 text-white',
}

function rowBg(status: string): string {
  if (status === 'deceased')    return 'bg-gray-50 dark:bg-slate-700/50 opacity-60'
  if (status === 'disenrolled') return 'bg-gray-50 dark:bg-slate-700/50'
  return 'bg-white dark:bg-slate-800'
}

function age(dob: string): number {
  const d = new Date(dob)
  const now = new Date()
  let a = now.getFullYear() - d.getFullYear()
  if (now < new Date(now.getFullYear(), d.getMonth(), d.getDate())) a--
  return a
}

// ─── Component ────────────────────────────────────────────────────────────────

export default function ParticipantIndex({ participants, sites, filters, canCreate }: Props) {
  const [search,   setSearch]   = useState(filters.search   ?? '')
  const [status,   setStatus]   = useState(filters.status   ?? '')
  const [siteId,   setSiteId]   = useState(filters.site_id  ?? '')
  const [hasFlags, setHasFlags] = useState(filters.has_flags === '1')

  const applyFilters = useCallback((overrides: Record<string, string | boolean> = {}) => {
    router.get('/participants', {
      search:    overrides.search    ?? search,
      status:    overrides.status    ?? status,
      site_id:   overrides.site_id   ?? siteId,
      has_flags: overrides.has_flags ?? (hasFlags ? '1' : ''),
    }, { preserveState: true, replace: true })
  }, [search, status, siteId, hasFlags])

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault()
    applyFilters()
  }

  const clearFilters = () => {
    setSearch(''); setStatus(''); setSiteId(''); setHasFlags(false)
    router.get('/participants', {}, { preserveState: false })
  }

  return (
    <AppShell>
      <Head title="Participants" />

      <div className="px-6 py-5">
        {/* ── Header ── */}
        <div className="flex items-center justify-between mb-5">
          <div>
            <h1 className="text-xl font-semibold text-gray-900 dark:text-slate-100">Participant Directory</h1>
            <p className="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
              {participants.total.toLocaleString()} participant{participants.total !== 1 ? 's' : ''}
            </p>
          </div>
          {canCreate && (
            <Link
              href="/participants/create"
              className="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
            >
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
              </svg>
              Add Participant
            </Link>
          )}
        </div>

        {/* ── Search + Filter bar ── */}
        <form onSubmit={handleSearch} className="flex flex-wrap items-center gap-2 mb-4">
          <div className="relative flex-1 min-w-[260px]">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 dark:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" />
            </svg>
            <input
              type="text"
              value={search}
              onChange={e => setSearch(e.target.value)}
              placeholder="Search by name, MRN, or DOB (YYYY-MM-DD)"
              className="w-full pl-9 pr-4 py-2 text-sm border border-gray-300 dark:border-slate-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400"
            />
          </div>

          <select
            value={status}
            onChange={e => { setStatus(e.target.value); applyFilters({ status: e.target.value }) }}
            className="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
          >
            <option value="">All Statuses</option>
            {['enrolled','referred','intake','pending','disenrolled','deceased'].map(s => (
              <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
            ))}
          </select>

          <select
            value={siteId}
            onChange={e => { setSiteId(e.target.value); applyFilters({ site_id: e.target.value }) }}
            className="text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-slate-700 dark:text-slate-100"
          >
            <option value="">All Sites</option>
            {sites.map(s => <option key={s.id} value={String(s.id)}>{s.name}</option>)}
          </select>

          <label className="flex items-center gap-1.5 text-sm text-gray-700 dark:text-slate-300 cursor-pointer">
            <input
              type="checkbox"
              checked={hasFlags}
              onChange={e => { setHasFlags(e.target.checked); applyFilters({ has_flags: e.target.checked ? '1' : '' }) }}
              className="rounded border-gray-300 text-blue-600 dark:text-blue-400"
            />
            Active flags only
          </label>

          <button type="submit" className="px-4 py-2 text-sm bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-700 dark:text-slate-300 rounded-lg font-medium transition-colors">
            Search
          </button>
          {(search || status || siteId || hasFlags) && (
            <button type="button" onClick={clearFilters} className="text-sm text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 underline">
              Clear
            </button>
          )}
        </form>

        {/* ── Table ── */}
        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden shadow-sm">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
              <tr>
                {['MRN', 'Name', 'DOB / Age', 'Status', 'Flags', 'Site'].map(h => (
                  <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
              {participants.data.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-10 text-center text-gray-400 dark:text-slate-500">
                    No participants found.
                  </td>
                </tr>
              ) : participants.data.map(ppt => (
                <tr
                  key={ppt.id}
                  className={`${rowBg(ppt.enrollment_status)} hover:bg-blue-50 dark:hover:bg-blue-900/20 cursor-pointer transition-colors`}
                  onClick={() => router.visit(`/participants/${ppt.id}`)}
                >
                  <td className="px-4 py-3 font-mono text-xs font-semibold text-gray-700 dark:text-slate-300">{ppt.mrn}</td>
                  <td className="px-4 py-3">
                    <div className="font-medium text-gray-900 dark:text-slate-100">
                      {ppt.last_name}, {ppt.first_name}
                    </div>
                    {ppt.preferred_name && (
                      <div className="text-xs text-gray-400 dark:text-slate-500">"{ppt.preferred_name}"</div>
                    )}
                  </td>
                  <td className="px-4 py-3 text-gray-600 dark:text-slate-400">
                    <div>{new Date(ppt.dob).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</div>
                    <div className="text-xs text-gray-400 dark:text-slate-500">{age(ppt.dob)} yrs</div>
                  </td>
                  <td className="px-4 py-3">
                    <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLORS[ppt.enrollment_status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'}`}>
                      {ppt.enrollment_status}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      {ppt.active_flags.slice(0, 4).map((f, i) => (
                        <span key={i} title={f.label} className={`inline-flex px-2.5 py-1 rounded-full text-xs font-semibold shadow-sm ${FLAG_COLORS[f.severity] ?? 'bg-slate-500 text-white'}`}>
                          {f.label}
                        </span>
                      ))}
                      {ppt.active_flags.length > 4 && (
                        <span className="text-xs text-gray-400 dark:text-slate-500">+{ppt.active_flags.length - 4}</span>
                      )}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-gray-500 dark:text-slate-400 text-xs">{ppt.site?.name}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* ── Pagination ── */}
        {participants.last_page > 1 && (
          <div className="flex items-center justify-between mt-4 text-sm">
            <span className="text-gray-500 dark:text-slate-400">
              Showing {((participants.current_page - 1) * participants.per_page) + 1}–
              {Math.min(participants.current_page * participants.per_page, participants.total)} of {participants.total}
            </span>
            <div className="flex gap-1">
              {participants.links.map((link, i) => (
                <button
                  key={i}
                  disabled={!link.url}
                  onClick={() => link.url && router.visit(link.url, { preserveState: true })}
                  className={`px-3 py-1.5 rounded-md border text-xs font-medium transition-colors ${
                    link.active
                      ? 'bg-blue-600 text-white border-blue-600'
                      : link.url
                        ? 'bg-white dark:bg-slate-800 text-gray-700 dark:text-slate-300 border-gray-300 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700/50'
                        : 'bg-white dark:bg-slate-800 text-gray-300 dark:text-slate-600 border-gray-200 dark:border-slate-700 cursor-not-allowed'
                  }`}
                  dangerouslySetInnerHTML={{ __html: link.label }}
                />
              ))}
            </div>
          </div>
        )}
      </div>
    </AppShell>
  )
}
