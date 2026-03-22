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

// ── Widget shell ────────────────────────────────────────────────────────────────

function WidgetCard({ title, badge, children }: {
    title: string;
    badge?: { label: string; color: string };
    children: React.ReactNode;
}) {
    return (
        <div className="card p-5 flex flex-col">
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold text-slate-700">{title}</h3>
                {badge && (
                    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${badge.color}`}>
                        {badge.label}
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}

function Skeleton() {
    return (
        <div className="space-y-2 animate-pulse">
            {[1, 2, 3].map(i => <div key={i} className="h-8 bg-slate-100 rounded" />)}
        </div>
    );
}

function Empty({ message }: { message: string }) {
    return <p className="text-xs text-slate-400 py-4 text-center">{message}</p>;
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

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Current Month Capitation */}
            <WidgetCard title="Current Month Capitation">
                {loading ? <Skeleton /> : !capitation ? <Empty message="No capitation data" /> : (
                    <div className="space-y-3">
                        <div className="flex items-end justify-between">
                            <div>
                                <p className="text-2xl font-bold text-slate-900">
                                    {formatCurrency(capitation.current_total)}
                                </p>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    {capitation.current_participant_count} participants · {capitation.current_month}
                                </p>
                            </div>
                            {capitation.change_percent !== null && (
                                <div className={`text-right ${capitation.change_percent >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    <p className="text-sm font-bold">
                                        {capitation.change_percent >= 0 ? '+' : ''}{capitation.change_percent}%
                                    </p>
                                    <p className="text-[10px] text-slate-400">vs {capitation.prior_month}</p>
                                </div>
                            )}
                        </div>
                        <div className="text-[10px] text-slate-400 pt-1 border-t border-slate-100">
                            Prior month: {formatCurrency(capitation.prior_total)}
                        </div>
                        <a href="/finance/dashboard" className="text-xs text-blue-600 hover:underline">
                            View full Finance Dashboard →
                        </a>
                    </div>
                )}
            </WidgetCard>

            {/* Authorizations Expiring */}
            <WidgetCard
                title="Authorizations Expiring Soon"
                badge={authorizations?.expiring_count ? {
                    label: `${authorizations.expiring_count} within 30 days`,
                    color: authorizations.expiring_this_week > 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700',
                } : undefined}
            >
                {loading ? <Skeleton /> : !authorizations?.authorizations.length ? <Empty message="No authorizations expiring soon" /> : (
                    <div className="overflow-auto">
                        <table className="w-full text-xs">
                            <thead>
                                <tr className="border-b border-slate-100">
                                    <th className="text-left py-1 font-medium text-slate-500">Participant</th>
                                    <th className="text-left py-1 font-medium text-slate-500">Service</th>
                                    <th className="text-right py-1 font-medium text-slate-500">Days</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {authorizations.authorizations.map(a => (
                                    <tr key={a.id} className={a.days_until_expiry <= 7 ? 'bg-red-50' : 'hover:bg-slate-50'}>
                                        <td className="py-1.5 font-medium text-slate-800">{a.participant?.name ?? '—'}</td>
                                        <td className="py-1.5 text-slate-600 text-[10px]">{a.service_label}</td>
                                        <td className={`py-1.5 text-right font-semibold ${a.days_until_expiry <= 7 ? 'text-red-600' : 'text-amber-600'}`}>
                                            {a.days_until_expiry}d
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </WidgetCard>

            {/* Enrollment Changes This Month */}
            <WidgetCard title="Enrollment Changes This Month">
                {loading ? <Skeleton /> : !enrollmentChanges ? <Empty message="No data" /> : (
                    <div className="space-y-3">
                        <div className="grid grid-cols-3 gap-3">
                            <div className="text-center p-3 rounded-lg bg-green-50 border border-green-200">
                                <p className="text-xl font-bold text-green-700">{enrollmentChanges.enrolled_this_month}</p>
                                <p className="text-[10px] text-green-600 font-medium">Enrolled</p>
                            </div>
                            <div className="text-center p-3 rounded-lg bg-red-50 border border-red-200">
                                <p className="text-xl font-bold text-red-700">{enrollmentChanges.disenrolled_this_month}</p>
                                <p className="text-[10px] text-red-600 font-medium">Disenrolled</p>
                            </div>
                            <div className="text-center p-3 rounded-lg bg-blue-50 border border-blue-200">
                                <p className="text-xl font-bold text-blue-700">{enrollmentChanges.total_enrolled}</p>
                                <p className="text-[10px] text-blue-600 font-medium">Total</p>
                            </div>
                        </div>
                        <p className="text-xs text-slate-500 text-center">
                            Net change: <span className={enrollmentChanges.net_change >= 0 ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'}>
                                {enrollmentChanges.net_change >= 0 ? '+' : ''}{enrollmentChanges.net_change}
                            </span>
                        </p>
                    </div>
                )}
            </WidgetCard>

            {/* Encounter Log */}
            <WidgetCard
                title="Encounter Log"
                badge={encounters ? { label: `${encounters.this_month_encounters} this month`, color: 'bg-blue-100 text-blue-700' } : undefined}
            >
                {loading ? <Skeleton /> : !encounters ? <Empty message="No encounter data" /> : (
                    <div className="space-y-3">
                        <div className="flex items-center justify-between p-3 rounded-lg bg-slate-50 border border-slate-200">
                            <span className="text-xs font-medium text-slate-600">Total Encounters</span>
                            <span className="text-lg font-bold text-slate-800">{encounters.total_encounters.toLocaleString()}</span>
                        </div>
                        {Object.keys(encounters.by_service_type).length > 0 && (
                            <div className="space-y-1">
                                <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">This Month by Type</p>
                                {Object.entries(encounters.by_service_type).map(([type, count]) => (
                                    <div key={type} className="flex items-center justify-between py-0.5">
                                        <span className="text-xs text-slate-600 capitalize">{type.replace('_', ' ')}</span>
                                        <span className="text-xs font-medium text-slate-700">{count}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                        <a href="/finance/dashboard" className="text-xs text-blue-600 hover:underline block text-center">
                            Export Encounters →
                        </a>
                    </div>
                )}
            </WidgetCard>

        </div>
    );
}
