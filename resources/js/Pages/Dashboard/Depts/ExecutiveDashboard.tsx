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
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

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

    // Build ActionItems for Site Comparison
    const siteCompItems: ActionItem[] = siteComp.map(row => ({
        label: row.site_name,
        href: `/finance/dashboard`,
        badge: `${row.enrolled} enrolled`,
        badgeColor: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
        sublabel: `${row.active_care_plans} open care plans`,
    }));

    // Build ActionItems for Financial Overview (by site)
    const financialItems: ActionItem[] = (financial?.by_site ?? []).map(row => ({
        label: row.site_name,
        href: `/finance/dashboard`,
        badge: `$${row.total_capitation.toLocaleString()}`,
        badgeColor: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
        sublabel: `${row.participant_count} members`,
    }));

    return (
        <div className="space-y-6">
            {/* Page header */}
            <div>
                <h1 className="text-2xl font-bold text-slate-800 dark:text-slate-200">{departmentLabel} Dashboard</h1>
                <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">Cross-site organizational overview for the current period</p>
            </div>

            {/* KPI row — kept as-is (KPI counts, not list items) */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {[
                    { label: 'Enrolled Participants', value: overview?.enrolled ?? '-',         color: 'bg-blue-50 dark:bg-blue-950/60 border-blue-200 dark:border-blue-800'    },
                    { label: 'Pending Enrollment',    value: overview?.pending_enrollment ?? '-', color: 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800' },
                    { label: 'New Referrals (30d)',   value: overview?.new_referrals_30d ?? '-', color: 'bg-emerald-50 dark:bg-emerald-950/60 border-emerald-200 dark:border-emerald-800' },
                    { label: 'Active Sites',          value: overview?.active_sites ?? '-',      color: 'bg-slate-50 dark:bg-slate-900 border-slate-200 dark:border-slate-700'  },
                ].map(kpi => (
                    <div key={kpi.label} className={`rounded-xl border p-4 ${kpi.color}`}>
                        <p className="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">{kpi.label}</p>
                        <p className="text-2xl font-bold text-slate-800 dark:text-slate-200 mt-1">
                            {loading ? <span className="inline-block w-12 h-6 bg-slate-200 dark:bg-slate-700 rounded animate-pulse" /> : String(kpi.value)}
                        </p>
                    </div>
                ))}
            </div>

            {/* Two-column row: site comparison + financial overview */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <ActionWidget
                    title="Site Comparison"
                    description="Enrollment, incident, and documentation metrics across all sites in your organization."
                    items={siteCompItems}
                    emptyMessage="No active sites"
                    viewAllHref="/finance/dashboard"
                    loading={loading}
                />

                <ActionWidget
                    title={`Capitation: ${financial?.month_year ?? '...'}`}
                    description="Capitation and encounter summary across sites. Click to view full finance dashboard."
                    items={financialItems}
                    emptyMessage="No capitation data for this month"
                    viewAllHref="/finance/dashboard"
                    loading={loading}
                />

            </div>

            {/* Site cards — kept as-is (visual grid, not a list of clickable action items) */}
            <div className="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-4">Sites</h3>
                {loading ? (
                    <div className="space-y-2">
                        {[1, 2, 3].map(i => (
                            <div key={i} className="h-4 bg-slate-100 dark:bg-slate-800 rounded animate-pulse" />
                        ))}
                    </div>
                ) : siteCards.length === 0 ? (
                    <p className="text-xs text-slate-400 text-center py-4">No sites found</p>
                ) : (
                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                        {siteCards.map(site => (
                            <div
                                key={site.id}
                                data-testid={`site-card-${site.id}`}
                                className={`rounded-lg border p-3 ${site.is_active ? 'border-blue-300 bg-blue-50 dark:bg-blue-950/60' : 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900'}`}
                            >
                                <div className="flex items-start justify-between gap-1">
                                    <p className="text-xs font-semibold text-slate-800 dark:text-slate-200 leading-tight">{site.name}</p>
                                    {site.is_active && (
                                        <span className="shrink-0 px-1 py-0.5 rounded text-[9px] font-medium bg-blue-100 dark:bg-blue-900/60 text-blue-600 dark:text-blue-400">Active</span>
                                    )}
                                </div>
                                {(site.city || site.state) && (
                                    <p className="text-[10px] text-slate-400 mt-0.5">{[site.city, site.state].filter(Boolean).join(', ')}</p>
                                )}
                                <p className="text-lg font-bold text-slate-700 dark:text-slate-300 mt-1">{site.enrolled}</p>
                                <p className="text-[10px] text-slate-400">enrolled</p>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
