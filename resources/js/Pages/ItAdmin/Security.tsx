// ─── ItAdmin/Security.tsx ─────────────────────────────────────────────────────
// Security & Compliance page — resolves BLOCKER-01 and BLOCKER-03.
//
// Layout: 3 tabs
//   1. BAA Records — table of all Business Associate Agreements, Add/Edit modal
//   2. SRA Records — table of Security Risk Analysis cycles, Add/Edit modal
//   3. Encryption Status — runtime checklist of HIPAA §164.312 encryption controls
//
// Data loading strategy:
//   All 3 tabs pre-loaded server-side (Inertia props) so there is no lazy-load
//   delay on tab switch. SecurityComplianceController pre-computes everything.
//
// Authorization: IT Admin only (enforced server-side).
//
// Props: baaRecords, sraRecords, encryptionStatus, vendorTypes, baaStatuses,
//        sraRiskLevels, sraStatuses, posture
// ─────────────────────────────────────────────────────────────────────────────

import React, { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AppShell from '@/Layouts/AppShell';
import { PageProps } from '@/types';
import {
    ShieldCheckIcon,
    ExclamationTriangleIcon,
    CheckCircleIcon,
    XCircleIcon,
    PlusIcon,
    PencilIcon,
    LockClosedIcon,
    DocumentTextIcon,
    ServerStackIcon,
} from '@heroicons/react/24/outline';

// ── Types ─────────────────────────────────────────────────────────────────────

interface BaaRecord {
    id:                   number;
    vendor_name:          string;
    vendor_type:          string;
    vendor_type_label:    string;
    phi_accessed:         boolean;
    baa_signed_date:      string | null;
    baa_expiration_date:  string | null;
    status:               string;
    status_label:         string;
    is_expired:           boolean;
    is_expiring_soon:     boolean;
    contact_name:         string | null;
    contact_email:        string | null;
    contact_phone:        string | null;
    notes:                string | null;
}

interface SraRecord {
    id:                 number;
    sra_date:           string;
    conducted_by:       string;
    scope_description:  string;
    risk_level:         string;
    risk_level_label:   string;
    findings_summary:   string | null;
    next_sra_due:       string | null;
    is_overdue:         boolean;
    status:             string;
    status_label:       string;
    reviewed_by:        string | null;
}

interface EncryptionCheck {
    label:  string;
    value:  boolean | string;
    status: 'pass' | 'warn' | 'fail';
    note:   string;
}

interface PostureChips {
    expired_baa_count:   number;
    expiring_soon_count: number;
    sra_overdue:         boolean;
    session_encrypted:   boolean;
    db_ssl_required:     boolean;
}

interface SecurityProps extends PageProps {
    baaRecords:       BaaRecord[];
    sraRecords:       SraRecord[];
    encryptionStatus: Record<string, EncryptionCheck>;
    vendorTypes:      Record<string, string>;
    baaStatuses:      Record<string, string>;
    sraRiskLevels:    Record<string, string>;
    sraStatuses:      Record<string, string>;
    posture:          PostureChips;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Status chip for BAA and SRA records */
function StatusChip({ status, label, isExpired, isExpiringSoon }: {
    status: string; label: string; isExpired?: boolean; isExpiringSoon?: boolean;
}) {
    let cls = 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300';
    if (status === 'active' && !isExpired && !isExpiringSoon) {
        cls = 'bg-green-100 dark:bg-green-950/60 text-green-700 dark:text-green-300';
    } else if (isExpiringSoon || status === 'expiring_soon') {
        cls = 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300';
    } else if (isExpired || status === 'expired') {
        cls = 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-300';
    } else if (status === 'pending') {
        cls = 'bg-blue-100 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300';
    } else if (status === 'terminated') {
        cls = 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-500';
    } else if (status === 'completed') {
        cls = 'bg-green-100 dark:bg-green-950/60 text-green-700 dark:text-green-300';
    } else if (status === 'needs_update') {
        cls = 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300';
    } else if (status === 'in_progress') {
        cls = 'bg-blue-100 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300';
    }
    return (
        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${cls}`}>
            {label}
        </span>
    );
}

/** Risk level chip for SRA records */
function RiskChip({ level, label }: { level: string; label: string }) {
    const cls: Record<string, string> = {
        low:      'bg-green-100 dark:bg-green-950/60 text-green-700 dark:text-green-300',
        moderate: 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300',
        high:     'bg-orange-100 dark:bg-orange-950/60 text-orange-700 dark:text-orange-300',
        critical: 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-300',
    };
    return (
        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${cls[level] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300'}`}>
            {label}
        </span>
    );
}

/** Encryption status icon */
function StatusIcon({ status }: { status: 'pass' | 'warn' | 'fail' }) {
    if (status === 'pass') return <CheckCircleIcon className="w-5 h-5 text-green-500 dark:text-green-400 shrink-0" />;
    if (status === 'warn') return <ExclamationTriangleIcon className="w-5 h-5 text-amber-500 dark:text-amber-400 shrink-0" />;
    return <XCircleIcon className="w-5 h-5 text-red-500 dark:text-red-400 shrink-0" />;
}

// ── BAA Modal ─────────────────────────────────────────────────────────────────

interface BaaFormData {
    vendor_name:          string;
    vendor_type:          string;
    phi_accessed:         boolean;
    baa_signed_date:      string;
    baa_expiration_date:  string;
    status:               string;
    contact_name:         string;
    contact_email:        string;
    contact_phone:        string;
    notes:                string;
}

function BaaModal({ record, vendorTypes, statuses, onClose }: {
    record:       BaaRecord | null;
    vendorTypes:  Record<string, string>;
    statuses:     Record<string, string>;
    onClose:      () => void;
}) {
    const isEdit = record !== null;
    const [form, setForm] = useState<BaaFormData>({
        vendor_name:         record?.vendor_name         ?? '',
        vendor_type:         record?.vendor_type         ?? 'cloud_provider',
        phi_accessed:        record?.phi_accessed        ?? true,
        baa_signed_date:     record?.baa_signed_date     ?? '',
        baa_expiration_date: record?.baa_expiration_date ?? '',
        status:              record?.status              ?? 'pending',
        contact_name:        record?.contact_name        ?? '',
        contact_email:       record?.contact_email       ?? '',
        contact_phone:       record?.contact_phone       ?? '',
        notes:               record?.notes               ?? '',
    });
    const [saving, setSaving] = useState(false);
    const [error, setError]   = useState<string | null>(null);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setError(null);

        const url    = isEdit ? `/it-admin/baa/${record!.id}` : '/it-admin/baa';
        const method = isEdit ? 'put' : 'post';

        axios({ method, url, data: form })
            .then(() => {
                router.reload({ only: ['baaRecords', 'posture'] });
                onClose();
            })
            .catch((err) => {
                setError(err.response?.data?.message ?? 'Save failed. Please try again.');
            })
            .finally(() => setSaving(false));
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4">
            <div className="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
                {/* Header */}
                <div className="flex items-center justify-between px-6 py-4 border-b dark:border-slate-700">
                    <h2 className="text-base font-semibold text-gray-900 dark:text-slate-100">
                        {isEdit ? 'Edit BAA Record' : 'Add BAA Record'}
                    </h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 text-lg leading-none">&times;</button>
                </div>

                <form onSubmit={handleSubmit} className="px-6 py-4 space-y-4">
                    {error && (
                        <div className="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-lg px-3 py-2">
                            {error}
                        </div>
                    )}

                    <div>
                        <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Vendor Name *</label>
                        <input type="text" value={form.vendor_name} onChange={e => setForm(f => ({ ...f, vendor_name: e.target.value }))}
                            className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm text-gray-900 dark:text-slate-100" required />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Vendor Type *</label>
                            <select value={form.vendor_type} onChange={e => setForm(f => ({ ...f, vendor_type: e.target.value }))}
                                className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm">
                                {Object.entries(vendorTypes).map(([k, v]) => (
                                    <option key={k} value={k}>{v}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Status *</label>
                            <select value={form.status} onChange={e => setForm(f => ({ ...f, status: e.target.value }))}
                                className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm">
                                {Object.entries(statuses).map(([k, v]) => (
                                    <option key={k} value={k}>{v}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <input type="checkbox" id="phi_accessed" checked={form.phi_accessed}
                            onChange={e => setForm(f => ({ ...f, phi_accessed: e.target.checked }))}
                            className="h-4 w-4 rounded border-gray-300" />
                        <label htmlFor="phi_accessed" className="text-sm text-gray-700 dark:text-slate-300">
                            Vendor accesses / processes PHI
                        </label>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">BAA Signed Date</label>
                            <input type="date" value={form.baa_signed_date}
                                onChange={e => setForm(f => ({ ...f, baa_signed_date: e.target.value }))}
                                className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Expiration Date</label>
                            <input type="date" value={form.baa_expiration_date}
                                onChange={e => setForm(f => ({ ...f, baa_expiration_date: e.target.value }))}
                                className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Contact Name</label>
                            <input type="text" value={form.contact_name}
                                onChange={e => setForm(f => ({ ...f, contact_name: e.target.value }))}
                                className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Contact Phone</label>
                            <input type="tel" value={form.contact_phone}
                                onChange={e => setForm(f => ({ ...f, contact_phone: e.target.value }))}
                                className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Contact Email</label>
                        <input type="email" value={form.contact_email}
                            onChange={e => setForm(f => ({ ...f, contact_email: e.target.value }))}
                            className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Notes</label>
                        <textarea value={form.notes}
                            onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
                            rows={3}
                            className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm resize-none" />
                    </div>

                    <div className="flex justify-end gap-3 pt-2">
                        <button type="button" onClick={onClose}
                            className="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200">
                            Cancel
                        </button>
                        <button type="submit" disabled={saving}
                            className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg disabled:opacity-60">
                            {saving ? 'Saving…' : (isEdit ? 'Save Changes' : 'Add BAA')}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ── SRA Modal ─────────────────────────────────────────────────────────────────

interface SraFormData {
    sra_date:           string;
    conducted_by:       string;
    scope_description:  string;
    risk_level:         string;
    findings_summary:   string;
    next_sra_due:       string;
    status:             string;
}

function SraModal({ record, riskLevels, statuses, onClose }: {
    record:     SraRecord | null;
    riskLevels: Record<string, string>;
    statuses:   Record<string, string>;
    onClose:    () => void;
}) {
    const isEdit = record !== null;
    const [form, setForm] = useState<SraFormData>({
        sra_date:          record?.sra_date          ?? '',
        conducted_by:      record?.conducted_by      ?? '',
        scope_description: record?.scope_description ?? '',
        risk_level:        record?.risk_level        ?? 'moderate',
        findings_summary:  record?.findings_summary  ?? '',
        next_sra_due:      record?.next_sra_due      ?? '',
        status:            record?.status            ?? 'in_progress',
    });
    const [saving, setSaving] = useState(false);
    const [error, setError]   = useState<string | null>(null);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        setError(null);

        const url    = isEdit ? `/it-admin/sra/${record!.id}` : '/it-admin/sra';
        const method = isEdit ? 'put' : 'post';

        axios({ method, url, data: form })
            .then(() => {
                router.reload({ only: ['sraRecords', 'posture'] });
                onClose();
            })
            .catch((err) => {
                setError(err.response?.data?.message ?? 'Save failed. Please try again.');
            })
            .finally(() => setSaving(false));
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4">
            <div className="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
                <div className="flex items-center justify-between px-6 py-4 border-b dark:border-slate-700">
                    <h2 className="text-base font-semibold text-gray-900 dark:text-slate-100">
                        {isEdit ? 'Edit SRA Record' : 'Add SRA Record'}
                    </h2>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300 text-lg leading-none">&times;</button>
                </div>

                <form onSubmit={handleSubmit} className="px-6 py-4 space-y-4">
                    {error && (
                        <div className="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800 rounded-lg px-3 py-2">
                            {error}
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">SRA Date *</label>
                            <input type="date" value={form.sra_date}
                                onChange={e => setForm(f => ({ ...f, sra_date: e.target.value }))}
                                className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm" required />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Next SRA Due</label>
                            <input type="date" value={form.next_sra_due}
                                onChange={e => setForm(f => ({ ...f, next_sra_due: e.target.value }))}
                                className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm" />
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Conducted By *</label>
                        <input type="text" value={form.conducted_by}
                            onChange={e => setForm(f => ({ ...f, conducted_by: e.target.value }))}
                            placeholder="Name or firm"
                            className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm" required />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Risk Level *</label>
                            <select value={form.risk_level}
                                onChange={e => setForm(f => ({ ...f, risk_level: e.target.value }))}
                                className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm">
                                {Object.entries(riskLevels).map(([k, v]) => (
                                    <option key={k} value={k}>{v}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Status *</label>
                            <select value={form.status}
                                onChange={e => setForm(f => ({ ...f, status: e.target.value }))}
                                className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm">
                                {Object.entries(statuses).map(([k, v]) => (
                                    <option key={k} value={k}>{v}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Scope Description *</label>
                        <textarea value={form.scope_description}
                            onChange={e => setForm(f => ({ ...f, scope_description: e.target.value }))}
                            rows={3} required
                            className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm resize-none" />
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Findings Summary</label>
                        <textarea value={form.findings_summary}
                            onChange={e => setForm(f => ({ ...f, findings_summary: e.target.value }))}
                            rows={4}
                            className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-700 px-3 py-2 text-sm resize-none" />
                    </div>

                    <div className="flex justify-end gap-3 pt-2">
                        <button type="button" onClick={onClose}
                            className="px-4 py-2 text-sm text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-slate-200">
                            Cancel
                        </button>
                        <button type="submit" disabled={saving}
                            className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg disabled:opacity-60">
                            {saving ? 'Saving…' : (isEdit ? 'Save Changes' : 'Add SRA')}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function Security({
    baaRecords,
    sraRecords,
    encryptionStatus,
    vendorTypes,
    baaStatuses,
    sraRiskLevels,
    sraStatuses,
    posture,
}: SecurityProps) {
    const [activeTab, setActiveTab] = useState<'baa' | 'sra' | 'encryption'>('baa');

    // BAA modal state
    const [showBaaModal, setShowBaaModal] = useState(false);
    const [editBaa, setEditBaa]           = useState<BaaRecord | null>(null);

    // SRA modal state
    const [showSraModal, setShowSraModal] = useState(false);
    const [editSra, setEditSra]           = useState<SraRecord | null>(null);

    const openAddBaa  = () => { setEditBaa(null);   setShowBaaModal(true); };
    const openEditBaa = (r: BaaRecord) => { setEditBaa(r); setShowBaaModal(true); };
    const openAddSra  = () => { setEditSra(null);   setShowSraModal(true); };
    const openEditSra = (r: SraRecord) => { setEditSra(r); setShowSraModal(true); };

    // Compute overall posture color
    const hasBlockers = posture.expired_baa_count > 0 || posture.sra_overdue
        || !posture.session_encrypted || !posture.db_ssl_required;
    const hasWarnings = posture.expiring_soon_count > 0;

    return (
        <AppShell>
            <Head title="Security and Compliance" />

            <div className="px-6 py-6 max-w-7xl mx-auto">
                {/* Page header */}
                <div className="mb-6">
                    <div className="flex items-center gap-3 mb-1">
                        <ShieldCheckIcon className="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                        <h1 className="text-xl font-bold text-gray-900 dark:text-slate-100">Security &amp; Compliance</h1>
                    </div>
                    <p className="text-sm text-gray-500 dark:text-slate-400">
                        HIPAA BAA tracking, Security Risk Analysis records, and encryption status.
                        Resolves BLOCKER-01 and BLOCKER-03 (45 CFR §164.308 + §164.312).
                    </p>
                </div>

                {/* Posture summary chips */}
                <div className="flex flex-wrap gap-2 mb-6">
                    <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold ${
                        posture.expired_baa_count > 0
                            ? 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-300'
                            : 'bg-green-100 dark:bg-green-950/60 text-green-700 dark:text-green-300'
                    }`}>
                        {posture.expired_baa_count > 0
                            ? <XCircleIcon className="w-3.5 h-3.5" />
                            : <CheckCircleIcon className="w-3.5 h-3.5" />}
                        {posture.expired_baa_count > 0
                            ? `${posture.expired_baa_count} Expired BAA${posture.expired_baa_count > 1 ? 's' : ''}`
                            : 'All BAAs Current'}
                    </span>

                    {posture.expiring_soon_count > 0 && (
                        <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300">
                            <ExclamationTriangleIcon className="w-3.5 h-3.5" />
                            {posture.expiring_soon_count} BAA{posture.expiring_soon_count > 1 ? 's' : ''} Expiring Soon
                        </span>
                    )}

                    <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold ${
                        posture.sra_overdue
                            ? 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-300'
                            : 'bg-green-100 dark:bg-green-950/60 text-green-700 dark:text-green-300'
                    }`}>
                        {posture.sra_overdue
                            ? <XCircleIcon className="w-3.5 h-3.5" />
                            : <CheckCircleIcon className="w-3.5 h-3.5" />}
                        {posture.sra_overdue ? 'SRA Overdue' : 'SRA Current'}
                    </span>

                    <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold ${
                        posture.session_encrypted
                            ? 'bg-green-100 dark:bg-green-950/60 text-green-700 dark:text-green-300'
                            : 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-300'
                    }`}>
                        {posture.session_encrypted
                            ? <CheckCircleIcon className="w-3.5 h-3.5" />
                            : <XCircleIcon className="w-3.5 h-3.5" />}
                        Session {posture.session_encrypted ? 'Encrypted' : 'NOT Encrypted'}
                    </span>

                    <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold ${
                        posture.db_ssl_required
                            ? 'bg-green-100 dark:bg-green-950/60 text-green-700 dark:text-green-300'
                            : 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300'
                    }`}>
                        {posture.db_ssl_required
                            ? <CheckCircleIcon className="w-3.5 h-3.5" />
                            : <ExclamationTriangleIcon className="w-3.5 h-3.5" />}
                        DB SSL {posture.db_ssl_required ? 'Required' : 'Not Enforced'}
                    </span>
                </div>

                {/* Tab bar */}
                <div className="border-b border-gray-200 dark:border-slate-700 mb-6">
                    <nav className="-mb-px flex gap-6">
                        {([
                            { key: 'baa',        label: 'BAA Records',        icon: DocumentTextIcon,  count: baaRecords.length },
                            { key: 'sra',        label: 'SRA Records',        icon: ShieldCheckIcon,   count: sraRecords.length },
                            { key: 'encryption', label: 'Encryption Status',  icon: LockClosedIcon,    count: null },
                        ] as const).map(({ key, label, icon: Icon, count }) => (
                            <button
                                key={key}
                                onClick={() => setActiveTab(key)}
                                className={`flex items-center gap-1.5 pb-3 text-sm font-medium border-b-2 transition-colors ${
                                    activeTab === key
                                        ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400 dark:border-indigo-400'
                                        : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200'
                                }`}
                            >
                                <Icon className="w-4 h-4" />
                                {label}
                                {count !== null && (
                                    <span className="ml-1 rounded-full bg-gray-100 dark:bg-slate-700 px-2 py-0.5 text-xs text-gray-600 dark:text-slate-400">
                                        {count}
                                    </span>
                                )}
                            </button>
                        ))}
                    </nav>
                </div>

                {/* ── BAA Records Tab ───────────────────────────────────────────── */}
                {activeTab === 'baa' && (
                    <div>
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">Business Associate Agreements</h2>
                                <p className="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                                    45 CFR §164.308(b)(1) requires a signed BAA with every vendor that accesses ePHI.
                                </p>
                            </div>
                            <button onClick={openAddBaa}
                                className="inline-flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
                                <PlusIcon className="w-4 h-4" />
                                Add BAA
                            </button>
                        </div>

                        {baaRecords.length === 0 ? (
                            <div className="text-center py-16 text-gray-500 dark:text-slate-400">
                                <DocumentTextIcon className="w-10 h-10 mx-auto mb-3 opacity-40" />
                                <p className="text-sm font-medium">No BAA records yet.</p>
                                <p className="text-xs mt-1">Add your first Business Associate Agreement above.</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
                                <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
                                    <thead className="bg-gray-50 dark:bg-slate-700/50">
                                        <tr>
                                            {['Vendor', 'Type', 'PHI?', 'Signed', 'Expires', 'Status', ''].map(h => (
                                                <th key={h} className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wider">{h}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-100 dark:divide-slate-700">
                                        {baaRecords.map(baa => (
                                            <tr key={baa.id} className={baa.is_expired ? 'bg-red-50/50 dark:bg-red-950/10' : baa.is_expiring_soon ? 'bg-amber-50/50 dark:bg-amber-950/10' : ''}>
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-gray-900 dark:text-slate-100">{baa.vendor_name}</p>
                                                    {baa.contact_name && <p className="text-xs text-gray-400 dark:text-slate-500">{baa.contact_name}</p>}
                                                </td>
                                                <td className="px-4 py-3 text-gray-700 dark:text-slate-300">{baa.vendor_type_label}</td>
                                                <td className="px-4 py-3">
                                                    <span className={`text-xs font-medium ${baa.phi_accessed ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400 dark:text-slate-500'}`}>
                                                        {baa.phi_accessed ? 'Yes' : 'No'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-gray-600 dark:text-slate-400">{baa.baa_signed_date ?? '-'}</td>
                                                <td className="px-4 py-3">
                                                    <span className={baa.is_expired ? 'text-red-600 dark:text-red-400 font-medium' : baa.is_expiring_soon ? 'text-amber-600 dark:text-amber-400 font-medium' : 'text-gray-600 dark:text-slate-400'}>
                                                        {baa.baa_expiration_date ?? '-'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StatusChip status={baa.status} label={baa.status_label}
                                                        isExpired={baa.is_expired} isExpiringSoon={baa.is_expiring_soon} />
                                                </td>
                                                <td className="px-4 py-3">
                                                    <button onClick={() => openEditBaa(baa)}
                                                        className="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400">
                                                        <PencilIcon className="w-4 h-4" />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* ── SRA Records Tab ───────────────────────────────────────────── */}
                {activeTab === 'sra' && (
                    <div>
                        <div className="flex items-center justify-between mb-4">
                            <div>
                                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">Security Risk Analysis Records</h2>
                                <p className="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                                    45 CFR §164.308(a)(1) requires an annual assessment of risks to ePHI confidentiality, integrity, and availability.
                                </p>
                            </div>
                            <button onClick={openAddSra}
                                className="inline-flex items-center gap-1.5 px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg">
                                <PlusIcon className="w-4 h-4" />
                                Add SRA
                            </button>
                        </div>

                        {sraRecords.length === 0 ? (
                            <div className="text-center py-16 text-gray-500 dark:text-slate-400">
                                <ShieldCheckIcon className="w-10 h-10 mx-auto mb-3 opacity-40" />
                                <p className="text-sm font-medium">No SRA records yet.</p>
                                <p className="text-xs mt-1">Document your Security Risk Analysis results above.</p>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {sraRecords.map(sra => (
                                    <div key={sra.id} className={`rounded-lg border p-4 ${sra.is_overdue ? 'border-red-300 dark:border-red-700 bg-red-50/30 dark:bg-red-950/10' : 'border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800'}`}>
                                        <div className="flex items-start justify-between gap-4">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2 flex-wrap mb-2">
                                                    <span className="text-sm font-semibold text-gray-900 dark:text-slate-100">{sra.sra_date}</span>
                                                    <StatusChip status={sra.status} label={sra.status_label} />
                                                    <RiskChip level={sra.risk_level} label={sra.risk_level_label} />
                                                    {sra.is_overdue && (
                                                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-300">
                                                            <ExclamationTriangleIcon className="w-3 h-3" />
                                                            Next SRA Overdue
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-xs text-gray-500 dark:text-slate-400">
                                                    Conducted by: <span className="font-medium text-gray-700 dark:text-slate-300">{sra.conducted_by}</span>
                                                    {sra.next_sra_due && (
                                                        <> &bull; Next due: <span className={`font-medium ${sra.is_overdue ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-slate-300'}`}>{sra.next_sra_due}</span></>
                                                    )}
                                                    {sra.reviewed_by && (
                                                        <> &bull; Reviewed by: <span className="font-medium text-gray-700 dark:text-slate-300">{sra.reviewed_by}</span></>
                                                    )}
                                                </p>
                                                {sra.findings_summary && (
                                                    <details className="mt-2">
                                                        <summary className="text-xs text-indigo-600 dark:text-indigo-400 cursor-pointer hover:text-indigo-800 dark:hover:text-indigo-300">
                                                            View findings summary
                                                        </summary>
                                                        <pre className="mt-2 text-xs text-gray-600 dark:text-slate-400 whitespace-pre-wrap font-sans bg-gray-50 dark:bg-slate-700/50 rounded p-2">
                                                            {sra.findings_summary}
                                                        </pre>
                                                    </details>
                                                )}
                                            </div>
                                            <button onClick={() => openEditSra(sra)}
                                                className="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 shrink-0">
                                                <PencilIcon className="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {/* ── Encryption Status Tab ─────────────────────────────────────── */}
                {activeTab === 'encryption' && (
                    <div>
                        <div className="mb-4">
                            <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">Encryption Status Checklist</h2>
                            <p className="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                                Runtime checks for HIPAA §164.312(a)(2)(iv) and §164.312(e)(2)(ii) encryption controls.
                                Values are computed live from environment config — no manual update required.
                            </p>
                        </div>

                        <div className="space-y-3">
                            {Object.entries(encryptionStatus).map(([key, check]) => (
                                <div key={key} className={`rounded-lg border p-4 flex gap-3 ${
                                    check.status === 'pass' ? 'border-green-200 dark:border-green-800 bg-green-50/30 dark:bg-green-950/10'
                                    : check.status === 'warn' ? 'border-amber-200 dark:border-amber-800 bg-amber-50/30 dark:bg-amber-950/10'
                                    : 'border-red-200 dark:border-red-800 bg-red-50/30 dark:bg-red-950/10'
                                }`}>
                                    <StatusIcon status={check.status} />
                                    <div className="flex-1">
                                        <div className="flex items-center gap-2 mb-1">
                                            <span className="text-sm font-semibold text-gray-900 dark:text-slate-100">{check.label}</span>
                                            <span className={`text-xs font-bold uppercase ${
                                                check.status === 'pass' ? 'text-green-600 dark:text-green-400'
                                                : check.status === 'warn' ? 'text-amber-600 dark:text-amber-400'
                                                : 'text-red-600 dark:text-red-400'
                                            }`}>
                                                {check.status}
                                            </span>
                                        </div>
                                        <p className="text-xs text-gray-600 dark:text-slate-400">{check.note}</p>
                                        {typeof check.value === 'string' && (
                                            <code className="text-xs font-mono bg-gray-100 dark:bg-slate-700 px-1.5 py-0.5 rounded mt-1 inline-block text-gray-700 dark:text-slate-300">
                                                {check.value}
                                            </code>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="mt-6 p-4 bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                            <div className="flex gap-2">
                                <ServerStackIcon className="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                                <div>
                                    <p className="text-xs font-semibold text-blue-800 dark:text-blue-200">Production Go-Live Requirements</p>
                                    <ul className="mt-1 text-xs text-blue-700 dark:text-blue-300 space-y-0.5 list-disc list-inside">
                                        <li>Set <code className="font-mono">SESSION_ENCRYPT=true</code> in production .env</li>
                                        <li>Set <code className="font-mono">DB_SSLMODE=require</code> in production .env</li>
                                        <li>Migrate documents to S3 with SSE-KMS enabled (DEBT-021)</li>
                                        <li>Rotate APP_KEY with a secure key and re-seed encrypted PHI fields</li>
                                        <li>Enable Redis AUTH (<code className="font-mono">REDIS_PASSWORD</code>) in production</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Modals */}
            {showBaaModal && (
                <BaaModal record={editBaa} vendorTypes={vendorTypes} statuses={baaStatuses}
                    onClose={() => setShowBaaModal(false)} />
            )}
            {showSraModal && (
                <SraModal record={editSra} riskLevels={sraRiskLevels} statuses={sraStatuses}
                    onClose={() => setShowSraModal(false)} />
            )}
        </AppShell>
    );
}
