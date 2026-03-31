// ─── FinanceDashboard (dept landing) ──────────────────────────────────────────
// Real-data dashboard for the Finance department landing page.
// Rendered from Dashboard/Index.tsx when department === 'finance'.
// Distinct from Finance/Dashboard.tsx (the full finance page at /finance/dashboard
// with capitation tables, authorization grids, and CSV export).
// Fetches 4 quick-glance widget endpoints in parallel on mount:
//   GET /dashboards/finance/capitation         — current month total vs prior month
//   GET /dashboards/finance/authorizations     — expiring authorizations (30 days)
//   GET /dashboards/finance/enrollment-changes — enrolled/disenrolled this month
//   GET /dashboards/finance/encounters         — encounter log count + service breakdown
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

// ── Types ──────────────────────────────────────────────────────────────────────

interface CapitationData {
    current_month: string;
    current_total: number;
    current_participant_count: number;
    prior_month: string;
    prior_total: number;
    change_percent: number | null;
}

interface AuthItem {
    id: number;
    participant: { id: number; name: string; mrn?: string } | null;
    service_type: string;
    service_label: string;
    authorized_end: string | null;
    days_until_expiry: number;
    href?: string;
}

interface EnrollmentChanges {
    enrolled_this_month: number;
    disenrolled_this_month: number;
    total_enrolled: number;
    net_change: number;
}

