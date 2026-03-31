// ─── Locations/Index ──────────────────────────────────────────────────────────
// Management page for service locations used in appointment scheduling.
//
// Layout: header with optional "Add Location" button (transportation team only),
//         filter bar (type + active/inactive toggle), sortable table of locations.
//
// Data loading: all locations pre-loaded server-side via Inertia props (managePage).
// Write access: transportation dept, super_admin, and dept=super_admin only.
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useMemo } from 'react'
import { Head, router } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'

// ── Types ─────────────────────────────────────────────────────────────────────

interface Location {
    id: number
    name: string
    label: string | null
    location_type: string
    type_label: string
    street: string | null
    city: string | null
    state: string | null
    zip: string | null
    phone: string | null
    contact_name: string | null
    notes: string | null
    is_active: boolean
    deleted_at: string | null
}

interface FormState {
    name: string
    label: string
    location_type: string
    street: string
    city: string
    state: string
    zip: string
    phone: string
    contact_name: string
    notes: string
    is_active: boolean
}

interface Props {
    locations: Location[]
    location_types: Record<string, string>
    can_write: boolean
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const TYPE_COLORS: Record<string, string> = {
    pace_center:    'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 ring-blue-600/20',
    acs_location:   'bg-purple-50 dark:bg-purple-950/60 text-purple-700 dark:text-purple-300 ring-purple-600/20',
    dialysis:       'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 ring-red-600/20',
    specialist:     'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 ring-amber-600/20',
    hospital:       'bg-orange-50 text-orange-700 ring-orange-600/20',
    pharmacy:       'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 ring-green-600/20',
    lab:            'bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 ring-teal-600/20',
    day_program:    'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 ring-indigo-600/20',
    other_external: 'bg-slate-50 dark:bg-slate-900 text-slate-700 dark:text-slate-300 ring-slate-600/20',
}

const blankForm = (): FormState => ({
    name: '', label: '', location_type: 'pace_center',
    street: '', city: '', state: '', zip: '',
    phone: '', contact_name: '', notes: '', is_active: true,
})

// ── Component ─────────────────────────────────────────────────────────────────

export default function LocationsIndex({ locations, location_types, can_write }: Props) {
    const [filterType, setFilterType]       = useState<string>('all')
    const [filterActive, setFilterActive]   = useState<'active' | 'inactive' | 'all'>('active')
    const [modal, setModal]                 = useState<'none' | 'create' | 'edit'>('none')
    const [editing, setEditing]             = useState<Location | null>(null)
    const [form, setForm]                   = useState<FormState>(blankForm())
    const [errors, setErrors]               = useState<Partial<FormState>>({})
    const [saving, setSaving]               = useState(false)
    const [confirmDelete, setConfirmDelete] = useState<Location | null>(null)

    // ── Filtered list ─────────────────────────────────────────────────────────

    const filtered = useMemo(() => {
        return locations.filter(l => {
            if (filterType !== 'all' && l.location_type !== filterType) return false
            if (filterActive === 'active'   && (!l.is_active || l.deleted_at)) return false
            if (filterActive === 'inactive' && l.is_active && !l.deleted_at)   return false
            return true
        })
    }, [locations, filterType, filterActive])

    // ── Modal helpers ─────────────────────────────────────────────────────────

    function openCreate() {
        setForm(blankForm())
        setErrors({})
        setModal('create')
    }

    function openEdit(loc: Location) {
        setEditing(loc)
        setForm({
            name:           loc.name,
            label:          loc.label ?? '',
            location_type:  loc.location_type,
            street:         loc.street ?? '',
            city:           loc.city ?? '',
            state:          loc.state ?? '',
            zip:            loc.zip ?? '',
            phone:          loc.phone ?? '',
            contact_name:   loc.contact_name ?? '',
            notes:          loc.notes ?? '',
            is_active:      loc.is_active,
        })
        setErrors({})
        setModal('edit')
    }

    function closeModal() {
        setModal('none')
        setEditing(null)
    }

    function handleField(key: keyof FormState, value: string | boolean) {
        setForm(f => ({ ...f, [key]: value }))
        setErrors(e => { const n = { ...e }; delete n[key]; return n })
    }

    // ── Save ──────────────────────────────────────────────────────────────────

    function handleSave() {
        const e: Partial<FormState> = {}
        if (!form.name.trim()) e.name = 'Name is required.'
        if (!form.location_type) e.location_type = 'Type is required.'
        if (Object.keys(e).length) { setErrors(e); return }

        setSaving(true)
        const payload = { ...form }

        if (modal === 'create') {
            router.post('/locations', payload, {
                onSuccess: closeModal,
                onError:   errs => { setErrors(errs as Partial<FormState>); setSaving(false) },
                onFinish:  () => setSaving(false),
            })
        } else if (editing) {
            router.put(`/locations/${editing.id}`, payload, {
                onSuccess: closeModal,
                onError:   errs => { setErrors(errs as Partial<FormState>); setSaving(false) },
                onFinish:  () => setSaving(false),
            })
        }
    }

    // ── Archive (soft-delete) ─────────────────────────────────────────────────

    function handleArchive(loc: Location) {
        router.delete(`/locations/${loc.id}`, {
            onFinish: () => setConfirmDelete(null),
        })
    }

    // ── Render ────────────────────────────────────────────────────────────────

    return (
        <AppShell>
            <Head title="Locations" />

            {/* Header */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-100">Locations</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        Service locations used for appointment scheduling across the PACE program.
                    </p>
                </div>
                {can_write && (
                    <button
                        onClick={openCreate}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 transition-colors"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Add Location
                    </button>
                )}
            </div>

            {/* Filters */}
            <div className="flex flex-wrap items-center gap-3 mb-4">
                <select
                    value={filterType}
                    onChange={e => setFilterType(e.target.value)}
                    className="rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-1.5 text-sm text-slate-700 dark:text-slate-300 shadow-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                >
                    <option value="all">All Types</option>
                    {Object.entries(location_types).map(([k, v]) => (
                        <option key={k} value={k}>{v}</option>
                    ))}
                </select>

                <div className="flex rounded-lg border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden shadow-sm text-sm">
                    {(['active', 'inactive', 'all'] as const).map(opt => (
                        <button
                            key={opt}
                            onClick={() => setFilterActive(opt)}
                            className={`px-3 py-1.5 capitalize transition-colors ${
                                filterActive === opt
                                    ? 'bg-blue-600 text-white font-medium'
                                    : 'text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-700'
                            }`}
                        >
                            {opt}
                        </button>
                    ))}
                </div>

                <span className="text-xs text-slate-400 ml-auto">
                    {filtered.length} location{filtered.length !== 1 ? 's' : ''}
                </span>
            </div>

            {/* Table */}
            <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-sm">
                {filtered.length === 0 ? (
                    <div className="py-16 text-center text-slate-400 text-sm">
                        No locations match the current filters.
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                            <tr>
                                <th className="px-4 py-3 text-left">Name</th>
                                <th className="px-4 py-3 text-left">Type</th>
                                <th className="px-4 py-3 text-left">Address</th>
                                <th className="px-4 py-3 text-left">Phone</th>
                                <th className="px-4 py-3 text-left">Contact</th>
                                <th className="px-4 py-3 text-left">Status</th>
                                {can_write && <th className="px-4 py-3" />}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                            {filtered.map(loc => (
                                <tr key={loc.id} className={`hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors ${loc.deleted_at ? 'opacity-50' : ''}`}>
                                    <td className="px-4 py-3 font-medium text-slate-900 dark:text-slate-100">
                                        {loc.name}
                                        {loc.label && (
                                            <span className="ml-1.5 text-xs text-slate-400">({loc.label})</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${TYPE_COLORS[loc.location_type] ?? 'bg-slate-50 dark:bg-slate-900 text-slate-700 dark:text-slate-300 ring-slate-600/20'}`}>
                                            {loc.type_label}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-slate-600 dark:text-slate-400">
                                        {loc.street
                                            ? `${loc.street}, ${loc.city ?? ''} ${loc.state ?? ''} ${loc.zip ?? ''}`.trim()
                                            : <span className="text-slate-400 italic">No address</span>
                                        }
                                    </td>
                                    <td className="px-4 py-3 text-slate-600 dark:text-slate-400">{loc.phone ?? '-'}</td>
                                    <td className="px-4 py-3 text-slate-600 dark:text-slate-400">{loc.contact_name ?? '-'}</td>
                                    <td className="px-4 py-3">
                                        {loc.deleted_at ? (
                                            <span className="inline-flex items-center rounded-full bg-slate-100 dark:bg-slate-800 px-2 py-0.5 text-xs font-medium text-slate-500 dark:text-slate-400">Archived</span>
                                        ) : loc.is_active ? (
                                            <span className="inline-flex items-center rounded-full bg-green-50 dark:bg-green-950/60 px-2 py-0.5 text-xs font-medium text-green-700 dark:text-green-300">Active</span>
                                        ) : (
                                            <span className="inline-flex items-center rounded-full bg-amber-50 dark:bg-amber-950/60 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300">Inactive</span>
                                        )}
                                    </td>
                                    {can_write && (
                                        <td className="px-4 py-3 text-right">
                                            {!loc.deleted_at && (
                                                <div className="flex items-center justify-end gap-2">
                                                    <button
                                                        onClick={() => openEdit(loc)}
                                                        className="text-slate-400 hover:text-blue-600 transition-colors"
                                                        title="Edit"
                                                    >
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" />
                                                        </svg>
                                                    </button>
                                                    <button
                                                        onClick={() => setConfirmDelete(loc)}
                                                        className="text-slate-400 hover:text-red-600 transition-colors"
                                                        title="Archive"
                                                    >
                                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* Create / Edit Modal */}
            {modal !== 'none' && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white dark:bg-slate-800 shadow-xl">
                        <div className="flex items-center justify-between border-b border-slate-200 dark:border-slate-700 px-6 py-4">
                            <h2 className="text-base font-semibold text-slate-900 dark:text-slate-100">
                                {modal === 'create' ? 'Add Location' : 'Edit Location'}
                            </h2>
                            <button onClick={closeModal} className="text-slate-400 hover:text-slate-600">
                                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div className="px-6 py-5 space-y-4 max-h-[70vh] overflow-y-auto">
                            {/* Name + Type */}
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Name <span className="text-red-500">*</span></label>
                                    <input
                                        value={form.name}
                                        onChange={e => handleField('name', e.target.value)}
                                        className={`w-full rounded-lg border px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none ${errors.name ? 'border-red-400' : 'border-slate-300'}`}
                                        placeholder="Sunrise PACE Center"
                                    />
                                    {errors.name && <p className="text-xs text-red-500 mt-1">{errors.name}</p>}
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Type <span className="text-red-500">*</span></label>
                                    <select
                                        value={form.location_type}
                                        onChange={e => handleField('location_type', e.target.value)}
                                        className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                    >
                                        {Object.entries(location_types).map(([k, v]) => (
                                            <option key={k} value={k}>{v}</option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            {/* Label (optional short name) */}
                            <div>
                                <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Short Label <span className="text-slate-400 font-normal">(optional)</span></label>
                                <input
                                    value={form.label}
                                    onChange={e => handleField('label', e.target.value)}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                    placeholder="PACE East"
                                />
                            </div>

                            {/* Address */}
                            <div>
                                <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Street Address</label>
                                <input
                                    value={form.street}
                                    onChange={e => handleField('street', e.target.value)}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                    placeholder="123 Main St"
                                />
                            </div>
                            <div className="grid grid-cols-3 gap-3">
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">City</label>
                                    <input value={form.city} onChange={e => handleField('city', e.target.value)}
                                        className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">State</label>
                                    <input value={form.state} onChange={e => handleField('state', e.target.value)} maxLength={2}
                                        className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="CA" />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">ZIP</label>
                                    <input value={form.zip} onChange={e => handleField('zip', e.target.value)}
                                        className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="90210" />
                                </div>
                            </div>

                            {/* Phone + Contact */}
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Phone</label>
                                    <input value={form.phone} onChange={e => handleField('phone', e.target.value)}
                                        className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="(555) 555-0100" />
                                </div>
                                <div>
                                    <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Contact Name</label>
                                    <input value={form.contact_name} onChange={e => handleField('contact_name', e.target.value)}
                                        className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" placeholder="Jane Smith" />
                                </div>
                            </div>

                            {/* Notes */}
                            <div>
                                <label className="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Notes</label>
                                <textarea value={form.notes} onChange={e => handleField('notes', e.target.value)} rows={2}
                                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none resize-none"
                                    placeholder="Any relevant notes about this location..." />
                            </div>

                            {/* Active toggle */}
                            <label className="flex items-center gap-2 cursor-pointer select-none">
                                <input type="checkbox" checked={form.is_active} onChange={e => handleField('is_active', e.target.checked)}
                                    className="rounded border-slate-300 text-blue-600 dark:text-blue-400 focus:ring-blue-500" />
                                <span className="text-sm text-slate-700 dark:text-slate-300">Active (visible in scheduling dropdowns)</span>
                            </label>
                        </div>

                        <div className="flex justify-end gap-3 border-t border-slate-200 dark:border-slate-700 px-6 py-4">
                            <button onClick={closeModal} className="rounded-lg border border-slate-200 dark:border-slate-700 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
                                Cancel
                            </button>
                            <button onClick={handleSave} disabled={saving}
                                className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors">
                                {saving ? 'Saving…' : modal === 'create' ? 'Add Location' : 'Save Changes'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Archive Confirm Dialog */}
            {confirmDelete && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-sm rounded-2xl bg-white dark:bg-slate-800 shadow-xl p-6 text-center">
                        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/60">
                            <svg className="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                        </div>
                        <h3 className="text-base font-semibold text-slate-900 dark:text-slate-100 mb-1">Archive Location?</h3>
                        <p className="text-sm text-slate-500 dark:text-slate-400 mb-5">
                            <strong>{confirmDelete.name}</strong> will be archived and removed from scheduling dropdowns.
                            Existing appointments are not affected.
                        </p>
                        <div className="flex gap-3">
                            <button onClick={() => setConfirmDelete(null)}
                                className="flex-1 rounded-lg border border-slate-200 dark:border-slate-700 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700">
                                Cancel
                            </button>
                            <button onClick={() => handleArchive(confirmDelete)}
                                className="flex-1 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                                Archive
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
