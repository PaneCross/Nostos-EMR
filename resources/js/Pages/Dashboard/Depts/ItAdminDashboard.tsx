// ─── ItAdminDashboard ─────────────────────────────────────────────────────────
// Real-data dashboard for the IT Administration department.
// Rendered from Dashboard/Index.tsx when department === 'it_admin'.
// Fetches 4 widget endpoints in parallel on mount:
//   GET /dashboards/it-admin/users        — recently provisioned + deactivated users
//   GET /dashboards/it-admin/integrations — integration health per connector
//   GET /dashboards/it-admin/audit        — last 20 audit log entries
//   GET /dashboards/it-admin/config       — tenant config: transport mode, sites
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';

// ── Types ──────────────────────────────────────────────────────────────────────

interface UserItem {
    id: number;
    name: string;
    email: string;
    department: string;
    role?: string;
    is_active: boolean;
    created_at?: string;
    updated_at?: string;
}

interface ConnectorHealth {
    connector_type: string;
    last_status: string | null;
    last_message_at: string | null;
    error_count: number;
    is_healthy: boolean;
    is_stale: boolean;
    total_today: number;
}

interface AuditEntry {
    id: number;
    action: string;
    user: { id: number; name: string; department: string } | null;
    resource_type: string | null;
    resource_id: number | null;
    ip_address: string | null;
    created_at: string;
}

interface SiteItem {
    id: number;
    name: string;
    mrn_prefix: string;
}

