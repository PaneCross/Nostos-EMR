// ─── QA / Compliance Dashboard ────────────────────────────────────────────────
// Powers the qa_compliance department's compliance monitoring view.
//
// Layout:
//   - 9 KPI cards (pre-loaded from server): SDR Rate, Overdue Assessments,
//     Unsigned Notes, Open Incidents, Overdue Care Plans, Hospitalizations,
//     Open Grievances (W4-1), Missing NPP (W4-1), CMS Disenrollment Pending (W4-5)
//   - Incident queue table (open incidents, all in props)
//   - Compliance tabs (lazy-loaded): Incidents | Unsigned Notes | Overdue Assessments | Grievances
//   - CSV export button (type selector → download)
//   - Security Posture widget (W4-2)
//
// Data loading strategy:
//   - KPIs and incident queue: pre-loaded server-side (Inertia props)
//   - Compliance tab details: lazy-loaded via JSON endpoints on first tab click
//   - CSV export: direct download link with ?type= param
//
// Props: kpis, openIncidents, incidentTypes, statuses, compliance_posture
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
    // W4-1: Grievance + consent KPIs (42 CFR §460.120, HIPAA 45 CFR §164.520)
    open_grievances_count:     number;
    missing_npp_count:         number;
    // W4-5: Disenrollment CMS notification overdue (42 CFR §460.116)
    pending_cms_disenrollment_count: number;
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

// W4-2: HIPAA security posture summary passed from QaDashboardController
interface CompliancePosture {
    expired_baa_count:   number;
    expiring_soon_count: number;
    sra_overdue:         boolean;
    session_encrypted:   boolean;
    db_ssl_enforced:     boolean;
    field_encryption:    boolean;
    latest_sra_date:     string | null;
}

interface QaDashboardProps extends PageProps {
    kpis:               Kpis;
    openIncidents:      IncidentRow[];
    incidentTypes:      Record<string, string>;
    statuses:           Record<string, string>;
    compliance_posture: CompliancePosture;
}

// ── Compliance Posture Widget (W4-2) ──────────────────────────────────────────
// Compact HIPAA security posture row for QA staff. Surfaces expired/expiring
// BAAs, SRA currency, and encryption config at a glance. Links to the full
// Security & Compliance page for details.

