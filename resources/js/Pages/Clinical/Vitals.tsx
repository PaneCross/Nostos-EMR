// ─── Clinical Vitals Dashboard ────────────────────────────────────────────────
// Shows the most recent vital signs for every participant, with color coding
// for out-of-range values.  Click any row to jump to the participant's Vitals tab.
// Route: GET /clinical/vitals → Inertia::render('Clinical/Vitals')
// ─────────────────────────────────────────────────────────────────────────────

import React from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ────────────────────────────────────────────────────────────────────

interface ParticipantSummary {
    id: number;
    mrn: string;
    first_name: string;
    last_name: string;
}

interface VitalRow {
    id: number;
    recorded_at: string | null;
    bp_systolic: number | null;
    bp_diastolic: number | null;
    pulse: number | null;
    o2_saturation: number | null;
    weight_lbs: number | null;
    temperature_f: number | null;
    pain_score: number | null;
    blood_glucose: number | null;
    participant: ParticipantSummary | null;
}

interface VitalsPageProps extends PageProps {
    vitals: VitalRow[];
    participantsWithFreshVitals: number[];
}

// ── Out-of-range logic ────────────────────────────────────────────────────────

type Severity = 'normal' | 'warning' | 'critical';

function bpSystolicSeverity(v: number | null): Severity {
    if (v === null) return 'normal';
    if (v < 80 || v > 180) return 'critical';
    if (v < 90 || v > 140) return 'warning';
    return 'normal';
}

function bpDiastolicSeverity(v: number | null): Severity {
    if (v === null) return 'normal';
    if (v > 120) return 'critical';
    if (v > 90 || v < 50) return 'warning';
    return 'normal';
}

function pulseSeverity(v: number | null): Severity {
    if (v === null) return 'normal';
    if (v < 40 || v > 150) return 'critical';
    if (v < 55 || v > 105) return 'warning';
    return 'normal';
}

function o2Severity(v: number | null): Severity {
    if (v === null) return 'normal';
    if (v < 90) return 'critical';
    if (v < 95) return 'warning';
    return 'normal';
}

function tempSeverity(v: number | null): Severity {
    if (v === null) return 'normal';
    if (v < 95 || v > 103) return 'critical';
    if (v < 97 || v >= 100.4) return 'warning';
    return 'normal';
}

function painSeverity(v: number | null): Severity {
    if (v === null) return 'normal';
    if (v >= 7) return 'critical';
    if (v >= 4) return 'warning';
    return 'normal';
}

function glucoseSeverity(v: number | null): Severity {
    if (v === null) return 'normal';
    if (v < 60 || v > 300) return 'critical';
    if (v < 70 || v > 200) return 'warning';
    return 'normal';
}

function severityClass(s: Severity): string {
    if (s === 'critical') return 'text-red-700 dark:text-red-300 font-semibold bg-red-50 dark:bg-red-950/60 rounded px-1';
    if (s === 'warning')  return 'text-amber-700 dark:text-amber-300 font-semibold bg-amber-50 dark:bg-amber-950/60 rounded px-1';
    return 'text-slate-700 dark:text-slate-300';
}

function Cell({ value, severity, suffix = '' }: { value: number | null; severity: Severity; suffix?: string }) {
    if (value === null) return <span className="text-slate-300">-</span>;
    return (
        <span className={severityClass(severity)}>
            {value}{suffix}
        </span>
    );
}

function timeAgo(iso: string | null): string {
    if (!iso) return '-';
    const diff = Date.now() - new Date(iso).getTime();
    const hrs  = Math.floor(diff / 3_600_000);
    if (hrs < 1)  return 'just now';
    if (hrs < 24) return `${hrs}h ago`;
    const days = Math.floor(hrs / 24);
    return `${days}d ago`;
}

function isDue(iso: string | null): boolean {
    if (!iso) return true;
    const diff = Date.now() - new Date(iso).getTime();
    return diff > 7 * 24 * 3_600_000; // > 7 days
}

function rowHasCritical(v: VitalRow): boolean {
    return (
        bpSystolicSeverity(v.bp_systolic)   === 'critical' ||
        bpDiastolicSeverity(v.bp_diastolic) === 'critical' ||
        pulseSeverity(v.pulse)              === 'critical' ||
        o2Severity(v.o2_saturation)         === 'critical' ||
        tempSeverity(v.temperature_f)       === 'critical' ||
        painSeverity(v.pain_score)          === 'critical' ||
        glucoseSeverity(v.blood_glucose)    === 'critical'
    );
}

function rowHasWarning(v: VitalRow): boolean {
    return (
        bpSystolicSeverity(v.bp_systolic)   === 'warning' ||
        bpDiastolicSeverity(v.bp_diastolic) === 'warning' ||
        pulseSeverity(v.pulse)              === 'warning' ||
        o2Severity(v.o2_saturation)         === 'warning' ||
        tempSeverity(v.temperature_f)       === 'warning' ||
        painSeverity(v.pain_score)          === 'warning' ||
        glucoseSeverity(v.blood_glucose)    === 'warning'
    );
}

// ── Page ─────────────────────────────────────────────────────────────────────

