// ─── ItAdmin/SystemSettings ───────────────────────────────────────────────────
// Tenant-level system settings page — PACE contract info, timezone, state,
// integration status panel, and state Medicaid configurations.
//
// Layout:
//   - Tenant settings card (PACE contract, state, timezone) — editable by it_admin
//   - Integration status grid (5 integrations with status badges)
//   - State Medicaid configs table (read-only here; edit via /it-admin/state-config)
//
// Data loading: all server-side (Inertia).
// URL: /admin/settings
// Backend: SystemSettingsController::index()
// ─────────────────────────────────────────────────────────────────────────────

import { Head, useForm } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import { ReactNode } from 'react'
import {
  CheckCircleIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  ServerIcon,
  LinkIcon,
} from '@heroicons/react/24/outline'

// ─── Types ────────────────────────────────────────────────────────────────────

interface Tenant {
  id:            number
  name:          string
  slug:          string
  pace_contract: string | null
  state:         string | null
  timezone:      string
  hipaa_timeout: number
}

interface MedicaidConfig {
  id:                number
  state_code:        string
  submission_format: string
  is_active:         boolean
  payer_id:          string | null
}

interface Integration {
  name:        string
  description: string
  status:      'configured' | 'pending' | 'error'
  endpoint:    string | null
}

interface Props {
  tenant:            Tenant | null
  medicaidConfigs:   MedicaidConfig[]
  canEdit:           boolean
  integrationStatus: Integration[]
}

// ─── Constants ────────────────────────────────────────────────────────────────

const INTEGRATION_COLORS: Record<string, string> = {
  configured: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
  pending:    'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
  error:      'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
}

const INTEGRATION_ICONS: Record<string, ReactNode> = {
  configured: <CheckCircleIcon className="w-4 h-4" />,
  pending:    <ClockIcon className="w-4 h-4" />,
  error:      <ExclamationTriangleIcon className="w-4 h-4" />,
}

const US_TIMEZONES = [
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Phoenix',
  'America/Los_Angeles',
  'America/Anchorage',
  'Pacific/Honolulu',
]

// ─── Component ────────────────────────────────────────────────────────────────

