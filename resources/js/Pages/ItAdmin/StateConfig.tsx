// ─── ItAdmin/StateConfig ───────────────────────────────────────────────────────
// State Medicaid encounter submission configuration management page.
// Accessible to IT Admin (write) and Finance (read-only view).
//
// Data flow:
//   - Initial props: configs (list), submissionFormats (label map) — server via Inertia
//   - Create: POST /it-admin/state-config
//   - Update: PUT  /it-admin/state-config/{id}
//   - Deactivate: DELETE /it-admin/state-config/{id}
//
// Context:
//   PACE participants are dually eligible (Medicare + Medicaid). Many states
//   require separate 837 encounter submissions to the state Medicaid agency.
//   This page configures those submission parameters per state. (DEBT-038)
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react'
import { Head, router } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import axios from 'axios'

// ── Types ─────────────────────────────────────────────────────────────────────

interface StateMedicaidConfig {
    id: number
    state_code: string
    state_name: string
    submission_format: string
    companion_guide_notes: string | null
    submission_endpoint: string | null
    clearinghouse_name: string | null
    days_to_submit: number
    effective_date: string
    contact_name: string | null
    contact_phone: string | null
    contact_email: string | null
    is_active: boolean
    created_at: string
    updated_at: string
}

interface Props {
    configs: StateMedicaidConfig[]
    submissionFormats: Record<string, string>
}

// ── Blank form state ──────────────────────────────────────────────────────────

const blankForm = {
    state_code: '',
    state_name: '',
    submission_format: '837P',
    companion_guide_notes: '',
    submission_endpoint: '',
    clearinghouse_name: '',
    days_to_submit: 180,
    effective_date: new Date().toISOString().slice(0, 10),
    contact_name: '',
    contact_phone: '',
    contact_email: '',
    is_active: true,
}

// ── Component ─────────────────────────────────────────────────────────────────

