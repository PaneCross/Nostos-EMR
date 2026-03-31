// ─── Finance/HosMSurvey ───────────────────────────────────────────────────────
// HOS-M (Health Outcomes Survey for Medicare) Annual Survey Administration.
//
// Layout:
//   - Completion stats: X of Y participants surveyed (progress bar for current year)
//   - Table: participant, survey_year, administered_by, administered_at,
//     completed badge, submitted_to_cms badge, Submit to CMS action
//   - Add Survey modal: participant search, 5 response fields
//     (physical_health 1–5, mental_health 1–5, pain 1–5,
//      falls_past_year 0–10, fall_injuries boolean)
//   - Submit to CMS button: PATCH /billing/hos-m/{id}/submit
//
// Data loading: full page refresh via Inertia props (surveys, stats, currentYear).
// Access: finance + primary_care + it_admin + super_admin
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface SurveyRow {
    id: number;
    survey_year: number;
    administered_at: string | null;
    completed: boolean;
    submitted_to_cms: boolean;
    cms_submission_date: string | null;
    responses: Record<string, any> | null;
    participant: { id: number; mrn: string; first_name: string; last_name: string } | null;
    administeredBy: { id: number; first_name: string; last_name: string } | null;
}

interface Stats {
    total_enrolled: number;
    surveyed_this_year: number;
    completed_this_year: number;
    submitted_to_cms: number;
}

interface Props extends PageProps {
    surveys: SurveyRow[];
    stats: Stats;
    currentYear: number;
}

// ── Response Scale Labels ──────────────────────────────────────────────────────

const healthScale = [
    { value: 1, label: '1: Excellent' },
    { value: 2, label: '2: Very Good' },
    { value: 3, label: '3: Good' },
    { value: 4, label: '4: Fair' },
    { value: 5, label: '5: Poor' },
];

// ── Sub-components ────────────────────────────────────────────────────────────

function StatCard({ label, value, total, color }: { label: string; value: number; total: number; color: string }) {
    const pct = total > 0 ? Math.round((value / total) * 100) : 0;
    return (
        <div className="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5">
            <div className="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">{label}</div>
            <div className="text-2xl font-bold text-gray-900 dark:text-slate-100 mt-1">{value} <span className="text-sm font-normal text-gray-400 dark:text-slate-500">/ {total}</span></div>
            <div className="mt-2 h-1.5 rounded-full bg-gray-100 dark:bg-slate-700">
                <div className={`h-1.5 rounded-full ${color}`} style={{ width: `${pct}%` }} />
            </div>
            <div className="text-xs text-gray-400 dark:text-slate-500 mt-1">{pct}%</div>
        </div>
    );
}

// ── Main Component ────────────────────────────────────────────────────────────

