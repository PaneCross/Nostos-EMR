// ─── ThemeToggle Component ─────────────────────────────────────────────────────
// Neumorphic pill toggle for switching between light and dark display modes.
// Used in the TopBar (AppShell.tsx) between the global search and notification bell.
//
// Props:
//   theme    — current theme value ('light' | 'dark'), controlled by AppShell state
//   onChange — callback that receives the NEW theme when the user toggles
//
// The parent (AppShell) is responsible for:
//   1. Applying the 'dark' class to document.documentElement
//   2. Persisting the choice to localStorage (FOUC backup)
//   3. POSTing to /user/theme to persist server-side across sessions
// ──────────────────────────────────────────────────────────────────────────────

import React from 'react';
import { SunIcon, MoonIcon } from '@heroicons/react/24/solid';

interface ThemeToggleProps {
    theme: 'light' | 'dark';
    onChange: (theme: 'light' | 'dark') => void;
}

export default function ThemeToggle({ theme, onChange }: ThemeToggleProps) {
    const isDark = theme === 'dark';

    return (
        <button
            type="button"
            role="switch"
            aria-checked={isDark}
            aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
            title={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
            onClick={() => onChange(isDark ? 'light' : 'dark')}
            data-testid="theme-toggle"
            className={[
                'relative inline-flex h-7 w-14 items-center rounded-full transition-all duration-300',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-1 dark:focus-visible:ring-offset-slate-800',
                isDark ? 'bg-slate-600' : 'bg-slate-200',
            ].join(' ')}
        >
            {/* Sliding knob with sun/moon icon */}
            <span
                className={[
                    'absolute flex h-5 w-5 items-center justify-center rounded-full shadow-md transition-all duration-300',
                    isDark ? 'translate-x-8 bg-slate-900 text-amber-400' : 'translate-x-1 bg-white text-amber-500',
                ].join(' ')}
            >
                {isDark
                    ? <MoonIcon className="h-3 w-3" />
                    : <SunIcon className="h-3 w-3" />
                }
            </span>
        </button>
    );
}
