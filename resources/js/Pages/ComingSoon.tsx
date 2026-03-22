// ─── ComingSoon Page ───────────────────────────────────────────────────────────
// Placeholder page rendered for nav links that haven't been built yet.
// Delegates to ComingSoonBanner or PlannedFeatureBanner based on the mode prop:
//
//   mode = 'transport' → ComingSoonBanner (amber, Nostos transport integration)
//   mode = 'planned'   → PlannedFeatureBanner (indigo, future roadmap item)
//
// Props injected by ComingSoonController:
//   module_label — Feature name (e.g. "Clinical Orders")
//   phase        — Planned phase number for the roadmap badge label
//   mode         — 'transport' | 'planned' (default 'planned')
//   description  — Optional one-sentence description for PlannedFeatureBanner
// ──────────────────────────────────────────────────────────────────────────────

import { Head } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import ComingSoonBanner from '@/Components/ComingSoonBanner'
import PlannedFeatureBanner from '@/Components/PlannedFeatureBanner'

interface Props {
    module_label: string
    phase?:       number
    mode?:        'transport' | 'planned'
    description?: string
}

export default function ComingSoon({ module_label, phase, mode = 'planned', description }: Props) {
    return (
        <AppShell>
            <Head title={`${module_label} — Coming Soon`} />

            {mode === 'transport' ? (
                <ComingSoonBanner
                    title={module_label}
                />
            ) : (
                <PlannedFeatureBanner
                    title={module_label}
                    description={description}
                    phase={phase !== undefined ? `Phase ${phase}` : undefined}
                />
            )}
        </AppShell>
    )
}
