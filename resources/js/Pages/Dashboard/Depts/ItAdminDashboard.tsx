// ─── ItAdminDashboard ─────────────────────────────────────────────────────────
// Real-data dashboard for the IT Administration department.
// Rendered from Dashboard/Index.tsx when department === 'it_admin'.
// Fetches 5 widget endpoints in parallel on mount:
//   GET /dashboards/it-admin/users        — recently provisioned + deactivated users
//   GET /dashboards/it-admin/integrations — integration health per connector
//   GET /dashboards/it-admin/audit        — last 20 audit log entries
//   GET /dashboards/it-admin/config       — tenant config: transport mode, sites
//   GET /dashboards/it-admin/break-glass  — BTG emergency access events (W5-1)
// ─────────────────────────────────────────────────────────────────────────────

import React, { useEffect, useState } from 'react';
import axios from 'axios';
import ActionWidget, { ActionItem } from '@/Components/Dashboard/ActionWidget';

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

// W5-1: Break-the-glass emergency access event (HIPAA 45 CFR §164.312(a)(2)(ii))
interface BreakGlassEventItem {
    id: number;
    user: { id: number; name: string; department: string } | null;
    participant: { id: number; name: string; mrn: string } | null;
    reason: string | null;
    is_acknowledged: boolean;
    accessed_at: string;
    access_expires_at: string | null;
    href: string;
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
    // W5-1: Break-the-glass emergency access events for HIPAA audit oversight
    const [breakGlass, setBreakGlass] = useState<{ events: BreakGlassEventItem[]; unreviewed_count: number; total_today: number } | null>(null);

    useEffect(() => {
        Promise.all([
            axios.get('/dashboards/it-admin/users'),
            axios.get('/dashboards/it-admin/integrations'),
            axios.get('/dashboards/it-admin/audit'),
            axios.get('/dashboards/it-admin/config'),
            axios.get('/dashboards/it-admin/break-glass'),
        ]).then(([usr, integ, aud, cfg, btg]) => {
            setUsers(usr.data);
            setIntegrations(integ.data);
            setAudit(aud.data);
            setConfig(cfg.data);
            setBreakGlass(btg.data);
        }).finally(() => setLoading(false));
    }, []);

    // Build ActionItems for Users — flatten provisioned + deactivated
    const userItems: ActionItem[] = users
        ? [
            ...users.recently_provisioned.map(u => ({
                label: u.name,
                href: `/it-admin/users`,
                badge: 'Provisioned',
                badgeColor: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' as string,
                sublabel: u.department.replace(/_/g, ' '),
            })),
            ...users.recently_deactivated.map(u => ({
                label: u.name,
                href: `/it-admin/users`,
                badge: 'Deactivated',
                badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300' as string,
                sublabel: u.updated_at ?? undefined,
            })),
          ]
        : [];

    // Build ActionItems for Integration Messages — unhealthy connectors first
    const integrationItems: ActionItem[] = (integrations?.connectors ?? []).map(c => ({
        label: CONNECTOR_LABELS[c.connector_type] ?? c.connector_type,
        href: `/it-admin/integrations`,
        badge: c.is_healthy ? 'Healthy' : `${c.error_count} errors`,
        badgeColor: c.is_healthy
            ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300'
            : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
        sublabel: `${c.last_message_at ?? 'Never'} | ${c.total_today} today`,
    }));

    // Build ActionItems for Audit Events
    const auditItems: ActionItem[] = (audit?.entries ?? []).map(e => ({
        label: e.action,
        href: `/it-admin/audit`,
        badge: e.resource_type ?? undefined,
        badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
        sublabel: `${e.user?.name ?? 'System'} | ${e.created_at}`,
    }));

    // W5-1: Map BTG events — unacknowledged events in red, acknowledged in amber
    const btgItems: ActionItem[] = (breakGlass?.events ?? []).map(e => ({
        label: `${e.user?.name ?? 'Unknown user'} accessed ${e.participant?.name ?? 'unknown participant'}`,
        href: e.href,
        badge: e.is_acknowledged ? 'Reviewed' : 'Unreviewed',
        badgeColor: e.is_acknowledged
            ? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300'
            : 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 font-bold',
        sublabel: [e.participant?.mrn, e.accessed_at].filter(Boolean).join(' | ') || undefined,
    }));

    // Build ActionItems for Config — sites + key settings
    const configItems: ActionItem[] = config
        ? [
            {
                label: `Transport Mode: ${config.transport_mode}`,
                href: `/it-admin/users`,
                badge: config.transport_mode,
                badgeColor: config.transport_mode === 'broker'
                    ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300'
                    : 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
            },
            {
                label: `Auto-Logout: ${config.auto_logout_minutes} min`,
                href: `/it-admin/users`,
                badge: `${config.auto_logout_minutes}m`,
                badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300',
            },
            ...config.sites.map(s => ({
                label: s.name,
                href: `/it-admin/users`,
                badge: s.mrn_prefix,
                badgeColor: 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300' as string,
                sublabel: 'Site',
            })),
          ]
        : [];

    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <ActionWidget
                title="User Management"
                description="Recently provisioned or recently deactivated users needing follow-up."
                items={userItems}
                emptyMessage="No recent user changes"
                viewAllHref="/it-admin/users"
                loading={loading}
            />

            <ActionWidget
                title="Integration Health"
                description="Failed or retryable integration messages (HL7 ADT, lab results). Click to review and retry."
                items={integrationItems}
                emptyMessage="No integration data"
                viewAllHref="/it-admin/integrations"
                loading={loading}
            />

            <ActionWidget
                title="Recent Audit Activity"
                description="Recent security and configuration events in the audit log."
                items={auditItems}
                emptyMessage="No recent audit entries"
                viewAllHref="/it-admin/audit"
                loading={loading}
            />

            <ActionWidget
                title="Tenant Configuration"
                description="Active site configurations and system parameters."
                items={configItems}
                emptyMessage="No configuration data"
                viewAllHref="/it-admin/users"
                loading={loading}
            />

            {/* W5-1: Break-the-Glass widget — HIPAA 45 CFR §164.312(a)(2)(ii) emergency access monitoring */}
            <ActionWidget
                title={`Break-the-Glass Access${breakGlass?.unreviewed_count ? ` (${breakGlass.unreviewed_count} Unreviewed)` : ''}`}
                description="Emergency access events bypassing normal RBAC. Unreviewed events require IT Admin acknowledgment for HIPAA compliance. Today's count shown in subtitle."
                items={btgItems}
                emptyMessage="No break-the-glass events"
                viewAllHref="/it-admin/break-glass"
                loading={loading}
            />

        </div>
    );
}
