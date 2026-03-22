// ─── ExecutiveDashboard ────────────────────────────────────────────────────────
// Real-data dashboard for the Executive department.
// Rendered from Dashboard/Index.tsx when department === 'executive'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/executive/org-overview      — enrolled participants, pending, referrals, sites
//   GET /dashboards/executive/site-comparison   — per-site enrolled count + open care plans
//   GET /dashboards/executive/financial-overview — current month capitation totals by site
//   GET /dashboards/executive/sites-list        — site switcher cards with enrolled counts
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────────

interface OrgOverview {
    enrolled: number;
    pending_enrollment: number;
    new_referrals_30d: number;
    active_sites: number;
}

interface SiteRow {
    site_id: number;
    site_name: string;
    enrolled: number;
    active_care_plans: number;
}

interface FinanceSiteRow {
    site_id: number;
    site_name: string;
    participant_count: number;
    total_capitation: number;
}

interface FinancialOverview {
    month_year: string;
    grand_total: number;
    by_site: FinanceSiteRow[];
}

interface SiteCard {
    id: number;
    name: string;
    city: string | null;
    state: string | null;
    enrolled: number;
    is_active: boolean;
}

// ── Widget card shell ──────────────────────────────────────────────────────────
function WidgetCard({ title, loading, children }: { title: string; loading: boolean; children: React.ReactNode }) {
    return (
        <div className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm">
            <h3 className="text-sm font-semibold text-slate-700 mb-4">{title}</h3>
            {loading ? (
                <div className="space-y-2">
                    {[1, 2, 3].map(i => (
                        <div key={i} className="h-4 bg-slate-100 rounded animate-pulse" />
                    ))}
                </div>
            ) : children}
        </div>
    );
}