interface ConfigData {
    transport_mode: 'direct' | 'broker';
    auto_logout_minutes: number;
    sites: SiteItem[];
    site_count: number;
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

const CONNECTOR_LABELS: Record<string, string> = {
    hl7_adt:        'HL7 ADT',
    lab_results:    'Lab Results',
    pharmacy_ncpdp: 'Pharmacy NCPDP',
    other:          'Other',
};

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function ItAdminDashboard({ departmentLabel, role }: Props) {
    const [loading, setLoading]       = useState(true);
    const [users, setUsers]           = useState<{
        recently_provisioned: UserItem[];
        recently_deactivated: UserItem[];
        total_active: number;
        total_inactive: number;
    } | null>(null);
    const [integrations, setIntegrations] = useState<{ connectors: ConnectorHealth[] } | null>(null);
    const [audit, setAudit]           = useState<{ entries: AuditEntry[] } | null>(null);
    const [config, setConfig]         = useState<ConfigData | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/it-admin/users'),
            axios.get('/dashboards/it-admin/integrations'),
            axios.get('/dashboards/it-admin/audit'),
            axios.get('/dashboards/it-admin/config'),
        ]).then(([usr, integ, aud, cfg]) => {
            setUsers(usr.data);
            setIntegrations(integ.data);
            setAudit(aud.data);
            setConfig(cfg.data);
        }).finally(() => setLoading(false));
    }, []);

    const unhealthyCount = integrations?.connectors.filter(c => !c.is_healthy).length ?? 0;

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* User Management */}
            <WidgetCard
                title="User Management"
                badge={users ? { label: `${users.total_active} active`, color: 'bg-blue-100 text-blue-700' } : undefined}
            >
                {loading ? <Skeleton /> : !users ? <Empty message="No user data" /> : (
                    <div className="space-y-3">
                        <div>
                            <div className="flex items-center justify-between mb-1">
                                <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">
                                    Recently Provisioned
                                </p>
                                <a href="/it-admin/users" className="text-[10px] text-blue-600 hover:underline">+ Provision User</a>
                            </div>
                            {!users.recently_provisioned.length
                                ? <p className="text-xs text-slate-400">None in last 30 days</p>
                                : users.recently_provisioned.map(u => (
                                    <div key={u.id} className="flex items-center justify-between py-1 border-b border-slate-50 last:border-0">
                                        <span className="text-xs font-medium text-slate-800">{u.name}</span>
                                        <span className="text-[10px] text-slate-500 capitalize">{u.department?.replace('_', ' ')}</span>
                                    </div>
                                ))}
                        </div>
                        {users.recently_deactivated.length > 0 && (
                            <div>
                                <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide mb-1">
                                    Recently Deactivated
                                </p>
                                {users.recently_deactivated.map(u => (
                                    <div key={u.id} className="flex items-center justify-between py-1 border-b border-slate-50 last:border-0">
                                        <span className="text-xs text-slate-600 line-through">{u.name}</span>
                                        <span className="text-[10px] text-slate-400">{u.updated_at}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                        <p className="text-[10px] text-slate-400">{users.total_inactive} inactive accounts</p>
                    </div>
                )}
            </WidgetCard>

            {/* Integration Health */}
            <WidgetCard
                title="Integration Health"
                badge={unhealthyCount > 0 ? { label: `${unhealthyCount} issues`, color: 'bg-red-100 text-red-700' }
                    : integrations ? { label: 'All healthy', color: 'bg-green-100 text-green-700' }
                    : undefined}
            >
                {loading ? <Skeleton /> : !integrations ? <Empty message="No integration data" /> : (
                    <div className="space-y-2">
                        {integrations.connectors.map(c => (
                            <div key={c.connector_type}
                                 className={`flex items-center justify-between p-2 rounded-lg border ${
                                     c.is_healthy ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'
                                 }`}>
                                <div className="flex items-center gap-2">
                                    <span className={`w-2 h-2 rounded-full ${c.is_healthy ? 'bg-green-500' : 'bg-red-500'}`} />
                                    <span className="text-xs font-medium text-slate-800">
                                        {CONNECTOR_LABELS[c.connector_type] ?? c.connector_type}
                                    </span>
                                </div>
                                <div className="text-right">
                                    <p className="text-[10px] text-slate-500">
                                        {c.last_message_at ?? 'Never'} · {c.total_today} today
                                    </p>
                                    {c.error_count > 0 && (
                                        <p className="text-[10px] text-red-600 font-semibold">{c.error_count} errors</p>
                                    )}
                                </div>
                            </div>
                        ))}
                        <a href="/it-admin/integrations" className="text-xs text-blue-600 hover:underline">
                            View integration logs →
                        </a>
                    </div>
                )}
            </WidgetCard>

            {/* Recent Audit Activity */}
            <WidgetCard title="Recent Audit Activity">
                {loading ? <Skeleton /> : !audit?.entries.length ? <Empty message="No recent audit entries" /> : (
                    <div className="space-y-1 overflow-auto max-h-52">
                        {audit.entries.map(e => (
                            <div key={e.id} className="flex items-start gap-2 py-1 border-b border-slate-50 last:border-0">
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-mono text-slate-700 truncate">{e.action}</p>
                                    <p className="text-[10px] text-slate-400">
                                        {e.user?.name ?? 'System'} · {e.resource_type ?? '—'} · {e.created_at}
                                    </p>
                                </div>
                            </div>
                        ))}
                        <a href="/it-admin/audit" className="text-xs text-blue-600 hover:underline block pt-1">
                            View full audit log →
                        </a>
                    </div>
                )}
            </WidgetCard>

            {/* Tenant Configuration */}
            <WidgetCard title="Tenant Configuration">
                {loading ? <Skeleton /> : !config ? <Empty message="No configuration data" /> : (
                    <div className="space-y-2">
                        <div className="flex items-center justify-between p-2.5 rounded-lg bg-slate-50 border border-slate-200">
                            <span className="text-xs font-medium text-slate-600">Transport Mode</span>
                            <span className={`text-xs font-bold px-2 py-0.5 rounded-full uppercase ${
                                config.transport_mode === 'broker'
                                    ? 'bg-purple-100 text-purple-700'
                                    : 'bg-green-100 text-green-700'
                            }`}>
                                {config.transport_mode}
                            </span>
                        </div>
                        <div className="flex items-center justify-between p-2.5 rounded-lg bg-slate-50 border border-slate-200">
                            <span className="text-xs font-medium text-slate-600">Auto-Logout Timeout</span>
                            <span className="text-xs text-slate-700">{config.auto_logout_minutes} minutes</span>
                        </div>
                        <div className="p-2.5 rounded-lg bg-slate-50 border border-slate-200">
                            <p className="text-xs font-medium text-slate-600 mb-1.5">
                                Sites ({config.site_count})
                            </p>
                            <div className="space-y-1">
                                {config.sites.map(s => (
                                    <div key={s.id} className="flex items-center justify-between">
                                        <span className="text-xs text-slate-700">{s.name}</span>
                                        <span className="text-[10px] font-mono text-slate-400">{s.mrn_prefix}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                        <a href="/it-admin/users" className="text-xs text-blue-600 hover:underline block text-center">
                            Edit in IT Admin →
                        </a>
                    </div>
                )}
            </WidgetCard>

        </div>
    );
}