interface EncounterData {
    total_encounters: number;
    this_month_encounters: number;
    by_service_type: Record<string, number>;
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function FinanceDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]                     = useState(true);
    const [capitation, setCapitation]               = useState<CapitationData | null>(null);
    const [authorizations, setAuthorizations]       = useState<{
        authorizations: AuthItem[]; expiring_count: number; expiring_this_week: number;
    } | null>(null);
    const [enrollmentChanges, setEnrollmentChanges] = useState<EnrollmentChanges | null>(null);
    const [encounters, setEncounters]               = useState<EncounterData | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/finance/capitation'),
            axios.get('/dashboards/finance/authorizations'),
            axios.get('/dashboards/finance/enrollment-changes'),
            axios.get('/dashboards/finance/encounters'),
        ]).then(([cap, auth, enroll, enc]) => {
            setCapitation(cap.data);
            setAuthorizations(auth.data);
            setEnrollmentChanges(enroll.data);
            setEncounters(enc.data);
        }).finally(() => setLoading(false));
    }, []);

    const formatCurrency = (n: number) =>
        new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(n);

    // Capitation KPI summary — kept as-is (not a list of clickable items)
    const renderCapitation = () => {
        if (loading) {
            return (
                <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5">
                    <div className="space-y-2 animate-pulse">
                        {[1, 2, 3].map(i => <div key={i} className="h-8 bg-slate-100 dark:bg-slate-800 rounded" />)}
                    </div>
                </div>
            );
        }
        return (
            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5">
                <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Current Month Capitation</h3>
                {!capitation ? (
                    <p className="text-xs text-gray-400 dark:text-slate-500 py-4 text-center">No capitation data</p>
                ) : (
                    <div className="space-y-3">
                        <div className="flex items-end justify-between">
                            <div>
                                <p className="text-2xl font-bold text-slate-900 dark:text-slate-100">
                                    {formatCurrency(capitation.current_total)}
                                </p>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    {capitation.current_participant_count} participants | {capitation.current_month}
                                </p>
                            </div>
                            {capitation.change_percent !== null && (
                                <div className={`text-right ${capitation.change_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>
                                    <p className="text-sm font-bold">
                                        {capitation.change_percent >= 0 ? '+' : ''}{capitation.change_percent}%
                                    </p>
                                    <p className="text-[10px] text-slate-400">vs {capitation.prior_month}</p>
                                </div>
                            )}
                        </div>
                        <div className="text-[10px] text-slate-400 pt-1 border-t border-slate-100 dark:border-slate-700">
                            Prior month: {formatCurrency(capitation.prior_total)}
                        </div>
                        <a href="/finance/dashboard" className="text-xs text-blue-600 dark:text-blue-400 hover:underline">
                            View full Finance Dashboard
                        </a>
                    </div>
                )}
            </div>
        );
    };

    // Enrollment Changes — kept as KPI stat grid (not clickable list items)
    const renderEnrollmentChanges = () => {
        if (loading) {
            return (
                <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5">
                    <div className="space-y-2 animate-pulse">
                        {[1, 2, 3].map(i => <div key={i} className="h-8 bg-slate-100 dark:bg-slate-800 rounded" />)}
                    </div>
                </div>
            );
        }
        return (
            <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm p-5">
                <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-3">Enrollment Changes This Month</h3>
                {!enrollmentChanges ? (
                    <p className="text-xs text-gray-400 dark:text-slate-500 py-4 text-center">No data</p>
                ) : (
                    <div className="space-y-3">
                        <div className="grid grid-cols-3 gap-3">
                            <div className="text-center p-3 rounded-lg bg-green-50 dark:bg-green-950/60 border border-green-200 dark:border-green-800">
                                <p className="text-xl font-bold text-green-700 dark:text-green-300">{enrollmentChanges.enrolled_this_month}</p>
                                <p className="text-[10px] text-green-600 dark:text-green-400 font-medium">Enrolled</p>
                            </div>
                            <div className="text-center p-3 rounded-lg bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800">
                                <p className="text-xl font-bold text-red-700 dark:text-red-300">{enrollmentChanges.disenrolled_this_month}</p>
                                <p className="text-[10px] text-red-600 dark:text-red-400 font-medium">Disenrolled</p>
                            </div>
                            <div className="text-center p-3 rounded-lg bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800">
                                <p className="text-xl font-bold text-blue-700 dark:text-blue-300">{enrollmentChanges.total_enrolled}</p>
                                <p className="text-[10px] text-blue-600 dark:text-blue-400 font-medium">Total</p>
                            </div>
                        </div>
                        <p className="text-xs text-slate-500 dark:text-slate-400 text-center">
                            Net change: <span className={enrollmentChanges.net_change >= 0 ? 'text-green-600 dark:text-green-400 font-semibold' : 'text-red-600 dark:text-red-400 font-semibold'}>
                                {enrollmentChanges.net_change >= 0 ? '+' : ''}{enrollmentChanges.net_change}
                            </span>
                        </p>
                    </div>
                )}
            </div>
        );
    };

    // Build ActionItems for Authorizations Expiring
    const authItems: ActionItem[] = (authorizations?.authorizations ?? []).map(a => ({
        label: `${a.participant?.name ?? '-'} : ${a.service_label}`,
        href: a.href ?? `/finance/capitation`,
        badge: `${a.days_until_expiry}d`,
        badgeColor: a.days_until_expiry <= 7
            ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300'
            : 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
        sublabel: a.authorized_end ?? undefined,
    }));

    // Build ActionItems for Encounters (by service type summary rows)
    const encounterItems: ActionItem[] = encounters
        ? Object.entries(encounters.by_service_type).map(([type, count]) => ({
            label: type.replace(/_/g, ' '),
            href: `/finance/encounters`,
            badge: String(count),
            badgeColor: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
          }))
        : [];

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Capitation KPI — not a list, keep as stat widget */}
            {renderCapitation()}

            <ActionWidget
                title="Authorizations Expiring Soon"
                description="Service authorizations expiring within 30 days. Renew before expiry to avoid service gaps."
                items={authItems}
                emptyMessage="No authorizations expiring soon"
                viewAllHref="/finance/encounters"
                loading={loading}
            />

            {/* Enrollment Changes KPI — not a list, keep as stat widget */}
            {renderEnrollmentChanges()}

            <ActionWidget
                title="Encounter Log"
                description="Encounter records pending 837P batch submission. These should be submitted to CMS within 180 days."
                items={encounterItems}
                emptyMessage="No encounter data"
                viewAllHref="/finance/encounters"
                loading={loading}
            />

        </div>
    );
}
