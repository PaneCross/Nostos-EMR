// ─── QA / Compliance Dashboard ────────────────────────────────────────────────
// Powers the qa_compliance department's compliance monitoring view.
//
// Layout:
//   - 6 KPI cards (pre-loaded from server): SDR Rate, Overdue Assessments,
//     Unsigned Notes, Open Incidents, Overdue Care Plans, Hospitalizations
//   - Incident queue table (open incidents, all in props)
//   - Compliance tabs (lazy-loaded): Unsigned Notes | Overdue Assessments
//   - CSV export button (type selector → download)
//
// Data loading strategy:
//   - KPIs and incident queue: pre-loaded server-side (Inertia props)
//   - Compliance tab details: lazy-loaded via JSON endpoints on first tab click
//   - CSV export: direct download link with ?type= param
//
// Props: kpis, openIncidents, incidentTypes, statuses
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';

// ── Types ─────────────────────────────────────────────────────────────────────

interface Kpis {
    sdr_compliance_rate:       number;
    overdue_assessments_count: number;
    unsigned_notes_count:      number;
    open_incidents_count:      number;
    overdue_care_plans_count:  number;
    hospitalizations_month:    number;
}

interface ParticipantSummary {
    id:         number;
    mrn:        string;
    name:       string;
}

interface UserSummary {
    id:         number;
    first_name: string;
    last_name:  string;
}

interface IncidentRow {
    id:              number;
    incident_type:   string;
    status:          string;
    occurred_at:     string;
    rca_required:    boolean;
    rca_completed:   boolean;
    cms_reportable:  boolean;
    participant:     ParticipantSummary | null;
    reportedBy:      UserSummary | null;
}

interface UnsignedNote {
    id:           number;
    note_type:    string;
    department:   string;
    participant:  ParticipantSummary | null;
    author:       string | null;
    created_at:   string;
    hours_overdue:number;
}

interface OverdueAssessment {
    id:               number;
    assessment_type:  string;
    department:       string;
    participant:      ParticipantSummary | null;
    author:           string | null;
    next_due_date:    string | null;
    days_overdue:     number;
}

interface QaDashboardProps extends PageProps {
    kpis:           Kpis;
    openIncidents:  IncidentRow[];
    incidentTypes:  Record<string, string>;
    statuses:       Record<string, string>;
}

// ── Compliance tab options ─────────────────────────────────────────────────────
type ComplianceTab = 'incidents' | 'unsigned_notes' | 'overdue_assessments';

