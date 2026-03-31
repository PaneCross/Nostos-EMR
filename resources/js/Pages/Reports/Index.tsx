// ─── Reports/Index ────────────────────────────────────────────────────────────
// Tabbed report catalog — organized by category (Enrollment, Quality, Finance,
// Clinical, Administration). Filtered server-side by department access.
//
// Layout:
//   - KPI summary row (loaded client-side via /reports/data)
//   - Category tab pills
//   - Report cards grid: title, description, export button
//
// Data loading: server-side catalog (Inertia), KPI counts via axios on mount.
// URL: /reports
// Backend: ReportsController::index()
// ─────────────────────────────────────────────────────────────────────────────

import { Head } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import { useState, useEffect } from 'react'
import axios from 'axios'
import {
  DocumentChartBarIcon,
  ArrowDownTrayIcon,
  ClockIcon,
  BuildingOfficeIcon,
} from '@heroicons/react/24/outline'

// ─── Types ────────────────────────────────────────────────────────────────────

interface Report {
  id:          string
  title:       string
  description: string
  category:    string
  export_url:  string | null
}

interface Kpis {
  enrolled_participants: number
  open_incidents:        number
  overdue_sdrs:          number
  meetings_this_month:   number
}

interface Props {
  reports:    Report[]
  department: string
  canExport:  boolean
}

// ─── Constants ────────────────────────────────────────────────────────────────

const CATEGORY_ORDER = ['Enrollment', 'Quality', 'Finance', 'Clinical', 'Administration']

const CATEGORY_COLORS: Record<string, string> = {
  Enrollment:     'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
  Quality:        'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
  Finance:        'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
  Clinical:       'bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300',
  Administration: 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300',
}

// ─── Types (W3-6 site transfer tab) ──────────────────────────────────────────
interface SiteTransferRow {
  participant_id:   number
  participant_name: string
  mrn:              string
  current_site:     string
  prior_sites:      string
  transfer_dates:   string
  transfer_count:   number
}

interface SiteOption { id: number; name: string }

// ─── Component ────────────────────────────────────────────────────────────────