function CompliancePostureWidget({ posture }: { posture: CompliancePosture }) {
    type ChipColor = 'green' | 'amber' | 'red';

    const baaChip: { label: string; color: ChipColor } =
        posture.expired_baa_count > 0
            ? { label: `${posture.expired_baa_count} BAA${posture.expired_baa_count > 1 ? 's' : ''} Expired`, color: 'red' }
            : posture.expiring_soon_count > 0
            ? { label: `${posture.expiring_soon_count} BAA${posture.expiring_soon_count > 1 ? 's' : ''} Expiring Soon`, color: 'amber' }
            : { label: 'BAA Coverage OK', color: 'green' };

    const sraChip: { label: string; color: ChipColor } = posture.sra_overdue
        ? { label: 'SRA Overdue', color: 'red' }
        : {
            label: posture.latest_sra_date
                ? `SRA Current (${new Date(posture.latest_sra_date).toLocaleDateString()})`
                : 'SRA Current',
            color: 'green',
          };

    const encGaps = [
        !posture.field_encryption  && 'Field Encryption',
        !posture.session_encrypted && 'Session Encrypt',
        !posture.db_ssl_enforced   && 'DB SSL',
    ].filter(Boolean) as string[];

    const encChip: { label: string; color: ChipColor } =
        encGaps.length === 0
            ? { label: 'Encryption Configured', color: 'green' }
            : { label: `Encryption Gaps: ${encGaps.join(', ')}`, color: 'amber' };

    const chipClasses: Record<ChipColor, string> = {
        green: 'bg-green-50 dark:bg-green-950/40 text-green-700 dark:text-green-300 ring-green-600/20 dark:ring-green-500/20',
        amber: 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-500/20',
        red:   'bg-red-50 dark:bg-red-950/40 text-red-700 dark:text-red-300 ring-red-600/20 dark:ring-red-500/20',
    };

    return (
        <div className="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 px-5 py-3 mb-6 shadow-sm flex flex-wrap items-center gap-3">
            <span className="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide shrink-0">
                Security Posture:
            </span>
            {[baaChip, sraChip, encChip].map((chip, i) => (
                <span
                    key={i}
                    className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${chipClasses[chip.color]}`}
                >
                    {chip.label}
                </span>
            ))}
            <a
                href="/it-admin/security"
                className="ml-auto text-xs text-blue-600 dark:text-blue-400 hover:underline shrink-0"
            >
                View Security Details
            </a>
        </div>
    );
}

// ── Compliance tab options ─────────────────────────────────────────────────────
type ComplianceTab = 'incidents' | 'unsigned_notes' | 'overdue_assessments' | 'grievances';

// ── Status badge helper ────────────────────────────────────────────────────────
function statusBadge(status: string): React.ReactElement {
    const classes: Record<string, string> = {
        open:         'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 ring-blue-600/20',
        under_review: 'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 ring-amber-600/20',
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
        blue:   'text-blue-700 dark:text-blue-300',
        amber:  'text-amber-700 dark:text-amber-300',
        red:    'text-red-700 dark:text-red-300',
        green:  'text-green-700 dark:text-green-300',
        purple: 'text-purple-700 dark:text-purple-300',
        slate:  'text-slate-700 dark:text-slate-300',
    };
    return (
        <div className={`bg-white dark:bg-slate-800 rounded-lg border border-gray-200 border-l-4 ${borderClasses[color]} p-5 shadow-sm`}>
            <p className="text-sm font-medium text-gray-500">{label}</p>
            <p className={`mt-1 text-3xl font-bold ${alert ? 'text-red-600 dark:text-red-400' : valueClasses[color]}`}>
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
                <tbody className="divide-y divide-gray-100 bg-white dark:bg-slate-800">
                    {notes.map(note => (
                        <tr key={note.id} className="hover:bg-gray-50">
                            <td className="px-4 py-3">
                                {note.participant
                                    ? <><span className="font-medium">{note.participant.name}</span> <span className="text-gray-400 text-xs">{note.participant.mrn}</span></>
                                    : <span className="text-gray-400">-</span>}
                            </td>
                            <td className="px-4 py-3 capitalize">{note.note_type.replace(/_/g, ' ')}</td>
                            <td className="px-4 py-3 capitalize">{note.department?.replace(/_/g, ' ')}</td>
                            <td className="px-4 py-3">{note.author ?? '-'}</td>
                            <td className="px-4 py-3 text-gray-500">{new Date(note.created_at).toLocaleDateString()}</td>
                            <td className="px-4 py-3 text-right">
                                <span className="font-semibold text-amber-600 dark:text-amber-400">{note.hours_overdue}h</span>
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
                <tbody className="divide-y divide-gray-100 bg-white dark:bg-slate-800">
                    {assessments.map(a => (
                        <tr key={a.id} className="hover:bg-gray-50">
                            <td className="px-4 py-3">
                                {a.participant
                                    ? <><span className="font-medium">{a.participant.name}</span> <span className="text-gray-400 text-xs">{a.participant.mrn}</span></>
                                    : <span className="text-gray-400">-</span>}
                            </td>
                            <td className="px-4 py-3 capitalize">{a.assessment_type.replace(/_/g, ' ')}</td>
                            <td className="px-4 py-3 capitalize">{a.department?.replace(/_/g, ' ') ?? '-'}</td>
                            <td className="px-4 py-3 text-gray-500">{a.next_due_date ?? '-'}</td>
                            <td className="px-4 py-3 text-right">
                                <span className={`font-semibold ${a.days_overdue >= 7 ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'}`}>
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

// ── Grievances Tab ────────────────────────────────────────────────────────────
interface GrievanceItem {
    id:             number;
    grievance_type: string;
    priority:       string;
    status:         string;
    subject:        string;
    received_at:    string;
    deadline_at:    string | null;
    is_overdue:     boolean;
    participant:    ParticipantSummary | null;
    assigned_to:    string | null;
}

function GrievancesTab() {
    const [grievances, setGrievances] = useState<GrievanceItem[]>([]);
    const [loaded, setLoaded]         = useState(false);
    const [loading, setLoading]       = useState(false);

    // Lazy-load on first render of this tab
    React.useEffect(() => {
        if (loaded) return;
        setLoading(true);
        axios.get<{ data: GrievanceItem[] }>('/grievances', { params: { status: 'open', per_page: 50 } })
            .then(res => {
                // Handle both array and paginated {data:[]} response shapes
                const items = Array.isArray(res.data) ? res.data : (res.data as { data: GrievanceItem[] }).data ?? [];
                setGrievances(items);
                setLoaded(true);
            })
            .finally(() => setLoading(false));
    }, []);

    const priorityClasses: Record<string, string> = {
        urgent:   'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 ring-red-200',
        standard: 'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 ring-blue-200',
    };

    if (loading) return <p className="text-sm text-gray-500 dark:text-slate-400 py-8 text-center">Loading…</p>;

    if (grievances.length === 0) {
        return (
            <div className="text-center py-12 text-gray-500 dark:text-slate-400">
                <p className="text-sm font-medium">No open grievances.</p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                <thead className="bg-gray-50 dark:bg-slate-700/50">
                    <tr>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600 dark:text-slate-300">Participant</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600 dark:text-slate-300">Type</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600 dark:text-slate-300">Priority</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600 dark:text-slate-300">Subject</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600 dark:text-slate-300">Received</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600 dark:text-slate-300">Deadline</th>
                        <th className="px-4 py-3 text-left font-semibold text-gray-600 dark:text-slate-300">Assigned</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-slate-700 bg-white dark:bg-slate-800">
                    {grievances.map(g => (
                        <tr key={g.id} className={`hover:bg-gray-50 dark:hover:bg-slate-700/50 ${g.is_overdue ? 'bg-red-50/40 dark:bg-red-950/20' : ''}`}>
                            <td className="px-4 py-3">
                                {g.participant
                                    ? <><span className="font-medium text-gray-900 dark:text-slate-100">{g.participant.name}</span> <span className="text-gray-400 dark:text-slate-500 text-xs">{g.participant.mrn}</span></>
                                    : <span className="text-gray-400 dark:text-slate-500">-</span>}
                            </td>
                            <td className="px-4 py-3 capitalize text-gray-700 dark:text-slate-300">{g.grievance_type.replace(/_/g, ' ')}</td>
                            <td className="px-4 py-3">
                                <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset ${priorityClasses[g.priority] ?? 'bg-gray-50 dark:bg-slate-700 text-gray-700 dark:text-slate-300 ring-gray-200'}`}>
                                    {g.priority === 'urgent' ? 'Urgent' : 'Standard'}
                                </span>
                            </td>
                            <td className="px-4 py-3 text-gray-700 dark:text-slate-300 max-w-xs truncate">{g.subject}</td>
                            <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                                {new Date(g.received_at).toLocaleDateString()}
                            </td>
                            <td className="px-4 py-3">
                                {g.deadline_at ? (
                                    <span className={`text-xs font-medium ${g.is_overdue ? 'text-red-600 dark:text-red-400' : 'text-gray-600 dark:text-slate-400'}`}>
                                        {new Date(g.deadline_at).toLocaleDateString()}
                                        {g.is_overdue && <span className="ml-1 font-semibold">(Overdue)</span>}
                                    </span>
                                ) : (
                                    <span className="text-gray-400 dark:text-slate-500 text-xs">-</span>
                                )}
                            </td>
                            <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{g.assigned_to ?? '-'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ── Main Component ─────────────────────────────────────────────────────────────
export default function QaDashboard() {
    const { kpis, openIncidents, incidentTypes, statuses, compliance_posture } = usePage<QaDashboardProps>().props;

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

            {/* ── 9 KPI Cards ──────────────────────────────────────────────── */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
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
                {/* W4-1: Grievance KPI — 42 CFR §460.120–§460.121 */}
                <KpiCard
                    label="Open Grievances"
                    value={kpis.open_grievances_count}
                    sublabel="Awaiting resolution"
                    color="amber"
                    alert={kpis.open_grievances_count > 0}
                />
                {/* W4-1: Missing NPP KPI — HIPAA 45 CFR §164.520 */}
                <KpiCard
                    label="Missing NPP"
                    value={kpis.missing_npp_count}
                    sublabel="NPP acknowledgment pending"
                    color="amber"
                    alert={kpis.missing_npp_count > 0}
                />
                {/* W4-5: Disenrollment CMS notification overdue — 42 CFR §460.116 */}
                <KpiCard
                    label="CMS Disenrollment Pending"
                    value={kpis.pending_cms_disenrollment_count}
                    sublabel="Awaiting CMS notification (>7d)"
                    color="amber"
                    alert={kpis.pending_cms_disenrollment_count > 0}
                />
            </div>

            {/* ── Security Posture (W4-2) ──────────────────────────────────── */}
            <CompliancePostureWidget posture={compliance_posture} />

            {/* ── Compliance tabs section ───────────────────────────────────── */}
            <div className="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 shadow-sm">

                {/* Tab bar */}
                <div className="border-b border-gray-200 px-4 flex gap-1 pt-1">
                    {([
                        ['incidents',            'Open Incidents'],
                        ['unsigned_notes',       'Unsigned Notes'],
                        ['overdue_assessments',  'Overdue Assessments'],
                        ['grievances',           'Grievances'],
                    ] as [ComplianceTab, string][]).map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => setComplianceTab(key)}
                            className={`px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
                                complianceTab === key
                                    ? 'border-blue-600 text-blue-700 dark:text-blue-300'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                        >
                            {label}
                            {key === 'incidents' && kpis.open_incidents_count > 0 && (
                                <span className="ml-1.5 bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 text-xs px-1.5 py-0.5 rounded-full">
                                    {kpis.open_incidents_count}
                                </span>
                            )}
                            {key === 'unsigned_notes' && kpis.unsigned_notes_count > 0 && (
                                <span className="ml-1.5 bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 text-xs px-1.5 py-0.5 rounded-full">
                                    {kpis.unsigned_notes_count}
                                </span>
                            )}
                            {key === 'overdue_assessments' && kpis.overdue_assessments_count > 0 && (
                                <span className="ml-1.5 bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 text-xs px-1.5 py-0.5 rounded-full">
                                    {kpis.overdue_assessments_count}
                                </span>
                            )}
                            {key === 'grievances' && kpis.open_grievances_count > 0 && (
                                <span className="ml-1.5 bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 text-xs px-1.5 py-0.5 rounded-full">
                                    {kpis.open_grievances_count}
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
                    {complianceTab === 'grievances' && <GrievancesTab />}
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
                <tbody className="divide-y divide-gray-100 bg-white dark:bg-slate-800">
                    {incidents.map(inc => (
                        <tr key={inc.id} className="hover:bg-gray-50">
                            <td className="px-4 py-3 text-gray-400 text-xs">#{inc.id}</td>
                            <td className="px-4 py-3">
                                {inc.participant
                                    ? <><span className="font-medium">{inc.participant.name}</span> <span className="text-gray-400 text-xs">{inc.participant.mrn}</span></>
                                    : <span className="text-gray-400">-</span>}
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
                                    : '-'}
                            </td>
                            <td className="px-4 py-3">
                                {inc.rca_required ? (
                                    inc.rca_completed
                                        ? <span className="text-green-600 dark:text-green-400 text-xs font-medium">Done</span>
                                        : <span className="text-red-600 dark:text-red-400 text-xs font-semibold">Pending</span>
                                ) : (
                                    <span className="text-gray-300 text-xs">N/A</span>
                                )}
                            </td>
                            <td className="px-4 py-3">
                                {inc.cms_reportable
                                    ? <span className="text-xs bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 px-1.5 py-0.5 rounded ring-1 ring-red-200">CMS</span>
                                    : <span className="text-gray-300 text-xs">-</span>}
                            </td>
                            <td className="px-4 py-3">{statusBadge(inc.status)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