export default function StateConfig({ configs: initialConfigs, submissionFormats }: Props) {
    const [configs, setConfigs]     = useState<StateMedicaidConfig[]>(initialConfigs)
    const [showForm, setShowForm]   = useState(false)
    const [editing, setEditing]     = useState<StateMedicaidConfig | null>(null)
    const [form, setForm]           = useState(blankForm)
    const [errors, setErrors]       = useState<Record<string, string>>({})
    const [saving, setSaving]       = useState(false)

    const openNew = () => {
        setEditing(null)
        setForm(blankForm)
        setErrors({})
        setShowForm(true)
    }

    const openEdit = (config: StateMedicaidConfig) => {
        setEditing(config)
        setForm({
            state_code: config.state_code,
            state_name: config.state_name,
            submission_format: config.submission_format,
            companion_guide_notes: config.companion_guide_notes ?? '',
            submission_endpoint: config.submission_endpoint ?? '',
            clearinghouse_name: config.clearinghouse_name ?? '',
            days_to_submit: config.days_to_submit,
            effective_date: config.effective_date.slice(0, 10),
            contact_name: config.contact_name ?? '',
            contact_phone: config.contact_phone ?? '',
            contact_email: config.contact_email ?? '',
            is_active: config.is_active,
        })
        setErrors({})
        setShowForm(true)
    }

    const save = async () => {
        setSaving(true)
        setErrors({})
        try {
            const payload = {
                ...form,
                state_code: form.state_code.toUpperCase(),
            }
            if (editing) {
                await axios.put(`/it-admin/state-config/${editing.id}`, payload)
            } else {
                await axios.post('/it-admin/state-config', payload)
            }
            // Reload page props via Inertia to get fresh configs
            router.reload({ only: ['configs'] })
            setShowForm(false)
        } catch (err: any) {
            if (err.response?.status === 422) {
                const apiErrors: Record<string, string> = {}
                Object.entries(err.response.data.errors ?? {}).forEach(([k, v]) => {
                    apiErrors[k] = Array.isArray(v) ? v[0] as string : String(v)
                })
                setErrors(apiErrors)
            }
        } finally {
            setSaving(false)
        }
    }

    const deactivate = async (config: StateMedicaidConfig) => {
        if (!confirm(`Deactivate the ${config.state_name} (${config.state_code}) configuration?`)) return
        await axios.delete(`/it-admin/state-config/${config.id}`)
        router.reload({ only: ['configs'] })
    }

    const fld = (key: string) => (
        <p className="text-xs text-red-600 mt-0.5">{errors[key]}</p>
    )

    return (
        <AppShell>
            <Head title="State Medicaid Configuration" />

            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900">State Medicaid Configuration</h1>
                        <p className="text-sm text-slate-500 mt-1">
                            Configure state-specific 837 encounter submission parameters for dual-eligible PACE participants.
                        </p>
                    </div>
                    <button
                        onClick={openNew}
                        className="px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700"
                    >
                        + Add State
                    </button>
                </div>

                {/* Config Table */}
                <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                            <tr>
                                <th className="px-4 py-3 text-left">State</th>
                                <th className="px-4 py-3 text-left">Format</th>
                                <th className="px-4 py-3 text-left">Clearinghouse</th>
                                <th className="px-4 py-3 text-center">Days to Submit</th>
                                <th className="px-4 py-3 text-center">Status</th>
                                <th className="px-4 py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {configs.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-10 text-center text-slate-400">
                                        No state configurations yet. Add your first state to begin configuring Medicaid encounter submissions.
                                    </td>
                                </tr>
                            )}
                            {configs.map(config => (
                                <tr key={config.id} className={`hover:bg-slate-50 ${!config.is_active ? 'opacity-50' : ''}`}>
                                    <td className="px-4 py-3">
                                        <span className="font-mono font-bold text-slate-900">{config.state_code}</span>
                                        <span className="text-slate-500 ml-2">{config.state_name}</span>
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className="px-2 py-0.5 bg-blue-50 text-blue-700 text-xs rounded font-mono">
                                            {config.submission_format}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-slate-600">{config.clearinghouse_name ?? '—'}</td>
                                    <td className="px-4 py-3 text-center text-slate-600">{config.days_to_submit}</td>
                                    <td className="px-4 py-3 text-center">
                                        <span className={`px-2 py-0.5 text-xs rounded-full font-medium ${
                                            config.is_active
                                                ? 'bg-green-50 text-green-700'
                                                : 'bg-slate-100 text-slate-500'
                                        }`}>
                                            {config.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-center space-x-3">
                                        <button
                                            onClick={() => openEdit(config)}
                                            className="text-xs text-blue-600 hover:text-blue-800"
                                        >
                                            Edit
                                        </button>
                                        {config.is_active && (
                                            <button
                                                onClick={() => deactivate(config)}
                                                className="text-xs text-red-500 hover:text-red-700"
                                            >
                                                Deactivate
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Create/Edit Modal */}
            {showForm && (
                <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                        <div className="px-6 py-5 border-b border-slate-200 flex items-center justify-between">
                            <h2 className="text-lg font-semibold text-slate-900">
                                {editing ? `Edit ${editing.state_name} Configuration` : 'Add State Medicaid Configuration'}
                            </h2>
                            <button onClick={() => setShowForm(false)} className="text-slate-400 hover:text-slate-600">✕</button>
                        </div>

                        <div className="px-6 py-5 space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                {/* State Code */}
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 mb-1">State Code *</label>
                                    <input
                                        type="text"
                                        maxLength={2}
                                        value={form.state_code}
                                        onChange={e => setForm(f => ({ ...f, state_code: e.target.value.toUpperCase() }))}
                                        disabled={!!editing}
                                        placeholder="CA"
                                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm uppercase disabled:bg-slate-50"
                                    />
                                    {fld('state_code')}
                                </div>

                                {/* State Name */}
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 mb-1">State Name *</label>
                                    <input
                                        type="text"
                                        value={form.state_name}
                                        onChange={e => setForm(f => ({ ...f, state_name: e.target.value }))}
                                        placeholder="California"
                                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                                    />
                                    {fld('state_name')}
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                {/* Submission Format */}
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 mb-1">Submission Format *</label>
                                    <select
                                        value={form.submission_format}
                                        onChange={e => setForm(f => ({ ...f, submission_format: e.target.value }))}
                                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                                    >
                                        {Object.entries(submissionFormats).map(([val, label]) => (
                                            <option key={val} value={val}>{label}</option>
                                        ))}
                                    </select>
                                    {fld('submission_format')}
                                </div>

                                {/* Days to Submit */}
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 mb-1">Days to Submit *</label>
                                    <input
                                        type="number"
                                        min={1}
                                        max={365}
                                        value={form.days_to_submit}
                                        onChange={e => setForm(f => ({ ...f, days_to_submit: parseInt(e.target.value) || 180 }))}
                                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                                    />
                                    {fld('days_to_submit')}
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                {/* Clearinghouse */}
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 mb-1">Clearinghouse Name</label>
                                    <input
                                        type="text"
                                        value={form.clearinghouse_name}
                                        onChange={e => setForm(f => ({ ...f, clearinghouse_name: e.target.value }))}
                                        placeholder="Availity"
                                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                                    />
                                    {fld('clearinghouse_name')}
                                </div>

                                {/* Effective Date */}
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 mb-1">Effective Date *</label>
                                    <input
                                        type="date"
                                        value={form.effective_date}
                                        onChange={e => setForm(f => ({ ...f, effective_date: e.target.value }))}
                                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                                    />
                                    {fld('effective_date')}
                                </div>
                            </div>

                            {/* Companion Guide Notes */}
                            <div>
                                <label className="block text-xs font-medium text-slate-700 mb-1">
                                    Companion Guide Notes
                                    <span className="text-slate-400 font-normal ml-1">(state-specific deviations from X12 standard)</span>
                                </label>
                                <textarea
                                    rows={3}
                                    value={form.companion_guide_notes}
                                    onChange={e => setForm(f => ({ ...f, companion_guide_notes: e.target.value }))}
                                    className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                                    placeholder="Note any state-specific companion guide requirements here..."
                                />
                                {fld('companion_guide_notes')}
                            </div>

                            {/* Contact Info */}
                            <div className="grid grid-cols-3 gap-4">
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 mb-1">Contact Name</label>
                                    <input
                                        type="text"
                                        value={form.contact_name}
                                        onChange={e => setForm(f => ({ ...f, contact_name: e.target.value }))}
                                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 mb-1">Contact Phone</label>
                                    <input
                                        type="text"
                                        value={form.contact_phone}
                                        onChange={e => setForm(f => ({ ...f, contact_phone: e.target.value }))}
                                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 mb-1">Contact Email</label>
                                    <input
                                        type="email"
                                        value={form.contact_email}
                                        onChange={e => setForm(f => ({ ...f, contact_email: e.target.value }))}
                                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="px-6 py-4 border-t border-slate-200 flex justify-end gap-3">
                            <button
                                onClick={() => setShowForm(false)}
                                className="px-4 py-2 text-sm text-slate-600 hover:text-slate-800"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={save}
                                disabled={saving}
                                className="px-5 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-50"
                            >
                                {saving ? 'Saving…' : editing ? 'Update Configuration' : 'Add Configuration'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
