// ─── SuperAdmin/Index ──────────────────────────────────────────────────────────
// Nostos Super Admin Panel — platform-level tenant management.
// Accessible only to isSuperAdmin() (role='super_admin') or isDeptSuperAdmin()
// (department='super_admin', Nostos staff). NOT for PACE org IT admins.
//
// Layout: summary KPIs header + 3 tabs:
//   Tenants  — table of all tenants with participant/user/site counts
//   Health   — system table row counts + queue depth
//   Onboard  — 5-step wizard to create a new tenant
//
// Data loading:
//   - Summary KPIs: pre-loaded as Inertia props from SuperAdminPanelController::index()
//   - Tenants table: lazy-loaded from GET /super-admin-panel/tenants on tab activation
//   - Health data:   lazy-loaded from GET /super-admin-panel/health on tab activation
//   - Onboard:       form POSTs to POST /super-admin-panel/onboard
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ──────────────────────────────────────────────────────────────────────

interface Summary {
    tenant_count: number;
    user_count: number;
    participant_count: number;
}

interface TenantRow {
    id: number;
    name: string;
    transport_mode: string;
    user_count: number;
    participant_count: number;
    site_count: number;
    created_at: string | null;
}

interface TableCount {
    table: string;
    count: number | null;
}

interface HealthData {
    table_counts: TableCount[];
    queues: { failed_jobs: number; pending_jobs: number };
}

interface OnboardForm {
    tenant_name: string;
    transport_mode: string;
    auto_logout_minutes: string;
    site_name: string;
    site_city: string;
    site_state: string;
    admin_first_name: string;
    admin_last_name: string;
    admin_email: string;
    admin_department: string;
}

const BLANK_FORM: OnboardForm = {
    tenant_name: '',
    transport_mode: 'direct',
    auto_logout_minutes: '15',
    site_name: '',
    site_city: '',
    site_state: '',
    admin_first_name: '',
    admin_last_name: '',
    admin_email: '',
    admin_department: 'it_admin',
};

