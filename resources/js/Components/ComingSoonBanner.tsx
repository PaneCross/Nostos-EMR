// ─── ComingSoonBanner ─────────────────────────────────────────────────────────
// Renders a professional "implementation pending" notice for features that
// require the live Nostos Transportation platform integration.
//
// Used on all /transport/* pages and the Transportation department dashboard
// widgets while the PACE-specific Nostos transport deployment is pending.
//
// Props:
//   title    — Feature name shown as the card heading (e.g., "Transport Manifest")
//   message  — Body text. Defaults to the standard Nostos transport integration note.
//   section  — Optional subtitle/context label below the badge (e.g., "Transportation Module")
// ─────────────────────────────────────────────────────────────────────────────

import React from 'react';
import { TruckIcon } from '@heroicons/react/24/outline';

interface ComingSoonBannerProps {
    /** Feature title displayed as the card heading */
    title: string;
    /** Override the default integration-pending message */
    message?: string;
    /** Optional context label (e.g., the module or section name) */
    section?: string;
}

const DEFAULT_MESSAGE =
    'This feature is pending implementation as part of the Nostos Transportation ' +
    'integration. The transportation module will connect to a PACE-specific ' +
    'deployment of the Nostos transport platform. Contact your Nostos ' +
    'implementation team for timeline details.';

/**
 * Full-width centered card shown on transport-dependent pages while the
 * live Nostos integration is not yet connected.
 */
export default function ComingSoonBanner({ title, message, section }: ComingSoonBannerProps) {
    return (
        <div className="flex items-start justify-center min-h-[320px] py-12 px-4">
            <div className="max-w-lg w-full bg-white border border-slate-200 rounded-2xl shadow-sm px-8 py-10 text-center">

                {/* Icon */}
                <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-navy-50 bg-slate-100">
                    <TruckIcon className="h-7 w-7 text-slate-500" aria-hidden="true" />
                </div>

                {/* Status badge */}
                <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20 mb-4">
                    <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                    Implementation Pending: Nostos Transport
                </span>

                {/* Title */}
                <h2 className="text-lg font-semibold text-slate-900 mb-1">{title}</h2>

                {/* Optional section context */}
                {section && (
                    <p className="text-xs font-medium text-slate-400 uppercase tracking-wide mb-3">{section}</p>
                )}

                {/* Body */}
                <p className="text-sm text-slate-500 leading-relaxed">
                    {message ?? DEFAULT_MESSAGE}
                </p>

                {/* Divider + contact note */}
                <div className="mt-6 pt-5 border-t border-slate-100">
                    <p className="text-xs text-slate-400">
                        PACE staff can still submit transport requests via the
                        participant profile. Live dispatch sync and run-sheet
                        data require the Nostos transport integration.
                    </p>
                </div>
            </div>
        </div>
    );
}