export default function ClinicalVitals() {
    const { vitals, participantsWithFreshVitals } = usePage<VitalsPageProps>().props;

    const criticals = vitals.filter(rowHasCritical);
    const warnings  = vitals.filter(v => !rowHasCritical(v) && rowHasWarning(v));
    const due       = vitals.filter(v => isDue(v.recorded_at));

    // Sort: critical first, then warning, then due, then normal
    const sorted = [...vitals].sort((a, b) => {
        const score = (v: VitalRow) =>
            rowHasCritical(v) ? 0 : rowHasWarning(v) ? 1 : isDue(v.recorded_at) ? 2 : 3;
        return score(a) - score(b);
    });

    return (
        <AppShell breadcrumbs={[{ label: 'Clinical' }, { label: 'Vitals Dashboard' }]}>
            <Head title="Vitals Dashboard" />

            {/* Header */}
            <div className="flex items-center justify-between mb-5">
                <div>
                    <h1 className="text-xl font-bold text-slate-900 dark:text-slate-100">Vitals Dashboard</h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                        Most recent vitals per participant · Click a row to record or review vitals
                    </p>
                </div>
            </div>

            {/* Stat chips */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
                {[
                    { label: 'Critical Values',  count: criticals.length,  color: 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300' },
                    { label: 'Out of Range',      count: warnings.length,   color: 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300' },
                    { label: 'Due / Overdue',     count: due.length,        color: 'bg-blue-50 dark:bg-blue-950/60 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300' },
                    { label: 'Participants Total',count: vitals.length,     color: 'bg-slate-50 dark:bg-slate-900 border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300' },
                ].map(chip => (
                    <div key={chip.label} className={`border rounded-xl px-4 py-3 ${chip.color}`}>
                        <p className="text-2xl font-bold">{chip.count}</p>
                        <p className="text-xs font-medium mt-0.5">{chip.label}</p>
                    </div>
                ))}
            </div>

            {/* Critical banner */}
            {criticals.length > 0 && (
                <div className="mb-5 flex items-start gap-3 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded-xl px-4 py-3">
                    <svg className="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <div>
                        <p className="text-sm font-semibold text-red-800 dark:text-red-300">
                            {criticals.length} participant{criticals.length !== 1 ? 's' : ''} with critical vital values
                        </p>
                        <p className="text-xs text-red-700 dark:text-red-300 mt-0.5">
                            {criticals.map(v => `${v.participant?.first_name} ${v.participant?.last_name}`).join(', ')}
                        </p>
                    </div>
                </div>
            )}

            {/* Table */}
            <div className="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-x-auto">
                {sorted.length === 0 ? (
                    <div className="px-6 py-12 text-center text-sm text-slate-500 dark:text-slate-400">
                        No vitals recorded yet. Go to a participant profile to record vitals.
                    </div>
                ) : (
                    <table className="min-w-full divide-y divide-slate-100 dark:divide-slate-700 text-sm">
                        <thead className="bg-slate-50 dark:bg-slate-900">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Participant</th>
                                <th className="px-4 py-3 text-left text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Last Recorded</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">BP (sys/dia)</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Pulse</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">O₂ Sat</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Temp °F</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Weight</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Pain</th>
                                <th className="px-4 py-3 text-center text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Glucose</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                            {sorted.map(v => {
                                const critical = rowHasCritical(v);
                                const warning  = !critical && rowHasWarning(v);
                                const due_     = isDue(v.recorded_at);

                                return (
                                    <tr
                                        key={v.id}
                                        onClick={() => router.visit(`/participants/${v.participant?.id}?tab=vitals`)}
                                        className={`cursor-pointer transition-colors hover:bg-slate-50 dark:hover:bg-slate-700 border-l-4 ${
                                            critical ? 'border-l-red-500 bg-red-50/40 dark:bg-red-900/20'
                                            : warning  ? 'border-l-amber-400 bg-amber-50/40 dark:bg-amber-900/15'
                                            : due_     ? 'border-l-blue-300'
                                            : 'border-l-transparent'
                                        }`}
                                    >
                                        <td className="px-4 py-3">
                                            <div>
                                                <p className="font-medium text-slate-800 dark:text-slate-200">
                                                    {v.participant?.first_name} {v.participant?.last_name}
                                                </p>
                                                <p className="text-xs text-slate-400">{v.participant?.mrn}</p>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <p className="text-slate-700 dark:text-slate-300">{timeAgo(v.recorded_at)}</p>
                                            {due_ && (
                                                <p className="text-[10px] text-blue-600 dark:text-blue-400 font-medium">Vitals due</p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            {(v.bp_systolic !== null || v.bp_diastolic !== null) ? (
                                                <span>
                                                    <Cell value={v.bp_systolic}  severity={bpSystolicSeverity(v.bp_systolic)} />
                                                    <span className="text-slate-300 mx-0.5">/</span>
                                                    <Cell value={v.bp_diastolic} severity={bpDiastolicSeverity(v.bp_diastolic)} />
                                                </span>
                                            ) : <span className="text-slate-300">-</span>}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Cell value={v.pulse} severity={pulseSeverity(v.pulse)} suffix=" bpm" />
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Cell value={v.o2_saturation} severity={o2Severity(v.o2_saturation)} suffix="%" />
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Cell value={v.temperature_f} severity={tempSeverity(v.temperature_f)} />
                                        </td>
                                        <td className="px-4 py-3 text-center text-slate-700 dark:text-slate-300">
                                            {v.weight_lbs !== null ? `${v.weight_lbs} lbs` : <span className="text-slate-300">-</span>}
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Cell value={v.pain_score} severity={painSeverity(v.pain_score)} suffix="/10" />
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <Cell value={v.blood_glucose} severity={glucoseSeverity(v.blood_glucose)} suffix=" mg/dL" />
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </div>

            <p className="mt-3 text-xs text-slate-400">
                Values shown are for reference only. Clinical decisions must not be based solely on this dashboard. Out-of-range thresholds: BP &gt;140/90 or &lt;90/60 (warning); O₂ &lt;95% (warning), &lt;90% (critical); pain ≥4 (warning), ≥7 (critical).
            </p>
        </AppShell>
    );
}
