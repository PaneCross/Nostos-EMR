// ─── PlannedFeatureBanner ─────────────────────────────────────────────────────
// Renders a "planned for future release" notice for features scoped beyond
// the current MVP phase but intentionally left in the nav for roadmap visibility.
//
// Distinct from ComingSoonBanner (which is transport-integration specific):
//   - No Nostos branding
//   - Neutral "Planned for Future Release" badge
//   - Accepts a brief description of what the feature will do
//
// Props:
//   title       — Feature name (e.g., "Claims Management")
//   description — One sentence on what this feature will provide
//   phase       — Optional roadmap label (e.g., "Phase 9" or "Wave 3")
// ─────────────────────────────────────────────────────────────────────────────

import React from 'react';
import { CalendarDaysIcon } from '@heroicons/react/24/outline';

interface PlannedFeatureBannerProps {
    /** Feature title displayed as the card heading */
    title: string;
    /** One-sentence description of the planned feature's purpose */
    description?: string;
    /** Optional phase/release label for the roadmap badge */
    phase?: string;
}

/**
 * Full-width centered card shown on nav items that link to planned-but-unbuilt
 * features. Communicates roadmap intent without looking like an error state.
 */
export default function PlannedFeatureBanner({ title, description, phase }: PlannedFeatureBannerProps) {
    return (
        <div className="flex items-start justify-center min-h-[320px] py-12 px-4">
            <div className="max-w-lg w-full bg-white border border-slate-200 rounded-2xl shadow-sm px-8 py-10 text-center">

                {/* Icon */}
                <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-indigo-50">
                    <CalendarDaysIcon className="h-7 w-7 text-indigo-400" aria-hidden="true" />
                </div>

                {/* Status badge */}
                <span className="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-600/20 mb-4">
                    <span className="h-1.5 w-1.5 rounded-full bg-indigo-400" />
                    Planned for Future Release{phase ? ` — ${phase}` : ''}
                </span>

                {/* Title */}
                <h2 className="text-lg font-semibold text-slate-900 mb-3">{title}</h2>

                {/* Description */}
                {description && (
                    <p className="text-sm text-slate-500 leading-relaxed">{description}</p>
                )}

                {/* Footer note */}
                <div className="mt-6 pt-5 border-t border-slate-100">
                    <p className="text-xs text-slate-400">
                        This feature is on the NostosEMR roadmap. Current functionality
                        is available through other modules in the navigation.
                    </p>
                </div>
            </div>
        </div>
    );
}