// ── Status badge helper ────────────────────────────────────────────────────────
function statusBadge(status: string): React.ReactElement {
    const classes: Record<string, string> = {
        open:         'bg-blue-50 text-blue-700 ring-blue-600/20',
        under_review: 'bg-amber-50 text-amber-700 ring-amber-600/20',
        rca_in_progress: 'bg-orange-50 text-orange-700 ring-orange-600/20',
        closed:       'bg-gray-50 text-gray-600 ring-gray-500/10',
    };
    const labels: Record<string, string> = {
        open:            'Open',
        under_review:    'Under Review',
        rca_in_progress: 'RCA In Progress',
        closed:          'Closed',
    };
    return (
        <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ${classes[status] ?? 'bg-gray-50 text-gray-600 ring-gray-500/10'}`}>
            {labels[status] ?? status}
        </span>
    );
}

// ── KPI Card ──────────────────────────────────────────────────────────────────
interface KpiCardProps {
    label:    string;
    value:    number | string;
    sublabel: string;
    color:    'blue' | 'amber' | 'red' | 'green' | 'purple' | 'slate';
    alert?:  boolean; // shows red highlight if value is concerning
}

function KpiCard({ label, value, sublabel, color, alert }: KpiCardProps) {
    const borderClasses: Record<string, string> = {
        blue:   'border-l-blue-500',
        amber:  'border-l-amber-500',
        red:    'border-l-red-500',
        green:  'border-l-green-500',
        purple: 'border-l-purple-500',
        slate:  'border-l-slate-400',
    };
    const valueClasses: Record<string, string> = {
        blue:   'text-blue-700',
        amber:  'text-amber-700',
        red:    'text-red-700',
        green:  'text-green-700',
        purple: 'text-purple-700',
        slate:  'text-slate-700',
    };
    return (
        <div className={`bg-white rounded-lg border border-gray-200 border-l-4 ${borderClasses[color]} p-5 shadow-sm`}>
            <p className="text-sm font-medium text-gray-500">{label}</p>
            <p className={`mt-1 text-3xl font-bold ${alert ? 'text-red-600' : valueClasses[color]}`}>
                {value}
            </p>
            <p className="mt-1 text-xs text-gray-400">{sublabel}</p>
        </div>
    );
}

// ── Unsigned Notes Tab ─────────────────────────────────────────────────────────
function UnsignedNotesTab({ tenantId }: { tenantId: number }) {
    const [notes, setNotes]     = useState<UnsignedNote[]>([]);
    const [loaded, setLoaded]   = useState(false);
    const [loading, setLoading] = useState(false);

    // Lazy-load on first render of this tab
    React.useEffect(() => {
        if (loaded) return;
        setLoading(true);
        axios.get<UnsignedNote[]>('/qa/compliance/unsigned-notes')
            .then(res => { setNotes(res.data); setLoaded(true); })
            .finally(() => setLoading(false));
    }, []);

    if (loading) return <p className="text-sm text-gray-500 py-8 text-center">Loading…</p>;

    if (notes.length === 0) {
        return (
            <div className="text-center py-12 text-gray-500">
                <p className="text-sm font-medium">No unsigned notes older than 24 hours.</p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
                <thead className="bg-gray-50">
                    <tr>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Participant</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Note Type</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Department</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Author</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Created</th>
                        <th className="px-4 py-3 text-right font-semibold text-gray-600">Hours Overdue</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 bg-white">
                    {notes.map(note => (
                        <tr key={note.id} className="hover:bg-gray-50">
                            <td className="px-4 py-3">
                                {note.participant
                                    ? <><span className="font-medium">{note.participant.name}</span> <span className="text-gray-400 text-xs">{note.participant.mrn}</span></>
                                    : <span className="text-gray-400">—</span>}
                            </td>
                            <td className="px-4 py-3 capitalize">{note.note_type.replace(/_/g, ' ')}</td>
                            <td className="px-4 py-3 capitalize">{note.department?.replace(/_/g, ' ')}</td>
                            <td className="px-4 py-3">{note.author ?? '—'}</td>
                            <td className="px-4 py-3 text-gray-500">{new Date(note.created_at).toLocaleDateString()}</td>
                            <td className="px-4 py-3 text-right">
                                <span className="font-semibold text-amber-600">{note.hours_overdue}h</span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ── Overdue Assessments Tab ────────────────────────────────────────────────────
function OverdueAssessmentsTab() {
    const [assessments, setAssessments] = useState<OverdueAssessment[]>([]);
    const [loaded, setLoaded]           = useState(false);
    const [loading, setLoading]         = useState(false);

    // Lazy-load on first render of this tab
    React.useEffect(() => {
        if (loaded) return;
        setLoading(true);
        axios.get<OverdueAssessment[]>('/qa/compliance/overdue-assessments')
            .then(res => { setAssessments(res.data); setLoaded(true); })
            .finally(() => setLoading(false));
    }, []);

    if (loading) return <p className="text-sm text-gray-500 py-8 text-center">Loading…</p>;

    if (assessments.length === 0) {
        return (
            <div className="text-center py-12 text-gray-500">
                <p className="text-sm font-medium">No overdue assessments.</p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
                <thead className="bg-gray-50">
                    <tr>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Participant</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Assessment Type</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Department</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Due Date</th>
                        <th className="px-4 py-3 text-right font-semibold text-gray-600">Days Overdue</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 bg-white">
                    {assessments.map(a => (
                        <tr key={a.id} className="hover:bg-gray-50">
                            <td className="px-4 py-3">
                                {a.participant
                                    ? <><span className="font-medium">{a.participant.name}</span> <span className="text-gray-400 text-xs">{a.participant.mrn}</span></>
                                    : <span className="text-gray-400">—</span>}
                            </td>
                            <td className="px-4 py-3 capitalize">{a.assessment_type.replace(/_/g, ' ')}</td>
                            <td className="px-4 py-3 capitalize">{a.department?.replace(/_/g, ' ') ?? '—'}</td>
                            <td className="px-4 py-3 text-gray-500">{a.next_due_date ?? '—'}</td>
                            <td className="px-4 py-3 text-right">
                                <span className={`font-semibold ${a.days_overdue >= 7 ? 'text-red-600' : 'text-amber-600'}`}>
                                    {a.days_overdue}d
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ── Main Component ─────────────────────────────────────────────────────────────
export default function QaDashboard() {
    const { kpis, openIncidents, incidentTypes, statuses } = usePage<QaDashboardProps>().props;

    const [complianceTab, setComplianceTab] = useState<ComplianceTab>('incidents');

    // CSV export — triggers a direct download via window.location
    const handleExport = useCallback((type: string) => {
        window.location.href = `/qa/reports/export?type=${type}`;
    }, []);

    return (
        <AppShell>
            <Head title="QA / Compliance Dashboard" />

            {/* ── Page header ──────────────────────────────────────────────── */}
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">QA / Compliance Dashboard</h1>
                    <p className="text-sm text-gray-500 mt-1">Real-time compliance monitoring for your organization</p>
                </div>
                {/* Export dropdown */}
                <div className="flex items-center gap-2">
                    <span className="text-sm text-gray-500">Export:</span>
                    <button
                        onClick={() => handleExport('incidents')}
                        className="text-sm px-3 py-1.5 rounded border border-gray-300 hover:bg-gray-50 text-gray-700"
                    >
                        Incidents CSV
                    </button>
                    <button
                        onClick={() => handleExport('unsigned_notes')}
                        className="text-sm px-3 py-1.5 rounded border border-gray-300 hover:bg-gray-50 text-gray-700"
                    >
                        Unsigned Notes CSV
                    </button>
                </div>
            </div>

            {/* ── 6 KPI Cards ──────────────────────────────────────────────── */}
            <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-8">
                <KpiCard
                    label="SDR Compliance"
                    value={`${kpis.sdr_compliance_rate}%`}
                    sublabel="Completed within 72h (30d)"
                    color={kpis.sdr_compliance_rate >= 95 ? 'green' : kpis.sdr_compliance_rate >= 80 ? 'amber' : 'red'}
                    alert={kpis.sdr_compliance_rate < 80}
                />
                <KpiCard
                    label="Overdue Assessments"
                    value={kpis.overdue_assessments_count}
                    sublabel="Past next_due_date"
                    color="amber"
                    alert={kpis.overdue_assessments_count > 5}
                />
                <KpiCard
                    label="Unsigned Notes"
                    value={kpis.unsigned_notes_count}
                    sublabel="Draft notes > 24 hours"
                    color="amber"
                    alert={kpis.unsigned_notes_count > 3}
                />
                <KpiCard
                    label="Open Incidents"
                    value={kpis.open_incidents_count}
                    sublabel="Awaiting resolution"
                    color="blue"
                    alert={kpis.open_incidents_count > 10}
                />
                <KpiCard
                    label="Overdue Care Plans"
                    value={kpis.overdue_care_plans_count}
                    sublabel="Review date passed"
                    color="purple"
                    alert={kpis.overdue_care_plans_count > 0}
                />
                <KpiCard
                    label="Hospital / ER"
                    value={kpis.hospitalizations_month}
                    sublabel="This month"
                    color="red"
                    alert={false}
                />
            </div>

            {/* ── Compliance tabs section ───────────────────────────────────── */}
            <div className="bg-white rounded-lg border border-gray-200 shadow-sm">

                {/* Tab bar */}
                <div className="border-b border-gray-200 px-4 flex gap-1 pt-1">
                    {([
                        ['incidents',            'Open Incidents'],
                        ['unsigned_notes',       'Unsigned Notes'],
                        ['overdue_assessments',  'Overdue Assessments'],
                    ] as [ComplianceTab, string][]).map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => setComplianceTab(key)}
                            className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
                                complianceTab === key
                                    ? 'border-blue-600 text-blue-700'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                        >
                            {label}
                            {key === 'incidents' && kpis.open_incidents_count > 0 && (
                                <span className="ml-1.5 bg-blue-100 text-blue-700 text-xs px-1.5 py-0.5 rounded-full">
                                    {kpis.open_incidents_count}
                                </span>
                            )}
                            {key === 'unsigned_notes' && kpis.unsigned_notes_count > 0 && (
                                <span className="ml-1.5 bg-amber-100 text-amber-700 text-xs px-1.5 py-0.5 rounded-full">
                                    {kpis.unsigned_notes_count}
                                </span>
                            )}
                            {key === 'overdue_assessments' && kpis.overdue_assessments_count > 0 && (
                                <span className="ml-1.5 bg-amber-100 text-amber-700 text-xs px-1.5 py-0.5 rounded-full">
                                    {kpis.overdue_assessments_count}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {/* Tab content */}
                <div className="p-0">
                    {complianceTab === 'incidents' && (
                        <IncidentQueueTable
                            incidents={openIncidents}
                            incidentTypes={incidentTypes}
                            statuses={statuses}
                        />
                    )}
                    {complianceTab === 'unsigned_notes' && <UnsignedNotesTab tenantId={0} />}
                    {complianceTab === 'overdue_assessments' && <OverdueAssessmentsTab />}
                </div>
            </div>
        </AppShell>
    );
}

// ── Incident Queue Table ───────────────────────────────────────────────────────
interface IncidentQueueTableProps {
    incidents:     IncidentRow[];
    incidentTypes: Record<string, string>;
    statuses:      Record<string, string>;
}

function IncidentQueueTable({ incidents, incidentTypes, statuses }: IncidentQueueTableProps) {
    if (incidents.length === 0) {
        return (
            <div className="text-center py-16 text-gray-500">
                <p className="text-sm font-medium">No open incidents. All clear!</p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm" data-testid="incident-queue">
                <thead className="bg-gray-50">
                    <tr>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">ID</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Participant</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Type</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Occurred</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Reported By</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">RCA</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">CMS</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 bg-white">
                    {incidents.map(inc => (
                        <tr key={inc.id} className="hover:bg-gray-50">
                            <td className="px-4 py-3 text-gray-400 text-xs">#{inc.id}</td>
                            <td className="px-4 py-3">
                                {inc.participant
                                    ? <><span className="font-medium">{inc.participant.name}</span> <span className="text-gray-400 text-xs">{inc.participant.mrn}</span></>
                                    : <span className="text-gray-400">—</span>}
                            </td>
                            <td className="px-4 py-3">
                                {incidentTypes[inc.incident_type] ?? inc.incident_type}
                            </td>
                            <td className="px-4 py-3 text-gray-500">
                                {new Date(inc.occurred_at).toLocaleDateString()}
                            </td>
                            <td className="px-4 py-3 text-gray-500">
                                {inc.reportedBy
                                    ? `${inc.reportedBy.first_name} ${inc.reportedBy.last_name}`
                                    : '—'}
                            </td>
                            <td className="px-4 py-3">
                                {inc.rca_required ? (
                                    inc.rca_completed
                                        ? <span className="text-green-600 text-xs font-medium">Done</span>
                                        : <span className="text-red-600 text-xs font-semibold">Pending</span>
                                ) : (
                                    <span className="text-gray-300 text-xs">N/A</span>
                                )}
                            </td>
                            <td className="px-4 py-3">
                                {inc.cms_reportable
                                    ? <span className="text-xs bg-red-50 text-red-700 px-1.5 py-0.5 rounded ring-1 ring-red-200">CMS</span>
                                    : <span className="text-gray-300 text-xs">—</span>}
                            </td>
                            <td className="px-4 py-3">{statusBadge(inc.status)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
