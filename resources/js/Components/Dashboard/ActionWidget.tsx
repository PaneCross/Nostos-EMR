// ─── ActionWidget ──────────────────────────────────────────────────────────────
// Reusable dashboard widget for W3-3 Dashboard Overhaul.
// Every widget answers three questions:
//   1. WHAT am I looking at? (title + description)
//   2. WHY is this surfaced to me? (description explains the filter logic)
//   3. WHAT should I do? (each item links directly to the specific record)
//
// Props:
//   title        — Widget header (e.g. 'Unsigned Notes')
//   description  — One-line explanation of WHAT + WHY, shown in italic below title
//   items        — Clickable rows: label, href, optional badge + sublabel
//   emptyMessage — Friendly text shown when items is empty
//   viewAllHref  — Optional 'View all' link in widget footer
//   maxItems     — Max rows to show before 'and N more...' (default: 5)
//   loading      — Shows skeleton pulse while data is fetching
// ─────────────────────────────────────────────────────────────────────────────

import React from 'react'
import { Link } from '@inertiajs/react'
import { ArrowRightIcon } from '@heroicons/react/24/outline'

// ── Types ──────────────────────────────────────────────────────────────────────

export interface ActionItem {
  label:      string          // Primary row text (e.g. 'Mildred Testpatient — SOAP Note')
  href:       string          // Direct link to the specific item
  badge?:     string          // Optional badge text (e.g. '3d overdue', 'Critical')
  badgeColor?: string         // Tailwind classes for the badge chip
  sublabel?:  string          // Secondary text shown below label (e.g. 'MRN 00042 | 09:30')
}

interface Props {
  title:        string
  description?: string
  items:        ActionItem[]
  emptyMessage: string
  viewAllHref?: string
  maxItems?:    number
  loading?:     boolean
}

// ── Sub-components ─────────────────────────────────────────────────────────────

function Skeleton() {
  return (
    <div className="space-y-2.5 animate-pulse py-1">
      {[1, 2, 3].map(i => (
        <div key={i} className="flex items-center gap-2">
          <div className="h-4 bg-slate-100 dark:bg-slate-700 rounded flex-1" />
          <div className="h-4 w-14 bg-slate-100 dark:bg-slate-700 rounded" />
        </div>
      ))}
    </div>
  )
}

// ── Main component ─────────────────────────────────────────────────────────────

export default function ActionWidget({
  title,
  description,
  items,
  emptyMessage,
  viewAllHref,
  maxItems = 5,
  loading = false,
}: Props) {
  const visible  = items.slice(0, maxItems)
  const overflow = items.length - maxItems

  return (
    <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 shadow-sm flex flex-col">

      {/* Header */}
      <div className="px-4 py-3 border-b border-gray-200 dark:border-slate-700">
        <div className="flex items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
            {title}
          </h3>
          {!loading && items.length > 0 && (
            <span className="text-xs font-medium text-gray-400 dark:text-slate-500 tabular-nums shrink-0">
              {items.length}
            </span>
          )}
        </div>
        {description && (
          <p className="text-xs italic text-gray-400 dark:text-slate-500 mt-0.5 leading-relaxed">
            {description}
          </p>
        )}
      </div>

      {/* Body */}
      <div className="flex-1 px-4 py-2">
        {loading ? (
          <Skeleton />
        ) : items.length === 0 ? (
          <p className="text-xs text-gray-400 dark:text-slate-500 py-4 text-center">
            {emptyMessage}
          </p>
        ) : (
          <ul className="divide-y divide-gray-50 dark:divide-slate-700/60">
            {visible.map((item, idx) => (
              <li key={idx}>
                <Link
                  href={item.href}
                  className="flex items-center justify-between gap-3 py-2.5 group hover:bg-gray-50 dark:hover:bg-slate-700/50 -mx-4 px-4 transition-colors"
                >
                  <div className="min-w-0 flex-1">
                    <p className="text-xs font-medium text-gray-800 dark:text-slate-200 truncate group-hover:text-blue-600 dark:group-hover:text-blue-400">
                      {item.label}
                    </p>
                    {item.sublabel && (
                      <p className="text-[10px] text-gray-400 dark:text-slate-500 mt-0.5 truncate">
                        {item.sublabel}
                      </p>
                    )}
                  </div>
                  {item.badge && (
                    <span className={`inline-flex shrink-0 items-center px-1.5 py-0.5 rounded text-[10px] font-semibold ${item.badgeColor ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'}`}>
                      {item.badge}
                    </span>
                  )}
                </Link>
              </li>
            ))}
            {overflow > 0 && (
              <li className="py-2 text-[10px] text-gray-400 dark:text-slate-500 text-center">
                and {overflow} more...
              </li>
            )}
          </ul>
        )}
      </div>

      {/* Footer — View All link */}
      {viewAllHref && (
        <div className="px-4 py-2.5 border-t border-gray-100 dark:border-slate-700/60">
          <Link
            href={viewAllHref}
            className="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 hover:underline"
          >
            View all
            <ArrowRightIcon className="w-3 h-3" />
          </Link>
        </div>
      )}

    </div>
  )
}
