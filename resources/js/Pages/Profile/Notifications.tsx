// ─── Profile/Notifications ────────────────────────────────────────────────────
// Notification preference settings for the authenticated user.
//
// Each alert type has four delivery modes:
//   In-App Only      — shown in the notification bell only (default)
//   Email Immediate  — also send an email immediately (no PHI in email)
//   Email Digest     — batched email every 2 hours
//   Off              — no delivery at all
// ──────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

type PrefValue = 'in_app_only' | 'email_immediate' | 'email_digest' | 'off';

interface Props extends PageProps {
    preferences: Record<string, PrefValue>;
    pref_keys:   string[];
    valid_values: PrefValue[];
}

const PREF_LABELS: Record<string, string> = {
    alert_critical: 'Critical Alerts',
    alert_warning:  'Warning Alerts',
    alert_info:     'Informational Alerts',
    sdr_overdue:    'SDR Overdue Notifications',
    new_message:    'Chat Messages',
};

const VALUE_LABELS: Record<PrefValue, string> = {
    in_app_only:     'In-App Only',
    email_immediate: 'Email Immediate',
    email_digest:    'Email Digest (every 2h)',
    off:             'Off',
};

const VALUE_DESCRIPTIONS: Record<PrefValue, string> = {
    in_app_only:     'Shows in the notification bell only. No email.',
    email_immediate: 'Sends an email as soon as the notification is generated. No PHI included.',
    email_digest:    'Batched into a single digest email every 2 hours. No PHI included.',
    off:             'No notification delivered.',
};

/** @component Profile/Notifications — Per-user notification preference settings */
export default function ProfileNotifications() {
    const { preferences, pref_keys } = usePage<Props>().props;

    const [prefs, setPrefs] = useState<Record<string, PrefValue>>(preferences);
    const [saving, setSaving] = useState(false);
    const [saved,  setSaved]  = useState(false);

    const handleChange = (key: string, value: PrefValue) => {
        setPrefs(prev => ({ ...prev, [key]: value }));
        setSaved(false);
    };

    const handleSave = async () => {
        setSaving(true);
        try {
            await axios.put('/profile/notifications', { preferences: prefs });
            setSaved(true);
            setTimeout(() => setSaved(false), 3000);
        } catch {
            // ignore
        } finally {
            setSaving(false);
        }
    };

    return (
        <AppShell>
            <div className="max-w-2xl mx-auto px-6 py-8">
                <div className="mb-6">
                    <h1 className="text-xl font-semibold text-slate-900 dark:text-slate-100">Notification Preferences</h1>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Control how you receive notifications. Emails contain no patient or clinical
                        information in compliance with HIPAA.
                    </p>
                </div>

                <div className="space-y-4">
                    {pref_keys.map(key => (
                        <div
                            key={key}
                            className="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5"
                        >
                            <h3 className="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-3">
                                {PREF_LABELS[key] ?? key}
                            </h3>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {(['in_app_only', 'email_immediate', 'email_digest', 'off'] as PrefValue[]).map(val => {
                                    const isSelected = prefs[key] === val;
                                    return (
                                        <button
                                            key={val}
                                            onClick={() => handleChange(key, val)}
                                            data-testid={`pref-${key}-${val}`}
                                            title={VALUE_DESCRIPTIONS[val]}
                                            className={`rounded-lg border px-3 py-2.5 text-left transition-colors ${
                                                isSelected
                                                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300'
                                                    : 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 text-slate-600 dark:text-slate-400 hover:border-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700'
                                            }`}
                                        >
                                            <p className="text-xs font-medium">{VALUE_LABELS[val]}</p>
                                            <p className="text-[10px] mt-0.5 opacity-70 leading-snug">
                                                {VALUE_DESCRIPTIONS[val]}
                                            </p>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </div>

                {/* Save button */}
                <div className="mt-6 flex items-center gap-3">
                    <button
                        onClick={handleSave}
                        disabled={saving}
                        data-testid="save-prefs-button"
                        className="rounded-lg bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
                    >
                        {saving ? 'Saving…' : 'Save Preferences'}
                    </button>
                    {saved && (
                        <span className="text-sm text-green-600 dark:text-green-400 font-medium">
                            ✓ Saved
                        </span>
                    )}
                </div>
            </div>
        </AppShell>
    );
}