export default function HosMSurvey({ auth, surveys, stats, currentYear }: Props) {
    const [showAdd, setShowAdd]           = useState(false);
    const [saving, setSaving]             = useState(false);
    const [saveMsg, setSaveMsg]           = useState<string | null>(null);
    const [submittingId, setSubmittingId] = useState<number | null>(null);

    // Participant typeahead state
    const [pptQuery, setPptQuery]         = useState('');
    const [pptResults, setPptResults]     = useState<{ id: number; mrn: string; name: string }[]>([]);
    const [pptLoading, setPptLoading]     = useState(false);
    const [showPptDrop, setShowPptDrop]   = useState(false);
    const [selectedPpt, setSelectedPpt]   = useState<{ id: number; name: string; mrn: string } | null>(null);

    // New survey form state
    const [form, setForm] = useState({
        participant_id: '',
        survey_year: String(currentYear),
        physical_health: '',
        mental_health: '',
        pain: '',
        falls_past_year: '',
        fall_injuries: false,
    });

    const searchParticipants = async (q: string) => {
        setPptQuery(q);
        if (q.length < 2) { setPptResults([]); setShowPptDrop(false); return; }
        setPptLoading(true);
        try {
            const res = await axios.get('/participants/search', { params: { q } });
            setPptResults(res.data);
            setShowPptDrop(true); // show even if empty so "No matches found" renders
        } catch { /* ignore */ } finally {
            setPptLoading(false);
        }
    };

    const selectParticipant = (p: { id: number; name: string; mrn: string }) => {
        setSelectedPpt(p);
        setForm(f => ({ ...f, participant_id: String(p.id) }));
        setShowPptDrop(false);
    };

    const clearParticipant = () => {
        setSelectedPpt(null);
        setPptQuery('');
        setPptResults([]);
        setForm(f => ({ ...f, participant_id: '' }));
    };

    const handleAdd = async () => {
        setSaving(true);
        setSaveMsg(null);
        try {
            await axios.post('/billing/hos-m', {
                participant_id: parseInt(form.participant_id),
                survey_year:    parseInt(form.survey_year),
                responses: {
                    physical_health:  parseInt(form.physical_health) || null,
                    mental_health:    parseInt(form.mental_health)   || null,
                    pain:             parseInt(form.pain)            || null,
                    falls_past_year:  form.falls_past_year !== '' ? parseInt(form.falls_past_year) : null,
                    fall_injuries:    form.fall_injuries,
                },
            });
            setShowAdd(false);
            setForm({ participant_id: '', survey_year: String(currentYear), physical_health: '', mental_health: '', pain: '', falls_past_year: '', fall_injuries: false });
            clearParticipant();
            router.reload({ only: ['surveys', 'stats'] });
        } catch (e: any) {
            setSaveMsg(e.response?.data?.message ?? e.response?.data?.error ?? 'Failed to save survey.');
        } finally {
            setSaving(false);
        }
    };

    const handleSubmitToCms = async (survey: SurveyRow) => {
        setSubmittingId(survey.id);
        try {
            await axios.patch(`/billing/hos-m/${survey.id}/submit`);
            router.reload({ only: ['surveys', 'stats'] });
        } catch (e: any) {
            alert(e.response?.data?.message ?? 'CMS submission failed.');
        } finally {
            setSubmittingId(null);
        }
    };

    return (
        <AppShell>
            <Head title="HOS-M Surveys" />
            <div className="max-w-7xl mx-auto px-4 py-8">

                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">HOS-M Annual Surveys</h1>
                        <p className="text-sm text-gray-500 dark:text-slate-400 mt-1">
                            Health Outcomes Survey for Medicare (PACE) — {currentYear} administration
                        </p>
                    </div>
                    <button
                        onClick={() => setShowAdd(true)}
                        className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700"
                    >
                        Add Survey
                    </button>
                </div>

                {/* Completion Stats */}
                <div className="grid grid-cols-4 gap-4 mb-8">
                    <StatCard
                        label="Enrolled Participants"
                        value={stats.total_enrolled}
                        total={stats.total_enrolled}
                        color="bg-gray-400"
                    />
                    <StatCard
                        label="Surveyed This Year"
                        value={stats.surveyed_this_year}
                        total={stats.total_enrolled}
                        color="bg-blue-500"
                    />
                    <StatCard
                        label="Completed Surveys"
                        value={stats.completed_this_year}
                        total={stats.total_enrolled}
                        color="bg-green-500"
                    />
                    <StatCard
                        label="Submitted to CMS"
                        value={stats.submitted_to_cms}
                        total={stats.total_enrolled}
                        color="bg-indigo-500"
                    />
                </div>

                {/* Surveys Table */}
                <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
                    <table className="w-full text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-700/50 text-gray-600 dark:text-slate-400 text-xs uppercase">
                            <tr>
                                <th className="px-3 py-2 text-left">Participant</th>
                                <th className="px-3 py-2 text-left">Year</th>
                                <th className="px-3 py-2 text-left">Administered At</th>
                                <th className="px-3 py-2 text-left">Administered By</th>
                                <th className="px-3 py-2 text-left">Completed</th>
                                <th className="px-3 py-2 text-left">CMS Status</th>
                                <th className="px-3 py-2 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
                            {surveys.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-3 py-8 text-center text-gray-400 dark:text-slate-500">
                                        No surveys recorded yet. Click Add Survey to begin.
                                    </td>
                                </tr>
                            ) : surveys.map(row => (
                                <tr key={row.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                                    <td className="px-3 py-2 font-medium text-gray-900 dark:text-slate-100">
                                        {row.participant
                                            ? `${row.participant.first_name} ${row.participant.last_name}`
                                            : '-'}
                                        {row.participant && (
                                            <div className="text-xs text-gray-400 dark:text-slate-500">{row.participant.mrn}</div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2 text-gray-600 dark:text-slate-400">{row.survey_year}</td>
                                    <td className="px-3 py-2 text-xs text-gray-500 dark:text-slate-400">
                                        {row.administered_at
                                            ? new Date(row.administered_at).toLocaleDateString()
                                            : '-'}
                                    </td>
                                    <td className="px-3 py-2 text-xs text-gray-500 dark:text-slate-400">
                                        {row.administeredBy
                                            ? `${row.administeredBy.first_name} ${row.administeredBy.last_name}`
                                            : '-'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.completed ? (
                                            <span className="px-2 py-0.5 rounded text-xs font-medium bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300">Complete</span>
                                        ) : (
                                            <span className="px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 dark:bg-yellow-900/60 text-yellow-700 dark:text-yellow-300">Incomplete</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.submitted_to_cms ? (
                                            <div>
                                                <span className="px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300">Submitted</span>
                                                {row.cms_submission_date && (
                                                    <div className="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                                                        {new Date(row.cms_submission_date).toLocaleDateString()}
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <span className="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400">Not Submitted</span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.completed && !row.submitted_to_cms && (
                                            <button
                                                onClick={() => handleSubmitToCms(row)}
                                                disabled={submittingId === row.id}
                                                className="text-xs text-indigo-600 hover:underline disabled:opacity-50"
                                            >
                                                {submittingId === row.id ? 'Submitting…' : 'Submit to CMS'}
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Add Survey Modal */}
                {showAdd && (
                    <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
                        <div className="bg-white dark:bg-slate-800 rounded-xl p-6 w-full max-w-lg shadow-lg">
                            <h2 className="text-lg font-bold text-gray-900 dark:text-slate-100 mb-4">Add HOS-M Survey</h2>

                            <div className="space-y-4">
                                <div className="grid grid-cols-2 gap-3">
                                    {/* Participant typeahead */}
                                    <div>
                                        <label className="block text-xs text-gray-500 dark:text-slate-400 mb-1">Participant</label>
                                        <div className="relative">
                                            {selectedPpt ? (
                                                <div className="flex items-center gap-2 border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 bg-white dark:bg-slate-700">
                                                    <span className="text-sm text-gray-900 dark:text-slate-100 flex-1 truncate">{selectedPpt.name}</span>
                                                    <span className="text-xs text-gray-400 dark:text-slate-500 shrink-0">{selectedPpt.mrn}</span>
                                                    <button type="button" onClick={clearParticipant} className="text-gray-400 hover:text-gray-600 dark:hover:text-slate-200 ml-1 shrink-0">✕</button>
                                                </div>
                                            ) : (
                                                <input
                                                    type="text"
                                                    value={pptQuery}
                                                    onChange={e => searchParticipants(e.target.value)}
                                                    onFocus={() => pptResults.length > 0 && setShowPptDrop(true)}
                                                    onBlur={() => setTimeout(() => setShowPptDrop(false), 150)}
                                                    placeholder="Search by name or MRN…"
                                                    className="border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm w-full dark:bg-slate-700"
                                                />
                                            )}
                                            {showPptDrop && (pptResults.length > 0 || (!pptLoading && pptQuery.length >= 2)) && (
                                                <div className="absolute z-10 mt-1 w-full bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                    {pptLoading ? (
                                                        <div className="px-3 py-2 text-xs text-gray-400 dark:text-slate-500">Searching…</div>
                                                    ) : pptResults.length === 0 ? (
                                                        <div className="px-3 py-2 text-xs text-gray-400 dark:text-slate-500">No matches found</div>
                                                    ) : pptResults.map(p => (
                                                        <button
                                                            key={p.id}
                                                            type="button"
                                                            onMouseDown={() => selectParticipant(p)}
                                                            className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-slate-600"
                                                        >
                                                            <span className="font-medium text-gray-900 dark:text-slate-100">{p.name}</span>
                                                            <span className="text-xs text-gray-400 dark:text-slate-400 ml-2">{p.mrn}</span>
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                    <div>
                                        <label className="block text-xs text-gray-500 dark:text-slate-400 mb-1">Survey Year</label>
                                        <input
                                            type="number"
                                            value={form.survey_year}
                                            min={2020}
                                            max={2035}
                                            onChange={e => setForm(f => ({ ...f, survey_year: e.target.value }))}
                                            className="border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm w-full dark:bg-slate-700 dark:text-slate-100"
                                        />
                                    </div>
                                </div>

                                {/* Health Responses */}
                                <div className="bg-gray-50 dark:bg-slate-700/50 rounded-lg p-4 space-y-3">
                                    <p className="text-xs font-semibold text-gray-600 dark:text-slate-300 uppercase tracking-wide">Survey Responses</p>

                                    <div>
                                        <label className="block text-xs text-gray-500 dark:text-slate-400 mb-1">
                                            Physical Health Rating <span className="text-gray-400 dark:text-slate-500">(1=Excellent, 5=Poor)</span>
                                        </label>
                                        <select
                                            value={form.physical_health}
                                            onChange={e => setForm(f => ({ ...f, physical_health: e.target.value }))}
                                            className="border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm w-full dark:bg-slate-700 dark:text-slate-100"
                                        >
                                            <option value="">Select rating…</option>
                                            {healthScale.map(s => (
                                                <option key={s.value} value={s.value}>{s.label}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs text-gray-500 dark:text-slate-400 mb-1">
                                            Mental Health Rating <span className="text-gray-400 dark:text-slate-500">(1=Excellent, 5=Poor)</span>
                                        </label>
                                        <select
                                            value={form.mental_health}
                                            onChange={e => setForm(f => ({ ...f, mental_health: e.target.value }))}
                                            className="border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm w-full dark:bg-slate-700 dark:text-slate-100"
                                        >
                                            <option value="">Select rating…</option>
                                            {healthScale.map(s => (
                                                <option key={s.value} value={s.value}>{s.label}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs text-gray-500 dark:text-slate-400 mb-1">
                                            Pain Level <span className="text-gray-400 dark:text-slate-500">(1=None, 5=Severe)</span>
                                        </label>
                                        <select
                                            value={form.pain}
                                            onChange={e => setForm(f => ({ ...f, pain: e.target.value }))}
                                            className="border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm w-full dark:bg-slate-700 dark:text-slate-100"
                                        >
                                            <option value="">Select rating…</option>
                                            {healthScale.map(s => (
                                                <option key={s.value} value={s.value}>{s.label}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs text-gray-500 dark:text-slate-400 mb-1">Falls in Past Year (count)</label>
                                        <input
                                            type="number"
                                            value={form.falls_past_year}
                                            min={0}
                                            max={99}
                                            onChange={e => setForm(f => ({ ...f, falls_past_year: e.target.value }))}
                                            className="border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm w-full dark:bg-slate-700 dark:text-slate-100"
                                            placeholder="0"
                                        />
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="fall-injuries"
                                            checked={form.fall_injuries}
                                            onChange={e => setForm(f => ({ ...f, fall_injuries: e.target.checked }))}
                                            className="rounded"
                                        />
                                        <label htmlFor="fall-injuries" className="text-sm text-gray-700 dark:text-slate-300">
                                            Fall-related injuries in past year
                                        </label>
                                    </div>
                                </div>
                            </div>

                            {saveMsg && (
                                <p className="text-sm text-red-600 dark:text-red-400 mt-3">{saveMsg}</p>
                            )}

                            <div className="flex justify-end gap-3 mt-5">
                                <button
                                    onClick={() => { setShowAdd(false); setSaveMsg(null); clearParticipant(); }}
                                    className="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
                                >
                                    Cancel
                                </button>
                                <button
                                    onClick={handleAdd}
                                    disabled={saving || !form.participant_id}
                                    className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {saving ? 'Saving…' : 'Save Survey'}
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
