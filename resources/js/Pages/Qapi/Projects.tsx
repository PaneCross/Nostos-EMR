// ─── Qapi/Projects ────────────────────────────────────────────────────────────
// QAPI (Quality Assessment and Performance Improvement) project board.
// 42 CFR §460.136–§460.140: PACE organizations must maintain at least 2 active
// QI projects at any time during the year.
//
// Layout: Kanban board with 5 columns (Planning | Active | Remeasuring |
//         Completed | Suspended). Projects are represented as cards.
//         A compliance banner warns when fewer than 2 active projects exist.
//
// Data loading strategy:
//   - Projects and metadata pre-loaded server-side (Inertia props)
//   - New project / edit project: modal with axios POST/PATCH
//   - Status advance (activate, remeasure, complete, suspend): axios PATCH
//
// Access: All users may view. QA Compliance and IT Admin may create/edit.
// Props: projects, active_count, meets_minimum, min_required, statuses, domains
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import {
    PlusIcon,
    ExclamationTriangleIcon,
    CheckCircleIcon,
    ClipboardDocumentListIcon,
    PencilSquareIcon,
    XMarkIcon,
    ChevronRightIcon,
} from '@heroicons/react/24/outline';

// ── Types ─────────────────────────────────────────────────────────────────────

interface ProjectLead {
    id:   number;
    name: string;
}

interface QapiProject {
    id:                     number;
    title:                  string;
    description:            string | null;
    aim_statement:          string | null;
    domain:                 string;
    domain_label:           string;
    status:                 string;
    status_label:           string;
    is_active:              boolean;
    start_date:             string | null;
    target_completion_date: string | null;
    actual_completion_date: string | null;
    baseline_metric:        string | null;
    target_metric:          string | null;
    current_metric:         string | null;
    project_lead:           ProjectLead | null;
    team_member_ids:        number[];
    interventions:          string | null;
    findings:               string | null;
    created_at:             string | null;
}

interface QapiProjectsProps extends PageProps {
    projects:       QapiProject[];
    active_count:   number;
    meets_minimum:  boolean;
    min_required:   number;
    statuses:       Record<string, string>;
    domains:        Record<string, string>;
}

// ── Kanban column config ──────────────────────────────────────────────────────

const COLUMNS: { key: string; label: string; color: string; headerClass: string }[] = [
    { key: 'planning',    label: 'Planning',    color: 'blue',   headerClass: 'border-blue-400' },
    { key: 'active',      label: 'Active',      color: 'green',  headerClass: 'border-green-500' },
    { key: 'remeasuring', label: 'Remeasuring', color: 'amber',  headerClass: 'border-amber-400' },
    { key: 'completed',   label: 'Completed',   color: 'slate',  headerClass: 'border-slate-400' },
    { key: 'suspended',   label: 'Suspended',   color: 'red',    headerClass: 'border-red-400' },
];

const STATUS_BADGE: Record<string, string> = {
    planning:    'bg-blue-50 dark:bg-blue-950/50 text-blue-700 dark:text-blue-300',
    active:      'bg-green-50 dark:bg-green-950/50 text-green-700 dark:text-green-300',
    remeasuring: 'bg-amber-50 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300',
    completed:   'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400',
    suspended:   'bg-red-50 dark:bg-red-950/50 text-red-700 dark:text-red-300',
};

const DOMAIN_COLORS: Record<string, string> = {
    clinical_outcomes: 'bg-purple-50 dark:bg-purple-950/50 text-purple-700 dark:text-purple-300',
    safety:            'bg-red-50 dark:bg-red-950/50 text-red-700 dark:text-red-300',
    access:            'bg-blue-50 dark:bg-blue-950/50 text-blue-700 dark:text-blue-300',
    satisfaction:      'bg-teal-50 dark:bg-teal-950/50 text-teal-700 dark:text-teal-300',
    efficiency:        'bg-orange-50 dark:bg-orange-950/50 text-orange-700 dark:text-orange-300',
};

// ── ProjectCard ───────────────────────────────────────────────────────────────

