// ─── TransportationDashboard ───────────────────────────────────────────────────
// Transportation department dashboard — rendered from Dashboard/Index.tsx when
// department === 'transportation'.
//
// ⚠️  Nostos Transport Integration Pending
// Live dispatch data (manifest, add-ons, flag alerts, config) requires the
// PACE-specific Nostos transport deployment. All four widgets render a
// ComingSoonBanner until the integration is connected.
// ─────────────────────────────────────────────────────────────────────────────

import React from 'react';
import ComingSoonBanner from '@/Components/ComingSoonBanner';

// ── Widget shell ────────────────────────────────────────────────────────────────

function WidgetCard({ title, children }: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div className="card p-5 flex flex-col">
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold text-slate-700">{title}</h3>
            </div>
            {children}
        </div>
    );
}

// ── Main component ─────────────────────────────────────────────────────────────

interface Props { departmentLabel: string; role: string }

export default function TransportationDashboard({ departmentLabel, role }: Props) {
    return (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">

            {/* Manifest Summary */}
            <WidgetCard title="Today's Manifest">
                <ComingSoonBanner
                    title="Live Manifest Data"
                    section="Nostos Integration Required"
                />
            </WidgetCard>

            {/* Add-On Queue */}
            <WidgetCard title="Add-On Queue">
                <ComingSoonBanner
                    title="Add-On Queue"
                    section="Nostos Integration Required"
                />
            </WidgetCard>

            {/* Participant Flag Alerts */}
            <WidgetCard title="New Mobility Flags Today">
                <ComingSoonBanner
                    title="Flag Alerts"
                    section="Nostos Integration Required"
                />
            </WidgetCard>

            {/* Transport Mode / Config */}
            <WidgetCard title="Transport Configuration">
                <ComingSoonBanner
                    title="Transport Configuration"
                    section="Nostos Integration Required"
                />
            </WidgetCard>

        </div>
    );
}