// ── TenantsTab ─────────────────────────────────────────────────────────────────
function TenantsTab() {
    const [tenants, setTenants] = useState<TenantRow[] | null>(null);
    const [loading, setLoading] = useState(false);

    // Lazy-load on first render
    React.useEffect(() => {
        setLoading(true);
        axios.get('/super-admin-panel/tenants')
            .then(res => setTenants(res.data.tenants ?? []))
            .finally(() => setLoading(false));
    }, []);

    if (loading) {
        return <div className="py-8 text-center text-sm text-slate-400">Loading tenants…</div>;
    }

    if (!tenants || tenants.length === 0) {
        return <div className="py-8 text-center text-sm text-slate-400">No tenants found.</div>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-xs text-slate-400 uppercase tracking-wide border-b border-slate-200">
                        <th className="text-left py-2.5 px-3 font-medium">Tenant</th>
                        <th className="text-center py-2.5 px-3 font-medium">Mode</th>
                        <th className="text-right py-2.5 px-3 font-medium">Sites</th>
                        <th className="text-right py-2.5 px-3 font-medium">Users</th>
                        <th className="text-right py-2.5 px-3 font-medium">Participants</th>
                        <th className="text-right py-2.5 px-3 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {tenants.map(t => (
                        <tr key={t.id} className="hover:bg-slate-50 transition-colors">
                            <td className="py-3 px-3 font-medium text-slate-800">{t.name}</td>
                            <td className="py-3 px-3 text-center">
                                <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${t.transport_mode === 'direct' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'}`}>
                                    {t.transport_mode}
                                </span>
                            </td>
                            <td className="py-3 px-3 text-right text-slate-600">{t.site_count}</td>
                            <td className="py-3 px-3 text-right text-slate-600">{t.user_count}</td>
                            <td className="py-3 px-3 text-right text-slate-600">{t.participant_count}</td>
                            <td className="py-3 px-3 text-right text-slate-400 text-xs">{t.created_at ?? '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ── HealthTab ──────────────────────────────────────────────────────────────────
function HealthTab() {
    const [health, setHealth] = useState<HealthData | null>(null);
    const [loading, setLoading] = useState(false);

    React.useEffect(() => {
        setLoading(true);
        axios.get('/super-admin-panel/health')
            .then(res => setHealth(res.data))
            .finally(() => setLoading(false));
    }, []);

    if (loading) {
        return <div className="py-8 text-center text-sm text-slate-400">Loading health data…</div>;
    }

    if (!health) {
        return <div className="py-8 text-center text-sm text-slate-400">Failed to load health data.</div>;
    }

    return (
        <div className="space-y-6">
            {/* Queue status */}
            <div className="grid grid-cols-2 gap-4">
                <div className={`rounded-xl border p-4 ${health.queues.failed_jobs > 0 ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200'}`}>
                    <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">Failed Jobs</p>
                    <p className={`text-2xl font-bold mt-1 ${health.queues.failed_jobs > 0 ? 'text-red-700' : 'text-emerald-700'}`}>
                        {health.queues.failed_jobs}
                    </p>
                </div>
                <div className="rounded-xl border bg-blue-50 border-blue-200 p-4">
                    <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">Pending Jobs</p>
                    <p className="text-2xl font-bold mt-1 text-blue-700">{health.queues.pending_jobs}</p>
                </div>
            </div>

            {/* Table row counts */}
            <div>
                <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Table Row Counts</h3>
                <div className="grid grid-cols-2 md:grid-cols-3 gap-2">
                    {health.table_counts.map(tc => (
                        <div key={tc.table} className="bg-slate-50 rounded-lg border border-slate-200 px-3 py-2">
                            <p className="text-[10px] text-slate-400 truncate">{tc.table}</p>
                            <p className="text-base font-semibold text-slate-700 mt-0.5">
                                {tc.count === null ? <span className="text-red-400 text-xs">Error</span> : tc.count.toLocaleString()}
                            </p>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

// ── OnboardTab ─────────────────────────────────────────────────────────────────
function OnboardTab() {
    const [form, setForm]         = useState<OnboardForm>(BLANK_FORM);
    const [submitting, setSubmitting] = useState(false);
    const [success, setSuccess]   = useState<string | null>(null);
    const [errors, setErrors]     = useState<Record<string, string>>({});

    const set = (field: keyof OnboardForm) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
        setForm(prev => ({ ...prev, [field]: e.target.value }));
        setErrors(prev => { const n = { ...prev }; delete n[field]; return n; });
    };

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);
        setSuccess(null);
        setErrors({});

        try {
            const res = await axios.post('/super-admin-panel/onboard', {
                ...form,
                auto_logout_minutes: parseInt(form.auto_logout_minutes, 10),
            });
            setSuccess(`Tenant "${res.data.ids?.tenant_id ? form.tenant_name : 'new'}" onboarded successfully.`);
            setForm(BLANK_FORM);
        } catch (err: unknown) {
            if (axios.isAxiosError(err) && err.response?.status === 422) {
                setErrors(err.response.data.errors ?? {});
            }
        } finally {
            setSubmitting(false);
        }
    };

    const field = (label: string, key: keyof OnboardForm, type = 'text', required = true) => (
        <div>
            <label className="block text-xs font-medium text-slate-600 mb-1">{label}{required && <span className="text-red-500 ml-0.5">*</span>}</label>
            <input
                type={type}
                value={form[key]}
                onChange={set(key)}
                data-testid={`onboard-${key}`}
                className={`w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 ${errors[key] ? 'border-red-300 bg-red-50' : 'border-slate-300'}`}
            />
            {errors[key] && <p className="text-xs text-red-500 mt-0.5">{errors[key]}</p>}
        </div>
    );

    return (
        <form onSubmit={submit} className="max-w-2xl space-y-6">
            {success && (
                <div className="bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3 text-sm text-emerald-700">
                    {success}
                </div>
            )}

            {/* Step 1: Tenant */}
            <div className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm space-y-4">
                <h3 className="text-sm font-semibold text-slate-700">Step 1 — Tenant Details</h3>
                {field('Organization Name', 'tenant_name')}
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className="block text-xs font-medium text-slate-600 mb-1">Transport Mode<span className="text-red-500 ml-0.5">*</span></label>
                        <select value={form.transport_mode} onChange={set('transport_mode')} data-testid="onboard-transport_mode"
                            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                            <option value="direct">Direct</option>
                            <option value="broker">Broker</option>
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600 mb-1">Auto-Logout (minutes)<span className="text-red-500 ml-0.5">*</span></label>
                        <input type="number" min={5} max={120} value={form.auto_logout_minutes} onChange={set('auto_logout_minutes')} data-testid="onboard-auto_logout_minutes"
                            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400" />
                    </div>
                </div>
            </div>

            {/* Step 2: First Site */}
            <div className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm space-y-4">
                <h3 className="text-sm font-semibold text-slate-700">Step 2 — First Site</h3>
                {field('Site Name', 'site_name')}
                <div className="grid grid-cols-2 gap-4">
                    {field('City', 'site_city', 'text', false)}
                    {field('State (2-letter)', 'site_state', 'text', false)}
                </div>
            </div>

            {/* Step 3: Admin User */}
            <div className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm space-y-4">
                <h3 className="text-sm font-semibold text-slate-700">Step 3 — Admin User</h3>
                <div className="grid grid-cols-2 gap-4">
                    {field('First Name', 'admin_first_name')}
                    {field('Last Name', 'admin_last_name')}
                </div>
                {field('Email Address', 'admin_email', 'email')}
                <div>
                    <label className="block text-xs font-medium text-slate-600 mb-1">Department<span className="text-red-500 ml-0.5">*</span></label>
                    <select value={form.admin_department} onChange={set('admin_department')} data-testid="onboard-admin_department"
                        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
                        <option value="it_admin">IT Admin</option>
                        <option value="enrollment">Enrollment</option>
                    </select>
                </div>
            </div>

            <button
                type="submit"
                disabled={submitting}
                data-testid="onboard-submit"
                className="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg disabled:opacity-50 transition-colors"
            >
                {submitting ? 'Creating tenant…' : 'Create Tenant'}
            </button>
        </form>
    );
}

// ── Main Page ──────────────────────────────────────────────────────────────────
export default function SuperAdminIndex() {
    const { summary } = usePage<PageProps & { summary: Summary }>().props;
    const [tab, setTab] = useState<'tenants' | 'health' | 'onboard'>('tenants');

    const breadcrumbs = [
        { label: 'Home', href: '/' },
        { label: 'Super Admin Panel' },
    ];

    return (
        <AppShell breadcrumbs={breadcrumbs}>
            <Head title="Super Admin Panel" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-800">Nostos Super Admin Panel</h1>
                        <p className="text-sm text-slate-500 mt-1">Platform-level tenant management — Nostos staff only</p>
                    </div>
                </div>

                {/* Summary KPIs */}
                <div className="grid grid-cols-3 gap-4">
                    <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm text-center">
                        <p className="text-xs text-slate-400 uppercase tracking-wide font-medium">Tenants</p>
                        <p className="text-3xl font-bold text-slate-800 mt-1">{summary.tenant_count}</p>
                    </div>
                    <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm text-center">
                        <p className="text-xs text-slate-400 uppercase tracking-wide font-medium">Users</p>
                        <p className="text-3xl font-bold text-slate-800 mt-1">{summary.user_count}</p>
                    </div>
                    <div className="bg-white rounded-xl border border-slate-200 p-4 shadow-sm text-center">
                        <p className="text-xs text-slate-400 uppercase tracking-wide font-medium">Participants</p>
                        <p className="text-3xl font-bold text-slate-800 mt-1">{summary.participant_count}</p>
                    </div>
                </div>

                {/* Tab bar */}
                <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                    <div className="flex border-b border-slate-200">
                        {(['tenants', 'health', 'onboard'] as const).map(t => (
                            <button
                                key={t}
                                onClick={() => setTab(t)}
                                data-testid={`tab-${t}`}
                                className={`px-5 py-3 text-sm font-medium capitalize transition-colors ${tab === t ? 'border-b-2 border-blue-500 text-blue-600' : 'text-slate-500 hover:text-slate-700'}`}
                            >
                                {t === 'onboard' ? 'Onboard Tenant' : t.charAt(0).toUpperCase() + t.slice(1)}
                            </button>
                        ))}
                    </div>

                    <div className="p-5">
                        {tab === 'tenants' && <TenantsTab />}
                        {tab === 'health'  && <HealthTab />}
                        {tab === 'onboard' && <OnboardTab />}
                    </div>
                </div>
            </div>
        </AppShell>
    );
}