interface ProjectCardProps {
    project:     QapiProject;
    isQaAdmin:   boolean;
    onEdit:      (p: QapiProject) => void;
    onSelect:    (p: QapiProject) => void;
}

function ProjectCard({ project, isQaAdmin, onEdit, onSelect }: ProjectCardProps) {
    return (
        <div
            className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-sm p-4 cursor-pointer hover:shadow-md transition-shadow"
            onClick={() => onSelect(project)}
        >
            {/* Domain badge + edit */}
            <div className="flex items-start justify-between gap-2 mb-2">
                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${DOMAIN_COLORS[project.domain] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'}`}>
                    {project.domain_label}
                </span>
                {isQaAdmin && (
                    <button
                        onClick={e => { e.stopPropagation(); onEdit(project); }}
                        className="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300"
                        title="Edit project"
                    >
                        <PencilSquareIcon className="w-4 h-4" />
                    </button>
                )}
            </div>

            {/* Title */}
            <p className="text-sm font-semibold text-gray-900 dark:text-slate-100 leading-snug mb-1">
                {project.title}
            </p>

            {/* Aim statement */}
            {project.aim_statement && (
                <p className="text-xs text-gray-500 dark:text-slate-400 line-clamp-2 mb-2">
                    {project.aim_statement}
                </p>
            )}

            {/* Dates */}
            <div className="text-xs text-gray-400 dark:text-slate-500 mt-2 space-y-0.5">
                {project.start_date && (
                    <p>Start: {new Date(project.start_date).toLocaleDateString()}</p>
                )}
                {project.target_completion_date && (
                    <p>Target: {new Date(project.target_completion_date).toLocaleDateString()}</p>
                )}
            </div>

            {/* Project lead */}
            {project.project_lead && (
                <p className="text-xs text-gray-500 dark:text-slate-400 mt-2">
                    Lead: {project.project_lead.name}
                </p>
            )}

            {/* Current metric (remeasuring/completed) */}
            {project.current_metric && (
                <div className="mt-2 p-2 bg-amber-50 dark:bg-amber-950/30 rounded text-xs text-amber-700 dark:text-amber-300">
                    {project.current_metric}
                </div>
            )}
        </div>
    );
}

// ── ProjectDetailModal ────────────────────────────────────────────────────────
// Read-only detail view with status advance actions for QA admins.

interface DetailModalProps {
    project:   QapiProject;
    isQaAdmin: boolean;
    onClose:   () => void;
    onEdit:    (p: QapiProject) => void;
    onRefresh: () => void;
}

function ProjectDetailModal({ project, isQaAdmin, onClose, onEdit, onRefresh }: DetailModalProps) {
    const [advancing, setAdvancing] = useState(false);
    const [error, setError]         = useState<string | null>(null);

    const advanceStatus = (newStatus: string) => {
        setAdvancing(true);
        setError(null);
        const isRemeasure = newStatus === 'remeasuring';
        const url = isRemeasure
            ? `/qapi/projects/${project.id}/remeasure`
            : `/qapi/projects/${project.id}`;
        const method = isRemeasure ? 'post' : 'patch';

        axios({ method, url, data: { status: newStatus } })
            .then(() => { onRefresh(); onClose(); })
            .catch(err => setError(err.response?.data?.message ?? 'Failed to update project.'))
            .finally(() => setAdvancing(false));
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div className="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
                {/* Header */}
                <div className="flex items-start justify-between px-6 py-4 border-b dark:border-slate-700">
                    <div>
                        <h2 className="text-lg font-bold text-gray-900 dark:text-slate-100">{project.title}</h2>
                        <div className="flex items-center gap-2 mt-1">
                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${STATUS_BADGE[project.status] ?? ''}`}>
                                {project.status_label}
                            </span>
                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${DOMAIN_COLORS[project.domain] ?? ''}`}>
                                {project.domain_label}
                            </span>
                        </div>
                    </div>
                    <button onClick={onClose} className="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300">
                        <XMarkIcon className="w-5 h-5" />
                    </button>
                </div>

                <div className="px-6 py-4 space-y-4">
                    {/* Aim statement */}
                    {project.aim_statement && (
                        <div>
                            <p className="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-1">Aim Statement</p>
                            <p className="text-sm text-gray-700 dark:text-slate-300">{project.aim_statement}</p>
                        </div>
                    )}

                    {/* Description */}
                    {project.description && (
                        <div>
                            <p className="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-1">Description</p>
                            <p className="text-sm text-gray-700 dark:text-slate-300">{project.description}</p>
                        </div>
                    )}

                    {/* Metrics grid */}
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        {project.baseline_metric && (
                            <div className="bg-gray-50 dark:bg-slate-700/50 rounded p-3">
                                <p className="text-xs text-gray-500 dark:text-slate-400 mb-1">Baseline</p>
                                <p className="text-sm text-gray-800 dark:text-slate-200">{project.baseline_metric}</p>
                            </div>
                        )}
                        {project.target_metric && (
                            <div className="bg-green-50 dark:bg-green-950/30 rounded p-3">
                                <p className="text-xs text-gray-500 dark:text-slate-400 mb-1">Target</p>
                                <p className="text-sm text-gray-800 dark:text-slate-200">{project.target_metric}</p>
                            </div>
                        )}
                        {project.current_metric && (
                            <div className="bg-amber-50 dark:bg-amber-950/30 rounded p-3">
                                <p className="text-xs text-gray-500 dark:text-slate-400 mb-1">Current</p>
                                <p className="text-sm text-gray-800 dark:text-slate-200">{project.current_metric}</p>
                            </div>
                        )}
                    </div>

                    {/* Dates + lead */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                        {project.start_date && (
                            <div>
                                <p className="text-xs text-gray-500 dark:text-slate-400">Start</p>
                                <p className="text-gray-800 dark:text-slate-200">{new Date(project.start_date).toLocaleDateString()}</p>
                            </div>
                        )}
                        {project.target_completion_date && (
                            <div>
                                <p className="text-xs text-gray-500 dark:text-slate-400">Target Completion</p>
                                <p className="text-gray-800 dark:text-slate-200">{new Date(project.target_completion_date).toLocaleDateString()}</p>
                            </div>
                        )}
                        {project.actual_completion_date && (
                            <div>
                                <p className="text-xs text-gray-500 dark:text-slate-400">Completed</p>
                                <p className="text-gray-800 dark:text-slate-200">{new Date(project.actual_completion_date).toLocaleDateString()}</p>
                            </div>
                        )}
                        {project.project_lead && (
                            <div>
                                <p className="text-xs text-gray-500 dark:text-slate-400">Project Lead</p>
                                <p className="text-gray-800 dark:text-slate-200">{project.project_lead.name}</p>
                            </div>
                        )}
                    </div>

                    {/* Interventions */}
                    {project.interventions && (
                        <div>
                            <p className="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-1">Interventions</p>
                            <p className="text-sm text-gray-700 dark:text-slate-300 whitespace-pre-line">{project.interventions}</p>
                        </div>
                    )}

                    {/* Findings */}
                    {project.findings && (
                        <div>
                            <p className="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-1">Findings</p>
                            <p className="text-sm text-gray-700 dark:text-slate-300 whitespace-pre-line">{project.findings}</p>
                        </div>
                    )}

                    {/* Error */}
                    {error && (
                        <p className="text-sm text-red-600 dark:text-red-400">{error}</p>
                    )}
                </div>

                {/* Footer actions */}
                <div className="flex items-center justify-between px-6 py-4 border-t dark:border-slate-700">
                    <div className="flex items-center gap-2">
                        {isQaAdmin && (
                            <>
                                {project.status === 'planning' && (
                                    <button
                                        onClick={() => advanceStatus('active')}
                                        disabled={advancing}
                                        className="px-3 py-1.5 text-sm bg-green-600 hover:bg-green-700 text-white rounded disabled:opacity-50"
                                    >
                                        Activate
                                    </button>
                                )}
                                {project.status === 'active' && (
                                    <button
                                        onClick={() => advanceStatus('remeasuring')}
                                        disabled={advancing}
                                        className="px-3 py-1.5 text-sm bg-amber-600 hover:bg-amber-700 text-white rounded disabled:opacity-50"
                                    >
                                        Begin Remeasuring
                                    </button>
                                )}
                                {project.status === 'remeasuring' && (
                                    <button
                                        onClick={() => advanceStatus('completed')}
                                        disabled={advancing}
                                        className="px-3 py-1.5 text-sm bg-slate-600 hover:bg-slate-700 text-white rounded disabled:opacity-50"
                                    >
                                        Mark Completed
                                    </button>
                                )}
                                {['planning', 'active', 'remeasuring'].includes(project.status) && (
                                    <button
                                        onClick={() => advanceStatus('suspended')}
                                        disabled={advancing}
                                        className="px-3 py-1.5 text-sm bg-red-100 dark:bg-red-950/50 hover:bg-red-200 dark:hover:bg-red-900/50 text-red-700 dark:text-red-400 rounded disabled:opacity-50"
                                    >
                                        Suspend
                                    </button>
                                )}
                                <button
                                    onClick={() => { onEdit(project); onClose(); }}
                                    className="px-3 py-1.5 text-sm border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700"
                                >
                                    Edit Details
                                </button>
                            </>
                        )}
                    </div>
                    <button
                        onClick={onClose}
                        className="px-3 py-1.5 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
}

// ── ProjectFormModal ──────────────────────────────────────────────────────────
// Create or edit a QAPI project.

interface FormModalProps {
    project?:  QapiProject | null;
    domains:   Record<string, string>;
    statuses:  Record<string, string>;
    onClose:   () => void;
    onSaved:   () => void;
}

function ProjectFormModal({ project, domains, statuses, onClose, onSaved }: FormModalProps) {
    const isEdit = !!project;

    const [form, setForm] = useState({
        title:                  project?.title ?? '',
        description:            project?.description ?? '',
        aim_statement:          project?.aim_statement ?? '',
        domain:                 project?.domain ?? Object.keys(domains)[0] ?? '',
        status:                 project?.status ?? 'planning',
        start_date:             project?.start_date ?? new Date().toISOString().split('T')[0],
        target_completion_date: project?.target_completion_date ?? '',
        baseline_metric:        project?.baseline_metric ?? '',
        target_metric:          project?.target_metric ?? '',
        current_metric:         project?.current_metric ?? '',
        interventions:          project?.interventions ?? '',
        findings:               project?.findings ?? '',
    });

    const [saving, setSaving]   = useState(false);
    const [errors, setErrors]   = useState<Record<string, string>>({});

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});

        const payload: Record<string, string | null> = {
            title:                  form.title,
            description:            form.description || null,
            aim_statement:          form.aim_statement || null,
            domain:                 form.domain,
            start_date:             form.start_date,
            target_completion_date: form.target_completion_date || null,
            baseline_metric:        form.baseline_metric || null,
            target_metric:          form.target_metric || null,
            interventions:          form.interventions || null,
        };

        if (isEdit) {
            payload.status          = form.status;
            payload.current_metric  = form.current_metric || null;
            payload.findings        = form.findings || null;
        }

        const request = isEdit
            ? axios.patch(`/qapi/projects/${project!.id}`, payload)
            : axios.post('/qapi/projects', payload);

        request
            .then(() => { onSaved(); onClose(); })
            .catch(err => {
                if (err.response?.status === 422) {
                    const validationErrors: Record<string, string> = {};
                    Object.entries(err.response.data.errors ?? {}).forEach(([k, v]) => {
                        validationErrors[k] = (v as string[])[0];
                    });
                    setErrors(validationErrors);
                } else {
                    setErrors({ general: err.response?.data?.message ?? 'Failed to save project.' });
                }
            })
            .finally(() => setSaving(false));
    };

    const field = (name: keyof typeof form, label: string, type = 'text', options?: Record<string, string>) => (
        <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">{label}</label>
            {options ? (
                <select
                    value={form[name] as string}
                    onChange={e => setForm(f => ({ ...f, [name]: e.target.value }))}
                    className="w-full rounded border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm"
                >
                    {Object.entries(options).map(([k, v]) => (
                        <option key={k} value={k}>{v}</option>
                    ))}
                </select>
            ) : type === 'textarea' ? (
                <textarea
                    value={form[name] as string}
                    onChange={e => setForm(f => ({ ...f, [name]: e.target.value }))}
                    rows={3}
                    className="w-full rounded border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm resize-none"
                />
            ) : (
                <input
                    type={type}
                    value={form[name] as string}
                    onChange={e => setForm(f => ({ ...f, [name]: e.target.value }))}
                    className="w-full rounded border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm"
                />
            )}
            {errors[name] && <p className="text-xs text-red-600 dark:text-red-400 mt-1">{errors[name]}</p>}
        </div>
    );

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div className="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-2xl mx-4 max-h-[90vh] overflow-y-auto">
                <form onSubmit={handleSubmit}>
                    {/* Header */}
                    <div className="flex items-center justify-between px-6 py-4 border-b dark:border-slate-700">
                        <h2 className="text-lg font-bold text-gray-900 dark:text-slate-100">
                            {isEdit ? 'Edit QAPI Project' : 'New QAPI Project'}
                        </h2>
                        <button type="button" onClick={onClose} className="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300">
                            <XMarkIcon className="w-5 h-5" />
                        </button>
                    </div>

                    <div className="px-6 py-4 space-y-4">
                        {errors.general && (
                            <p className="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/30 rounded p-3">{errors.general}</p>
                        )}

                        {field('title', 'Project Title *')}
                        {field('domain', 'Quality Domain *', 'text', domains)}
                        {isEdit && field('status', 'Status', 'text', statuses)}

                        <div className="grid grid-cols-2 gap-4">
                            {field('start_date', 'Start Date *', 'date')}
                            {field('target_completion_date', 'Target Completion', 'date')}
                        </div>

                        {field('aim_statement', 'Aim Statement', 'textarea')}
                        {field('description', 'Description', 'textarea')}

                        <div className="grid grid-cols-2 gap-4">
                            {field('baseline_metric', 'Baseline Metric')}
                            {field('target_metric', 'Target Metric')}
                        </div>

                        {isEdit && field('current_metric', 'Current Metric (Remeasuring)')}
                        {field('interventions', 'Interventions', 'textarea')}
                        {isEdit && field('findings', 'Findings', 'textarea')}
                    </div>

                    {/* Footer */}
                    <div className="flex items-center justify-end gap-3 px-6 py-4 border-t dark:border-slate-700">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={saving}
                            className="px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded disabled:opacity-50"
                        >
                            {saving ? 'Saving…' : (isEdit ? 'Save Changes' : 'Create Project')}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ── Main Component ─────────────────────────────────────────────────────────────
