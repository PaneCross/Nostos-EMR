// ─── Finance Dashboard ────────────────────────────────────────────────────────
// Powers the finance department's billing and capitation monitoring view.
//
// Layout:
//   - 4 KPI cards (pre-loaded): Capitation This Month, Auths Expiring 30d,
//     Encounters This Month, Active Participants
//   - Tabs: Capitation Summary | Expiring Authorizations | Encounter Log
//   - CSV export buttons (capitation / encounters / authorizations)
//
// Data loading strategy:
//   - KPIs, recentCapitation, expiringAuths: pre-loaded server-side (Inertia props)
//   - EncounterLog: lazy-loaded via JSON endpoint on first tab click
//   - CSV export: direct download link with ?type= param
//
// Props: kpis, recentCapitation, expiringAuths, currentMonthYear, serviceTypeLabels
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ──────────────────────────────────────────────────────────────────────

interface FinanceKpis {
    capitation_this_month: number;
    auths_expiring_30d:    number;
    encounters_this_month: number;
    active_participants:   number;
}

interface CapitationSummaryRow {
    month_year:        string;
    total:             number;
    participant_count: number;
}

interface ExpiringAuth {
    id:               number;
    service_type:     string;
    authorized_start: string;
    authorized_end:   string;
    authorized_units: number | null;
    status:           string;
    participant: {
        id:         number;
        mrn:        string;
        first_name: string;
        last_name:  string;
    } | null;
}

interface EncounterRow {
    id:             number;
    service_date:   string;
    service_type:   string;
    procedure_code: string | null;
    notes:          string | null;
    participant: {
        id:         number;
        mrn:        string;
        first_name: string;
        last_name:  string;
    } | null;
    provider: {
        id:         number;
        first_name: string;
        last_name:  string;
    } | null;
}

interface FinanceDashboardProps extends PageProps {
    kpis:               FinanceKpis;
    recentCapitation:   CapitationSummaryRow[];
    expiringAuths:      ExpiringAuth[];
    currentMonthYear:   string;
    serviceTypeLabels:  Record<string, string>;
}

type DashTab = 'capitation' | 'authorizations' | 'encounters';

// ── KPI Card component ─────────────────────────────────────────────────────────

interface KpiCardProps {
    label:    string;
    value:    string | number;
    sublabel: string;
    color:    'green' | 'amber' | 'blue' | 'slate';
    alert?:   boolean;
}

function KpiCard({ label, value, sublabel, color, alert }: KpiCardProps) {
    const borderColor = {
        green: 'border-green-500',
        amber: 'border-amber-500',
        blue:  'border-blue-500',
        slate: 'border-slate-400',
    }[color];

    const valueColor = {
        green: 'text-green-700',
        amber: 'text-amber-700',
        blue:  'text-blue-700',
        slate: 'text-slate-700',
    }[color];

    return (
        <div className={`bg-white rounded-lg border-l-4 ${borderColor} shadow-sm p-4 flex flex-col gap-1`}
             data-testid="kpi-card">
            <div className="flex items-center justify-between">
                <span className="text-xs font-semibold text-slate-500 uppercase tracking-wide">{label}</span>
                {alert && (
                    <span className="text-xs bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full font-medium">
                        Action Needed
                    </span>
                )}
            </div>
            <div className={`text-3xl font-bold ${valueColor}`}>{value}</div>
            <div className="text-xs text-slate-500">{sublabel}</div>
        </div>
    );
}

// ── Format helpers ─────────────────────────────────────────────────────────────

function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(amount);
}