export default function SystemSettings({ tenant, medicaidConfigs, canEdit, integrationStatus }: Props) {
  const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
    pace_contract: tenant?.pace_contract ?? '',
    state:         tenant?.state ?? '',
    timezone:      tenant?.timezone ?? 'America/New_York',
  })

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    put('/admin/settings')
  }

  return (
    <AppShell>
      <Head title="System Settings" />

      <div className="px-6 py-6 space-y-6">

        {/* Header */}
        <div>
          <h1 className="text-xl font-bold text-gray-900 dark:text-slate-100">System Settings</h1>
          <p className="text-sm text-gray-500 dark:text-slate-400 mt-0.5">
            Tenant configuration, integration status, and HIPAA parameters.
          </p>
        </div>

        {/* Tenant info card */}
        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm">
          <div className="px-5 py-3.5 border-b border-gray-200 dark:border-slate-700 flex items-center gap-2">
            <ServerIcon className="w-4 h-4 text-gray-500 dark:text-slate-400" />
            <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
              Tenant Configuration
            </h2>
            {tenant && (
              <span className="ml-auto text-xs text-gray-400 dark:text-slate-500 font-mono">
                {tenant.name}
              </span>
            )}
          </div>

          {tenant ? (
            <form onSubmit={submit} className="p-5 space-y-4">
              <div className="grid grid-cols-2 gap-4">

                {/* PACE contract number */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                    PACE Contract Number
                  </label>
                  <input
                    type="text"
                    value={data.pace_contract}
                    onChange={e => setData('pace_contract', e.target.value)}
                    disabled={!canEdit}
                    placeholder="e.g. H1234"
                    className="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700 dark:text-slate-100 disabled:opacity-60 disabled:cursor-not-allowed"
                  />
                  {errors.pace_contract && (
                    <p className="text-xs text-red-600 dark:text-red-400 mt-1">{errors.pace_contract}</p>
                  )}
                </div>

                {/* State */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                    State (2-letter code)
                  </label>
                  <input
                    type="text"
                    value={data.state}
                    onChange={e => setData('state', e.target.value.toUpperCase().slice(0, 2))}
                    disabled={!canEdit}
                    placeholder="e.g. NY"
                    maxLength={2}
                    className="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700 dark:text-slate-100 disabled:opacity-60 disabled:cursor-not-allowed uppercase"
                  />
                  {errors.state && (
                    <p className="text-xs text-red-600 dark:text-red-400 mt-1">{errors.state}</p>
                  )}
                </div>

                {/* Timezone */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                    Timezone
                  </label>
                  <select
                    value={data.timezone}
                    onChange={e => setData('timezone', e.target.value)}
                    disabled={!canEdit}
                    className="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700 dark:text-slate-100 disabled:opacity-60 disabled:cursor-not-allowed"
                  >
                    {US_TIMEZONES.map(tz => (
                      <option key={tz} value={tz}>{tz}</option>
                    ))}
                  </select>
                  {errors.timezone && (
                    <p className="text-xs text-red-600 dark:text-red-400 mt-1">{errors.timezone}</p>
                  )}
                </div>

                {/* HIPAA timeout — read-only (config-driven) */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                    HIPAA Session Timeout
                  </label>
                  <div className="flex items-center gap-2">
                    <input
                      type="text"
                      value={`${tenant.hipaa_timeout} minutes`}
                      disabled
                      className="w-full text-sm border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 dark:bg-slate-700 dark:text-slate-400 opacity-60 cursor-not-allowed"
                    />
                  </div>
                  <p className="text-xs text-gray-400 dark:text-slate-500 mt-1">
                    Set via SESSION_LIFETIME in server config.
                  </p>
                </div>

              </div>

              {canEdit && (
                <div className="flex items-center gap-3 pt-2">
                  <button
                    type="submit"
                    disabled={processing}
                    className="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-lg disabled:opacity-50 transition-colors"
                  >
                    {processing ? 'Saving...' : 'Save Settings'}
                  </button>
                  {recentlySuccessful && (
                    <span className="text-sm text-green-600 dark:text-green-400 flex items-center gap-1">
                      <CheckCircleIcon className="w-4 h-4" />
                      Saved
                    </span>
                  )}
                </div>
              )}

              {!canEdit && (
                <p className="text-xs text-gray-400 dark:text-slate-500 pt-1">
                  Read-only. IT Admin access required to edit settings.
                </p>
              )}
            </form>
          ) : (
            <div className="p-5 text-sm text-gray-400 dark:text-slate-500">
              No tenant configuration found.
            </div>
          )}
        </div>

        {/* Integration status */}
        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm">
          <div className="px-5 py-3.5 border-b border-gray-200 dark:border-slate-700 flex items-center gap-2">
            <LinkIcon className="w-4 h-4 text-gray-500 dark:text-slate-400" />
            <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
              Integration Status
            </h2>
          </div>

          <div className="divide-y divide-gray-100 dark:divide-slate-700">
            {integrationStatus.map(integration => (
              <div key={integration.name} className="px-5 py-4 flex items-start gap-4">
                <div className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium mt-0.5 shrink-0 ${INTEGRATION_COLORS[integration.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'}`}>
                  {INTEGRATION_ICONS[integration.status]}
                  <span className="capitalize">{integration.status.replace('_', ' ')}</span>
                </div>

                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-gray-900 dark:text-slate-100">
                    {integration.name}
                  </p>
                  <p className="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                    {integration.description}
                  </p>
                  {integration.endpoint && (
                    <p className="text-xs font-mono text-gray-400 dark:text-slate-500 mt-1">
                      {integration.endpoint}
                    </p>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* State Medicaid configs */}
        {medicaidConfigs.length > 0 && (
          <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm">
            <div className="px-5 py-3.5 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
              <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                State Medicaid Configurations
              </h2>
              <a
                href="/it-admin/state-config"
                className="text-xs text-blue-600 dark:text-blue-400 hover:underline"
              >
                Manage
              </a>
            </div>

            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-slate-700/50">
                <tr>
                  {['State', 'Format', 'Payer ID', 'Status'].map(h => (
                    <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
                {medicaidConfigs.map(cfg => (
                  <tr key={cfg.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors">
                    <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100 font-mono">
                      {cfg.state_code}
                    </td>
                    <td className="px-4 py-3 text-gray-600 dark:text-slate-400">
                      {cfg.submission_format.toUpperCase()}
                    </td>
                    <td className="px-4 py-3 text-gray-600 dark:text-slate-400 font-mono text-xs">
                      {cfg.payer_id ?? '-'}
                    </td>
                    <td className="px-4 py-3">
                      <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${cfg.is_active ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' : 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'}`}>
                        {cfg.is_active ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

      </div>
    </AppShell>
  )
}