// ── Main component ─────────────────────────────────────────────────────────────
export default function ExecutiveDashboard({ departmentLabel }: { departmentLabel: string; role: string }) {
    const [loading, setLoading]               = useState(true);
    const [overview, setOverview]             = useState<OrgOverview | null>(null);
    const [siteComp, setSiteComp]             = useState<SiteRow[]>([]);
    const [financial, setFinancial]           = useState<FinancialOverview | null>(null);
    const [siteCards, setSiteCards]           = useState<SiteCard[]>([]);

    // Fetch all 4 widget endpoints in parallel
    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/executive/org-overview'),
            axios.get('/dashboards/executive/site-comparison'),
            axios.get('/dashboards/executive/financial-overview'),
            axios.get('/dashboards/executive/sites-list'),
        ]).then(([ovRes, scRes, finRes, slRes]) => {
            setOverview(ovRes.data);
            setSiteComp(scRes.data.sites ?? []);
            setFinancial(finRes.data);
            setSiteCards(slRes.data.sites ?? []);
        }).catch(() => {
            // Silently degrade — widgets show empty state
        }).finally(() => setLoading(false));
    }, []);

    return (
        <div className="space-y-6">
            {/* Page header */}
            <div>
                <h1 className="text-2xl font-bold text-slate-800">{departmentLabel} Dashboard</h1>
                <p className="text-sm text-slate-500 mt-1">Cross-site organizational overview for the current period</p>
            </div>

            {/* KPI row */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {[
                    { label: 'Enrolled Participants', value: overview?.enrolled ?? '—',         color: 'bg-blue-50 border-blue-200'    },
                    { label: 'Pending Enrollment',    value: overview?.pending_enrollment ?? '—', color: 'bg-amber-50 border-amber-200' },
                    { label: 'New Referrals (30d)',   value: overview?.new_referrals_30d ?? '—', color: 'bg-emerald-50 border-emerald-200' },
                    { label: 'Active Sites',          value: overview?.active_sites ?? '—',      color: 'bg-slate-50 border-slate-200'  },
                ].map(kpi => (
                    <div key={kpi.label} className={`rounded-xl border p-4 ${kpi.color}`}>
                        <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">{kpi.label}</p>
                        <p className="text-2xl font-bold text-slate-800 mt-1">
                            {loading ? <span className="inline-block w-12 h-6 bg-slate-200 rounded animate-pulse" /> : String(kpi.value)}
                        </p>
                    </div>
                ))}
            </div>

            {/* Two-column row: site comparison + financial overview */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

                {/* Site Comparison */}
                <WidgetCard title="Site Comparison" loading={loading}>
                    {siteComp.length === 0 ? (
                        <p className="text-xs text-slate-400 text-center py-4">No active sites</p>
                    ) : (
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="text-slate-400 uppercase tracking-wide border-b border-slate-100">
                                    <th className="text-left pb-2 font-medium">Site</th>
                                    <th className="text-right pb-2 font-medium">Enrolled</th>
                                    <th className="text-right pb-2 font-medium">Open Plans</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {siteComp.map(row => (
                                    <tr key={row.site_id}>
                                        <td className="py-1.5 text-slate-700 font-medium">{row.site_name}</td>
                                        <td className="py-1.5 text-right text-slate-600">{row.enrolled}</td>
                                        <td className="py-1.5 text-right text-slate-600">{row.active_care_plans}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </WidgetCard>

                {/* Financial Overview */}
                <WidgetCard title={`Capitation — ${financial?.month_year ?? '…'}`} loading={loading}>
                    {!financial || financial.by_site.length === 0 ? (
                        <p className="text-xs text-slate-400 text-center py-4">No capitation data for this month</p>
                    ) : (
                        <>
                            <div className="mb-3 px-3 py-2 bg-emerald-50 rounded-lg border border-emerald-200">
                                <p className="text-xs text-emerald-600 font-medium">Grand Total</p>
                                <p className="text-lg font-bold text-emerald-700">${financial.grand_total.toLocaleString()}</p>
                            </div>
                            <table className="w-full text-xs">
                                <thead>
                                    <tr className="text-slate-400 uppercase tracking-wide border-b border-slate-100">
                                        <th className="text-left pb-2 font-medium">Site</th>
                                        <th className="text-right pb-2 font-medium">Members</th>
                                        <th className="text-right pb-2 font-medium">Total</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-50">
                                    {financial.by_site.map(row => (
                                        <tr key={row.site_id}>
                                            <td className="py-1.5 text-slate-700 font-medium">{row.site_name}</td>
                                            <td className="py-1.5 text-right text-slate-600">{row.participant_count}</td>
                                            <td className="py-1.5 text-right text-slate-600">${row.total_capitation.toLocaleString()}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </>
                    )}
                </WidgetCard>
            </div>

            {/* Site cards */}
            <WidgetCard title="Sites" loading={loading}>
                {siteCards.length === 0 ? (
                    <p className="text-xs text-slate-400 text-center py-4">No sites found</p>
                ) : (
                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                        {siteCards.map(site => (
                            <div
                                key={site.id}
                                data-testid={`site-card-${site.id}`}
                                className={`rounded-lg border p-3 ${site.is_active ? 'border-blue-300 bg-blue-50' : 'border-slate-200 bg-slate-50'}`}
                            >
                                <div className="flex items-start justify-between gap-1">
                                    <p className="text-xs font-semibold text-slate-800 leading-tight">{site.name}</p>
                                    {site.is_active && (
                                        <span className="shrink-0 px-1 py-0.5 rounded text-[9px] font-medium bg-blue-100 text-blue-600">Active</span>
                                    )}
                                </div>
                                {(site.city || site.state) && (
                                    <p className="text-[10px] text-slate-400 mt-0.5">{[site.city, site.state].filter(Boolean).join(', ')}</p>
                                )}
                                <p className="text-lg font-bold text-slate-700 mt-1">{site.enrolled}</p>
                                <p className="text-[10px] text-slate-400">enrolled</p>
                            </div>
                        ))}
                    </div>
                )}
            </WidgetCard>
        </div>
    );
}