function formatMonthYear(monthYear: string): string {
    const [year, month] = monthYear.split('-');
    return new Date(parseInt(year), parseInt(month) - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

function daysUntil(dateStr: string): number {
    const end = new Date(dateStr);
    const now = new Date();
    return Math.ceil((end.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
}

// ── Capitation Summary Tab ─────────────────────────────────────────────────────

function CapitationTab({ rows }: { rows: CapitationSummaryRow[] }) {
    if (rows.length === 0) {
        return <p className="text-slate-500 text-sm py-8 text-center">No capitation records found.</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-sm" data-testid="capitation-table">
                <thead className="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Month</th>
                        <th className="text-right px-4 py-2 font-semibold text-slate-600">Participants</th>
                        <th className="text-right px-4 py-2 font-semibold text-slate-600">Total Capitation</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={row.month_year} className={i % 2 === 0 ? 'bg-white' : 'bg-slate-50'}>
                            <td className="px-4 py-2 font-medium text-slate-800">{formatMonthYear(row.month_year)}</td>
                            <td className="px-4 py-2 text-right text-slate-600">{row.participant_count}</td>
                            <td className="px-4 py-2 text-right font-semibold text-green-700">
                                {formatCurrency(row.total)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ── Expiring Authorizations Tab ────────────────────────────────────────────────

function AuthorizationsTab({ auths, serviceTypeLabels }: { auths: ExpiringAuth[]; serviceTypeLabels: Record<string, string> }) {
    if (auths.length === 0) {
        return (
            <p className="text-slate-500 text-sm py-8 text-center">
                No authorizations expiring within 30 days.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-sm" data-testid="authorizations-table">
                <thead className="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Participant</th>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Service Type</th>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Units</th>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Expires</th>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Days Left</th>
                    </tr>
                </thead>
                <tbody>
                    {auths.map((auth, i) => {
                        const days = daysUntil(auth.authorized_end);
                        const urgency = days <= 7 ? 'text-red-700 font-bold' : days <= 14 ? 'text-amber-700 font-semibold' : 'text-slate-600';

                        return (
                            <tr key={auth.id} className={i % 2 === 0 ? 'bg-white' : 'bg-slate-50'}>
                                <td className="px-4 py-2">
                                    {auth.participant ? (
                                        <div>
                                            <div className="font-medium text-slate-800">
                                                {auth.participant.first_name} {auth.participant.last_name}
                                            </div>
                                            <div className="text-xs text-slate-500">{auth.participant.mrn}</div>
                                        </div>
                                    ) : <span className="text-slate-400">—</span>}
                                </td>
                                <td className="px-4 py-2 text-slate-700">
                                    {serviceTypeLabels[auth.service_type] ?? auth.service_type}
                                </td>
                                <td className="px-4 py-2 text-slate-600">
                                    {auth.authorized_units ?? '—'}
                                </td>
                                <td className="px-4 py-2 text-slate-700">
                                    {new Date(auth.authorized_end).toLocaleDateString()}
                                </td>
                                <td className={`px-4 py-2 ${urgency}`}>{days}d</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

// ── Encounter Log Tab (lazy-loaded) ───────────────────────────────────────────

function EncounterLogTab({ serviceTypeLabels }: { serviceTypeLabels: Record<string, string> }) {
    const [encounters, setEncounters] = useState<EncounterRow[]>([]);
    const [loaded, setLoaded]         = useState(false);
    const [loading, setLoading]       = useState(false);

    // Lazy-load on first mount of this tab
    const load = useCallback(() => {
        if (loaded || loading) return;
        setLoading(true);
        axios.get('/finance/encounters?per_page=100').then(res => {
            setEncounters(res.data.data ?? []);
            setLoaded(true);
        }).finally(() => setLoading(false));
    }, [loaded, loading]);

    // useEffect fires once when this tab is rendered for the first time
    React.useEffect(() => { load(); }, []);

    if (loading) {
        return <p className="text-slate-500 text-sm py-8 text-center">Loading encounters…</p>;
    }

    if (loaded && encounters.length === 0) {
        return <p className="text-slate-500 text-sm py-8 text-center">No encounters logged yet.</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-sm" data-testid="encounters-table">
                <thead className="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Date</th>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Participant</th>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Service</th>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Procedure</th>
                        <th className="text-left px-4 py-2 font-semibold text-slate-600">Provider</th>
                    </tr>
                </thead>
                <tbody>
                    {encounters.map((enc, i) => (
                        <tr key={enc.id} className={i % 2 === 0 ? 'bg-white' : 'bg-slate-50'}>
                            <td className="px-4 py-2 text-slate-700 whitespace-nowrap">
                                {new Date(enc.service_date).toLocaleDateString()}
                            </td>
                            <td className="px-4 py-2">
                                {enc.participant ? (
                                    <div>
                                        <div className="font-medium text-slate-800">
                                            {enc.participant.first_name} {enc.participant.last_name}
                                        </div>
                                        <div className="text-xs text-slate-500">{enc.participant.mrn}</div>
                                    </div>
                                ) : <span className="text-slate-400">—</span>}
                            </td>
                            <td className="px-4 py-2 text-slate-700">
                                {serviceTypeLabels[enc.service_type] ?? enc.service_type}
                            </td>
                            <td className="px-4 py-2 text-slate-500 text-xs">{enc.procedure_code ?? '—'}</td>
                            <td className="px-4 py-2 text-slate-600 text-sm">
                                {enc.provider ? `${enc.provider.first_name} ${enc.provider.last_name}` : '—'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ── Main Dashboard ─────────────────────────────────────────────────────────────

export default function FinanceDashboard({
    kpis,
    recentCapitation,
    expiringAuths,
    currentMonthYear,
    serviceTypeLabels,
}: FinanceDashboardProps) {
    const [activeTab, setActiveTab] = useState<DashTab>('capitation');

    function handleExport(type: 'capitation' | 'encounters' | 'authorizations') {
        // Trigger CSV download via direct link
        window.location.href = `/finance/reports/export?type=${type}`;
    }

    return (
        <AppShell>
            <Head title="Finance Dashboard" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

                {/* ── Header ── */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900">Finance Dashboard</h1>
                        <p className="text-sm text-slate-500 mt-1">
                            Capitation & billing overview — {formatMonthYear(currentMonthYear)}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={() => handleExport('capitation')}
                            className="text-xs bg-white border border-slate-300 text-slate-700 px-3 py-2 rounded hover:bg-slate-50"
                            data-testid="export-capitation"
                        >
                            Export Capitation
                        </button>
                        <button
                            onClick={() => handleExport('encounters')}
                            className="text-xs bg-white border border-slate-300 text-slate-700 px-3 py-2 rounded hover:bg-slate-50"
                            data-testid="export-encounters"
                        >
                            Export Encounters
                        </button>
                        <button
                            onClick={() => handleExport('authorizations')}
                            className="text-xs bg-white border border-slate-300 text-slate-700 px-3 py-2 rounded hover:bg-slate-50"
                            data-testid="export-authorizations"
                        >
                            Export Authorizations
                        </button>
                    </div>
                </div>

                {/* ── KPI Cards ── */}
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-4" data-testid="kpi-cards">
                    <KpiCard
                        label="Capitation This Month"
                        value={formatCurrency(kpis.capitation_this_month)}
                        sublabel={`${formatMonthYear(currentMonthYear)} total CMS payment`}
                        color="green"
                    />
                    <KpiCard
                        label="Auths Expiring (30d)"
                        value={kpis.auths_expiring_30d}
                        sublabel="authorizations need renewal"
                        color={kpis.auths_expiring_30d > 0 ? 'amber' : 'slate'}
                        alert={kpis.auths_expiring_30d > 0}
                    />
                    <KpiCard
                        label="Encounters This Month"
                        value={kpis.encounters_this_month}
                        sublabel="billable encounters logged"
                        color="blue"
                    />
                    <KpiCard
                        label="Active Participants"
                        value={kpis.active_participants}
                        sublabel="currently enrolled in PACE"
                        color="slate"
                    />
                </div>

                {/* ── Tab bar ── */}
                <div className="bg-white rounded-lg shadow-sm border border-slate-200">
                    <div className="border-b border-slate-200">
                        <nav className="flex gap-0 px-4" aria-label="Finance tabs">
                            {([
                                ['capitation',     'Capitation Summary'],
                                ['authorizations', 'Expiring Authorizations'],
                                ['encounters',     'Encounter Log'],
                            ] as [DashTab, string][]).map(([key, label]) => (
                                <button
                                    key={key}
                                    onClick={() => setActiveTab(key)}
                                    data-testid={`tab-${key}`}
                                    className={[
                                        'px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-colors',
                                        activeTab === key
                                            ? 'border-blue-500 text-blue-600'
                                            : 'border-transparent text-slate-500 hover:text-slate-700',
                                    ].join(' ')}
                                >
                                    {label}
                                    {key === 'authorizations' && kpis.auths_expiring_30d > 0 && (
                                        <span className="ml-1.5 bg-amber-100 text-amber-800 text-xs px-1.5 py-0.5 rounded-full">
                                            {kpis.auths_expiring_30d}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </nav>
                    </div>

                    <div className="p-4">
                        {activeTab === 'capitation'     && <CapitationTab rows={recentCapitation} />}
                        {activeTab === 'authorizations' && <AuthorizationsTab auths={expiringAuths} serviceTypeLabels={serviceTypeLabels} />}
                        {activeTab === 'encounters'     && <EncounterLogTab serviceTypeLabels={serviceTypeLabels} />}
                    </div>
                </div>

            </div>
        </AppShell>
    );
}