export default function QapiProjects() {
    const { props } = usePage<QapiProjectsProps>();
    const { projects, active_count, meets_minimum, min_required, statuses, domains } = props;

    // Detect if user is QA/admin from auth props
    const user = (props as PageProps).auth?.user;
    const isQaAdmin = user?.department === 'qa_compliance' || user?.department === 'it_admin' || !!user?.is_super_admin;

    const [selectedProject, setSelectedProject] = useState<QapiProject | null>(null);
    const [editProject, setEditProject]         = useState<QapiProject | null>(null);
    const [showNewModal, setShowNewModal]        = useState(false);

    const handleRefresh = () => router.reload({ only: ['projects', 'active_count', 'meets_minimum'] });

    // Group projects by status
    const byStatus = (status: string) => projects.filter(p => p.status === status);

    return (
        <AppShell>
            <Head title="QAPI Projects" />

            {/* ── Page header ──────────────────────────────────────────────── */}
            <div className="flex items-start justify-between mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 dark:text-slate-100">QAPI Projects</h1>
                    <p className="text-sm text-gray-500 dark:text-slate-400 mt-1">
                        Quality Assessment and Performance Improvement board. 42 CFR §460.136-§460.140.
                    </p>
                </div>
                {isQaAdmin && (
                    <button
                        onClick={() => setShowNewModal(true)}
                        className="flex items-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg"
                    >
                        <PlusIcon className="w-4 h-4" />
                        New Project
                    </button>
                )}
            </div>

            {/* ── Compliance minimum banner ─────────────────────────────────── */}
            {meets_minimum ? (
                <div className="flex items-center gap-2 mb-5 px-4 py-3 bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-800 rounded-lg text-sm text-green-700 dark:text-green-300">
                    <CheckCircleIcon className="w-5 h-5 shrink-0" />
                    <span>
                        <strong>{active_count}</strong> active QI project{active_count !== 1 ? 's' : ''} - meets the minimum {min_required} required by 42 CFR §460.136.
                    </span>
                </div>
            ) : (
                <div className="flex items-center gap-2 mb-5 px-4 py-3 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800 rounded-lg text-sm text-amber-700 dark:text-amber-300">
                    <ExclamationTriangleIcon className="w-5 h-5 shrink-0" />
                    <span>
                        <strong>Compliance alert:</strong> Only <strong>{active_count}</strong> active QI project{active_count !== 1 ? 's' : ''}. CMS requires at least <strong>{min_required}</strong> at all times (42 CFR §460.136).
                    </span>
                </div>
            )}

            {/* ── Kanban board ──────────────────────────────────────────────── */}
            <div className="flex gap-4 overflow-x-auto pb-4">
                {COLUMNS.map(col => {
                    const colProjects = byStatus(col.key);
                    return (
                        <div key={col.key} className="flex-shrink-0 w-72">
                            {/* Column header */}
                            <div className={`flex items-center justify-between px-3 py-2 mb-3 bg-white dark:bg-slate-800 border-l-4 ${col.headerClass} border border-gray-200 dark:border-slate-700 rounded-r-lg shadow-sm`}>
                                <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-200">{col.label}</h3>
                                <span className="bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 text-xs font-medium px-2 py-0.5 rounded-full">
                                    {colProjects.length}
                                </span>
                            </div>

                            {/* Cards */}
                            <div className="space-y-3 min-h-24">
                                {colProjects.length === 0 ? (
                                    <div className="flex items-center justify-center h-16 border-2 border-dashed border-gray-200 dark:border-slate-700 rounded-lg">
                                        <p className="text-xs text-gray-400 dark:text-slate-500">No projects</p>
                                    </div>
                                ) : colProjects.map(p => (
                                    <ProjectCard
                                        key={p.id}
                                        project={p}
                                        isQaAdmin={isQaAdmin}
                                        onEdit={setEditProject}
                                        onSelect={setSelectedProject}
                                    />
                                ))}
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* ── Empty state when no projects at all ───────────────────────── */}
            {projects.length === 0 && (
                <div className="text-center py-16">
                    <ClipboardDocumentListIcon className="w-12 h-12 text-gray-300 dark:text-slate-600 mx-auto mb-3" />
                    <p className="text-gray-500 dark:text-slate-400 font-medium">No QAPI projects yet</p>
                    <p className="text-sm text-gray-400 dark:text-slate-500 mt-1">
                        42 CFR §460.136 requires at least {min_required} active QI projects.
                    </p>
                    {isQaAdmin && (
                        <button
                            onClick={() => setShowNewModal(true)}
                            className="mt-4 inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg"
                        >
                            <PlusIcon className="w-4 h-4" />
                            Create First Project
                        </button>
                    )}
                </div>
            )}

            {/* ── Modals ─────────────────────────────────────────────────────── */}
            {selectedProject && (
                <ProjectDetailModal
                    project={selectedProject}
                    isQaAdmin={isQaAdmin}
                    onClose={() => setSelectedProject(null)}
                    onEdit={p => { setSelectedProject(null); setEditProject(p); }}
                    onRefresh={handleRefresh}
                />
            )}

            {editProject && (
                <ProjectFormModal
                    project={editProject}
                    domains={domains}
                    statuses={statuses}
                    onClose={() => setEditProject(null)}
                    onSaved={handleRefresh}
                />
            )}

            {showNewModal && (
                <ProjectFormModal
                    project={null}
                    domains={domains}
                    statuses={statuses}
                    onClose={() => setShowNewModal(false)}
                    onSaved={handleRefresh}
                />
            )}
        </AppShell>
    );
}
