// ─── IT Admin: User Management ────────────────────────────────────────────────
// Allows IT Admin to view, deactivate, and reactivate user accounts.
//
// Layout:
//   - Search/filter bar (by name, department, active status)
//   - User table with: name, email, department, status, joined date, actions
//   - Deactivate/Reactivate buttons with confirmation
//
// Data loading strategy:
//   - Full user list pre-loaded (Inertia props) — typically <100 users per tenant
//   - Deactivate/Reactivate: POST endpoints, optimistic local state update
//
// Props: users
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useMemo } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface UserRow {
    id:         number;
    first_name: string;
    last_name:  string;
    email:      string;
    department: string;
    is_active:  boolean;
    created_at: string;
}

interface Props extends PageProps {
    users: UserRow[];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

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
    qa_compliance:     'QA / Compliance',
    it_admin:          'IT Admin',
};

// ── Provision Modal ───────────────────────────────────────────────────────────

interface ProvisionForm {
    first_name: string;
    last_name:  string;
    email:      string;
    department: string;
    role:       string;
}

const BLANK_FORM: ProvisionForm = { first_name: '', last_name: '', email: '', department: '', role: 'standard' };

// ── Component ─────────────────────────────────────────────────────────────────

export default function Users({ users: initialUsers }: Props) {
    const { auth } = usePage<Props>().props;
    const [users, setUsers]           = useState<UserRow[]>(initialUsers);
    const [search, setSearch]         = useState('');
    const [deptFilter, setDeptFilter] = useState('');
    const [activeFilter, setActiveFilter] = useState('');
    const [busy, setBusy]             = useState<number | null>(null);
    const [showProvision, setShowProvision] = useState(false);
    const [form, setForm]             = useState<ProvisionForm>(BLANK_FORM);
    const [provisioning, setProvisioning] = useState(false);
    const [formErrors, setFormErrors] = useState<Record<string, string>>({});

    const handleProvision = async (e: React.FormEvent) => {
        e.preventDefault();
        setProvisioning(true);
        setFormErrors({});
        try {
            const res = await axios.post('/it-admin/users', form);
            setUsers(prev => [...prev, res.data.user]);
            setShowProvision(false);
            setForm(BLANK_FORM);
        } catch (err: any) {
            if (err.response?.status === 422) {
                const errs = err.response.data.errors ?? {};
                const flat: Record<string, string> = {};
                Object.keys(errs).forEach(k => { flat[k] = errs[k][0]; });
                setFormErrors(flat);
            } else {
                alert('Provision failed. Please try again.');
            }
        } finally {
            setProvisioning(false);
        }
    };

    // Client-side filtering of the pre-loaded user list
    const filtered = useMemo(() => users.filter(u => {
        const name = `${u.first_name} ${u.last_name}`.toLowerCase();
        if (search && !name.includes(search.toLowerCase()) && !u.email.toLowerCase().includes(search.toLowerCase())) return false;
        if (deptFilter && u.department !== deptFilter) return false;
        if (activeFilter === 'active'   && !u.is_active)  return false;
        if (activeFilter === 'inactive' &&  u.is_active)  return false;
        return true;
    }), [users, search, deptFilter, activeFilter]);

    const toggleActive = async (user: UserRow) => {
        const endpoint = user.is_active
            ? `/it-admin/users/${user.id}/deactivate`
            : `/it-admin/users/${user.id}/reactivate`;
        const confirmMsg = user.is_active
            ? `Deactivate ${user.first_name} ${user.last_name}? They will lose access immediately.`
            : `Reactivate ${user.first_name} ${user.last_name}?`;

        if (!window.confirm(confirmMsg)) return;

        setBusy(user.id);
        try {
            await axios.post(endpoint);
            setUsers(prev => prev.map(u => u.id === user.id ? { ...u, is_active: !u.is_active } : u));
        } catch {
            alert('Action failed. Please try again.');
        } finally {
            setBusy(null);
        }
    };

    const departments = Array.from(new Set(users.map(u => u.department))).sort();

    return (
        <AppShell>
            <Head title="IT Admin — Users" />

            <div className="max-w-7xl mx-auto px-4 py-8">
                <div className="flex items-center justify-between mb-6">
                    <h1 className="text-2xl font-bold text-gray-900">User Management</h1>
                    <button
                        onClick={() => setShowProvision(true)}
                        className="bg-blue-700 text-white text-sm px-4 py-2 rounded hover:bg-blue-800"
                        data-testid="provision-user-btn"
                    >
                        + Provision User
                    </button>
                </div>

                {/* ── Provision User Modal ──────────────────────────────────── */}
                {showProvision && (
                    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                        <div className="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
                            <h2 className="text-lg font-semibold mb-4">Provision New User</h2>
                            <form onSubmit={handleProvision} className="space-y-3">
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="text-sm text-gray-600">First Name</label>
                                        <input type="text" value={form.first_name} onChange={e => setForm(f => ({...f, first_name: e.target.value}))}
                                            className="w-full border rounded px-3 py-1.5 text-sm mt-1" />
                                        {formErrors.first_name && <p className="text-xs text-red-600 mt-1">{formErrors.first_name}</p>}
                                    </div>
                                    <div>
                                        <label className="text-sm text-gray-600">Last Name</label>
                                        <input type="text" value={form.last_name} onChange={e => setForm(f => ({...f, last_name: e.target.value}))}
                                            className="w-full border rounded px-3 py-1.5 text-sm mt-1" />
                                        {formErrors.last_name && <p className="text-xs text-red-600 mt-1">{formErrors.last_name}</p>}
                                    </div>
                                </div>
                                <div>
                                    <label className="text-sm text-gray-600">Email</label>
                                    <input type="email" value={form.email} onChange={e => setForm(f => ({...f, email: e.target.value}))}
                                        className="w-full border rounded px-3 py-1.5 text-sm mt-1" />
                                    {formErrors.email && <p className="text-xs text-red-600 mt-1">{formErrors.email}</p>}
                                </div>
                                <div>
                                    <label className="text-sm text-gray-600">Department</label>
                                    <select value={form.department} onChange={e => setForm(f => ({...f, department: e.target.value}))}
                                        className="w-full border rounded px-3 py-1.5 text-sm mt-1">
                                        <option value="">— Select —</option>
                                        {Object.entries(DEPT_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                    </select>
                                    {formErrors.department && <p className="text-xs text-red-600 mt-1">{formErrors.department}</p>}
                                </div>
                                <div>
                                    <label className="text-sm text-gray-600">Role</label>
                                    <select value={form.role} onChange={e => setForm(f => ({...f, role: e.target.value}))}
                                        className="w-full border rounded px-3 py-1.5 text-sm mt-1">
                                        <option value="standard">Standard</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                                <div className="flex gap-3 pt-2 justify-end">
                                    <button type="button" onClick={() => setShowProvision(false)}
                                        className="text-sm px-4 py-2 border rounded hover:bg-gray-50">Cancel</button>
                                    <button type="submit" disabled={provisioning}
                                        className="text-sm px-4 py-2 bg-blue-700 text-white rounded hover:bg-blue-800 disabled:opacity-50">
                                        {provisioning ? 'Creating…' : 'Create User'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* ── Filter bar ─────────────────────────────────────────────── */}
                <div className="flex flex-wrap gap-3 mb-6">
                    <input
                        type="text"
                        placeholder="Search name or email…"
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                        className="border border-gray-300 rounded px-3 py-1.5 text-sm w-64"
                        data-testid="user-search"
                    />
                    <select
                        value={deptFilter}
                        onChange={e => setDeptFilter(e.target.value)}
                        className="border border-gray-300 rounded px-2 py-1.5 text-sm"
                        data-testid="dept-filter"
                    >
                        <option value="">All Departments</option>
                        {departments.map(d => (
                            <option key={d} value={d}>{DEPT_LABELS[d] ?? d}</option>
                        ))}
                    </select>
                    <select
                        value={activeFilter}
                        onChange={e => setActiveFilter(e.target.value)}
                        className="border border-gray-300 rounded px-2 py-1.5 text-sm"
                        data-testid="active-filter"
                    >
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <span className="ml-auto text-sm text-gray-500 self-center">
                        {filtered.length} of {users.length} users
                    </span>
                </div>

                {/* ── User table ─────────────────────────────────────────────── */}
                <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 text-xs text-gray-500 uppercase">
                            <tr>
                                <th className="text-left px-4 py-3">Name</th>
                                <th className="text-left px-4 py-3">Email</th>
                                <th className="text-left px-4 py-3">Department</th>
                                <th className="text-left px-4 py-3">Status</th>
                                <th className="text-left px-4 py-3">Joined</th>
                                <th className="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {filtered.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-center py-8 text-gray-400">
                                        No users found.
                                    </td>
                                </tr>
                            )}
                            {filtered.map(user => (
                                <tr key={user.id} className={`hover:bg-gray-50 ${!user.is_active ? 'opacity-60' : ''}`}>
                                    <td className="px-4 py-3 font-medium text-gray-900">
                                        {user.first_name} {user.last_name}
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">{user.email}</td>
                                    <td className="px-4 py-3 text-gray-600">
                                        {DEPT_LABELS[user.department] ?? user.department}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${user.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'}`}>
                                            {user.is_active ? 'Active' : 'Inactive'}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-gray-500">
                                        {new Date(user.created_at).toLocaleDateString()}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {/* Don't show toggle for the current user */}
                                        {user.id !== (auth as any)?.user?.id && (
                                            <button
                                                onClick={() => toggleActive(user)}
                                                disabled={busy === user.id}
                                                className={`text-xs font-medium disabled:opacity-50 ${user.is_active ? 'text-red-600 hover:text-red-800' : 'text-green-600 hover:text-green-800'}`}
                                                data-testid={`toggle-user-${user.id}`}
                                            >
                                                {busy === user.id
                                                    ? 'Saving…'
                                                    : user.is_active ? 'Deactivate' : 'Reactivate'
                                                }
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppShell>
    );
}