export default function Index({ reports, canExport }: Props) {
  const [mainTab, setMainTab]               = useState<'catalog' | 'site'>('catalog')
  const [activeCategory, setActiveCategory] = useState<string>('All')
  const [kpis, setKpis]                     = useState<Kpis | null>(null)
  const [downloading, setDownloading]       = useState<string | null>(null)

  // By PACE Site tab state
  const [siteRows, setSiteRows]     = useState<SiteTransferRow[] | null>(null)
  const [siteOptions, setSiteOptions] = useState<SiteOption[]>([])
  const [siteFilter, setSiteFilter] = useState<string>('')
  const [siteLoading, setSiteLoading] = useState(false)

  // Load KPI summary counts on mount
  useEffect(() => {
    axios.get('/reports/data')
      .then(res => setKpis(res.data.kpis))
      .catch(() => {/* non-fatal — KPIs are supplementary */})
  }, [])

  // Load site-transfer report lazily when tab is activated
  useEffect(() => {
    if (mainTab !== 'site' || siteRows !== null) return
    setSiteLoading(true)
    axios.get('/reports/site-transfers')
      .then(res => {
        setSiteRows(res.data.participants ?? [])
        setSiteOptions(res.data.sites ?? [])
      })
      .catch(() => setSiteRows([]))
      .finally(() => setSiteLoading(false))
  }, [mainTab])

  const filteredSiteRows = siteRows
    ? siteFilter
      ? siteRows.filter(r => r.current_site.includes(siteFilter) || r.prior_sites.includes(siteFilter))
      : siteRows
    : []

  // Derive available categories from the reports passed down
  const availableCategories = CATEGORY_ORDER.filter(cat =>
    reports.some(r => r.category === cat)
  )

  const visibleReports = activeCategory === 'All'
    ? reports
    : reports.filter(r => r.category === activeCategory)

  const handleExport = async (report: Report) => {
    if (!report.export_url) return
    setDownloading(report.id)
    try {
      window.open(report.export_url, '_blank')
    } finally {
      setDownloading(null)
    }
  }

  const kpiItems = kpis ? [
    { label: 'Enrolled Participants', value: kpis.enrolled_participants, color: 'text-blue-600 dark:text-blue-400' },
    { label: 'Open Incidents',        value: kpis.open_incidents,        color: 'text-red-600 dark:text-red-400' },
    { label: 'Overdue SDRs',          value: kpis.overdue_sdrs,          color: 'text-amber-600 dark:text-amber-400' },
    { label: 'Meetings This Month',   value: kpis.meetings_this_month,   color: 'text-green-600 dark:text-green-400' },
  ] : null

  return (
    <AppShell>
      <Head title="Reports" />

      <div className="px-6 py-6 space-y-6">

        {/* Header */}
        <div className="flex items-end justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-gray-900 dark:text-slate-100">Reports</h1>
            <p className="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
              {reports.length} report{reports.length !== 1 ? 's' : ''} available for your department.
            </p>
          </div>
          {/* Top-level view tabs */}
          <div className="flex gap-2">
            <button
              onClick={() => setMainTab('catalog')}
              className={`text-sm px-3 py-1.5 rounded-lg border font-medium transition-colors flex items-center gap-1.5 ${
                mainTab === 'catalog'
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400'
              }`}
            >
              <DocumentChartBarIcon className="w-4 h-4" /> Report Catalog
            </button>
            <button
              onClick={() => setMainTab('site')}
              className={`text-sm px-3 py-1.5 rounded-lg border font-medium transition-colors flex items-center gap-1.5 ${
                mainTab === 'site'
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400'
              }`}
            >
              <BuildingOfficeIcon className="w-4 h-4" /> By PACE Site
            </button>
          </div>
        </div>

        {/* KPI row */}
        {kpiItems && (
          <div className="grid grid-cols-4 gap-3">
            {kpiItems.map(({ label, value, color }) => (
              <div key={label} className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-4 py-3 text-center shadow-sm">
                <p className={`text-2xl font-bold ${color}`}>{value}</p>
                <p className="text-xs text-gray-500 dark:text-slate-400 mt-0.5">{label}</p>
              </div>
            ))}
          </div>
        )}

        {/* ── By PACE Site tab ─────────────────────────────────────────────── */}
        {mainTab === 'site' && (
          <div className="space-y-4">
            <div className="flex items-center justify-between gap-4">
              <div className="flex items-center gap-3">
                <select
                  value={siteFilter}
                  onChange={e => setSiteFilter(e.target.value)}
                  className="text-sm border border-gray-300 dark:border-slate-600 dark:bg-slate-800 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">All sites</option>
                  {siteOptions.map(s => (
                    <option key={s.id} value={s.name}>{s.name}</option>
                  ))}
                </select>
                {siteRows && (
                  <span className="text-sm text-gray-500 dark:text-slate-400">
                    {filteredSiteRows.length} participant{filteredSiteRows.length !== 1 ? 's' : ''} with transfer history
                  </span>
                )}
              </div>
              {canExport && (
                <a
                  href="/reports/site-transfers/export"
                  className="inline-flex items-center gap-1.5 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium"
                >
                  <ArrowDownTrayIcon className="w-4 h-4" /> Export CSV
                </a>
              )}
            </div>

            {siteLoading ? (
              <p className="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">Loading transfer history…</p>
            ) : filteredSiteRows.length === 0 ? (
              <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-6 py-10 text-center">
                <BuildingOfficeIcon className="w-10 h-10 text-gray-300 dark:text-slate-600 mx-auto mb-3" />
                <p className="text-gray-400 dark:text-slate-500 text-sm">No completed site transfers on record.</p>
              </div>
            ) : (
              <div className="overflow-x-auto rounded-xl border border-gray-200 dark:border-slate-700">
                <table className="w-full text-sm">
                  <thead className="bg-gray-50 dark:bg-slate-700/50">
                    <tr>
                      {['Participant', 'MRN', 'Current Site', 'Prior Sites', 'Transfer Date(s)', 'Count'].map(h => (
                        <th key={h} className="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-slate-700 bg-white dark:bg-slate-800">
                    {filteredSiteRows.map(row => (
                      <tr key={row.participant_id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                        <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{row.participant_name}</td>
                        <td className="px-4 py-3 text-gray-500 dark:text-slate-400 font-mono text-xs">{row.mrn}</td>
                        <td className="px-4 py-3 text-gray-700 dark:text-slate-300">{row.current_site}</td>
                        <td className="px-4 py-3 text-gray-600 dark:text-slate-400 text-xs">{row.prior_sites}</td>
                        <td className="px-4 py-3 text-gray-600 dark:text-slate-400 text-xs">{row.transfer_dates}</td>
                        <td className="px-4 py-3">
                          <span className="px-2 py-0.5 rounded text-xs font-medium bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300">
                            {row.transfer_count}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}

        {/* Category tabs */}
        {mainTab === 'catalog' && availableCategories.length > 1 && (
          <div className="flex gap-2 flex-wrap">
            {['All', ...availableCategories].map(cat => (
              <button
                key={cat}
                onClick={() => setActiveCategory(cat)}
                className={`text-xs px-3 py-1.5 rounded-full border font-medium transition-colors ${
                  activeCategory === cat
                    ? 'bg-blue-600 text-white border-blue-600'
                    : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-300 dark:border-slate-600 hover:border-blue-400 dark:hover:border-blue-500'
                }`}
              >
                {cat}
                <span className="ml-1.5 text-xs opacity-70">
                  {cat === 'All' ? reports.length : reports.filter(r => r.category === cat).length}
                </span>
              </button>
            ))}
          </div>
        )}

        {/* Report cards */}
        {mainTab === 'catalog' && visibleReports.length === 0 ? (
          <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 px-6 py-10 text-center shadow-sm">
            <DocumentChartBarIcon className="w-10 h-10 text-gray-300 dark:text-slate-600 mx-auto mb-3" />
            <p className="text-gray-400 dark:text-slate-500 text-sm">
              No reports available for the selected category.
            </p>
          </div>
        ) : mainTab === 'catalog' ? (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {visibleReports.map(report => (
              <div
                key={report.id}
                className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5 flex flex-col gap-3"
              >
                {/* Category badge + title */}
                <div className="flex items-start justify-between gap-2">
                  <div className="flex-1 min-w-0">
                    <span className={`inline-flex text-xs font-medium px-2 py-0.5 rounded mb-1.5 ${CATEGORY_COLORS[report.category] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'}`}>
                      {report.category}
                    </span>
                    <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100 leading-snug">
                      {report.title}
                    </h3>
                  </div>
                  <DocumentChartBarIcon className="w-5 h-5 text-gray-400 dark:text-slate-500 shrink-0 mt-0.5" />
                </div>

                {/* Description */}
                <p className="text-xs text-gray-600 dark:text-slate-400 leading-relaxed flex-1">
                  {report.description}
                </p>

                {/* Action */}
                <div className="pt-1 border-t border-gray-100 dark:border-slate-700">
                  {report.export_url && canExport ? (
                    <button
                      onClick={() => handleExport(report)}
                      disabled={downloading === report.id}
                      className="inline-flex items-center gap-1.5 text-xs text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium disabled:opacity-50 transition-colors"
                    >
                      <ArrowDownTrayIcon className="w-3.5 h-3.5" />
                      {downloading === report.id ? 'Downloading...' : 'Export CSV'}
                    </button>
                  ) : report.export_url && !canExport ? (
                    <span className="text-xs text-gray-400 dark:text-slate-500">Export restricted</span>
                  ) : (
                    <span className="inline-flex items-center gap-1 text-xs text-gray-400 dark:text-slate-500">
                      <ClockIcon className="w-3.5 h-3.5" />
                      Export coming soon
                    </span>
                  )}
                </div>
              </div>
            ))}
          </div>
        ) : null}

      </div>
    </AppShell>
  )
}
