// ─── Participant Profile Page ──────────────────────────────────────────────────
// Phase 3: Clinical Documentation Engine.
//
// Two-section tab navigation:
//   Overview (standalone) | CLINICAL: Chart · Vitals · Assessments · Problems · Allergies · ADL
//                         |    ADMIN: Contacts · Flags · Insurance · Documents · Audit
//
// Data loading strategy:
//   Pre-loaded (Inertia props): demographics, problems, allergies, vitals (last 100),
//                               icd10Codes (~200), noteTemplates (8 schemas)
//   Lazy (axios on first activation): clinical notes, assessments, ADL records + thresholds
//   Mutations: axios POST → optimistic update → router.reload({ only: [...] })
//
// Life-threatening allergy banner persists across all tabs (red bar under sticky header).
// CLINICAL tabs use blue active underline; ADMIN tabs use slate.
// ──────────────────────────────────────────────────────────────────────────────

import { Head, Link, router, usePage } from '@inertiajs/react'
import AppShell from '@/Layouts/AppShell'
import PhoneInput from '@/Components/PhoneInput'
import axios from 'axios'
import React, { useState, useEffect } from 'react'
import {
  LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, ReferenceLine,
} from 'recharts'
import {
  ExclamationTriangleIcon,
  DocumentIcon,
  PhotoIcon,
  DocumentTextIcon,
  PaperClipIcon,
  ClockIcon,
} from '@heroicons/react/24/outline'

// ─── Phase 2 Types ────────────────────────────────────────────────────────────

interface Flag {
  id:          number
  flag_type:   string
  description: string | null
  severity:    'low' | 'medium' | 'high' | 'critical'
  is_active:   boolean
  created_by:  { id: number; first_name: string; last_name: string } | null
  resolved_by: { id: number; first_name: string; last_name: string } | null
  resolved_at: string | null
  created_at:  string
}

interface Contact {
  id:                      number
  contact_type:            string
  first_name:              string
  last_name:               string
  relationship:            string | null
  phone_primary:           string | null
  phone_secondary:         string | null
  email:                   string | null
  is_legal_representative: boolean
  is_emergency_contact:    boolean
  priority_order:          number
  notes:                   string | null
}

interface Insurance {
  id:             number
  payer_type:     string
  member_id:      string | null
  plan_name:      string | null
  effective_date: string | null
  term_date:      string | null
  is_active:      boolean
}

interface Address {
  id:           number
  address_type: string
  street:       string
  unit:         string | null
  city:         string
  state:        string
  zip:          string
  is_primary:   boolean
}

interface AuditEntry {
  id:          number
  action:      string
  description: string | null
  user_id:     number | null
  created_at:  string
}

interface Participant {
  id:                       number
  mrn:                      string
  first_name:               string
  last_name:                string
  preferred_name:           string | null
  dob:                      string
  gender:                   string | null
  pronouns:                 string | null
  ssn_last_four:            string | null
  medicare_id:              string | null
  medicaid_id:              string | null
  pace_contract_id:         string | null
  h_number:                 string | null
  primary_language:         string
  interpreter_needed:       boolean
  interpreter_language:     string | null
  enrollment_status:        string
  enrollment_date:          string | null
  disenrollment_date:       string | null
  disenrollment_reason:     string | null
  nursing_facility_eligible:            boolean
  nf_certification_date:                string | null
  is_active:                            boolean
  // Phase 11B: Advance directive structured fields (42 CFR 460.96)
  advance_directive_status:             'has_directive' | 'declined_directive' | 'incapacitated_no_directive' | 'unknown' | null
  advance_directive_type:               'dnr' | 'polst' | 'living_will' | 'healthcare_proxy' | 'combined' | null
  advance_directive_reviewed_at:        string | null
  // W4-3: Demographics expansion
  photo_path:                           string | null
  race:                                 string | null
  ethnicity:                            string | null
  race_detail:                          string | null
  marital_status:                       string | null
  legal_representative_type:            string | null
  legal_representative_contact_id:      number | null
  religion:                             string | null
  veteran_status:                       string | null
  education_level:                      string | null
  site:                                 { id: number; name: string }
  tenant:                               { id: number; name: string }
  created_by:                           { id: number; first_name: string; last_name: string } | null
  created_at:                           string
}

// ─── Phase 3 Types ────────────────────────────────────────────────────────────

interface ClinicalNote {
  id:                  number
  note_type:           string
  status:              'draft' | 'signed' | 'amended'
  department:          string
  visit_type:          string
  visit_date:          string
  visit_time:          string | null
  subjective:          string | null
  objective:           string | null
  assessment:          string | null
  plan:                string | null
  content:             Record<string, unknown> | null
  is_late_entry:       boolean
  late_entry_reason:   string | null
  signed_at:           string | null
  authored_by_user_id: number
  author:              { id: number; first_name: string; last_name: string } | null
  site:                { id: number; name: string } | null
  created_at:          string
}

interface Vital {
  id:                    number
  recorded_at:           string
  bp_systolic:           number | null
  bp_diastolic:          number | null
  pulse:                 number | null
  temperature_f:         number | null
  respiratory_rate:      number | null
  o2_saturation:         number | null
  weight_lbs:            number | null
  height_in:             number | null
  pain_score:            number | null
  blood_glucose:         number | null
  blood_glucose_timing:  string | null
  bmi:                   number | null
  notes:                 string | null
  recorded_by:           { id: number; first_name: string; last_name: string } | null
}

interface Assessment {
  id:              number
  assessment_type: string
  score:           number | null
  completed_at:    string
  next_due_date:   string | null
  department:      string
  authored_by:     { id: number; first_name: string; last_name: string } | null
}

interface Problem {
  id:                   number
  icd10_code:           string
  icd10_description:    string
  category:             string | null
  status:               'active' | 'resolved' | 'chronic' | 'ruled_out'
  onset_date:           string | null
  is_primary_diagnosis: boolean
  notes:                string | null
  added_by:             { id: number; first_name: string; last_name: string } | null
}

interface Allergy {
  id:                   number
  allergy_type:         string
  allergen_name:        string
  reaction_description: string | null
  severity:             'mild' | 'moderate' | 'severe' | 'life_threatening' | 'intolerance'
  is_active:            boolean
  notes:                string | null
  verified_by:          { id: number; first_name: string; last_name: string } | null
}

interface AdlSummary {
  latest:     Record<string, { level: string; recorded_at: string } | null>
  history:    Array<{ adl_category: string; independence_level: string; recorded_at: string }>
  thresholds: Record<string, { alert_level: string; set_at: string } | null>
}

interface Icd10Code {
  code:        string
  description: string
  category:    string | null
}

// ─── Page Props ───────────────────────────────────────────────────────────────

interface Props {
  participant:  Participant
  addresses:    Address[]
  contacts:     Contact[]
  flags:        Flag[]
  insurances:   Insurance[]
  auditLogs:    AuditEntry[]
  canEdit:      boolean
  canDelete:    boolean
  canViewAudit: boolean
  // Phase 3 (pre-loaded via Inertia)
  problems:                    Problem[]
  allergies:                   Allergy[]
  lifeThreateningAllergyCount: number
  vitals:                      Vital[]
  icd10Codes:                  Icd10Code[]
  noteTemplates:               Record<string, { label: string; departments: string[] }>
  // W3-6: site transfer context
  hasMultipleSites:    boolean
  completedTransfers:  { effective_date: string; from_site_name: string | null; to_site_name: string | null }[]
}

// ─── Display Constants ────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
  enrolled:    'bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300',
  referred:    'bg-blue-100 dark:bg-blue-900/60 text-blue-800 dark:text-blue-300',
  intake:      'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-800 dark:text-indigo-300',
  pending:     'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300',
  disenrolled: 'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400',
  deceased:    'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-500',
}

const FLAG_SEVERITY_COLORS: Record<string, string> = {
  low:      'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
  medium:   'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300 border-yellow-200 dark:border-yellow-800',
  high:     'bg-orange-100 dark:bg-orange-950/60 text-orange-800 dark:text-orange-300 border-orange-200 dark:border-orange-800',
  critical: 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800',
}

const FLAG_LABELS: Record<string, string> = {
  wheelchair:               'Wheelchair',
  stretcher:                'Stretcher',
  oxygen:                   'Oxygen',
  behavioral:               'Behavioral',
  fall_risk:                'Fall Risk',
  wandering_risk:           'Wandering Risk',
  isolation:                'Isolation',
  dnr:                      'DNR',
  weight_bearing_restriction: 'Weight Bearing',
  dietary_restriction:      'Dietary',
  elopement_risk:           'Elopement Risk',
  hospice:                  'Hospice',
  other:                    'Other',
}

const PAYER_LABELS: Record<string, string> = {
  medicare_a: 'Medicare Part A',
  medicare_b: 'Medicare Part B',
  medicare_d: 'Medicare Part D',
  medicaid:   'Medicaid',
  other:      'Other',
}

const CONTACT_TYPE_COLORS: Record<string, string> = {
  emergency:   'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
  poa:         'bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300',
  next_of_kin: 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300',
  caregiver:   'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
  pcp:         'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300',
  specialist:  'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-600 dark:text-indigo-400',
  other:       'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400',
}

const NOTE_TYPE_LABELS: Record<string, string> = {
  soap:               'Primary Care SOAP',
  progress_nursing:   'Nursing Progress',
  therapy_pt:         'PT Therapy',
  therapy_ot:         'OT Therapy',
  therapy_st:         'ST Therapy',
  social_work:        'Social Work',
  behavioral_health:  'Behavioral Health',
  dietary:            'Dietary / Nutrition',
  home_visit:         'Home Visit',
  telehealth:         'Telehealth',
  idt_summary:        'IDT Summary',
  incident:           'Incident Report',
  addendum:           'Addendum',
}

const ADL_CATEGORIES = [
  'bathing','dressing','grooming','toileting','transferring',
  'ambulation','eating','continence','medication_management','communication',
]

const ADL_CATEGORY_LABELS: Record<string, string> = {
  bathing: 'Bathing', dressing: 'Dressing', grooming: 'Grooming',
  toileting: 'Toileting', transferring: 'Transferring', ambulation: 'Ambulation',
  eating: 'Eating', continence: 'Continence',
  medication_management: 'Medication Mgmt', communication: 'Communication',
}

const ADL_LEVEL_LABELS: Record<string, string> = {
  independent: 'Independent', supervision: 'Supervision',
  limited_assist: 'Limited Assist', extensive_assist: 'Extensive Assist',
  total_dependent: 'Total Dependent',
}

const ADL_LEVEL_COLORS: Record<string, string> = {
  independent:      'text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-950/60 border-green-200 dark:border-green-800',
  supervision:      'text-lime-700 dark:text-lime-300 bg-lime-50 dark:bg-lime-950/60 border-lime-200 dark:border-lime-800',
  limited_assist:   'text-yellow-700 dark:text-yellow-300 bg-yellow-50 dark:bg-yellow-950/60 border-yellow-200 dark:border-yellow-800',
  extensive_assist: 'text-orange-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-950/60 border-orange-200 dark:border-orange-800',
  total_dependent:  'text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800',
}

const ASSESSMENT_LABELS: Record<string, string> = {
  initial_comprehensive: 'Initial Comprehensive',
  adl_functional:        'ADL Functional',
  mmse_cognitive:        'MMSE Cognitive',
  phq9_depression:       'PHQ-9 Depression',
  gad7_anxiety:          'GAD-7 Anxiety',
  nutritional:           'Nutritional',
  fall_risk_morse:       'Fall Risk (Morse)',
  pain_scale:            'Pain Scale',
  annual_reassessment:   'Annual Reassessment',
  custom:                'Custom',
  braden_scale:          'Braden Scale (Pressure Injury Risk)',
  moca_cognitive:        'MoCA (Cognitive Assessment)',
  oral_health:           'Oral Health Screening (OHAT)',
}

const ALLERGY_TYPE_LABELS: Record<string, string> = {
  drug: 'Drug', food: 'Food', environmental: 'Environmental',
  dietary_restriction: 'Dietary Restriction', latex: 'Latex', contrast: 'Contrast',
}

const ALLERGY_SEVERITY_COLORS: Record<string, string> = {
  life_threatening: 'text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-950/60 border-red-300',
  severe:           'text-orange-700 dark:text-orange-300 bg-orange-50 dark:bg-orange-950/60 border-orange-200 dark:border-orange-800',
  moderate:         'text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800',
  mild:             'text-yellow-700 dark:text-yellow-300 bg-yellow-50 dark:bg-yellow-950/60 border-yellow-200 dark:border-yellow-800',
  intolerance:      'text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-950/60 border-blue-200 dark:border-blue-800',
}

// ─── Utility Helpers ──────────────────────────────────────────────────────────

// Laravel 11 serializes `date` cast fields as full ISO timestamps
// ("1942-04-01T00:00:00.000000Z"), not bare "YYYY-MM-DD" strings.
// Appending 'T12:00:00' to an ISO timestamp produces an unparseable string → "Invalid Date".
// This helper slices to the date portion first, then anchors at local noon to avoid
// UTC-midnight → previous-day timezone shifts.
function parseDate(val: string | null | undefined): Date | null {
  if (!val) return null
  return new Date(val.slice(0, 10) + 'T12:00:00')
}

function fmtDate(val: string | null | undefined, opts?: Intl.DateTimeFormatOptions): string {
  const d = parseDate(val)
  if (!d) return '-'
  return d.toLocaleDateString('en-US', opts ?? { month: 'short', day: 'numeric', year: 'numeric' })
}

function age(dob: string): number {
  const d   = parseDate(dob) ?? new Date()
  const now = new Date()
  let a = now.getFullYear() - d.getFullYear()
  if (now < new Date(now.getFullYear(), d.getMonth(), d.getDate())) a--
  return a
}

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

// ─── Shared UI Components ─────────────────────────────────────────────────────

function DemoField({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <dt className="text-xs font-medium text-gray-500 dark:text-slate-400 uppercase tracking-wide">{label}</dt>
      <dd className="mt-0.5 text-sm text-gray-900 dark:text-slate-100">{value || <span className="text-gray-300 dark:text-slate-600">-</span>}</dd>
    </div>
  )
}

function LoadingSpinner() {
  return (
    <div className="py-12 text-center text-gray-400 dark:text-slate-500 text-sm animate-pulse">
      Loading…
    </div>
  )
}

// ─── Sticky Participant Header ─────────────────────────────────────────────────
// Shown at the top of every tab. Displays avatar, name, MRN, DOB, flag chips,
// advance directive badge, and quick-access buttons.
function ParticipantHeader({ participant, activeFlags, canDelete, canEdit, onTabChange, onEdit }: {
  participant:  Participant
  activeFlags:  Flag[]
  canDelete:    boolean
  canEdit:      boolean
  onTabChange:  (tab: string) => void
  onEdit:       () => void
}) {
  const [deleting, setDeleting] = useState(false)

  const handleDelete = () => {
    if (!confirm(`Deactivate ${participant.mrn}? This cannot be undone from the UI.`)) return
    setDeleting(true)
    router.delete(`/participants/${participant.id}`)
  }

  return (
    <div className="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-6 py-4 sticky top-0 z-20 shadow-sm">
      <div className="flex items-start gap-4">
        {participant.photo_path ? (
          <img
            src={`/storage/${participant.photo_path}`}
            alt={`${participant.first_name} ${participant.last_name}`}
            className="w-14 h-14 rounded-full object-cover flex-shrink-0 border-2 border-gray-200 dark:border-slate-600"
          />
        ) : (
          <div className="w-14 h-14 rounded-full bg-gradient-to-br from-blue-400 to-indigo-500 flex items-center justify-center text-white text-xl font-bold flex-shrink-0">
            {participant.first_name[0]}{participant.last_name[0]}
          </div>
        )}

        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <h1 className="text-lg font-semibold text-gray-900 dark:text-slate-100">
              {participant.first_name} {participant.last_name}
              {participant.preferred_name && (
                <span className="text-gray-400 dark:text-slate-500 font-normal text-base ml-1">"{participant.preferred_name}"</span>
              )}
            </h1>
            <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLORS[participant.enrollment_status] ?? ''}`}>
              {participant.enrollment_status}
            </span>
          </div>

          <div className="flex items-center gap-3 mt-1 flex-wrap">
            <span className="font-mono text-xs bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 px-2 py-0.5 rounded">{participant.mrn}</span>
            <span className="text-xs text-gray-500 dark:text-slate-400">
              {fmtDate(participant.dob)}
              <span className="ml-1 text-gray-400 dark:text-slate-500">({age(participant.dob)} yrs)</span>
            </span>
            <span className="text-xs bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 px-2 py-0.5 rounded">{participant.site.name}</span>
          </div>

          {(activeFlags.length > 0 || participant.advance_directive_status) && (
            <div className="flex flex-wrap gap-1 mt-2">
              {activeFlags.map(f => (
                <span
                  key={f.id}
                  title={f.description ?? f.flag_type}
                  className={`inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium ${FLAG_SEVERITY_COLORS[f.severity]}`}
                >
                  {FLAG_LABELS[f.flag_type] ?? f.flag_type}
                </span>
              ))}
              {/* Advance directive badge — 42 CFR 460.96 */}
              {participant.advance_directive_type === 'dnr' && (
                <span className="inline-flex items-center px-2 py-0.5 rounded border text-xs font-bold bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 border-red-300 dark:border-red-700">
                  DNR
                </span>
              )}
              {participant.advance_directive_type === 'polst' && (
                <span className="inline-flex items-center px-2 py-0.5 rounded border text-xs font-bold bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300 border-amber-300 dark:border-amber-700">
                  POLST
                </span>
              )}
              {participant.advance_directive_status === 'has_directive' &&
               participant.advance_directive_type !== 'dnr' &&
               participant.advance_directive_type !== 'polst' && (
                <span className="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300 border-gray-300 dark:border-slate-600">
                  Advance Directive on File
                </span>
              )}
              {(participant.advance_directive_status === 'declined_directive' ||
                participant.advance_directive_status === 'unknown' ||
                participant.advance_directive_status === 'incapacitated_no_directive') && (
                <span className="inline-flex items-center px-2 py-0.5 rounded border text-xs font-medium bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400 border-gray-300 dark:border-slate-600">
                  No Directive
                </span>
              )}
            </div>
          )}
        </div>

        <div className="flex items-center gap-2 flex-shrink-0">
          <button
            onClick={() => onTabChange('careplan')}
            className="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
          >
            Care Plan
          </button>
          <Link href="/schedule" className="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
            Schedule
          </Link>
          {canEdit && (
            <button
              onClick={onEdit}
              data-testid="edit-participant-btn"
              className="text-xs px-3 py-1.5 border border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-colors"
            >
              Edit
            </button>
          )}
          {canDelete && (
            <button
              onClick={handleDelete}
              disabled={deleting}
              data-testid="deactivate-btn"
              className="text-xs px-3 py-1.5 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-50 transition-colors disabled:opacity-50"
            >
              {deleting ? 'Deactivating…' : 'Deactivate'}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

// ─── Life-Threatening Allergy Banner ──────────────────────────────────────────
// Persistent red alert bar shown below the header whenever a participant has
// one or more active life-threatening allergies. Clicking navigates to Allergies tab.
function LifeThreateningBanner({ count, onViewAllergies }: {
  count:            number
  onViewAllergies:  () => void
}) {
  return (
    <div
      role="alert"
      data-testid="lt-allergy-banner"
      className="bg-red-600 text-white px-6 py-2 flex items-center gap-3 text-sm"
    >
      <ExclamationTriangleIcon className="w-5 h-5 shrink-0" />
      <span className="font-semibold">ALLERGY ALERT:</span>
      <span>
        {count} life-threatening {count === 1 ? 'allergy' : 'allergies'} on file.
      </span>
      <button
        onClick={onViewAllergies}
        className="ml-auto text-xs underline hover:no-underline opacity-90 hover:opacity-100"
      >
        View Allergies →
      </button>
    </div>
  )
}

// ─── Overview Tab — PACE Facesheet ────────────────────────────────────────────
// Full clinical facesheet layout with print-to-PDF support.
// ─── Edit Participant Modal ───────────────────────────────────────────────────
// Full demographics + identity edit form. Visible to canEdit users (enrollment admin
// and it_admin). Sections are shown/hidden based on the user's department.
// Uses Inertia router.patch() — on success the page re-renders with fresh data.
function EditParticipantModal({ participant, contacts, onClose, department }: {
  participant: Participant
  contacts:    Contact[]
  onClose:     () => void
  department:  string
}) {
  const isEnrollmentOrAdmin = ['enrollment', 'it_admin'].includes(department)

  const [saving, setSaving] = React.useState(false)
  const [errors, setErrors] = React.useState<Record<string, string>>({})
  const [form, setForm] = React.useState({
    // Core identity (enrollment/it_admin only)
    first_name:        participant.first_name,
    last_name:         participant.last_name,
    preferred_name:    participant.preferred_name ?? '',
    dob:               participant.dob ? participant.dob.slice(0, 10) : '',
    gender:            participant.gender ?? '',
    pronouns:          participant.pronouns ?? '',
    // Enrollment info (enrollment/it_admin only)
    enrollment_status:          participant.enrollment_status,
    enrollment_date:            participant.enrollment_date ? participant.enrollment_date.slice(0, 10) : '',
    disenrollment_date:         participant.disenrollment_date ? participant.disenrollment_date.slice(0, 10) : '',
    disenrollment_reason:       participant.disenrollment_reason ?? '',
    nursing_facility_eligible:  participant.nursing_facility_eligible,
    nf_certification_date:      participant.nf_certification_date ? participant.nf_certification_date.slice(0, 10) : '',
    medicare_id:                participant.medicare_id ?? '',
    medicaid_id:                participant.medicaid_id ?? '',
    pace_contract_id:           participant.pace_contract_id ?? '',
    h_number:                   participant.h_number ?? '',
    ssn_last_four:              participant.ssn_last_four ?? '',
    // Language (all depts)
    primary_language:           participant.primary_language ?? '',
    interpreter_needed:         participant.interpreter_needed,
    interpreter_language:       participant.interpreter_language ?? '',
    // Advance directive (all depts)
    advance_directive_status:   participant.advance_directive_status ?? '',
    advance_directive_type:     participant.advance_directive_type ?? '',
    advance_directive_reviewed_at: participant.advance_directive_reviewed_at ? participant.advance_directive_reviewed_at.slice(0, 10) : '',
    // Demographics (all depts)
    race:                             participant.race ?? '',
    ethnicity:                        participant.ethnicity ?? '',
    race_detail:                      participant.race_detail ?? '',
    marital_status:                   participant.marital_status ?? '',
    legal_representative_type:        participant.legal_representative_type ?? '',
    legal_representative_contact_id:  participant.legal_representative_contact_id?.toString() ?? '',
    religion:                         participant.religion ?? '',
    veteran_status:                   participant.veteran_status ?? '',
    education_level:                  participant.education_level ?? '',
  })

  const field = (label: string, name: string, type = 'text', opts?: { placeholder?: string }) => (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">{label}</label>
      <input
        type={type}
        value={form[name as keyof typeof form] as string}
        onChange={e => setForm(f => ({ ...f, [name]: e.target.value }))}
        placeholder={opts?.placeholder}
        className="w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
      {errors[name] && <p className="text-red-600 dark:text-red-400 text-xs mt-0.5">{errors[name]}</p>}
    </div>
  )

  const select = (label: string, name: string, options: { value: string; label: string }[], nullable = true) => (
    <div>
      <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">{label}</label>
      <select
        value={form[name as keyof typeof form] as string}
        onChange={e => setForm(f => ({ ...f, [name]: e.target.value }))}
        className="w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        {nullable && <option value="">— not set —</option>}
        {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
      {errors[name] && <p className="text-red-600 dark:text-red-400 text-xs mt-0.5">{errors[name]}</p>}
    </div>
  )

  const sectionHead = (title: string) => (
    <h4 className="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest border-b border-gray-200 dark:border-slate-700 pb-1 mt-2">{title}</h4>
  )

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    setErrors({})

    // Build payload — omit empty strings for optional fields, convert types
    const payload: Record<string, unknown> = {
      primary_language:    form.primary_language || null,
      interpreter_needed:  form.interpreter_needed,
      interpreter_language: form.interpreter_needed ? (form.interpreter_language || null) : null,
      advance_directive_status:      form.advance_directive_status  || null,
      advance_directive_type:        form.advance_directive_type    || null,
      advance_directive_reviewed_at: form.advance_directive_reviewed_at || null,
      race:                form.race           || null,
      ethnicity:           form.ethnicity      || null,
      race_detail:         form.race_detail    || null,
      marital_status:      form.marital_status || null,
      legal_representative_type:        form.legal_representative_type       || null,
      legal_representative_contact_id:  form.legal_representative_contact_id
        ? parseInt(form.legal_representative_contact_id)
        : null,
      religion:        form.religion       || null,
      veteran_status:  form.veteran_status || null,
      education_level: form.education_level || null,
    }

    if (isEnrollmentOrAdmin) {
      Object.assign(payload, {
        first_name:        form.first_name,
        last_name:         form.last_name,
        preferred_name:    form.preferred_name    || null,
        dob:               form.dob               || null,
        gender:            form.gender            || null,
        pronouns:          form.pronouns          || null,
        enrollment_status: form.enrollment_status,
        enrollment_date:   form.enrollment_date   || null,
        disenrollment_date:   form.disenrollment_date   || null,
        disenrollment_reason: form.disenrollment_reason || null,
        nursing_facility_eligible: form.nursing_facility_eligible,
        nf_certification_date: form.nf_certification_date || null,
        medicare_id:      form.medicare_id      || null,
        medicaid_id:      form.medicaid_id      || null,
        pace_contract_id: form.pace_contract_id || null,
        h_number:         form.h_number         || null,
        ssn_last_four:    form.ssn_last_four    || null,
      })
    }

    router.patch(`/participants/${participant.id}`, payload, {
      onSuccess: () => { setSaving(false); onClose() },
      onError:   (errs) => { setSaving(false); setErrors(errs) },
    })
  }

  return (
    <div
      className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
      onClick={e => { if (e.target === e.currentTarget) onClose() }}
    >
      <div className="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700 shrink-0">
          <h2 className="text-base font-semibold text-gray-900 dark:text-slate-100">
            Edit Participant — {participant.first_name} {participant.last_name}
          </h2>
          <button
            type="button"
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600 dark:hover:text-slate-200 text-xl leading-none"
          >
            ✕
          </button>
        </div>

        {/* Scrollable form body */}
        <form onSubmit={handleSubmit} className="overflow-y-auto flex-1 px-6 py-4 space-y-4">

          {/* ── Core Identity — enrollment/it_admin only ───────────────────── */}
          {isEnrollmentOrAdmin && (
            <div className="space-y-3">
              {sectionHead('Identity')}
              <div className="grid grid-cols-2 gap-3">
                {field('First Name', 'first_name')}
                {field('Last Name', 'last_name')}
              </div>
              <div className="grid grid-cols-2 gap-3">
                {field('Preferred Name', 'preferred_name', 'text', { placeholder: 'Optional' })}
                {field('Date of Birth', 'dob', 'date')}
              </div>
              <div className="grid grid-cols-2 gap-3">
                {select('Gender', 'gender', [
                  { value: 'male',              label: 'Male' },
                  { value: 'female',            label: 'Female' },
                  { value: 'non_binary',        label: 'Non-binary' },
                  { value: 'prefer_not_to_say', label: 'Prefer not to say' },
                ])}
                {field('Pronouns', 'pronouns', 'text', { placeholder: 'e.g. she/her' })}
              </div>
            </div>
          )}

          {/* ── Enrollment Info — enrollment/it_admin only ─────────────────── */}
          {isEnrollmentOrAdmin && (
            <div className="space-y-3">
              {sectionHead('Enrollment')}
              <div className="grid grid-cols-2 gap-3">
                {select('Enrollment Status', 'enrollment_status', [
                  { value: 'referred',    label: 'Referred' },
                  { value: 'intake',      label: 'Intake' },
                  { value: 'pending',     label: 'Pending' },
                  { value: 'enrolled',    label: 'Enrolled' },
                  { value: 'disenrolled', label: 'Disenrolled' },
                  { value: 'deceased',    label: 'Deceased' },
                ], false)}
                {field('Enrollment Date', 'enrollment_date', 'date')}
              </div>
              <div className="grid grid-cols-2 gap-3">
                {field('Disenrollment Date', 'disenrollment_date', 'date')}
                {field('Disenrollment Reason', 'disenrollment_reason', 'text')}
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">NF Eligible</label>
                  <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300 cursor-pointer mt-1.5">
                    <input
                      type="checkbox"
                      checked={form.nursing_facility_eligible}
                      onChange={e => setForm(f => ({ ...f, nursing_facility_eligible: e.target.checked }))}
                      className="rounded border-gray-300"
                    />
                    Nursing Facility Eligible
                  </label>
                </div>
                {field('NF Certification Date', 'nf_certification_date', 'date')}
              </div>
              <div className="grid grid-cols-2 gap-3">
                {field('Medicare ID', 'medicare_id')}
                {field('Medicaid ID', 'medicaid_id')}
              </div>
              <div className="grid grid-cols-3 gap-3">
                {field('PACE Contract ID', 'pace_contract_id')}
                {field('H-Number', 'h_number')}
                {field('SSN Last 4', 'ssn_last_four', 'text', { placeholder: '####' })}
              </div>
            </div>
          )}

          {/* ── Language & Interpreter — all depts ────────────────────────── */}
          <div className="space-y-3">
            {sectionHead('Language & Interpreter')}
            <div className="grid grid-cols-2 gap-3">
              {field('Primary Language', 'primary_language')}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Interpreter Needed</label>
                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300 cursor-pointer mt-1.5">
                  <input
                    type="checkbox"
                    checked={form.interpreter_needed}
                    onChange={e => setForm(f => ({ ...f, interpreter_needed: e.target.checked }))}
                    className="rounded border-gray-300"
                  />
                  Interpreter needed
                </label>
              </div>
            </div>
            {form.interpreter_needed && field('Interpreter Language', 'interpreter_language')}
          </div>

          {/* ── Advance Directive — all depts ─────────────────────────────── */}
          <div className="space-y-3">
            {sectionHead('Advance Directive (42 CFR 460.96)')}
            <div className="grid grid-cols-2 gap-3">
              {select('Directive Status', 'advance_directive_status', [
                { value: 'has_directive',                 label: 'Has Directive' },
                { value: 'declined_directive',            label: 'Declined' },
                { value: 'incapacitated_no_directive',    label: 'Incapacitated — No Directive' },
                { value: 'unknown',                       label: 'Unknown' },
              ])}
              {select('Directive Type', 'advance_directive_type', [
                { value: 'dnr',              label: 'DNR' },
                { value: 'polst',            label: 'POLST' },
                { value: 'living_will',      label: 'Living Will' },
                { value: 'healthcare_proxy', label: 'Healthcare Proxy' },
                { value: 'combined',         label: 'Combined' },
              ])}
            </div>
            {field('Reviewed Date', 'advance_directive_reviewed_at', 'date')}
          </div>

          {/* ── Demographics — all depts ───────────────────────────────────── */}
          <div className="space-y-3">
            {sectionHead('Demographics')}
            <div className="grid grid-cols-2 gap-3">
              {select('Ethnicity (OMB Q1)', 'ethnicity', [
                { value: 'hispanic_latino',     label: 'Hispanic or Latino' },
                { value: 'not_hispanic_latino', label: 'Not Hispanic or Latino' },
                { value: 'unknown',             label: 'Unknown' },
                { value: 'declined',            label: 'Prefer not to answer' },
              ])}
              {select('Race (OMB Q2)', 'race', [
                { value: 'white',                              label: 'White' },
                { value: 'black_african_american',             label: 'Black or African American' },
                { value: 'asian',                              label: 'Asian' },
                { value: 'american_indian_alaska_native',      label: 'American Indian or Alaska Native' },
                { value: 'native_hawaiian_pacific_islander',   label: 'Native Hawaiian or Other Pacific Islander' },
                { value: 'multiracial',                        label: 'Multiracial' },
                { value: 'other',                              label: 'Other' },
                { value: 'unknown',                            label: 'Unknown' },
                { value: 'declined',                           label: 'Prefer not to answer' },
              ])}
            </div>
            {field('Race Detail (self-identified)', 'race_detail', 'text', { placeholder: 'Optional — e.g. Guatemalan, Filipino' })}
            <div className="grid grid-cols-2 gap-3">
              {select('Marital Status', 'marital_status', [
                { value: 'single',           label: 'Single' },
                { value: 'married',          label: 'Married' },
                { value: 'domestic_partner', label: 'Domestic Partner' },
                { value: 'divorced',         label: 'Divorced' },
                { value: 'widowed',          label: 'Widowed' },
                { value: 'separated',        label: 'Separated' },
                { value: 'unknown',          label: 'Unknown' },
              ])}
              {select('Veteran Status', 'veteran_status', [
                { value: 'not_veteran',      label: 'Not a Veteran' },
                { value: 'veteran_active',   label: 'Veteran (Active Benefits)' },
                { value: 'veteran_inactive', label: 'Veteran (Inactive)' },
                { value: 'unknown',          label: 'Unknown' },
              ])}
            </div>
            <div className="grid grid-cols-2 gap-3">
              {select('Education Level', 'education_level', [
                { value: 'less_than_high_school', label: 'Less than High School' },
                { value: 'high_school_ged',       label: 'High School / GED' },
                { value: 'some_college',          label: 'Some College' },
                { value: 'associates',            label: 'Associate Degree' },
                { value: 'bachelors',             label: "Bachelor's Degree" },
                { value: 'graduate',              label: 'Graduate Degree' },
                { value: 'unknown',               label: 'Unknown' },
              ])}
              {field('Religion', 'religion', 'text', { placeholder: 'Optional — patient-supplied' })}
            </div>
            <div className="grid grid-cols-2 gap-3">
              {select('Legal Representative Type', 'legal_representative_type', [
                { value: 'self',             label: 'Self' },
                { value: 'legal_guardian',   label: 'Legal Guardian' },
                { value: 'durable_poa',      label: 'Durable POA' },
                { value: 'healthcare_proxy', label: 'Healthcare Proxy' },
                { value: 'court_appointed',  label: 'Court-Appointed' },
                { value: 'other',            label: 'Other' },
              ])}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Legal Rep Contact</label>
                <select
                  value={form.legal_representative_contact_id}
                  onChange={e => setForm(f => ({ ...f, legal_representative_contact_id: e.target.value }))}
                  className="w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-1.5 text-sm bg-white dark:bg-slate-700 text-gray-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">— select from contacts —</option>
                  {contacts.map(c => (
                    <option key={c.id} value={c.id.toString()}>
                      {c.first_name} {c.last_name}{c.relationship ? ` (${c.relationship})` : ''}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>
        </form>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200 dark:border-slate-700 shrink-0">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 text-sm border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
          >
            Cancel
          </button>
          <button
            type="submit"
            form="edit-participant-form"
            onClick={handleSubmit}
            disabled={saving}
            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50"
          >
            {saving ? 'Saving…' : 'Save Changes'}
          </button>
        </div>
      </div>
    </div>
  )
}

// ─── Additional Demographics Section (collapsible, W4-3) ─────────────────────
// Shows veteran status, religion, education level, and legal representative.
// Collapsed by default — expands on click. Hidden during print to keep facesheet concise.
function AdditionalDemographicsSection({ participant, contacts }: { participant: Participant; contacts: Contact[] }) {
  const [open, setOpen] = React.useState(false)

  const legalRepContact = contacts.find(c => c.id === participant.legal_representative_contact_id)

  const VETERAN_LABELS: Record<string, string> = {
    veteran_active:   'Veteran (Active Benefits)',
    veteran_inactive: 'Veteran (Inactive)',
    not_veteran:      'Not a Veteran',
    unknown:          'Unknown',
  }
  const EDU_LABELS: Record<string, string> = {
    less_than_high_school: 'Less than High School',
    high_school_ged:       'High School / GED',
    some_college:          'Some College',
    associates:            'Associate Degree',
    bachelors:             "Bachelor's Degree",
    graduate:              'Graduate Degree',
    unknown:               'Unknown',
  }
  const LEGAL_REP_LABELS: Record<string, string> = {
    self:              'Self',
    legal_guardian:    'Legal Guardian',
    durable_poa:       'Durable POA',
    healthcare_proxy:  'Healthcare Proxy',
    court_appointed:   'Court-Appointed',
    other:             'Other',
  }

  return (
    <section id="facesheet-no-print">
      <button
        type="button"
        onClick={() => setOpen(o => !o)}
        className="w-full flex items-center justify-between text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5 hover:text-slate-700 dark:hover:text-slate-200 transition-colors"
      >
        <span>Additional Demographics</span>
        <ChevronIcon open={open} />
      </button>
      {open && (
        <div className="space-y-1 text-[12px]">
          {participant.veteran_status && (
            <div className="flex items-center gap-1.5">
              <span className="text-gray-500 dark:text-slate-400 shrink-0">Veteran:</span>
              <span className={`font-medium ${participant.veteran_status.startsWith('veteran') ? 'text-blue-700 dark:text-blue-300' : 'text-gray-800 dark:text-slate-200'}`}>
                {VETERAN_LABELS[participant.veteran_status] ?? participant.veteran_status}
              </span>
            </div>
          )}
          {participant.education_level && (
            <div className="flex items-center gap-1.5">
              <span className="text-gray-500 dark:text-slate-400 shrink-0">Education:</span>
              <span className="text-gray-800 dark:text-slate-200">{EDU_LABELS[participant.education_level] ?? participant.education_level}</span>
            </div>
          )}
          {participant.religion && (
            <div className="flex items-center gap-1.5">
              <span className="text-gray-500 dark:text-slate-400 shrink-0">Religion:</span>
              <span className="text-gray-800 dark:text-slate-200">{participant.religion}</span>
            </div>
          )}
          {participant.legal_representative_type && (
            <div className="flex items-start gap-1.5">
              <span className="text-gray-500 dark:text-slate-400 shrink-0">Legal Rep:</span>
              <div>
                <span className="text-gray-800 dark:text-slate-200">{LEGAL_REP_LABELS[participant.legal_representative_type] ?? participant.legal_representative_type}</span>
                {legalRepContact && (
                  <span className="text-gray-500 dark:text-slate-400 ml-1">
                    ({legalRepContact.first_name} {legalRepContact.last_name})
                  </span>
                )}
              </div>
            </div>
          )}
        </div>
      )}
    </section>
  )
}

// Small inline chevron icon for the collapsible section toggle
function ChevronIcon({ open }: { open: boolean }) {
  return (
    <svg
      className={`w-3 h-3 transition-transform ${open ? 'rotate-180' : ''}`}
      fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}
    >
      <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
    </svg>
  )
}

interface OverviewTabProps {
  participant:  Participant
  addresses:    Address[]
  contacts:     Contact[]
  flags:        Flag[]
  problems:     Problem[]
  allergies:    Allergy[]
  vitals:       Vital[]
  insurances:   Insurance[]
}

function OverviewTab({ participant, addresses, contacts, flags, problems, allergies, vitals, insurances }: OverviewTabProps) {
  const primary       = addresses.find(a => a.is_primary) ?? addresses[0]
  const emergencyContacts = contacts
    .filter(c => c.is_emergency_contact)
    .sort((a, b) => a.priority_order - b.priority_order)
  const activeAllergies  = allergies.filter(a => a.is_active)
  const lifeThreateningA = activeAllergies.filter(a => a.severity === 'life_threatening')
  const activeProblems   = problems.filter(p => p.status === 'active' || p.status === 'chronic')
  const activeFlags      = flags.filter(f => f.is_active)
  const latestVital      = vitals[0] ?? null
  const printDate        = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })

  // Inject @media print CSS that isolates the facesheet content.
  // zoom: 0.85 acts as a safety net to keep the sheet on one page even when
  // the participant has many diagnoses or contacts — without it the footer
  // clips onto page 2.
  useEffect(() => {
    const style = document.createElement('style')
    style.id = 'facesheet-print-style'
    style.innerHTML = `
      @media print {
        @page { margin: 8mm; size: letter portrait; }
        body * { visibility: hidden !important; }
        #facesheet-print, #facesheet-print * { visibility: visible !important; }
        #facesheet-print { position: absolute; top: 0; left: 0; width: 100%; zoom: 0.85; }
        #facesheet-no-print { display: none !important; }
      }
    `
    document.head.appendChild(style)
    return () => { document.getElementById('facesheet-print-style')?.remove() }
  }, [])

  const handlePrint = () => window.print()

  // ── Severity colour for allergy badges
  const allergySeverityColor = (sev: Allergy['severity']): string => {
    if (sev === 'life_threatening') return 'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 border border-red-300'
    if (sev === 'severe')           return 'bg-orange-100 dark:bg-orange-950/60 text-orange-800 dark:text-orange-300 border border-orange-200 dark:border-orange-800'
    if (sev === 'moderate')         return 'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-800'
    return 'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400 border border-gray-200 dark:border-slate-700'
  }

  // ── Category label for allergy type
  const allergyTypeLabel: Record<string, string> = {
    drug: 'Drug', food: 'Food', environmental: 'Environmental',
    dietary_restriction: 'Dietary', latex: 'Latex', contrast: 'Contrast',
  }

  // ── Classify flags for facesheet sections
  const TRANSPORT_FLAGS  = ['wheelchair','stretcher','oxygen','weight_bearing_restriction']
  const CLINICAL_FLAGS   = ['fall_risk','wandering_risk','elopement_risk','isolation','behavioral','dnr','hospice','other']
  const transportF = activeFlags.filter(f => TRANSPORT_FLAGS.includes(f.flag_type))
  const clinicalF  = activeFlags.filter(f => CLINICAL_FLAGS.includes(f.flag_type))

  return (
    <div>
      {/* ── Print button — hidden during print ──────────────────────────────── */}
      <div id="facesheet-no-print" className="flex justify-end mb-4">
        <button
          onClick={handlePrint}
          data-testid="facesheet-print-btn"
          className="inline-flex items-center gap-2 px-4 py-2 bg-slate-700 text-white text-sm font-medium rounded-lg hover:bg-slate-800 transition-colors"
        >
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="w-4 h-4">
            <polyline points="6 9 6 2 18 2 18 9" />
            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
            <rect x="6" y="14" width="12" height="8" />
          </svg>
          Print / Save PDF
        </button>
      </div>

      {/* ═══════════════════ FACESHEET ══════════════════════════════════════════ */}
      <div id="facesheet-print" className="bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-700 rounded-lg shadow-sm font-sans text-gray-900 dark:text-slate-100 text-[12px] leading-snug">

        {/* ── Header ────────────────────────────────────────────────────────── */}
        <div className="bg-slate-800 text-white px-4 py-2 rounded-t-lg flex items-center justify-between">
          <div className="flex items-center gap-3">
            {participant.photo_path && (
              <img
                src={`/storage/${participant.photo_path}`}
                alt={`${participant.first_name} ${participant.last_name}`}
                className="w-10 h-10 rounded-full object-cover border border-slate-600 shrink-0"
              />
            )}
            <div>
              <p className="text-[10px] font-medium text-slate-300 uppercase tracking-widest">{participant.tenant.name}</p>
              <p className="text-base font-bold tracking-tight">PACE Participant Facesheet</p>
            </div>
          </div>
          <div className="text-right text-[11px] text-slate-300">
            <p className="font-semibold text-white">CONFIDENTIAL · HIPAA PHI</p>
            <p className="mt-0.5">Printed: {printDate}</p>
          </div>
        </div>

        {/* ── Patient identity strip — two compact inline rows ──────────────── */}
        <div className="border-b border-gray-200 dark:border-slate-700 px-4 py-2 bg-slate-50 dark:bg-slate-900 space-y-1">
          {/* Row 1: name + core demographics */}
          <div className="flex flex-wrap items-baseline gap-x-6 gap-y-0.5">
            <span className="font-bold text-[14px] text-gray-900 dark:text-slate-100">
              {participant.last_name}, {participant.first_name}
              {participant.preferred_name && <span className="font-normal text-gray-500 dark:text-slate-400 text-[12px] ml-1.5">"{participant.preferred_name}"</span>}
            </span>
            <span className="text-gray-500 dark:text-slate-400">DOB: <span className="font-semibold text-gray-800 dark:text-slate-200">{fmtDate(participant.dob)}</span></span>
            <span className="text-gray-500 dark:text-slate-400">Age: <span className="font-semibold text-gray-800 dark:text-slate-200">{age(participant.dob)} yrs</span></span>
            <span className="text-gray-500 dark:text-slate-400">Gender: <span className="font-semibold text-gray-800 dark:text-slate-200">{participant.gender ?? '-'}</span></span>
            {participant.marital_status && participant.marital_status !== 'unknown' && (
              <span className="text-gray-500 dark:text-slate-400">Marital: <span className="font-semibold text-gray-800 dark:text-slate-200 capitalize">{participant.marital_status.replace('_', ' ')}</span></span>
            )}
            <span className="text-gray-500 dark:text-slate-400">Language: <span className="font-semibold text-gray-800 dark:text-slate-200">
              {participant.primary_language}
              {participant.interpreter_needed && <span className="font-normal text-amber-700 dark:text-amber-300 ml-1">(Interp.{participant.interpreter_language ? ` ${participant.interpreter_language}` : ''})</span>}
            </span></span>
            {participant.race && participant.race !== 'declined' && participant.race !== 'unknown' && (
              <span className="text-gray-500 dark:text-slate-400">Race: <span className="font-semibold text-gray-800 dark:text-slate-200">{participant.race_detail ?? participant.race.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</span></span>
            )}
            {participant.ethnicity && participant.ethnicity !== 'declined' && participant.ethnicity !== 'unknown' && (
              <span className="text-gray-500 dark:text-slate-400">Ethnicity: <span className="font-semibold text-gray-800 dark:text-slate-200">{participant.ethnicity === 'hispanic_latino' ? 'Hispanic/Latino' : 'Not Hispanic/Latino'}</span></span>
            )}
          </div>
          {/* Row 2: identifiers + enrollment info */}
          <div className="flex flex-wrap items-baseline gap-x-6 gap-y-0.5">
            <span className="text-gray-500 dark:text-slate-400">MRN: <span className="font-mono font-bold text-slate-700 dark:text-slate-300">{participant.mrn}</span></span>
            <span className="text-gray-500 dark:text-slate-400">Status: <span className="font-semibold text-gray-800 dark:text-slate-200 capitalize">{participant.enrollment_status}</span></span>
            <span className="text-gray-500 dark:text-slate-400">Site: <span className="font-semibold text-gray-800 dark:text-slate-200">{participant.site.name}</span></span>
            <span className="text-gray-500 dark:text-slate-400">NF Eligible: <span className="font-semibold text-gray-800 dark:text-slate-200">{participant.nursing_facility_eligible ? 'Yes' : 'No'}</span></span>
          </div>
        </div>

        {/* ── IDs strip ────────────────────────────────────────────────────── */}
        <div className="border-b border-gray-200 dark:border-slate-700 px-5 py-2 flex flex-wrap gap-x-8 gap-y-0.5 bg-white dark:bg-slate-800">
          {[
            { label: 'Medicare ID',   value: participant.medicare_id },
            { label: 'Medicaid ID',   value: participant.medicaid_id },
            { label: 'H-Number',      value: participant.h_number },
            { label: 'Contract ID',   value: participant.pace_contract_id },
            { label: 'SSN (last 4)',  value: participant.ssn_last_four ? `•••–••–${participant.ssn_last_four}` : null },
            { label: 'Enrolled',      value: fmtDate(participant.enrollment_date) !== '-' ? fmtDate(participant.enrollment_date) : null },
          ].map(({ label, value }) => value ? (
            <div key={label} className="flex items-center gap-1.5">
              <span className="text-[11px] text-gray-400 dark:text-slate-500 uppercase tracking-wide">{label}:</span>
              <span className="font-mono text-[12px] text-gray-800 dark:text-slate-200 font-medium">{value}</span>
            </div>
          ) : null)}
        </div>

        {/* ── 3-column body ────────────────────────────────────────────────── */}
        <div className="grid grid-cols-1 lg:grid-cols-3 divide-y lg:divide-y-0 lg:divide-x divide-gray-200 dark:divide-slate-700 px-0">

          {/* ── Col 1: Address + Emergency Contacts + Enrollment ────────────── */}
          <div className="p-3 space-y-3">
            {/* Address */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5">Home Address</h4>
              {primary ? (
                <div>
                  <p>{primary.street}{primary.unit ? `, ${primary.unit}` : ''}</p>
                  <p>{primary.city}, {primary.state} {primary.zip} <span className="text-gray-400 dark:text-slate-500 capitalize">({primary.address_type.replace('_', ' ')})</span></p>
                </div>
              ) : <p className="text-gray-400 dark:text-slate-500">No address on file</p>}
            </section>

            {/* Emergency Contacts */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5">Emergency Contacts</h4>
              {emergencyContacts.length === 0
                ? <p className="text-gray-400 dark:text-slate-500">None on file</p>
                : emergencyContacts.map((c, i) => (
                  <div key={c.id} className={`${i > 0 ? 'mt-1.5 pt-1.5 border-t border-gray-100 dark:border-slate-700/50' : ''}`}>
                    <p className="font-semibold leading-tight">{c.first_name} {c.last_name}
                      {c.relationship && <span className="font-normal text-gray-500 dark:text-slate-400"> · {c.relationship}</span>}
                      {c.is_legal_representative && <span className="ml-1 text-[10px] bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300 px-1 py-0.5 rounded font-medium">Legal Rep</span>}
                    </p>
                    <p className="text-gray-600 dark:text-slate-400">
                      {c.phone_primary}
                      {c.phone_secondary && <span className="ml-2 text-gray-400 dark:text-slate-500">{c.phone_secondary}</span>}
                    </p>
                  </div>
                ))
              }
            </section>

            {/* Enrollment dates */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5">Enrollment</h4>
              <div className="flex flex-wrap gap-x-5 gap-y-0.5">
                <span className="text-gray-500 dark:text-slate-400">Enrolled: <span className="font-medium text-gray-800 dark:text-slate-200">{fmtDate(participant.enrollment_date)}</span></span>
                {participant.nf_certification_date && (
                  <span className="text-gray-500 dark:text-slate-400">NF Cert: <span className="font-medium text-gray-800 dark:text-slate-200">{fmtDate(participant.nf_certification_date)}</span></span>
                )}
                {participant.disenrollment_date && (
                  <span className="text-gray-500 dark:text-slate-400">Disenrolled: <span className="font-medium text-gray-800 dark:text-slate-200">{fmtDate(participant.disenrollment_date)}</span></span>
                )}
              </div>
            </section>
          </div>

          {/* ── Col 2: Allergies + Active Diagnoses ─────────────────────────── */}
          <div className="p-3 space-y-3">
            {/* Allergies */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5">Allergies / Dietary Restrictions</h4>
              {activeAllergies.length === 0 ? (
                <span className="inline-block text-[12px] font-bold bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 border border-green-200 dark:border-green-800 px-2 py-1 rounded">NKDA: No Known Drug Allergies</span>
              ) : (
                <div className="space-y-1">
                  {lifeThreateningA.length > 0 && (
                    <div className="bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded p-1.5 mb-2">
                      <p className="text-[10px] font-bold text-red-700 dark:text-red-300 uppercase tracking-wide mb-1 flex items-center gap-0.5"><ExclamationTriangleIcon className="w-3 h-3" /> Life-Threatening</p>
                      {lifeThreateningA.map(a => (
                        <p key={a.id} className="text-[12px] font-semibold text-red-800 dark:text-red-300">
                          {a.allergen_name}
                          <span className="font-normal text-red-600 dark:text-red-400 ml-1">({allergyTypeLabel[a.allergy_type] ?? a.allergy_type})</span>
                          {a.reaction_description && <span className="font-normal text-red-600 dark:text-red-400">: {a.reaction_description}</span>}
                        </p>
                      ))}
                    </div>
                  )}
                  {activeAllergies
                    .filter(a => a.severity !== 'life_threatening')
                    .map(a => (
                      <div key={a.id} className="flex items-start gap-1.5">
                        <span className={`text-[10px] px-1 py-0.5 rounded font-medium shrink-0 ${allergySeverityColor(a.severity)}`}>
                          {a.severity === 'intolerance' ? 'Intol.' : a.severity.charAt(0).toUpperCase() + a.severity.slice(1)}
                        </span>
                        <span className="text-[12px]">
                          <span className="font-medium">{a.allergen_name}</span>
                          <span className="text-gray-500 dark:text-slate-400 ml-1">({allergyTypeLabel[a.allergy_type] ?? a.allergy_type})</span>
                          {a.reaction_description && <span className="text-gray-500 dark:text-slate-400">: {a.reaction_description}</span>}
                        </span>
                      </div>
                    ))
                  }
                </div>
              )}
            </section>

            {/* Active Diagnoses */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5">Active Diagnoses</h4>
              {activeProblems.length === 0
                ? <p className="text-gray-400 dark:text-slate-500 text-[12px]">None recorded</p>
                : (
                  <div className="space-y-1">
                    {activeProblems.map(p => (
                      <div key={p.id} className="flex items-start gap-1.5">
                        <span className="font-mono text-[11px] text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 px-1 py-0.5 rounded shrink-0">{p.icd10_code}</span>
                        <span className="text-[12px]">
                          {p.is_primary_diagnosis && <span className="text-blue-700 dark:text-blue-300 font-semibold">[Primary] </span>}
                          {p.icd10_description}
                          {p.status === 'chronic' && <span className="text-gray-400 dark:text-slate-500 ml-1 text-[11px]">(Chronic)</span>}
                        </span>
                      </div>
                    ))}
                  </div>
                )
              }
            </section>
          </div>

          {/* ── Col 3: Transport/Clinical Flags + Insurance + Latest Vitals ─── */}
          <div className="p-3 space-y-3">
            {/* Transport Needs */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5">Transport / Equipment Needs</h4>
              {transportF.length === 0
                ? <p className="text-gray-400 dark:text-slate-500 text-[12px]">None</p>
                : (
                  <div className="flex flex-wrap gap-1">
                    {transportF.map(f => (
                      <span key={f.id} className="text-[11px] bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800 px-1.5 py-0.5 rounded font-medium">
                        {FLAG_LABELS[f.flag_type] ?? f.flag_type}
                      </span>
                    ))}
                  </div>
                )
              }
            </section>

            {/* Clinical / Safety Flags */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5">Clinical / Safety Flags</h4>
              {clinicalF.length === 0
                ? <p className="text-gray-400 dark:text-slate-500 text-[12px]">None</p>
                : (
                  <div className="flex flex-wrap gap-1">
                    {clinicalF.map(f => (
                      <span key={f.id} className={`text-[11px] px-1.5 py-0.5 rounded font-medium border ${
                        f.flag_type === 'dnr'     ? 'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 border-red-200 dark:border-red-800' :
                        f.flag_type === 'hospice' ? 'bg-purple-100 dark:bg-purple-900/60 text-purple-800 dark:text-purple-300 border-purple-200 dark:border-purple-800' :
                        f.severity === 'critical' ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800' :
                        f.severity === 'high'     ? 'bg-orange-100 dark:bg-orange-950/60 text-orange-800 dark:text-orange-300 border-orange-200 dark:border-orange-800' :
                        'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700'
                      }`}>
                        {FLAG_LABELS[f.flag_type] ?? f.flag_type}
                      </span>
                    ))}
                  </div>
                )
              }
            </section>

            {/* Advance Directive — 42 CFR 460.96 Participant Rights */}
            {participant.advance_directive_status && participant.advance_directive_status !== 'unknown' && (
              <section>
                <h4 className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5">Advance Directive</h4>
                <div className="flex flex-wrap gap-1 items-center">
                  <span className={`text-[11px] px-1.5 py-0.5 rounded font-medium border ${
                    participant.advance_directive_status === 'has_directive'
                      ? 'bg-purple-100 dark:bg-purple-900/60 text-purple-800 dark:text-purple-300 border-purple-200 dark:border-purple-800'
                      : 'bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-200 dark:border-slate-700'
                  }`}>
                    {participant.advance_directive_status === 'has_directive'    ? 'Has Directive' :
                     participant.advance_directive_status === 'declined_directive' ? 'Declined' :
                     'Incapacitated: No Directive'}
                  </span>
                  {participant.advance_directive_type && (
                    <span className="text-[11px] px-1.5 py-0.5 rounded font-medium border bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700">
                      {participant.advance_directive_type === 'dnr'              ? 'DNR' :
                       participant.advance_directive_type === 'polst'            ? 'POLST' :
                       participant.advance_directive_type === 'living_will'      ? 'Living Will' :
                       participant.advance_directive_type === 'healthcare_proxy' ? 'Healthcare Proxy' :
                       'Combined'}
                    </span>
                  )}
                  {participant.advance_directive_reviewed_at && (
                    <span className="text-[10px] text-gray-400 dark:text-slate-500 ml-1">
                      Reviewed {fmtDate(participant.advance_directive_reviewed_at, { month: 'short', day: 'numeric', year: 'numeric' })}
                    </span>
                  )}
                </div>
              </section>
            )}

            {/* Additional Demographics — collapsible (W4-3) */}
            {(participant.veteran_status || participant.religion || participant.education_level || participant.legal_representative_type) && (
              <AdditionalDemographicsSection participant={participant} contacts={contacts} />
            )}

            {/* Insurance */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5">Insurance Coverages</h4>
              {insurances.filter(i => i.is_active).length === 0
                ? <p className="text-gray-400 dark:text-slate-500">None on file</p>
                : <div className="flex flex-wrap gap-x-4 gap-y-0.5">
                    {insurances.filter(i => i.is_active).map(ins => (
                      <span key={ins.id} className="font-medium">
                        {PAYER_LABELS[ins.payer_type] ?? ins.payer_type}
                        {ins.member_id && <span className="font-mono text-[11px] text-gray-500 dark:text-slate-400 ml-1">{ins.member_id}</span>}
                      </span>
                    ))}
                  </div>
              }
            </section>

            {/* Latest Vitals */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest mb-1 border-b border-gray-100 dark:border-slate-700/50 pb-0.5">
                Latest Vitals
                {latestVital && <span className="ml-2 font-normal text-gray-400 dark:text-slate-500 normal-case tracking-normal">{new Date(latestVital.recorded_at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })}</span>}
              </h4>
              {!latestVital
                ? <p className="text-gray-400 dark:text-slate-500">No vitals recorded</p>
                : (
                  <div className="flex flex-wrap gap-x-4 gap-y-0.5">
                    {latestVital.bp_systolic != null && latestVital.bp_diastolic != null && (
                      <span className="text-gray-500 dark:text-slate-400">BP: <span className="font-semibold text-gray-800 dark:text-slate-200">{latestVital.bp_systolic}/{latestVital.bp_diastolic}</span></span>
                    )}
                    {latestVital.pulse != null && (
                      <span className="text-gray-500 dark:text-slate-400">Pulse: <span className="font-semibold text-gray-800 dark:text-slate-200">{latestVital.pulse} bpm</span></span>
                    )}
                    {latestVital.o2_saturation != null && (
                      <span className="text-gray-500 dark:text-slate-400">O₂: <span className="font-semibold text-gray-800 dark:text-slate-200">{latestVital.o2_saturation}%</span></span>
                    )}
                    {latestVital.temperature_f != null && (
                      <span className="text-gray-500 dark:text-slate-400">Temp: <span className="font-semibold text-gray-800 dark:text-slate-200">{latestVital.temperature_f}°F</span></span>
                    )}
                    {latestVital.weight_lbs != null && (
                      <span className="text-gray-500 dark:text-slate-400">Wt: <span className="font-semibold text-gray-800 dark:text-slate-200">{latestVital.weight_lbs} lbs</span></span>
                    )}
                    {latestVital.pain_score != null && (
                      <span className="text-gray-500 dark:text-slate-400">Pain: <span className="font-semibold text-gray-800 dark:text-slate-200">{latestVital.pain_score}/10</span></span>
                    )}
                  </div>
                )
              }
            </section>
          </div>
        </div>

        {/* ── Footer ───────────────────────────────────────────────────────── */}
        <div className="border-t border-gray-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900 px-5 py-2 rounded-b-lg flex items-center justify-between text-[10px] text-gray-400 dark:text-slate-500">
          <p>This document contains Protected Health Information (PHI). Unauthorized disclosure is prohibited by HIPAA (45 CFR §164.502).</p>
          <p className="shrink-0 ml-4">MRN: <span className="font-mono text-gray-600 dark:text-slate-400">{participant.mrn}</span> · {printDate}</p>
        </div>
      </div>
    </div>
  )
}

// ─── CLINICAL TABS ────────────────────────────────────────────────────────────

// ─── Note Card ────────────────────────────────────────────────────────────────
// Expandable card for a single clinical note. Shown inside ChartTab.
// Draft notes display a Sign button; signed notes are read-only.
function NoteCard({ note, participantId, onSign, signingId, onAddendum, showSiteBadge, currentUserId }: {
  note:           ClinicalNote
  participantId:  number
  onSign:         (id: number) => void
  signingId:      number | null
  onAddendum:     (parentNoteId: number) => void
  showSiteBadge?: boolean
  currentUserId?: number
}) {
  const [expanded, setExpanded] = useState(false)

  const preview = note.note_type === 'soap'
    ? [note.subjective, note.objective, note.assessment, note.plan].filter(Boolean).join(' · ').slice(0, 160)
    : ((note.content?.notes as string) ?? '').slice(0, 160)

  return (
    <div data-testid={`note-${note.id}`} className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3">
      <div className="flex items-start justify-between gap-2">
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-sm font-medium text-gray-900 dark:text-slate-100">
              {NOTE_TYPE_LABELS[note.note_type] ?? note.note_type}
            </span>
            <span className={`text-xs px-1.5 py-0.5 rounded-full font-medium ${
              note.status === 'signed'  ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' :
              note.status === 'amended' ? 'bg-indigo-100 dark:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300' :
                                          'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-700 dark:text-yellow-300'
            }`}>
              {note.status}
            </span>
            {note.is_late_entry && (
              <span className="text-xs bg-orange-100 dark:bg-orange-950/60 text-orange-600 dark:text-orange-400 px-1.5 py-0.5 rounded-full">Late Entry</span>
            )}
            {showSiteBadge && note.site && (
              <span className="text-xs bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 px-1.5 py-0.5 rounded-full">{note.site.name}</span>
            )}
          </div>
          <div className="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
            {new Date(note.visit_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
            {note.author && ` · ${note.author.first_name} ${note.author.last_name}`}
          </div>
          {!expanded && preview && (
            <p className="text-xs text-gray-500 dark:text-slate-400 mt-1.5 line-clamp-2">{preview}{preview.length === 160 ? '…' : ''}</p>
          )}
        </div>

        <div className="flex items-center gap-1 flex-shrink-0">
          {note.status === 'draft' && currentUserId === note.authored_by_user_id && (
            <button
              onClick={() => onSign(note.id)}
              disabled={signingId === note.id}
              data-testid={`sign-note-${note.id}`}
              className="text-xs px-2.5 py-1 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 transition-colors"
            >
              {signingId === note.id ? 'Signing…' : 'Sign'}
            </button>
          )}
          <button
            onClick={() => setExpanded(v => !v)}
            className="text-xs px-2.5 py-1 border border-gray-200 dark:border-slate-600 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
          >
            {expanded ? 'Collapse' : 'Expand'}
          </button>
        </div>
      </div>

      {/* Expanded note content */}
      {expanded && (
        <div className="mt-3 pt-3 border-t border-gray-100 dark:border-slate-700/50">
          {note.note_type === 'soap' ? (
            <div className="grid grid-cols-2 gap-4">
              {([['Subjective (S)', note.subjective], ['Objective (O)', note.objective],
                 ['Assessment (A)', note.assessment], ['Plan (P)', note.plan]] as [string, string|null][])
                .filter(([, val]) => val)
                .map(([label, val]) => (
                  <div key={label}>
                    <dt className="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">{label}</dt>
                    <dd className="mt-1 text-xs text-gray-800 dark:text-slate-200 whitespace-pre-wrap">{val}</dd>
                  </div>
                ))
              }
            </div>
          ) : (
            <p className="text-xs text-gray-700 dark:text-slate-300 whitespace-pre-wrap">
              {(note.content?.notes as string) ?? ''}
            </p>
          )}
          {note.signed_at && (
            <div className="mt-3 pt-2 border-t border-gray-100 dark:border-slate-700/50 flex items-center justify-between">
              <p className="text-xs text-gray-400 dark:text-slate-500">
                Signed {new Date(note.signed_at).toLocaleString('en-US')}
              </p>
              <button
                onClick={() => onAddendum(note.id)}
                data-testid={`addendum-note-${note.id}`}
                className="text-xs px-2.5 py-1 border border-blue-300 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-50 transition-colors"
              >
                + Addendum
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

// ─── Chart Tab (Clinical Notes) ───────────────────────────────────────────────
// Lazy-loaded from GET /participants/{id}/notes on first activation.
// New Note form supports SOAP (4 structured fields) and all other template types (notes textarea).
// Draft notes can be signed; signed notes are immutable.
function ChartTab({ participantId, noteTemplates, hasMultipleSites }: {
  participantId:    number
  noteTemplates:    Record<string, { label: string; departments: string[] }>
  hasMultipleSites: boolean
}) {
  const [notes, setNotes]         = useState<ClinicalNote[] | null>(null)
  const [loading, setLoading]     = useState(false)
  const [error, setError]         = useState<string | null>(null)
  const [showForm, setShowForm]   = useState(false)
  const [saving, setSaving]       = useState(false)
  const [signingId, setSigningId]         = useState<number | null>(null)
  const [statusFilter, setStatusFilter]   = useState<string>('all')
  const [addendumParentId, setAddendumParentId] = useState<number | null>(null)

  const blankNote = {
    note_type: 'soap', visit_type: 'in_center', visit_date: today(),
    subjective: '', objective: '', assessment_text: '', plan: '',
    content_notes: '', is_late_entry: false, late_entry_reason: '',
  }
  const [form, setForm] = useState(blankNote)

  // Load once on first tab activation; skip if already loaded (data !== null)
  useEffect(() => {
    if (notes !== null) return
    setLoading(true)
    axios.get(`/participants/${participantId}/notes`, { params: { per_page: 50 } })
      .then(r => setNotes(r.data.data ?? r.data))
      .catch(() => setError('Failed to load notes. Please refresh.'))
      .finally(() => setLoading(false))
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const { auth } = usePage<{ auth: { user: { id: number; department: string } } }>().props
  const handleCreateNote = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      const payload: Record<string, unknown> = {
        note_type:         form.note_type,
        visit_type:        form.visit_type,
        visit_date:        form.visit_date,
        department:        auth.user.department,
        is_late_entry:     form.is_late_entry,
        late_entry_reason: form.is_late_entry ? form.late_entry_reason : null,
      }
      if (form.note_type === 'soap') {
        payload.subjective = form.subjective
        payload.objective  = form.objective
        payload.assessment = form.assessment_text
        payload.plan       = form.plan
      } else {
        payload.content = { notes: form.content_notes }
      }
      const { data } = await axios.post(`/participants/${participantId}/notes`, payload)
      setNotes(n => [data, ...(n ?? [])])
      setShowForm(false)
      setForm(blankNote)
    } catch {
      // leave form open for retry
    } finally {
      setSaving(false)
    }
  }

  const handleSign = async (noteId: number) => {
    setSigningId(noteId)
    setError(null)
    try {
      const { data } = await axios.post(`/participants/${participantId}/notes/${noteId}/sign`)
      setNotes(n => n!.map(note => note.id === noteId ? { ...note, ...data } : note))
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setError(msg ?? 'Failed to sign note. Please try again.')
    } finally {
      setSigningId(null)
    }
  }

  const handleAddendum = async (parentNoteId: number) => {
    setAddendumParentId(parentNoteId)
    setSaving(true)
    try {
      const { data } = await axios.post(`/participants/${participantId}/notes/${parentNoteId}/addendum`, {
        note_type:   'addendum',
        visit_type:  'in_center',
        visit_date:  today(),
        department:  auth.user.department,
        content:     { notes: '' },
      })
      setNotes(n => [data, ...(n ?? [])])
    } catch {
      // addendum failed; no-op
    } finally {
      setSaving(false)
      setAddendumParentId(null)
    }
  }

  if (loading) return <LoadingSpinner />
  if (error)   return <div className="py-8 text-center text-red-500 text-sm">{error}</div>
  if (!notes)  return null

  const filtered = statusFilter === 'all' ? notes : notes.filter(n => n.status === statusFilter)

  return (
    <div>
      {/* Header: title + status filter chips + new note button */}
      <div className="flex items-center justify-between mb-4 gap-4 flex-wrap">
        <div className="flex items-center gap-3">
          <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Clinical Notes ({notes.length})</h3>
          <div className="flex gap-1">
            {(['all', 'draft', 'signed'] as const).map(s => (
              <button
                key={s}
                onClick={() => setStatusFilter(s)}
                className={`text-xs px-2 py-0.5 rounded-full border transition-colors ${
                  statusFilter === s
                    ? 'bg-blue-600 text-white border-blue-600'
                    : 'text-gray-500 dark:text-slate-400 border-gray-200 dark:border-slate-700 hover:border-gray-300 dark:hover:border-slate-600'
                }`}
              >
                {s === 'all' ? 'All' : s.charAt(0).toUpperCase() + s.slice(1)}
              </button>
            ))}
          </div>
        </div>
        <button
          onClick={() => setShowForm(v => !v)}
          data-testid="add-note-btn"
          className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          {showForm ? 'Cancel' : '+ New Note'}
        </button>
      </div>

      {/* Note composer — inline form with template-driven fields */}
      {showForm && (
        <form
          onSubmit={handleCreateNote}
          data-testid="note-form"
          className="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4 space-y-3"
        >
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Note Type</label>
              <select
                value={form.note_type}
                onChange={e => setForm(f => ({ ...f, note_type: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
              >
                {Object.entries(noteTemplates).map(([key, tmpl]) => (
                  <option key={key} value={key}>{tmpl.label}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Visit Type</label>
              <select
                value={form.visit_type}
                onChange={e => setForm(f => ({ ...f, visit_type: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
              >
                {['in_center','home_visit','telehealth','phone'].map(t => (
                  <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Visit Date</label>
              <input
                type="date"
                value={form.visit_date}
                max={today()}
                onChange={e => setForm(f => ({ ...f, visit_date: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
              />
            </div>
          </div>

          {/* SOAP: four structured fields; all other types: single notes textarea */}
          {form.note_type === 'soap' ? (
            <div className="grid grid-cols-2 gap-3">
              {([
                ['subjective',    'Subjective (S)'],
                ['objective',     'Objective (O)'],
                ['assessment_text','Assessment (A)'],
                ['plan',          'Plan (P)'],
              ] as [keyof typeof form, string][]).map(([field, label]) => (
                <div key={field}>
                  <label className="text-xs font-medium text-gray-600 dark:text-slate-400">{label}</label>
                  <textarea
                    rows={3}
                    value={form[field] as string}
                    onChange={e => setForm(f => ({ ...f, [field]: e.target.value }))}
                    placeholder={`Enter ${label}…`}
                    className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 resize-none"
                  />
                </div>
              ))}
            </div>
          ) : (
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">
                Notes: {noteTemplates[form.note_type]?.label ?? form.note_type}
              </label>
              <textarea
                rows={5}
                value={form.content_notes}
                onChange={e => setForm(f => ({ ...f, content_notes: e.target.value }))}
                placeholder="Enter clinical notes…"
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 resize-none"
              />
            </div>
          )}

          {/* Late entry toggle */}
          <div className="flex items-center gap-3 flex-wrap">
            <label className="flex items-center gap-1.5 text-xs text-gray-600 dark:text-slate-400">
              <input
                type="checkbox"
                checked={form.is_late_entry}
                onChange={e => setForm(f => ({ ...f, is_late_entry: e.target.checked }))}
              />
              Late entry
            </label>
            {form.is_late_entry && (
              <input
                type="text"
                value={form.late_entry_reason}
                onChange={e => setForm(f => ({ ...f, late_entry_reason: e.target.value }))}
                placeholder="Reason for late entry"
                className="flex-1 text-xs border border-gray-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-800"
              />
            )}
          </div>

          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => { setShowForm(false); setForm(blankNote) }}
              className="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 dark:text-slate-300"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving}
              data-testid="save-note-btn"
              className="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
            >
              {saving ? 'Saving…' : 'Save as Draft'}
            </button>
          </div>
        </form>
      )}

      {/* Notes list */}
      <div className="space-y-2">
        {filtered.length === 0 && (
          <p className="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">
            {statusFilter === 'all' ? 'No notes on file.' : `No ${statusFilter} notes.`}
          </p>
        )}
        {filtered.map(note => (
          <NoteCard
            key={note.id}
            note={note}
            participantId={participantId}
            onSign={handleSign}
            signingId={signingId}
            onAddendum={handleAddendum}
            showSiteBadge={hasMultipleSites}
            currentUserId={auth.user.id}
          />
        ))}
      </div>
    </div>
  )
}

// ─── Vitals Tab ───────────────────────────────────────────────────────────────
// Pre-loaded vitals (last 100). Recharts line chart for BP trend (last 30 readings).
// Out-of-range values highlighted: BP systolic > 180 → red, O2 < 92 → red.
function VitalsTab({ participantId, initialVitals, completedTransfers }: {
  participantId:      number
  initialVitals:      Vital[]
  completedTransfers: { effective_date: string; from_site_name: string | null; to_site_name: string | null }[]
}) {
  const [vitals, setVitals]    = useState<Vital[]>(initialVitals)
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving]    = useState(false)
  const [activeChart, setActiveChart] = useState<string>('bp')

  // ── Chart configuration per vital sign ──────────────────────────────────────
  const CHART_CONFIGS: Record<string, {
    label: string
    lines: { key: string; color: string; name: string }[]
    unit: string
    domain?: [number, number]
  }> = {
    bp:               { label: 'Blood Pressure',    lines: [{ key: 'sys', color: '#dc2626', name: 'Systolic' }, { key: 'dia', color: '#ea580c', name: 'Diastolic' }], unit: 'mmHg', domain: [40, 220] },
    pulse:            { label: 'Pulse',             lines: [{ key: 'pulse', color: '#2563eb', name: 'Pulse' }],              unit: 'bpm',  domain: [30, 160] },
    temperature_f:    { label: 'Temperature',       lines: [{ key: 'temperature_f', color: '#d97706', name: 'Temp' }],      unit: '°F',   domain: [94, 106] },
    respiratory_rate: { label: 'Respiratory Rate',  lines: [{ key: 'respiratory_rate', color: '#059669', name: 'RR' }],     unit: '/min', domain: [8, 40] },
    o2_saturation:    { label: 'O₂ Saturation',     lines: [{ key: 'o2_saturation', color: '#7c3aed', name: 'O₂ Sat' }],   unit: '%',    domain: [80, 100] },
    weight_lbs:       { label: 'Weight',            lines: [{ key: 'weight_lbs', color: '#64748b', name: 'Weight' }],       unit: 'lbs' },
    pain_score:       { label: 'Pain Score',        lines: [{ key: 'pain_score', color: '#be123c', name: 'Pain' }],         unit: '/10',  domain: [0, 10] },
    blood_glucose:    { label: 'Blood Glucose',     lines: [{ key: 'blood_glucose', color: '#0891b2', name: 'Glucose' }],  unit: 'mg/dL', domain: [40, 400] },
  }

  // Table column headers — those with a chartKey become clickable buttons
  const TABLE_HEADERS: { label: string; chartKey?: string }[] = [
    { label: 'Date / Time' },
    { label: 'BP',      chartKey: 'bp' },
    { label: 'Pulse',   chartKey: 'pulse' },
    { label: 'Temp',    chartKey: 'temperature_f' },
    { label: 'RR',      chartKey: 'respiratory_rate' },
    { label: 'O₂%',     chartKey: 'o2_saturation' },
    { label: 'Weight',  chartKey: 'weight_lbs' },
    { label: 'BMI' },
    { label: 'Glucose', chartKey: 'blood_glucose' },
    { label: 'Pain',    chartKey: 'pain_score' },
  ]

  const blankForm = {
    bp_systolic: '', bp_diastolic: '', pulse: '', temperature_f: '',
    respiratory_rate: '', o2_saturation: '', weight_lbs: '', height_in: '',
    pain_score: '', blood_glucose: '', blood_glucose_timing: '', notes: '',
  }

  // BMI color coding: <18.5 amber, 18.5–24.9 green, 25–29.9 amber, ≥30 red
  const bmiColor = (bmi: number | null): string => {
    if (bmi === null) return 'text-gray-500 dark:text-slate-400'
    if (bmi < 18.5) return 'text-amber-600 dark:text-amber-400 font-semibold'
    if (bmi < 25)   return 'text-green-600 dark:text-green-400'
    if (bmi < 30)   return 'text-amber-600 dark:text-amber-400 font-semibold'
    return 'text-red-600 dark:text-red-400 font-semibold'
  }

  const GLUCOSE_TIMING_LABELS: Record<string, string> = {
    fasting:      'Fasting',
    post_meal_2h: '2h Post-meal',
    random:       'Random',
    pre_meal:     'Pre-meal',
  }
  const [form, setForm] = useState(blankForm)

  // Keep in sync if Inertia refreshes props after a router.reload
  useEffect(() => { setVitals(initialVitals) }, [initialVitals])

  // Recharts: last 30 records, chronological order (oldest first), all fields
  const chartData = [...vitals].slice(0, 30).reverse().map(v => ({
    date:             new Date(v.recorded_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
    sys:              v.bp_systolic,
    dia:              v.bp_diastolic,
    pulse:            v.pulse,
    temperature_f:    v.temperature_f,
    respiratory_rate: v.respiratory_rate,
    o2_saturation:    v.o2_saturation,
    weight_lbs:       v.weight_lbs,
    pain_score:       v.pain_score,
    blood_glucose:    v.blood_glucose,
  }))

  const handleRecord = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      const stringFields = new Set(['notes', 'blood_glucose_timing'])
      const payload = Object.fromEntries(
        Object.entries(form).map(([k, v]) => [k, v === '' ? null : (stringFields.has(k) ? v : Number(v))])
      )
      const { data } = await axios.post(`/participants/${participantId}/vitals`, payload)
      setVitals(v => [data, ...v])
      setShowForm(false)
      setForm(blankForm)
      router.reload({ only: ['vitals'] })
    } catch {
      // form stays open for retry
    } finally {
      setSaving(false)
    }
  }

  const highSys = (v: number | null) => v !== null && v > 180
  const lowO2   = (v: number | null) => v !== null && v < 92

  return (
    <div className="space-y-6">
      {/* Header + record button */}
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Vital Signs ({vitals.length} records)</h3>
        <button
          onClick={() => setShowForm(v => !v)}
          data-testid="add-vitals-btn"
          className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          {showForm ? 'Cancel' : '+ Record Vitals'}
        </button>
      </div>

      {/* Record vitals form */}
      {showForm && (
        <form
          onSubmit={handleRecord}
          data-testid="vitals-form"
          className="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 grid grid-cols-4 gap-3"
        >
          {([
            ['bp_systolic',     'BP Systolic',  'mmHg', '1'],
            ['bp_diastolic',    'BP Diastolic', 'mmHg', '1'],
            ['pulse',           'Pulse',        'bpm',  '1'],
            ['temperature_f',   'Temp',         '°F',   '0.1'],
            ['respiratory_rate','Resp Rate',    '/min', '1'],
            ['o2_saturation',   'O₂ Sat',       '%',    '1'],
            ['weight_lbs',      'Weight',       'lbs',  '0.1'],
            ['height_in',       'Height',       'in',   '0.1'],
            ['blood_glucose',   'Blood Glucose','mg/dL','1'],
            ['pain_score',      'Pain',         '0–10', '1'],
          ] as [string, string, string, string][]).map(([field, label, unit, step]) => (
            <div key={field}>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">{label} <span className="text-gray-400 dark:text-slate-500 font-normal">({unit})</span></label>
              <input
                type="number"
                step={step}
                value={form[field as keyof typeof form] as string}
                onChange={e => setForm(f => ({ ...f, [field]: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
              />
            </div>
          ))}
          {/* Blood glucose timing — contextual selector (QW-02) */}
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Glucose Timing</label>
            <select
              value={form.blood_glucose_timing}
              onChange={e => setForm(f => ({ ...f, blood_glucose_timing: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            >
              <option value="">-- select --</option>
              <option value="fasting">Fasting</option>
              <option value="pre_meal">Pre-meal</option>
              <option value="post_meal_2h">2h Post-meal</option>
              <option value="random">Random</option>
            </select>
          </div>
          <div className="col-span-4">
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Notes (optional)</label>
            <input
              type="text"
              value={form.notes}
              onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            />
          </div>
          <div className="col-span-4 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700">Cancel</button>
            <button type="submit" disabled={saving} data-testid="save-vitals-btn"
              className="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Record Vitals'}
            </button>
          </div>
        </form>
      )}

      {/* Vital sign trend chart — driven by activeChart (set by clicking table headers) */}
      {chartData.length > 1 && (() => {
        const cfg = CHART_CONFIGS[activeChart]
        const primaryKey = cfg.lines[0].key
        const hasData = chartData.filter(d => d[primaryKey as keyof typeof d] !== null).length > 1
        if (!hasData) return (
          <div className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-4 text-center text-sm text-gray-400 dark:text-slate-500 py-10">
            No {cfg.label} data recorded yet. Click a column header below to switch views.
          </div>
        )
        return (
          <div className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-4">
            <h4 className="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-3">
              {cfg.label} Trend <span className="font-normal text-gray-400 dark:text-slate-500">(last 30 readings · click column headers to switch)</span>
            </h4>
            <ResponsiveContainer width="100%" height={180}>
              <LineChart data={chartData} margin={{ top: 4, right: 8, bottom: 4, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="date" tick={{ fontSize: 10 }} />
                <YAxis
                  domain={cfg.domain ?? ['auto', 'auto']}
                  tick={{ fontSize: 10 }}
                  unit={` ${cfg.unit}`}
                  width={72}
                />
                <Tooltip
                  formatter={(val: number, name: string) => {
                    const lineCfg = cfg.lines.find(l => l.key === name)
                    return [`${val} ${cfg.unit}`, lineCfg?.name ?? name]
                  }}
                />
                {cfg.lines.map(l => (
                  <Line key={l.key} type="monotone" dataKey={l.key} stroke={l.color} dot={false} strokeWidth={2} name={l.key} />
                ))}
                {/* W3-6: vertical dashed line at each completed transfer date */}
                {completedTransfers.map((t, i) => {
                  const label = new Date(t.effective_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
                  return (
                    <ReferenceLine
                      key={i}
                      x={label}
                      stroke="#f59e0b"
                      strokeDasharray="4 3"
                      label={{ value: `Transfer: ${t.to_site_name ?? ''}`, position: 'top', fontSize: 9, fill: '#f59e0b' }}
                    />
                  )
                })}
              </LineChart>
            </ResponsiveContainer>
          </div>
        )
      })()}

      {/* Vitals table (most recent 20) */}
      <div className="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-slate-700/50">
            <tr>
              {TABLE_HEADERS.map(h => (
                <th key={h.label} className="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide whitespace-nowrap">
                  {h.chartKey ? (
                    <button
                      onClick={() => setActiveChart(h.chartKey!)}
                      className={`transition-colors ${
                        activeChart === h.chartKey
                          ? 'text-blue-600 dark:text-blue-400 border-b-2 border-blue-500 pb-0.5'
                          : 'text-gray-400 dark:text-slate-500 hover:text-gray-700 dark:hover:text-slate-300'
                      }`}
                    >
                      {h.label}
                    </button>
                  ) : (
                    <span className="text-gray-500 dark:text-slate-400">{h.label}</span>
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
            {vitals.length === 0 && (
              <tr><td colSpan={10} className="px-4 py-6 text-center text-gray-400 dark:text-slate-500 text-sm">No vitals recorded.</td></tr>
            )}
            {vitals.slice(0, 20).map(v => (
              <tr key={v.id} className="bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700/50">
                <td className="px-3 py-2 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">
                  {new Date(v.recorded_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
                </td>
                <td className={`px-3 py-2 text-xs font-mono ${highSys(v.bp_systolic) ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-slate-300'}`}>
                  {v.bp_systolic != null ? `${v.bp_systolic}/${v.bp_diastolic}` : '-'}
                </td>
                <td className="px-3 py-2 text-xs text-gray-700 dark:text-slate-300">{v.pulse ?? '-'}</td>
                <td className="px-3 py-2 text-xs text-gray-700 dark:text-slate-300">{v.temperature_f != null ? `${v.temperature_f}°` : '-'}</td>
                <td className="px-3 py-2 text-xs text-gray-700 dark:text-slate-300">{v.respiratory_rate ?? '-'}</td>
                <td className={`px-3 py-2 text-xs font-mono ${lowO2(v.o2_saturation) ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-slate-300'}`}>
                  {v.o2_saturation != null ? `${v.o2_saturation}%` : '-'}
                </td>
                <td className="px-3 py-2 text-xs text-gray-700 dark:text-slate-300">{v.weight_lbs != null ? `${v.weight_lbs} lbs` : '-'}</td>
                {/* BMI — QW-01: color-coded by clinical category */}
                <td className={`px-3 py-2 text-xs font-mono ${bmiColor(v.bmi)}`}>
                  {v.bmi != null ? v.bmi : '-'}
                </td>
                {/* Blood glucose with timing label — QW-02 */}
                <td className="px-3 py-2 text-xs text-gray-700 dark:text-slate-300">
                  {v.blood_glucose != null ? (
                    <span>
                      {v.blood_glucose}
                      {v.blood_glucose_timing && (
                        <span className="ml-1 text-gray-400 dark:text-slate-500">
                          ({GLUCOSE_TIMING_LABELS[v.blood_glucose_timing] ?? v.blood_glucose_timing})
                        </span>
                      )}
                    </span>
                  ) : '-'}
                </td>
                <td className="px-3 py-2 text-xs text-gray-700 dark:text-slate-300">{v.pain_score ?? '-'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ─── Assessments Tab ──────────────────────────────────────────────────────────
// Lazy-loaded. Shows scored structured assessments (PHQ-9, Morse, MMSE, etc.)
// with overdue / due-soon indicators and a simple new-assessment form.
// Subscale definitions are module-level constants (no re-creation on render)
const BRADEN_SUBSCALES = [
  { key: 'sensory_perception', label: 'Sensory Perception', max: 4 },
  { key: 'moisture',           label: 'Moisture',           max: 4 },
  { key: 'activity',           label: 'Activity',           max: 4 },
  { key: 'mobility',           label: 'Mobility',           max: 4 },
  { key: 'nutrition',          label: 'Nutrition',          max: 4 },
  { key: 'friction_shear',     label: 'Friction & Shear',   max: 3 },
]
const MOCA_SUBSCALES = [
  { key: 'visuospatial',   label: 'Visuospatial / Executive', max: 5 },
  { key: 'naming',         label: 'Naming',                   max: 3 },
  { key: 'attention',      label: 'Attention',                max: 6 },
  { key: 'language',       label: 'Language',                 max: 3 },
  { key: 'abstraction',    label: 'Abstraction',              max: 2 },
  { key: 'delayed_recall', label: 'Delayed Recall',           max: 5 },
  { key: 'orientation',    label: 'Orientation',              max: 6 },
]
const OHAT_SUBSCALES = [
  { key: 'lips',         label: 'Lips',           max: 2 },
  { key: 'tongue',       label: 'Tongue',         max: 2 },
  { key: 'gums_tissues', label: 'Gums / Tissues', max: 2 },
  { key: 'saliva',       label: 'Saliva',         max: 2 },
  { key: 'natural_teeth',label: 'Natural Teeth',  max: 2 },
  { key: 'dentures',     label: 'Dentures',       max: 2 },
  { key: 'oral_hygiene', label: 'Oral Hygiene',   max: 2 },
  { key: 'dental_pain',  label: 'Dental Pain',    max: 2 },
]
const isStructuredType = (t: string) => ['braden_scale', 'moca_cognitive', 'oral_health'].includes(t)
const subscaleDefinitions = (type: string) =>
  type === 'braden_scale'  ? BRADEN_SUBSCALES :
  type === 'moca_cognitive' ? MOCA_SUBSCALES :
  type === 'oral_health'   ? OHAT_SUBSCALES : []

function AssessmentsTab({ participantId }: { participantId: number }) {
  const [assessments, setAssessments] = useState<Assessment[] | null>(null)
  const [loading, setLoading]         = useState(false)
  const [error, setError]             = useState<string | null>(null)
  const [showForm, setShowForm]       = useState(false)
  const [saving, setSaving]           = useState(false)
  // Must be declared at top level — useState cannot be called after conditional returns
  const [subscales, setSubscales]         = useState<Record<string, string>>({})
  const [educationBonus, setEducationBonus] = useState(false)

  const blankForm = {
    assessment_type: 'phq9_depression', score: '', completed_at: today(), next_due_date: '', notes: '',
  }
  const [form, setForm] = useState(blankForm)

  const computedSubscaleScore = (type: string): number => {
    const defs = subscaleDefinitions(type)
    const total = defs.reduce((sum, s) => sum + (parseInt(subscales[s.key] ?? '0') || 0), 0)
    if (type === 'moca_cognitive' && educationBonus) return Math.min(total + 1, 30)
    return total
  }

  // Load once on first activation
  useEffect(() => {
    if (assessments !== null) return
    setLoading(true)
    axios.get(`/participants/${participantId}/assessments`)
      .then(r => setAssessments(r.data.data ?? r.data))
      .catch(() => setError('Failed to load assessments.'))
      .finally(() => setLoading(false))
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      const structured = isStructuredType(form.assessment_type)
      const finalScore = structured ? computedSubscaleScore(form.assessment_type) : (form.score === '' ? null : Number(form.score))
      const responses  = structured
        ? { ...subscales, education_bonus: educationBonus, notes: form.notes }
        : { notes: form.notes }

      const { data } = await axios.post(`/participants/${participantId}/assessments`, {
        assessment_type: form.assessment_type,
        score:           finalScore,
        completed_at:    form.completed_at,
        next_due_date:   form.next_due_date || null,
        responses,
      })
      setAssessments(a => [data, ...(a ?? [])])
      setShowForm(false)
      setForm(blankForm)
      setSubscales({})
      setEducationBonus(false)
    } catch {
      // form stays open
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <LoadingSpinner />
  if (error)   return <div className="py-8 text-center text-red-500 text-sm">{error}</div>
  if (!assessments) return null

  const now = new Date()

  // QW-05: due-date alert banners — overdue (red) and due-within-14-days (amber)
  const overdueAssessments = assessments.filter(a => a.next_due_date && new Date(a.next_due_date.slice(0, 10)) < now)
  const dueSoonAssessments = assessments.filter(a => {
    if (!a.next_due_date) return false
    const d = new Date(a.next_due_date.slice(0, 10))
    return d >= now && d <= new Date(Date.now() + 14 * 86400000)
  })

  return (
    <div>
      {/* QW-05: Assessment due-date alert banners */}
      {overdueAssessments.length > 0 && (
        <div className="mb-4 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded-lg px-4 py-3">
          <p className="text-xs font-semibold text-red-700 dark:text-red-300 mb-1">Overdue Assessments ({overdueAssessments.length})</p>
          <ul className="text-xs text-red-600 dark:text-red-400 space-y-0.5">
            {overdueAssessments.map(a => (
              <li key={a.id}>
                {ASSESSMENT_LABELS[a.assessment_type] ?? a.assessment_type} - due {new Date(a.next_due_date!.slice(0, 10)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
              </li>
            ))}
          </ul>
        </div>
      )}
      {dueSoonAssessments.length > 0 && (
        <div className="mb-4 bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 rounded-lg px-4 py-3">
          <p className="text-xs font-semibold text-amber-700 dark:text-amber-300 mb-1">Due Within 14 Days ({dueSoonAssessments.length})</p>
          <ul className="text-xs text-amber-600 dark:text-amber-400 space-y-0.5">
            {dueSoonAssessments.map(a => (
              <li key={a.id}>
                {ASSESSMENT_LABELS[a.assessment_type] ?? a.assessment_type} - due {new Date(a.next_due_date!.slice(0, 10)).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Assessments ({assessments.length})</h3>
        <button
          onClick={() => setShowForm(v => !v)}
          data-testid="add-assessment-btn"
          className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          {showForm ? 'Cancel' : '+ New Assessment'}
        </button>
      </div>

      {/* New assessment form */}
      {showForm && (
        <form
          onSubmit={handleCreate}
          data-testid="assessment-form"
          className="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4 grid grid-cols-2 gap-3"
        >
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Type</label>
            <select
              value={form.assessment_type}
              onChange={e => setForm(f => ({ ...f, assessment_type: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            >
              {Object.entries(ASSESSMENT_LABELS).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
          </div>
          {/* Score: auto-computed for structured types, manual for all others */}
          {isStructuredType(form.assessment_type) ? (
            <div className="col-span-2 bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-800 rounded p-3">
              <p className="text-xs font-semibold text-indigo-700 dark:text-indigo-300 mb-2">
                Subscale Scores - Total: {computedSubscaleScore(form.assessment_type)}
              </p>
              <div className="grid grid-cols-3 gap-2">
                {subscaleDefinitions(form.assessment_type).map(s => (
                  <div key={s.key}>
                    <label className="text-xs text-gray-600 dark:text-slate-400">{s.label} (0-{s.max})</label>
                    <input
                      type="number" min={0} max={s.max}
                      value={subscales[s.key] ?? ''}
                      onChange={e => setSubscales(sc => ({ ...sc, [s.key]: e.target.value }))}
                      className="w-full mt-0.5 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-800"
                    />
                  </div>
                ))}
              </div>
              {form.assessment_type === 'moca_cognitive' && (
                <label className="flex items-center gap-2 mt-2 text-xs text-gray-600 dark:text-slate-400">
                  <input type="checkbox" checked={educationBonus} onChange={e => setEducationBonus(e.target.checked)} />
                  Add +1 education bonus (12 years or fewer of formal education)
                </label>
              )}
            </div>
          ) : (
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Score (optional)</label>
              <input
                type="number"
                value={form.score}
                onChange={e => setForm(f => ({ ...f, score: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
              />
            </div>
          )}
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Completed Date *</label>
            <input
              required type="date" value={form.completed_at} max={today()}
              onChange={e => setForm(f => ({ ...f, completed_at: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Next Due Date</label>
            <input
              type="date" value={form.next_due_date} min={today()}
              onChange={e => setForm(f => ({ ...f, next_due_date: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            />
          </div>
          <div className="col-span-2">
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Notes / Findings</label>
            <textarea
              rows={3} value={form.notes}
              onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 resize-none"
            />
          </div>
          <div className="col-span-2 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700">Cancel</button>
            <button type="submit" disabled={saving} data-testid="save-assessment-btn"
              className="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Save Assessment'}
            </button>
          </div>
        </form>
      )}

      {/* Assessment list */}
      <div className="space-y-2">
        {assessments.length === 0 && (
          <p className="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">No assessments on file.</p>
        )}
        {assessments.map(a => {
          const dueDate  = a.next_due_date ? new Date(a.next_due_date) : null
          const overdue  = dueDate && dueDate < now
          const dueSoon  = !overdue && dueDate && dueDate <= new Date(Date.now() + 14 * 86400000)
          return (
            <div key={a.id} data-testid={`assessment-${a.id}`}
              className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3 flex items-start justify-between gap-3">
              <div>
                <div className="flex items-center gap-2">
                  <span className="text-sm font-medium text-gray-900 dark:text-slate-100">
                    {ASSESSMENT_LABELS[a.assessment_type] ?? a.assessment_type}
                  </span>
                  {a.score != null && (
                    <span className="text-xs bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 px-1.5 py-0.5 rounded">Score: {a.score}</span>
                  )}
                </div>
                <div className="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                  Completed {new Date(a.completed_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                  {a.authored_by && ` · ${a.authored_by.first_name} ${a.authored_by.last_name}`}
                </div>
              </div>
              {dueDate && (
                <span className={`text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0 ${
                  overdue  ? 'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300' :
                  dueSoon  ? 'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-700 dark:text-yellow-300' :
                             'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'
                }`}>
                  {overdue ? 'OVERDUE' : dueSoon ? 'Due Soon' : 'Due'}{' '}
                  {dueDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                </span>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

// ─── Problems Tab ─────────────────────────────────────────────────────────────
// Pre-loaded. Grouped by status (active, chronic, resolved, ruled_out).
// ICD-10 typeahead filters ~200 pre-loaded codes client-side as user types.
function ProblemsTab({ participantId, initialProblems, icd10Codes }: {
  participantId:   number
  initialProblems: Problem[]
  icd10Codes:      Icd10Code[]
}) {
  const [problems, setProblems]     = useState<Problem[]>(initialProblems)
  const [showForm, setShowForm]     = useState(false)
  const [saving, setSaving]         = useState(false)
  const [icd10Query, setIcd10Query] = useState('')
  const [selectedCode, setSelectedCode] = useState<Icd10Code | null>(null)
  const [showSuggestions, setShowSuggestions] = useState(false)

  useEffect(() => { setProblems(initialProblems) }, [initialProblems])

  // Client-side ICD-10 typeahead — exact code prefix first, then description match
  const suggestions = icd10Query.length >= 2
    ? icd10Codes
        .filter(c =>
          c.code.toUpperCase().startsWith(icd10Query.toUpperCase()) ||
          c.description.toLowerCase().includes(icd10Query.toLowerCase())
        )
        .slice(0, 15)
    : []

  const blankForm = { status: 'active', onset_date: '', is_primary_diagnosis: false, notes: '' }
  const [form, setForm] = useState(blankForm)
  const [saveError, setSaveError] = useState<string | null>(null)

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedCode) return
    setSaving(true)
    setSaveError(null)
    try {
      const { data } = await axios.post(`/participants/${participantId}/problems`, {
        icd10_code:           selectedCode.code,
        icd10_description:    selectedCode.description,
        category:             selectedCode.category,
        status:               form.status,
        onset_date:           form.onset_date || null,
        is_primary_diagnosis: form.is_primary_diagnosis,
        notes:                form.notes || null,
      })
      setProblems(p => [data, ...p])
      setShowForm(false)
      setSelectedCode(null)
      setIcd10Query('')
      setForm(blankForm)
      setSaveError(null)
      router.reload({ only: ['problems'] })
    } catch {
      setSaveError('Failed to save problem. Please try again.')
    } finally {
      setSaving(false)
    }
  }

  const STATUS_COLORS: Record<string, string> = {
    active:   'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
    chronic:  'bg-orange-100 dark:bg-orange-950/60 text-orange-700 dark:text-orange-300',
    resolved: 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    ruled_out:'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400',
  }

  const STATUS_DOT: Record<string, string> = {
    active: 'bg-red-500', chronic: 'bg-orange-500', resolved: 'bg-green-500', ruled_out: 'bg-gray-400',
  }

  // Group problems by status in display order
  const statusOrder = ['active', 'chronic', 'resolved', 'ruled_out'] as const
  const grouped = Object.fromEntries(statusOrder.map(s => [s, problems.filter(p => p.status === s)]))

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Diagnoses ({problems.length})</h3>
        <button
          onClick={() => { setShowForm(v => !v); setSaveError(null) }}
          data-testid="add-problem-btn"
          className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          {showForm ? 'Cancel' : '+ Add Diagnosis'}
        </button>
      </div>

      {/* Add problem form with ICD-10 typeahead */}
      {showForm && (
        <form
          onSubmit={handleCreate}
          data-testid="problem-form"
          className="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4 space-y-3"
        >
          {/* ICD-10 typeahead */}
          <div className="relative">
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">ICD-10 Code / Diagnosis *</label>
            {selectedCode ? (
              <div className="mt-1 flex items-center gap-2 bg-white dark:bg-slate-800 border border-blue-300 dark:border-blue-700 rounded px-3 py-2">
                <span className="font-mono text-sm text-blue-700 dark:text-blue-300">{selectedCode.code}</span>
                <span className="text-sm text-gray-700 dark:text-slate-300 flex-1">{selectedCode.description}</span>
                <button
                  type="button"
                  onClick={() => { setSelectedCode(null); setIcd10Query('') }}
                  className="text-xs text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300"
                >✕</button>
              </div>
            ) : (
              <div className="relative">
                <input
                  type="text"
                  value={icd10Query}
                  onChange={e => { setIcd10Query(e.target.value); setShowSuggestions(true) }}
                  onFocus={() => setShowSuggestions(true)}
                  onBlur={() => setTimeout(() => setShowSuggestions(false), 200)}
                  placeholder="Search by code or description (e.g. I10 or hypertension)…"
                  data-testid="icd10-search"
                  className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-3 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
                />
                {showSuggestions && suggestions.length > 0 && (
                  <ul className="absolute z-10 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto w-full">
                    {suggestions.map(c => (
                      <li key={c.code}>
                        <button
                          type="button"
                          onMouseDown={() => { setSelectedCode(c); setIcd10Query(c.code); setShowSuggestions(false) }}
                          className="w-full text-left px-3 py-2 hover:bg-blue-50 dark:hover:bg-slate-700 text-sm flex items-start gap-2"
                        >
                          <span className="font-mono text-blue-600 dark:text-blue-400 flex-shrink-0">{c.code}</span>
                          <span className="text-gray-700 dark:text-slate-300 flex-1">{c.description}</span>
                          {c.category && <span className="text-xs text-gray-400 dark:text-slate-500 flex-shrink-0">{c.category}</span>}
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}
          </div>

          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Status</label>
              <select
                value={form.status}
                onChange={e => setForm(f => ({ ...f, status: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
              >
                {statusOrder.map(s => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Onset Date</label>
              <input
                type="date" value={form.onset_date} max={today()}
                onChange={e => setForm(f => ({ ...f, onset_date: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100 dark:[color-scheme:dark]"
              />
            </div>
            <div className="flex items-end pb-1.5">
              <label className="flex items-center gap-2 text-xs text-gray-600 dark:text-slate-400 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.is_primary_diagnosis}
                  onChange={e => setForm(f => ({ ...f, is_primary_diagnosis: e.target.checked }))}
                />
                Primary diagnosis
              </label>
            </div>
          </div>

          {saveError && (
            <p className="text-xs text-red-600 dark:text-red-400">{saveError}</p>
          )}
          <div className="flex justify-end gap-2">
            <button type="button"
              onClick={() => { setShowForm(false); setSelectedCode(null); setIcd10Query(''); setForm(blankForm); setSaveError(null) }}
              className="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700">Cancel</button>
            <button type="submit" disabled={saving || !selectedCode} data-testid="save-problem-btn"
              className="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Save Diagnosis'}
            </button>
          </div>
        </form>
      )}

      {/* Problem list grouped by status */}
      {statusOrder.map(status => {
        const group = grouped[status] ?? []
        if (group.length === 0) return null
        return (
          <div key={status} className="mb-5">
            <h4 className="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-2 flex items-center gap-2">
              <span className={`inline-block w-2 h-2 rounded-full ${STATUS_DOT[status]}`} />
              {status.replace('_', ' ')} ({group.length})
            </h4>
            <div className="space-y-1.5">
              {group.map(p => (
                <div
                  key={p.id}
                  data-testid={`problem-${p.id}`}
                  className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-2.5 flex items-center gap-3"
                >
                  <span className="font-mono text-sm text-blue-600 dark:text-blue-400 flex-shrink-0">{p.icd10_code}</span>
                  <span className="text-sm text-gray-800 dark:text-slate-200 flex-1">{p.icd10_description}</span>
                  {p.is_primary_diagnosis && (
                    <span className="text-xs bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 px-1.5 py-0.5 rounded flex-shrink-0">Primary</span>
                  )}
                  <span className={`text-xs px-1.5 py-0.5 rounded font-medium flex-shrink-0 ${STATUS_COLORS[p.status]}`}>
                    {p.status.replace('_', ' ')}
                  </span>
                </div>
              ))}
            </div>
          </div>
        )
      })}

      {problems.length === 0 && (
        <p className="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">No problems on file.</p>
      )}
    </div>
  )
}

// ─── Allergies Tab ─────────────────────────────────────────────────────────────
// Pre-loaded. Life-threatening allergies shown in a red highlight block at top.
// Remaining allergies grouped by type. Add allergy form with severity selector.
function AllergiesTab({ participantId, initialAllergies }: {
  participantId:    number
  initialAllergies: Allergy[]
}) {
  const [allergies, setAllergies] = useState<Allergy[]>(initialAllergies)
  const [showForm, setShowForm]   = useState(false)
  const [saving, setSaving]       = useState(false)

  useEffect(() => { setAllergies(initialAllergies) }, [initialAllergies])

  const blankForm = {
    allergy_type: 'drug', allergen_name: '', reaction_description: '', severity: 'moderate', notes: '',
  }
  const [form, setForm] = useState(blankForm)

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      const { data } = await axios.post(`/participants/${participantId}/allergies`, {
        ...form, is_active: true,
      })
      setAllergies(a => [data, ...a])
      setShowForm(false)
      setForm(blankForm)
      // Reload to update lifeThreateningAllergyCount in Props (used by the banner)
      router.reload({ only: ['allergies', 'lifeThreateningAllergyCount'] })
    } catch {
      // form stays open
    } finally {
      setSaving(false)
    }
  }

  const lifeThreats = allergies.filter(a => a.severity === 'life_threatening' && a.is_active)

  // Group by allergy_type for display
  const byType: Record<string, Allergy[]> = {}
  for (const a of allergies) {
    if (!byType[a.allergy_type]) byType[a.allergy_type] = []
    byType[a.allergy_type].push(a)
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Allergies & Restrictions ({allergies.length})</h3>
        <button
          onClick={() => setShowForm(v => !v)}
          data-testid="add-allergy-btn"
          className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          {showForm ? 'Cancel' : '+ Add Allergy'}
        </button>
      </div>

      {/* Life-threatening allergies highlighted block */}
      {lifeThreats.length > 0 && (
        <div className="bg-red-50 dark:bg-red-950/60 border border-red-300 dark:border-red-800 rounded-lg px-4 py-3 mb-4" data-testid="lt-allergy-list">
          <h4 className="text-sm font-semibold text-red-700 dark:text-red-300 mb-2 flex items-center gap-1"><ExclamationTriangleIcon className="w-4 h-4" /> Life-Threatening Allergies</h4>
          <div className="space-y-1">
            {lifeThreats.map(a => (
              <div key={a.id} className="flex items-center gap-2 text-sm text-red-800 dark:text-red-300">
                <span className="font-semibold">{a.allergen_name}</span>
                {a.reaction_description && (
                  <span className="text-red-600 dark:text-red-400">{a.reaction_description}</span>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Add allergy form */}
      {showForm && (
        <form
          onSubmit={handleCreate}
          data-testid="allergy-form"
          className="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4 grid grid-cols-2 gap-3"
        >
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Type</label>
            <select
              value={form.allergy_type}
              onChange={e => setForm(f => ({ ...f, allergy_type: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            >
              {Object.entries(ALLERGY_TYPE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Allergen / Item *</label>
            <input
              required value={form.allergen_name}
              onChange={e => setForm(f => ({ ...f, allergen_name: e.target.value }))}
              placeholder="e.g. Penicillin, Shellfish, Low sodium diet"
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Severity</label>
            <select
              value={form.severity}
              onChange={e => setForm(f => ({ ...f, severity: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            >
              {Object.keys(ALLERGY_SEVERITY_COLORS).map(s => (
                <option key={s} value={s}>{s.replace('_', ' ')}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Reaction</label>
            <input
              value={form.reaction_description}
              onChange={e => setForm(f => ({ ...f, reaction_description: e.target.value }))}
              placeholder="e.g. Anaphylaxis, Rash, GI upset"
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            />
          </div>
          <div className="col-span-2 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700">Cancel</button>
            <button type="submit" disabled={saving} data-testid="save-allergy-btn"
              className="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Add Allergy'}
            </button>
          </div>
        </form>
      )}

      {/* Allergies grouped by type */}
      {Object.entries(byType).map(([type, items]) => (
        <div key={type} className="mb-4">
          <h4 className="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-2">
            {ALLERGY_TYPE_LABELS[type] ?? type} ({items.length})
          </h4>
          <div className="space-y-1.5">
            {items.map(a => (
              <div
                key={a.id}
                data-testid={`allergy-${a.id}`}
                className={`border rounded-lg px-4 py-2.5 flex items-center gap-3 ${ALLERGY_SEVERITY_COLORS[a.severity] ?? 'border-gray-200'}`}
              >
                <span className="font-medium text-sm flex-1">{a.allergen_name}</span>
                {a.reaction_description && (
                  <span className="text-xs opacity-75">{a.reaction_description}</span>
                )}
                <span className={`text-xs px-1.5 py-0.5 rounded-full font-medium border flex-shrink-0 ${ALLERGY_SEVERITY_COLORS[a.severity]}`}>
                  {a.severity.replace('_', ' ')}
                </span>
                {!a.is_active && (
                  <span className="text-xs bg-gray-100 dark:bg-slate-700 text-gray-400 dark:text-slate-500 px-1.5 py-0.5 rounded flex-shrink-0">Inactive</span>
                )}
              </div>
            ))}
          </div>
        </div>
      ))}

      {allergies.length === 0 && (
        <p className="text-sm text-gray-400 dark:text-slate-500 py-8 text-center">No allergies or dietary restrictions on file.</p>
      )}
    </div>
  )
}

// ─── ADL Tab ──────────────────────────────────────────────────────────────────
// Lazy-loaded from GET /participants/{id}/adl.
// Shows a 2-column grid with the latest independence level per ADL category,
// color-coded green → red. Alert threshold set_at shown per category.
// Record ADL form allows logging a new observation.
function AdlTab({ participantId }: { participantId: number }) {
  const [adlData, setAdlData] = useState<AdlSummary | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError]     = useState<string | null>(null)
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving]   = useState(false)

  const blankForm = { adl_category: 'bathing', independence_level: 'supervision', notes: '' }
  const [form, setForm] = useState(blankForm)

  // Load once on first tab activation
  useEffect(() => {
    if (adlData !== null) return
    setLoading(true)
    axios.get(`/participants/${participantId}/adl`)
      .then(r => setAdlData(r.data))
      .catch(() => setError('Failed to load ADL data.'))
      .finally(() => setLoading(false))
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const handleRecord = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      await axios.post(`/participants/${participantId}/adl`, {
        adl_category:      form.adl_category,
        independence_level: form.independence_level,
        notes:             form.notes || null,
      })
      // Re-fetch to get updated latest values (observer may have set threshold_breached)
      const r = await axios.get(`/participants/${participantId}/adl`)
      setAdlData(r.data)
      setShowForm(false)
      setForm(blankForm)
    } catch {
      // form stays open
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <LoadingSpinner />
  if (error)   return <div className="py-8 text-center text-red-500 text-sm">{error}</div>
  if (!adlData) return null

  const { latest, thresholds } = adlData

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">ADL Tracking</h3>
        <button
          onClick={() => setShowForm(v => !v)}
          data-testid="add-adl-btn"
          className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          {showForm ? 'Cancel' : '+ Record ADL'}
        </button>
      </div>

      {/* Record ADL form */}
      {showForm && (
        <form
          onSubmit={handleRecord}
          data-testid="adl-form"
          className="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 grid grid-cols-3 gap-3"
        >
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Category</label>
            <select
              value={form.adl_category}
              onChange={e => setForm(f => ({ ...f, adl_category: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            >
              {ADL_CATEGORIES.map(c => (
                <option key={c} value={c}>{ADL_CATEGORY_LABELS[c]}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Independence Level</label>
            <select
              value={form.independence_level}
              onChange={e => setForm(f => ({ ...f, independence_level: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            >
              {Object.entries(ADL_LEVEL_LABELS).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Notes (optional)</label>
            <input
              value={form.notes}
              onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800"
            />
          </div>
          <div className="col-span-3 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700">Cancel</button>
            <button type="submit" disabled={saving} data-testid="save-adl-btn"
              className="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Record ADL'}
            </button>
          </div>
        </form>
      )}

      {/* Current functional status grid — 2 columns, one card per ADL category */}
      <div>
        <h4 className="text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide mb-3">Current Functional Status</h4>
        <div className="grid grid-cols-2 gap-2">
          {ADL_CATEGORIES.map(cat => {
            const record    = latest?.[cat]
            const threshold = thresholds?.[cat]
            const level     = record?.level
            return (
              <div
                key={cat}
                data-testid={`adl-${cat}`}
                className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3 flex items-center justify-between"
              >
                <div>
                  <div className="text-xs font-medium text-gray-700 dark:text-slate-300">{ADL_CATEGORY_LABELS[cat]}</div>
                  {threshold && (
                    <div className="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                      Alert ≥ {ADL_LEVEL_LABELS[threshold.alert_level] ?? threshold.alert_level}
                    </div>
                  )}
                </div>
                <div className="text-right">
                  {level ? (
                    <span className={`text-xs px-2 py-0.5 rounded-full border font-medium ${ADL_LEVEL_COLORS[level] ?? ''}`}>
                      {ADL_LEVEL_LABELS[level] ?? level}
                    </span>
                  ) : (
                    <span className="text-xs text-gray-300 dark:text-slate-600">Not recorded</span>
                  )}
                  {record?.recorded_at && (
                    <div className="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                      {new Date(record.recorded_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}
                    </div>
                  )}
                </div>
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}

// ─── ADMIN TABS ───────────────────────────────────────────────────────────────

// ─── Contacts Tab ─────────────────────────────────────────────────────────────
// Lists all participant contacts. Allows adding new contacts.
// State sync: useEffect keeps local state in sync with Inertia props so that
// router.reload() updates are reflected without a full page refresh.
function ContactsTab({ participantId, initialContacts }: { participantId: number; initialContacts: Contact[] }) {
  const [contacts, setContacts] = useState<Contact[]>(initialContacts)
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving]     = useState(false)

  const blankForm = {
    contact_type: 'emergency', first_name: '', last_name: '', relationship: '',
    phone_primary: '', phone_secondary: '', email: '', is_legal_representative: false,
    is_emergency_contact: true, priority_order: 1, notes: '',
  }
  const [form, setForm] = useState(blankForm)

  useEffect(() => { setContacts(initialContacts) }, [initialContacts])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      const { data } = await axios.post(`/participants/${participantId}/contacts`, form)
      setContacts(c => [...c, data])
      setShowForm(false)
      setForm(blankForm)
      router.reload({ only: ['contacts'] })
    } catch {
      // leave form open
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Contacts ({contacts.length})</h3>
        <button
          onClick={() => setShowForm(v => !v)}
          data-testid="add-contact-btn"
          className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          {showForm ? 'Cancel' : '+ Add Contact'}
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} data-testid="contact-form" className="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-4 grid grid-cols-2 gap-3">
          <div className="col-span-2 grid grid-cols-3 gap-3">
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Type</label>
              <select
                value={form.contact_type}
                onChange={e => setForm(f => ({ ...f, contact_type: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
              >
                {['emergency','next_of_kin','poa','caregiver','pcp','specialist','other'].map(t => (
                  <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">First Name *</label>
              <input required value={form.first_name}
                onChange={e => setForm(f => ({ ...f, first_name: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Last Name *</label>
              <input required value={form.last_name}
                onChange={e => setForm(f => ({ ...f, last_name: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
            </div>
          </div>

          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Relationship</label>
            <input value={form.relationship}
              onChange={e => setForm(f => ({ ...f, relationship: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Primary Phone</label>
            <PhoneInput value={form.phone_primary}
              onChange={v => setForm(f => ({ ...f, phone_primary: v }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Secondary Phone</label>
            <PhoneInput value={form.phone_secondary}
              onChange={v => setForm(f => ({ ...f, phone_secondary: v }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Email</label>
            <input type="email" value={form.email}
              onChange={e => setForm(f => ({ ...f, email: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
          </div>

          <div className="flex items-center gap-4 col-span-2">
            <label className="flex items-center gap-1.5 text-xs text-gray-700 dark:text-slate-300">
              <input type="checkbox" checked={form.is_emergency_contact}
                onChange={e => setForm(f => ({ ...f, is_emergency_contact: e.target.checked }))} />
              Emergency contact
            </label>
            <label className="flex items-center gap-1.5 text-xs text-gray-700 dark:text-slate-300">
              <input type="checkbox" checked={form.is_legal_representative}
                onChange={e => setForm(f => ({ ...f, is_legal_representative: e.target.checked }))} />
              Legal representative (POA)
            </label>
          </div>

          <div className="col-span-2 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700">Cancel</button>
            <button type="submit" disabled={saving} data-testid="save-contact-btn"
              className="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Save Contact'}
            </button>
          </div>
        </form>
      )}

      <div className="space-y-2">
        {contacts.length === 0 && (
          <p className="text-sm text-gray-400 dark:text-slate-500 py-4 text-center">No contacts on file.</p>
        )}
        {contacts.map(c => (
          <div key={c.id} className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg px-4 py-3 flex items-start justify-between gap-3">
            <div className="flex items-start gap-3">
              <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium mt-0.5 ${CONTACT_TYPE_COLORS[c.contact_type] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400'}`}>
                {c.contact_type.replace(/_/g, ' ')}
              </span>
              <div>
                <div className="font-medium text-sm text-gray-900 dark:text-slate-100">
                  {c.first_name} {c.last_name}
                  {c.is_legal_representative && <span className="ml-2 text-xs bg-purple-100 dark:bg-purple-900/60 text-purple-700 dark:text-purple-300 px-1.5 py-0.5 rounded">POA</span>}
                  {c.is_emergency_contact    && <span className="ml-1 text-xs bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 px-1.5 py-0.5 rounded">Emergency</span>}
                </div>
                {c.relationship && <div className="text-xs text-gray-500 dark:text-slate-400">{c.relationship}</div>}
                <div className="text-xs text-gray-500 dark:text-slate-400 mt-0.5">
                  {[c.phone_primary, c.phone_secondary, c.email].filter(Boolean).join(' · ')}
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

// ─── Flags Tab ─────────────────────────────────────────────────────────────────
// Displays active and resolved clinical flags. Allows adding and resolving flags.
function FlagsTab({ participantId, initialFlags }: { participantId: number; initialFlags: Flag[] }) {
  const [flags, setFlags]             = useState<Flag[]>(initialFlags)
  const [showForm, setShowForm]       = useState(false)
  const [saving, setSaving]           = useState(false)
  const [resolvingId, setResolvingId] = useState<number | null>(null)

  const blankForm = { flag_type: 'fall_risk', description: '', severity: 'medium' }
  const [form, setForm] = useState(blankForm)

  useEffect(() => { setFlags(initialFlags) }, [initialFlags])

  const handleAdd = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      const { data } = await axios.post(`/participants/${participantId}/flags`, form)
      setFlags(f => [data, ...f])
      setShowForm(false)
      setForm(blankForm)
      router.reload({ only: ['flags'] })
    } catch {
      // form stays open
    } finally {
      setSaving(false)
    }
  }

  const handleResolve = async (flagId: number) => {
    setResolvingId(flagId)
    try {
      await axios.post(`/participants/${participantId}/flags/${flagId}/resolve`)
      setFlags(f => f.map(fl => fl.id === flagId ? { ...fl, is_active: false } : fl))
      router.reload({ only: ['flags'] })
    } catch {
      // flag stays active; user can retry
    } finally {
      setResolvingId(null)
    }
  }

  const active   = flags.filter(f => f.is_active)
  const resolved = flags.filter(f => !f.is_active)

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Active Flags ({active.length})</h3>
        <button
          onClick={() => setShowForm(v => !v)}
          data-testid="add-flag-btn"
          className="text-xs px-3 py-1.5 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition-colors"
        >
          {showForm ? 'Cancel' : '+ Add Flag'}
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleAdd} data-testid="flag-form" className="bg-orange-50 dark:bg-orange-950/40 border border-orange-200 dark:border-orange-800 rounded-lg p-4 mb-4 grid grid-cols-3 gap-3">
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Flag Type</label>
            <select value={form.flag_type}
              onChange={e => setForm(f => ({ ...f, flag_type: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100">
              {Object.entries(FLAG_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Severity</label>
            <select value={form.severity}
              onChange={e => setForm(f => ({ ...f, severity: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100">
              {['low','medium','high','critical'].map(s => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Description</label>
            <input value={form.description}
              onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
              placeholder="Optional detail"
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
          </div>
          <div className="col-span-3 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border border-gray-200 dark:border-slate-600 dark:text-slate-300 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700">Cancel</button>
            <button type="submit" disabled={saving} data-testid="save-flag-btn"
              className="text-xs px-4 py-1.5 bg-orange-500 text-white rounded-lg hover:bg-orange-600 disabled:opacity-50">
              {saving ? 'Saving…' : 'Add Flag'}
            </button>
          </div>
        </form>
      )}

      <div className="space-y-2 mb-6">
        {active.length === 0 && <p className="text-sm text-gray-400 dark:text-slate-500 py-4 text-center">No active flags.</p>}
        {active.map(f => (
          <div key={f.id} data-testid={`flag-${f.id}`}
            className={`border rounded-lg px-4 py-3 flex items-start justify-between gap-3 ${FLAG_SEVERITY_COLORS[f.severity]}`}>
            <div>
              <div className="flex items-center gap-2">
                <span className="font-semibold text-sm">{FLAG_LABELS[f.flag_type] ?? f.flag_type}</span>
                <span className="text-xs opacity-70 uppercase">{f.severity}</span>
              </div>
              {f.description && <p className="text-xs mt-0.5 opacity-80">{f.description}</p>}
              <p className="text-xs opacity-60 mt-0.5">
                Added {new Date(f.created_at).toLocaleDateString('en-US')}
                {f.created_by && ` by ${f.created_by.first_name} ${f.created_by.last_name}`}
              </p>
            </div>
            <button onClick={() => handleResolve(f.id)} disabled={resolvingId === f.id}
              data-testid={`resolve-flag-${f.id}`}
              className="flex-shrink-0 text-xs px-2.5 py-1 border border-current rounded-lg hover:bg-white dark:hover:bg-slate-700/50 transition-colors disabled:opacity-50">
              {resolvingId === f.id ? 'Resolving…' : 'Resolve'}
            </button>
          </div>
        ))}
      </div>

      {resolved.length > 0 && (
        <>
          <h4 className="text-xs font-semibold text-gray-400 dark:text-slate-500 uppercase tracking-wide mb-2">Resolved ({resolved.length})</h4>
          <div className="space-y-1">
            {resolved.map(f => (
              <div key={f.id} className="bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-slate-700 rounded px-4 py-2 flex items-center justify-between opacity-60">
                <span className="text-sm dark:text-slate-300">{FLAG_LABELS[f.flag_type]} <span className="text-xs text-gray-400 dark:text-slate-500">({f.severity})</span></span>
                {f.resolved_at && <span className="text-xs text-gray-400 dark:text-slate-500">{new Date(f.resolved_at).toLocaleDateString('en-US')}</span>}
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  )
}

// ─── Insurance Tab ─────────────────────────────────────────────────────────────
// Read-only display of all insurance coverages.
function InsuranceTab({ insurances }: { insurances: Insurance[] }) {
  return (
    <div>
      <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Insurance Coverage ({insurances.length})</h3>
      {insurances.length === 0 && (
        <p className="text-sm text-gray-400 dark:text-slate-500 py-4 text-center">No insurance records on file.</p>
      )}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        {insurances.map(ins => (
          <div key={ins.id} className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg p-4">
            <div className="flex items-center justify-between mb-2">
              <span className="font-semibold text-sm text-gray-900 dark:text-slate-100">{PAYER_LABELS[ins.payer_type] ?? ins.payer_type}</span>
              <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${ins.is_active ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' : 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'}`}>
                {ins.is_active ? 'Active' : 'Inactive'}
              </span>
            </div>
            <dl className="grid grid-cols-2 gap-2 text-sm">
              {ins.member_id      && <DemoField label="Member ID"  value={ins.member_id} />}
              {ins.plan_name      && <DemoField label="Plan"       value={ins.plan_name} />}
              {ins.effective_date && <DemoField label="Effective"  value={new Date(ins.effective_date).toLocaleDateString('en-US')} />}
              {ins.term_date      && <DemoField label="Term Date"  value={new Date(ins.term_date).toLocaleDateString('en-US')} />}
            </dl>
          </div>
        ))}
      </div>
    </div>
  )
}

// ─── Documents Tab ─────────────────────────────────────────────────────────────
// Participant-level document store. Lists uploaded files with category filter chips.
// Supports PDF/JPEG/PNG/DOCX uploads up to 20 MB.
//
// Data loading: lazy-fetches GET /participants/{id}/documents on first activation.
// Re-fetches after each successful upload or delete.
// Downloads stream through the controller (file path never exposed to client).
// ─────────────────────────────────────────────────────────────────────────────

interface DocumentItem {
  id:                number
  file_name:         string
  file_type:         string
  file_size:         string
  file_size_bytes:   number
  description:       string | null
  document_category: string
  category_label:    string
  uploaded_by:       string
  uploaded_at:       string
}

const CATEGORY_LABELS: Record<string, string> = {
  consent:       'Consent Forms',
  care_plan:     'Care Plans',
  referral:      'Referrals',
  lab_report:    'Lab Reports',
  imaging:       'Imaging',
  insurance:     'Insurance',
  legal:         'Legal',
  clinical_note: 'Clinical Notes',
  assessment:    'Assessments',
  other:         'Other',
}

const FILE_ICONS: Record<string, React.ReactNode> = {
  pdf:  <DocumentIcon className="w-5 h-5 text-red-400" />,
  jpeg: <PhotoIcon className="w-5 h-5 text-blue-400" />,
  png:  <PhotoIcon className="w-5 h-5 text-blue-400" />,
  docx: <DocumentTextIcon className="w-5 h-5 text-indigo-400" />,
}

function DocumentsTab({ participantId }: { participantId: number }) {
  const [docs, setDocs]                 = useState<DocumentItem[]>([])
  const [total, setTotal]               = useState(0)
  const [loading, setLoading]           = useState(true)
  const [activeCategory, setActiveCategory] = useState<string>('all')
  const [showUpload, setShowUpload]     = useState(false)
  const [uploading, setUploading]       = useState(false)
  const [uploadError, setUploadError]   = useState<string | null>(null)

  // Upload form state
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [uploadCategory, setUploadCategory] = useState('other')
  const [uploadDescription, setUploadDescription] = useState('')

  // Load documents (re-run when category filter changes)
  const fetchDocs = (category?: string) => {
    setLoading(true)
    const params = category && category !== 'all' ? `?category=${category}` : ''
    axios.get(`/participants/${participantId}/documents${params}`)
      .then(r => {
        setDocs(r.data.data)
        setTotal(r.data.total)
      })
      .catch(() => {})
      .finally(() => setLoading(false))
  }

  useEffect(() => { fetchDocs(activeCategory) }, [participantId, activeCategory])

  const handleUpload = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedFile) return

    setUploading(true)
    setUploadError(null)

    const form = new FormData()
    form.append('file', selectedFile)
    form.append('document_category', uploadCategory)
    if (uploadDescription) form.append('description', uploadDescription)

    try {
      await axios.post(`/participants/${participantId}/documents`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      setShowUpload(false)
      setSelectedFile(null)
      setUploadDescription('')
      setUploadCategory('other')
      fetchDocs(activeCategory)
    } catch (err: any) {
      const msg = err.response?.data?.message
        || (Object.values(err.response?.data?.errors ?? {}) as string[][])[0]?.[0]
        || 'Upload failed. Please try again.'
      setUploadError(String(msg))
    } finally {
      setUploading(false)
    }
  }

  const handleDelete = async (doc: DocumentItem) => {
    if (!confirm(`Remove "${doc.file_name}"? This cannot be undone.`)) return
    await axios.delete(`/participants/${participantId}/documents/${doc.id}`)
    fetchDocs(activeCategory)
  }

  const handleDownload = (doc: DocumentItem) => {
    // Open in new tab — controller streams the file with correct Content-Disposition
    window.open(`/participants/${participantId}/documents/${doc.id}/download`, '_blank')
  }

  return (
    <div>
      {/* Header row */}
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">
          Documents {total > 0 && <span className="text-gray-400 dark:text-slate-500 font-normal">({total})</span>}
        </h3>
        <button
          onClick={() => { setShowUpload(true); setUploadError(null) }}
          className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors"
        >
          <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4" />
          </svg>
          Upload
        </button>
      </div>

      {/* Category filter chips */}
      <div className="flex flex-wrap gap-2 mb-5">
        {[['all', 'All'], ...Object.entries(CATEGORY_LABELS)].map(([key, label]) => (
          <button
            key={key}
            onClick={() => setActiveCategory(key)}
            className={`px-3 py-1 rounded-full text-xs font-medium border transition-colors ${
              activeCategory === key
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-slate-400 border-gray-200 dark:border-slate-700 hover:border-blue-300 hover:text-blue-600'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {/* Upload modal */}
      {showUpload && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-md p-6">
            <div className="flex items-center justify-between mb-4">
              <h4 className="text-base font-semibold text-gray-900 dark:text-slate-100">Upload Document</h4>
              <button onClick={() => setShowUpload(false)} className="text-gray-400 dark:text-slate-500 hover:text-gray-600 dark:hover:text-slate-300">
                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <form onSubmit={handleUpload} className="space-y-4">
              {/* File picker */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">File <span className="text-red-500">*</span></label>
                <input
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png,.docx"
                  onChange={e => setSelectedFile(e.target.files?.[0] ?? null)}
                  className="block w-full text-sm text-gray-700 dark:text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                  required
                />
                <p className="text-[10px] text-gray-400 dark:text-slate-500 mt-1">PDF, JPEG, PNG, DOCX (max 20 MB)</p>
              </div>

              {/* Category */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Category <span className="text-red-500">*</span></label>
                <select
                  value={uploadCategory}
                  onChange={e => setUploadCategory(e.target.value)}
                  className="w-full border border-gray-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
                  {Object.entries(CATEGORY_LABELS).map(([k, l]) => (
                    <option key={k} value={k}>{l}</option>
                  ))}
                </select>
              </div>

              {/* Description */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Description <span className="text-gray-400 dark:text-slate-500">(optional)</span></label>
                <input
                  type="text"
                  maxLength={500}
                  value={uploadDescription}
                  onChange={e => setUploadDescription(e.target.value)}
                  placeholder="Brief description of the document…"
                  className="w-full border border-gray-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm bg-white dark:bg-slate-800 dark:text-slate-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
              </div>

              {uploadError && (
                <p className="text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 rounded-lg px-3 py-2">
                  {uploadError}
                </p>
              )}

              <div className="flex gap-3 pt-1">
                <button
                  type="button"
                  onClick={() => setShowUpload(false)}
                  className="flex-1 px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={uploading || !selectedFile}
                  className="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  {uploading ? 'Uploading…' : 'Upload'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Document list */}
      {loading ? (
        <div className="space-y-2 animate-pulse">
          {[1,2,3].map(i => <div key={i} className="h-14 bg-gray-100 dark:bg-slate-700 rounded-lg" />)}
        </div>
      ) : docs.length === 0 ? (
        <div className="text-center py-12 text-gray-400 dark:text-slate-500">
          <svg className="w-10 h-10 mx-auto mb-3 text-gray-300 dark:text-slate-600" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
          </svg>
          <p className="text-sm font-medium">No documents{activeCategory !== 'all' ? ` in ${CATEGORY_LABELS[activeCategory]}` : ''}</p>
          <p className="text-xs mt-1">Use the Upload button to add documents.</p>
        </div>
      ) : (
        <div className="divide-y divide-gray-100 dark:divide-slate-700 rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 overflow-hidden">
          {docs.map(doc => (
            <div key={doc.id} className="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors">
              {/* File type icon */}
              <span className="flex-shrink-0 w-8 flex justify-center" title={doc.file_type.toUpperCase()}>
                {FILE_ICONS[doc.file_type] ?? <PaperClipIcon className="w-5 h-5 text-slate-400" />}
              </span>

              {/* File info */}
              <div className="min-w-0 flex-1">
                <button
                  onClick={() => handleDownload(doc)}
                  className="text-sm font-medium text-blue-600 dark:text-blue-400 hover:underline truncate block text-left max-w-full"
                  title={doc.file_name}
                >
                  {doc.file_name}
                </button>
                <div className="flex items-center gap-2 flex-wrap mt-0.5">
                  <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                    {doc.category_label}
                  </span>
                  {doc.description && (
                    <span className="text-[11px] text-gray-400 dark:text-slate-500 truncate max-w-xs">{doc.description}</span>
                  )}
                </div>
                <p className="text-[10px] text-gray-400 dark:text-slate-500 mt-0.5">
                  {doc.file_size} · Uploaded by {doc.uploaded_by} · {doc.uploaded_at?.split('T')[0] ?? ''}
                </p>
              </div>

              {/* Actions */}
              <div className="flex items-center gap-1 flex-shrink-0">
                <button
                  onClick={() => handleDownload(doc)}
                  className="p-1.5 text-gray-400 dark:text-slate-500 hover:text-blue-600 dark:hover:text-blue-400 rounded hover:bg-blue-50 dark:hover:bg-blue-950/50 transition-colors"
                  title="Download"
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                  </svg>
                </button>
                <button
                  onClick={() => handleDelete(doc)}
                  className="p-1.5 text-gray-400 dark:text-slate-500 hover:text-red-600 dark:hover:text-red-400 rounded hover:bg-red-50 dark:hover:bg-red-950/50 transition-colors"
                  title="Remove document"
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                  </svg>
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ─── Audit Tab ─────────────────────────────────────────────────────────────────
// HIPAA-required audit trail. Visible only to users with canViewAudit permission.
function AuditTab({ logs }: { logs: AuditEntry[] }) {
  return (
    <div>
      <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Audit Trail ({logs.length} entries)</h3>
      <div className="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-slate-700/50">
            <tr>
              {['Timestamp', 'Action', 'Description'].map(h => (
                <th key={h} className="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
            {logs.length === 0 && (
              <tr><td colSpan={3} className="px-4 py-6 text-center text-gray-400 dark:text-slate-500">No audit entries.</td></tr>
            )}
            {logs.map(e => (
              <tr key={e.id} className="bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700/50">
                <td className="px-4 py-2 text-xs text-gray-500 dark:text-slate-400 whitespace-nowrap">
                  {new Date(e.created_at).toLocaleString('en-US')}
                </td>
                <td className="px-4 py-2 font-mono text-xs text-gray-700 dark:text-slate-300">{e.action}</td>
                <td className="px-4 py-2 text-xs text-gray-600 dark:text-slate-400">{e.description}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ─── TransfersTab ─────────────────────────────────────────────────────────────
// Phase 10A — Site Transfer history + request modal.
// Visible to enrollment, it_admin, super_admin (can request/approve/cancel).
// Other departments see the read-only history list only.
//
// Data: lazy-loaded from GET /participants/{id}/transfers on first activation.
// Mutations: POST /participants/{id}/transfers, POST …/{id}/approve, POST …/{id}/cancel
// ─────────────────────────────────────────────────────────────────────────────

interface SiteTransfer {
  id:                    number
  from_site:             { id: number; name: string } | null
  to_site:               { id: number; name: string } | null
  transfer_reason:       string
  transfer_reason_label: string
  transfer_reason_notes: string | null
  requested_by:          { id: number; name: string } | null
  requested_at:          string | null
  approved_by:           { id: number; name: string } | null
  approved_at:           string | null
  effective_date:        string | null
  status:                'pending' | 'approved' | 'completed' | 'cancelled'
}

interface TransferSite {
  id:   number
  name: string
}

const TRANSFER_REASONS: Record<string, string> = {
  participant_request: 'Participant Request',
  relocation:          'Participant Relocation',
  capacity:            'Site Capacity',
  program_closure:     'Program Closure',
  other:               'Other',
}

const STATUS_PILL: Record<string, string> = {
  pending:   'bg-yellow-50 dark:bg-yellow-950/60 text-yellow-700 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-800',
  approved:  'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800',
  completed: 'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 border border-green-200 dark:border-green-800',
  cancelled: 'bg-gray-50 dark:bg-slate-800 text-gray-500 dark:text-slate-400 border border-gray-200 dark:border-slate-700',
}

function TransfersTab({ participantId, currentSiteId, canManageTransfers }: {
  participantId:       number
  currentSiteId:       number
  canManageTransfers:  boolean
}) {
  const [transfers, setTransfers] = useState<SiteTransfer[]>([])
  const [sites, setSites]         = useState<TransferSite[]>([])
  const [loading, setLoading]     = useState(true)
  const [showModal, setShowModal] = useState(false)
  const [saving, setSaving]       = useState(false)
  const [actionId, setActionId]   = useState<number | null>(null)

  // W3-6: data summary + integrity check
  type SitePeriod = { site_name: string; start: string; end: string | null; note_count: number; vital_count: number; appointment_count: number }
  const [periods, setPeriods]             = useState<SitePeriod[]>([])
  const [verifyStatus, setVerifyStatus]   = useState<'idle' | 'running' | 'verified' | 'anomalies_found'>('idle')
  const [verifyAnomalies, setVerifyAnomalies] = useState<string[]>([])

  const [form, setForm] = useState({
    to_site_id:            '',
    transfer_reason:       '',
    transfer_reason_notes: '',
    effective_date:        '',
  })
  const [formErr, setFormErr] = useState<Record<string, string>>({})

  // ── Lazy load transfers + destination sites + data summary ───────────────
  useEffect(() => {
    const requests: Promise<any>[] = [
      axios.get(`/participants/${participantId}/transfers`),
      axios.get(`/participants/${participantId}/transfers/summary`),
    ]
    if (canManageTransfers) {
      requests.push(axios.get(`/participants/${participantId}/transfers/sites`))
    }
    Promise.all(requests).then(([txRes, summaryRes, sitesRes]) => {
      setTransfers(txRes.data.transfers ?? [])
      setPeriods(summaryRes.data.periods ?? [])
      if (sitesRes) {
        setSites(sitesRes.data.sites ?? [])
      }
    }).finally(() => setLoading(false))
  }, [participantId])

  const activePendingTransfer = transfers.find(t => t.status === 'pending' || t.status === 'approved')

  // ── Submit request ────────────────────────────────────────────────────────
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setFormErr({})
    if (!form.to_site_id)      { setFormErr({ to_site_id: 'Required' }); return }
    if (!form.transfer_reason) { setFormErr({ transfer_reason: 'Required' }); return }
    if (!form.effective_date)  { setFormErr({ effective_date: 'Required' }); return }

    setSaving(true)
    try {
      const res = await axios.post(`/participants/${participantId}/transfers`, {
        to_site_id:            parseInt(form.to_site_id),
        transfer_reason:       form.transfer_reason,
        transfer_reason_notes: form.transfer_reason_notes || null,
        effective_date:        form.effective_date,
      })
      setTransfers(prev => [res.data, ...prev])
      setShowModal(false)
      setForm({ to_site_id: '', transfer_reason: '', transfer_reason_notes: '', effective_date: '' })
    } catch (err: any) {
      if (err.response?.data?.message) {
        setFormErr({ _global: err.response.data.message })
      } else if (err.response?.data?.errors) {
        const errs: Record<string, string> = {}
        Object.entries(err.response.data.errors).forEach(([k, v]) => { errs[k] = Array.isArray(v) ? v[0] as string : String(v) })
        setFormErr(errs)
      }
    } finally {
      setSaving(false)
    }
  }

  // ── Approve ───────────────────────────────────────────────────────────────
  const handleApprove = async (transfer: SiteTransfer) => {
    setActionId(transfer.id)
    try {
      const res = await axios.post(`/participants/${participantId}/transfers/${transfer.id}/approve`)
      setTransfers(prev => prev.map(t => t.id === transfer.id ? res.data : t))
    } finally {
      setActionId(null)
    }
  }

  // ── Cancel ────────────────────────────────────────────────────────────────
  const handleCancel = async (transfer: SiteTransfer) => {
    if (!confirm('Cancel this transfer request?')) return
    setActionId(transfer.id)
    try {
      const res = await axios.post(`/participants/${participantId}/transfers/${transfer.id}/cancel`)
      setTransfers(prev => prev.map(t => t.id === transfer.id ? res.data : t))
    } finally {
      setActionId(null)
    }
  }

  // ── Render ────────────────────────────────────────────────────────────────
  if (loading) return <p className="text-sm text-gray-400 dark:text-slate-500 py-6 text-center">Loading transfer history…</p>

  return (
    <div className="space-y-4">
      {/* Amber pending banner */}
      {activePendingTransfer && (
        <div className="bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 rounded-lg px-4 py-3 flex items-start gap-3">
          <ClockIcon className="w-5 h-5 text-amber-500 shrink-0" />
          <div className="flex-1 text-sm">
            <p className="font-semibold text-amber-800 dark:text-amber-300">
              Transfer {activePendingTransfer.status === 'pending' ? 'Pending Approval' : 'Approved: Awaiting Effective Date'}
            </p>
            <p className="text-amber-700 dark:text-amber-300 mt-0.5">
              To: <strong>{activePendingTransfer.to_site?.name ?? '-'}</strong> · Effective: {activePendingTransfer.effective_date ?? '-'} · Reason: {activePendingTransfer.transfer_reason_label}
            </p>
          </div>
        </div>
      )}

      {/* Header row */}
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Transfer History ({transfers.length})</h3>
        {canManageTransfers && !activePendingTransfer && (
          <button
            onClick={() => setShowModal(true)}
            className="text-sm px-3 py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700 font-medium"
          >
            + Request Transfer
          </button>
        )}
      </div>

      {/* History table */}
      <div className="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 dark:bg-slate-700/50">
            <tr>
              {['Status', 'From', 'To', 'Reason', 'Effective Date', 'Requested By', 'Actions'].map(h => (
                <th key={h} className="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
            {transfers.length === 0 && (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-gray-400 dark:text-slate-500">No transfer history.</td></tr>
            )}
            {transfers.map(t => (
              <tr key={t.id} className="bg-white dark:bg-slate-800 hover:bg-gray-50 dark:hover:bg-slate-700/50">
                <td className="px-4 py-2">
                  <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${STATUS_PILL[t.status] ?? ''}`}>
                    {t.status.charAt(0).toUpperCase() + t.status.slice(1)}
                  </span>
                </td>
                <td className="px-4 py-2 text-xs text-gray-700 dark:text-slate-300">{t.from_site?.name ?? '-'}</td>
                <td className="px-4 py-2 text-xs text-gray-700 dark:text-slate-300">{t.to_site?.name ?? '-'}</td>
                <td className="px-4 py-2 text-xs text-gray-600 dark:text-slate-400">{t.transfer_reason_label}</td>
                <td className="px-4 py-2 text-xs text-gray-700 dark:text-slate-300">{t.effective_date ?? '-'}</td>
                <td className="px-4 py-2 text-xs text-gray-500 dark:text-slate-400">{t.requested_by?.name ?? '-'}</td>
                <td className="px-4 py-2 text-xs space-x-2 whitespace-nowrap">
                  {canManageTransfers && t.status === 'pending' && (
                    <>
                      <button
                        disabled={actionId === t.id}
                        onClick={() => handleApprove(t)}
                        className="text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-50"
                      >Approve</button>
                      <button
                        disabled={actionId === t.id}
                        onClick={() => handleCancel(t)}
                        className="text-red-500 hover:underline disabled:opacity-50"
                      >Cancel</button>
                    </>
                  )}
                  {canManageTransfers && t.status === 'approved' && (
                    <button
                      disabled={actionId === t.id}
                      onClick={() => handleCancel(t)}
                      className="text-red-500 hover:underline disabled:opacity-50"
                    >Cancel</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Data summary per site period */}
      {periods.length > 0 && (
        <div className="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
          <div className="flex items-center justify-between px-4 py-2 bg-gray-50 dark:bg-slate-700/50">
            <h4 className="text-xs font-semibold text-gray-600 dark:text-slate-300 uppercase tracking-wide">Care Period Summary</h4>
            <button
              onClick={async () => {
                setVerifyStatus('running')
                try {
                  const res = await axios.post(`/participants/${participantId}/transfers/verify`)
                  setVerifyStatus(res.data.status)
                  setVerifyAnomalies(res.data.anomalies ?? [])
                } catch { setVerifyStatus('idle') }
              }}
              disabled={verifyStatus === 'running'}
              className="text-xs px-2.5 py-1 bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600 rounded disabled:opacity-50 border border-slate-200 dark:border-slate-600"
            >
              {verifyStatus === 'running' ? 'Checking…' : 'Verify Data Integrity'}
            </button>
          </div>

          {verifyStatus === 'verified' && (
            <div className="px-4 py-2 text-xs text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-950/60 border-b border-gray-200 dark:border-slate-700">
              Data integrity verified — all clinical records are properly site-attributed.
            </div>
          )}
          {verifyStatus === 'anomalies_found' && (
            <div className="px-4 py-2 text-xs text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-950/60 border-b border-gray-200 dark:border-slate-700 space-y-1">
              <p className="font-semibold">Anomalies found:</p>
              {verifyAnomalies.map((a, i) => <p key={i}>{a}</p>)}
            </div>
          )}

          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-slate-700/50">
              <tr>
                {['Site', 'Period', 'Notes', 'Vitals', 'Appointments'].map(h => (
                  <th key={h} className="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-slate-400 uppercase tracking-wide">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
              {periods.map((p, i) => (
                <tr key={i} className="bg-white dark:bg-slate-800">
                  <td className="px-4 py-2 text-sm font-medium text-gray-800 dark:text-slate-200">{p.site_name}</td>
                  <td className="px-4 py-2 text-xs text-gray-500 dark:text-slate-400">
                    {p.start} - {p.end ?? 'Present'}
                  </td>
                  <td className="px-4 py-2 text-sm text-gray-700 dark:text-slate-300">{p.note_count}</td>
                  <td className="px-4 py-2 text-sm text-gray-700 dark:text-slate-300">{p.vital_count}</td>
                  <td className="px-4 py-2 text-sm text-gray-700 dark:text-slate-300">{p.appointment_count}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Request Transfer Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50" onClick={e => { if (e.target === e.currentTarget) setShowModal(false) }}>
          <div className="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-lg p-6 space-y-4">
            <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">Request Site Transfer</h3>

            {formErr._global && (
              <div className="bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 text-sm px-3 py-2 rounded border border-red-200 dark:border-red-800">{formErr._global}</div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4">
              {/* Destination Site — named dropdown from shared_sites (excludes current site) */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Destination Site</label>
                <select
                  value={form.to_site_id}
                  onChange={e => setForm(f => ({ ...f, to_site_id: e.target.value }))}
                  className="w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select destination site…</option>
                  {sites.map(s => (
                    <option key={s.id} value={String(s.id)}>{s.name}</option>
                  ))}
                </select>
                {formErr.to_site_id && <p className="text-xs text-red-500 mt-1">{formErr.to_site_id}</p>}
              </div>

              {/* Reason */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Transfer Reason</label>
                <select
                  value={form.transfer_reason}
                  onChange={e => setForm(f => ({ ...f, transfer_reason: e.target.value }))}
                  className="w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-slate-700 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select reason…</option>
                  {Object.entries(TRANSFER_REASONS).map(([v, l]) => (
                    <option key={v} value={v}>{l}</option>
                  ))}
                </select>
                {formErr.transfer_reason && <p className="text-xs text-red-500 mt-1">{formErr.transfer_reason}</p>}
              </div>

              {/* Notes */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Notes (optional)</label>
                <textarea
                  value={form.transfer_reason_notes}
                  onChange={e => setForm(f => ({ ...f, transfer_reason_notes: e.target.value }))}
                  rows={2}
                  className="w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-slate-700 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>

              {/* Effective Date */}
              <div>
                <label className="block text-xs font-medium text-gray-700 dark:text-slate-300 mb-1">Effective Date</label>
                <input
                  type="date"
                  value={form.effective_date}
                  onChange={e => setForm(f => ({ ...f, effective_date: e.target.value }))}
                  min={new Date().toISOString().split('T')[0]}
                  className="w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-slate-700 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                {formErr.effective_date && <p className="text-xs text-red-500 mt-1">{formErr.effective_date}</p>}
              </div>

              <div className="flex justify-end gap-3 pt-2">
                <button type="button" onClick={() => setShowModal(false)} className="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 rounded-md text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-700">
                  Cancel
                </button>
                <button type="submit" disabled={saving} className="text-sm px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 font-medium">
                  {saving ? 'Submitting…' : 'Submit Request'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

// ─── CarePlanTab ───────────────────────────────────────────────────────────────
// Displays the active care plan for the participant with domain goals.
// Lazy-loads on first activation via GET /participants/{id}/careplan.
//
// All 12 PACE domains are always shown. Domains with an existing goal show the
// goal card; domains without one show an "Add Goal" placeholder (edit form).
//
// Approval requires isAdmin() && department in [idt, primary_care] — derived
// from auth.user in usePage() props (not passed as a prop to avoid stale data).
// IDT Admin + Primary Care Admin get Approve and New Version buttons.
function CarePlanTab({ participantId }: { participantId: number }) {
  const { auth } = usePage<any>().props
  // Mirror of CarePlan::canBeApprovedBy() — IDT Admin or Primary Care Admin
  const canApprove = auth.user.role === 'admin'
    && ['idt', 'primary_care'].includes(auth.user.department)
  const [plan, setPlan]       = React.useState<any>(null)
  const [loaded, setLoaded]   = React.useState(false)
  const [loading, setLoading] = React.useState(false)
  const [editDomain, setEditDomain] = React.useState<string | null>(null)
  const [editForm, setEditForm]     = React.useState<any>({})
  const [saving, setSaving]         = React.useState(false)
  const [saveError, setSaveError]   = React.useState<string | null>(null)
  const [approving, setApproving]   = React.useState(false)
  const [versioning, setVersioning] = React.useState(false)

  React.useEffect(() => {
    if (loaded) return
    setLoading(true)
    axios.get(`/participants/${participantId}/careplan`)
      .then(r => { setPlan(r.data); setLoaded(true) })
      .catch(() => setLoaded(true))
      .finally(() => setLoading(false))
  }, [participantId, loaded])

  const STATUS_BADGE: Record<string, string> = {
    active:       'bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 ring-blue-600/20',
    met:          'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300 ring-green-600/20',
    modified:     'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 ring-amber-600/20',
    discontinued: 'bg-gray-50 text-gray-700 ring-gray-600/20',
  }

  const PLAN_STATUS_BADGE: Record<string, string> = {
    draft:        'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400',
    active:       'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
    under_review: 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
    archived:     'bg-gray-100 text-gray-500',
  }

  // All 12 PACE IDT domains — matches CarePlanGoal::DOMAINS on backend.
  // Used to show every domain slot even if no goal has been recorded yet.
  const ALL_CARE_DOMAINS = [
    { id: 'medical',        label: 'Medical' },
    { id: 'nursing',        label: 'Nursing' },
    { id: 'social',         label: 'Social Work' },
    { id: 'behavioral',     label: 'Behavioral Health' },
    { id: 'therapy_pt',     label: 'Physical Therapy' },
    { id: 'therapy_ot',     label: 'Occupational Therapy' },
    { id: 'therapy_st',     label: 'Speech Therapy' },
    { id: 'dietary',        label: 'Dietary / Nutrition' },
    { id: 'activities',     label: 'Activities' },
    { id: 'home_care',      label: 'Home Care' },
    { id: 'transportation', label: 'Transportation' },
    { id: 'pharmacy',       label: 'Pharmacy' },
  ]

  const openEdit = (goal: any) => {
    setEditDomain(goal.domain)
    setEditForm({
      goal_description:    goal.goal_description   ?? '',
      measurable_outcomes: goal.measurable_outcomes ?? '',
      interventions:       goal.interventions       ?? '',
      target_date:         goal.target_date         ? goal.target_date.split('T')[0] : '',
      status:              goal.status              ?? 'active',
    })
  }

  // Opens an empty edit form for a domain that has no goal recorded yet.
  const openNewGoal = (domainId: string) => {
    setEditDomain(domainId)
    setEditForm({ goal_description: '', measurable_outcomes: '', interventions: '', target_date: '', status: 'active' })
    setSaveError(null)
  }

  const saveGoal = async (carePlanId: number) => {
    setSaving(true)
    setSaveError(null)
    try {
      const { data } = await axios.put(
        `/participants/${participantId}/careplan/${carePlanId}/goals/${editDomain}`,
        editForm
      )
      setPlan((prev: any) => {
        const exists = prev.goals.some((g: any) => g.domain === editDomain)
        return {
          ...prev,
          goals: exists
            ? prev.goals.map((g: any) => g.domain === editDomain ? data : g)
            : [...prev.goals, data],
        }
      })
      setEditDomain(null)
    } catch (err: any) {
      const msg = err?.response?.data?.message ?? err?.response?.data?.error ?? 'Save failed. Check that the care plan is in draft or under-review status.'
      setSaveError(msg)
    } finally { setSaving(false) }
  }

  const approvePlan = async () => {
    if (!plan || !window.confirm('Approve and activate this care plan? It will replace the current active plan.')) return
    setApproving(true)
    setSaveError(null)
    try {
      const { data } = await axios.post(`/participants/${participantId}/careplan/${plan.id}/approve`)
      setPlan(data)
    } catch (err: any) {
      const msg = err?.response?.data?.message ?? err?.response?.data?.error
        ?? 'Approval failed. Only IDT Admin or Primary Care Admin can approve care plans.'
      setSaveError(msg)
    } finally { setApproving(false) }
  }

  // Creates a new draft version from the active plan so goals can be edited.
  // The active plan moves to 'under_review'; the new draft becomes current.
  const startNewVersion = async () => {
    if (!plan || !window.confirm('Start a new revision? The current active plan will move to Under Review and a new draft will be created for editing.')) return
    setVersioning(true)
    try {
      const { data } = await axios.post(`/participants/${participantId}/careplan/${plan.id}/new-version`)
      setPlan(data)
    } catch { /* ignore */ } finally { setVersioning(false) }
  }

  if (loading) return <div className="py-12 text-center text-sm text-gray-500 dark:text-slate-400">Loading care plan…</div>
  if (!plan)   return (
    <div className="py-12 text-center">
      <p className="text-gray-500 dark:text-slate-400 text-sm mb-3">No care plan found for this participant.</p>
      <button
        onClick={async () => {
          const { data } = await axios.post(`/participants/${participantId}/careplan`)
          setPlan(data); setLoaded(true)
        }}
        className="px-4 py-2 text-sm font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700"
        data-testid="create-care-plan-btn"
      >
        Create Care Plan
      </button>
    </div>
  )

  const goals: any[] = plan.goals ?? []
  const daysUntilReview = plan.review_due_date
    ? Math.ceil((new Date(plan.review_due_date).getTime() - Date.now()) / 86_400_000)
    : null

  return (
    <div className="space-y-6" data-testid="care-plan-tab">
      {/* Plan meta header */}
      <div className="flex items-start justify-between gap-4 p-4 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
        <div>
          <div className="flex items-center gap-2">
            <h3 className="font-semibold text-slate-800 dark:text-slate-200">Care Plan v{plan.version}</h3>
            <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ${PLAN_STATUS_BADGE[plan.status] ?? ''}`}>
              {plan.status?.replace('_', ' ').toUpperCase()}
            </span>
          </div>
          <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
            {plan.effective_date ? `Effective: ${plan.effective_date}` : 'Draft: not yet effective'}
            {plan.review_due_date && ` · Review due: ${plan.review_due_date}`}
            {daysUntilReview !== null && daysUntilReview <= 30 && (
              <span className="ml-1.5 text-amber-600 dark:text-amber-400 font-medium">({daysUntilReview}d)</span>
            )}
          </p>
          {plan.approved_by && (
            <p className="text-xs text-slate-400 mt-0.5">
              Approved by {plan.approved_by.first_name} {plan.approved_by.last_name}
            </p>
          )}
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {/* Active plans are immutable — start a new revision to edit goals */}
          {plan.status === 'active' && (
            <button
              onClick={startNewVersion}
              disabled={versioning}
              data-testid="new-version-btn"
              className="px-3 py-1.5 text-xs font-medium bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
            >
              {versioning ? 'Creating…' : 'Start New Version'}
            </button>
          )}
          {canApprove && (plan.status === 'draft' || plan.status === 'under_review') && (
            <button
              onClick={approvePlan}
              disabled={approving}
              data-testid="approve-care-plan-btn"
              className="px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
            >
              {approving ? 'Approving…' : 'Approve Plan'}
            </button>
          )}
        </div>
      </div>

      {/* Overall goals text */}
      {plan.overall_goals_text && (
        <div className="px-4 py-3 bg-blue-50 dark:bg-blue-950/60 border border-blue-100 dark:border-blue-800 rounded-xl">
          <p className="text-xs font-semibold text-blue-800 dark:text-blue-300 mb-1">Overall Care Goals</p>
          <p className="text-sm text-blue-900 dark:text-blue-200">{plan.overall_goals_text}</p>
        </div>
      )}

      {/* Domain goals grid — all 12 PACE disciplines always shown */}
      <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
        {ALL_CARE_DOMAINS.map(({ id: domainId, label: domainLabel }) => {
          const goal = goals.find((g: any) => g.domain === domainId)
          return (
          <div
            key={domainId}
            data-testid={`goal-card-${domainId}`}
            className="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 space-y-2"
          >
            <div className="flex items-center justify-between">
              <h4 className="text-sm font-semibold text-slate-800 dark:text-slate-200">
                {domainLabel}
              </h4>
              <div className="flex items-center gap-1.5">
                {goal && (
                  <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset ${STATUS_BADGE[goal.status] ?? ''}`}>
                    {goal.status?.toUpperCase()}
                  </span>
                )}
                {!goal && editDomain !== domainId && (plan.status === 'draft' || plan.status === 'under_review') && (
                  <button
                    onClick={() => openNewGoal(domainId)}
                    data-testid={`add-goal-${domainId}`}
                    className="text-[11px] text-blue-600 dark:text-blue-400 hover:underline"
                  >
                    + Add Goal
                  </button>
                )}
                {goal && editDomain !== domainId && (plan.status === 'draft' || plan.status === 'under_review') && (
                  <button
                    onClick={() => openEdit(goal)}
                    data-testid={`edit-goal-${domainId}`}
                    className="text-[11px] text-blue-600 dark:text-blue-400 hover:underline"
                  >
                    Edit
                  </button>
                )}
                {goal && plan.status === 'active' && editDomain !== domainId && (
                  <span className="text-[11px] text-gray-400 dark:text-slate-600">Approved — read only</span>
                )}
              </div>
            </div>

            {editDomain === domainId ? (
              <div className="space-y-2 pt-1">
                <div>
                  <label className="block text-[10px] font-medium text-slate-600 dark:text-slate-400 mb-0.5">Goal</label>
                  <textarea
                    rows={2}
                    value={editForm.goal_description}
                    onChange={e => setEditForm((f: any) => ({ ...f, goal_description: e.target.value }))}
                    className="w-full text-xs border border-slate-300 dark:border-slate-600 rounded px-2 py-1 resize-none bg-white dark:bg-slate-700 dark:text-slate-100 focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-[10px] font-medium text-slate-600 dark:text-slate-400 mb-0.5">Outcomes</label>
                  <textarea
                    rows={2}
                    value={editForm.measurable_outcomes}
                    onChange={e => setEditForm((f: any) => ({ ...f, measurable_outcomes: e.target.value }))}
                    className="w-full text-xs border border-slate-300 dark:border-slate-600 rounded px-2 py-1 resize-none bg-white dark:bg-slate-700 dark:text-slate-100 focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <label className="block text-[10px] font-medium text-slate-600 dark:text-slate-400 mb-0.5">Status</label>
                    <select
                      value={editForm.status}
                      onChange={e => setEditForm((f: any) => ({ ...f, status: e.target.value }))}
                      className="w-full text-xs border border-slate-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-700 dark:text-slate-100 focus:ring-blue-500 focus:border-blue-500"
                    >
                      {['active','met','modified','discontinued'].map(s => (
                        <option key={s} value={s}>{s}</option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-[10px] font-medium text-slate-600 dark:text-slate-400 mb-0.5">Target Date</label>
                    <input
                      type="date"
                      value={editForm.target_date}
                      onChange={e => setEditForm((f: any) => ({ ...f, target_date: e.target.value }))}
                      className="w-full text-xs border border-slate-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-700 dark:text-slate-100 focus:ring-blue-500 focus:border-blue-500"
                    />
                  </div>
                </div>
                <div className="flex gap-2 pt-1">
                  <button
                    onClick={() => saveGoal(plan.id)}
                    disabled={saving}
                    data-testid={`save-goal-${domainId}`}
                    className="px-3 py-1 text-[11px] font-medium bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
                  >
                    {saving ? 'Saving…' : 'Save'}
                  </button>
                  <button
                    onClick={() => { setEditDomain(null); setSaveError(null) }}
                    className="px-3 py-1 text-[11px] text-slate-600 dark:text-slate-400 border border-slate-300 rounded hover:bg-slate-50 dark:hover:bg-slate-700"
                  >
                    Cancel
                  </button>
                </div>
                {saveError && (
                  <p className="text-xs text-red-600 dark:text-red-400 mt-1">{saveError}</p>
                )}
              </div>
            ) : goal ? (
              <div className="space-y-1.5">
                <p className="text-xs text-slate-700 dark:text-slate-300">{goal.goal_description}</p>
                {goal.measurable_outcomes && (
                  <div>
                    <p className="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Outcomes</p>
                    <p className="text-[11px] text-slate-600 dark:text-slate-400">{goal.measurable_outcomes}</p>
                  </div>
                )}
                {goal.target_date && (
                  <p className="text-[10px] text-slate-400">Target: {goal.target_date?.split('T')[0]}</p>
                )}
              </div>
            ) : (
              // No goal recorded for this domain yet
              <p className="text-[11px] text-slate-400 dark:text-slate-500 italic">
                {(plan.status === 'draft' || plan.status === 'under_review')
                  ? 'No goal recorded — click + Add Goal to create one.'
                  : 'No goal recorded for this domain.'}
              </p>
            )}
          </div>
          )
        })}
      </div>
    </div>
  )
}

// ─── MedicationsTab ────────────────────────────────────────────────────────────
// Displays participant medication list with drug interaction alerts.
// Lazy-loaded on first tab activation. Prescribers can add/discontinue meds.
// Drug interaction alerts are shown in a top banner (unacknowledged only).
// "Add Medication" modal includes typeahead against the reference table.

type MedicationRow = {
  id: number
  drug_name: string
  dose: number | null
  dose_unit: string | null
  route: string | null
  frequency: string | null
  is_prn: boolean
  status: string
  is_controlled: boolean
  controlled_schedule: string | null
  start_date: string
  end_date: string | null
  prescribing_provider?: { first_name: string; last_name: string } | null
}

type InteractionAlert = {
  id: number
  drug_name_1: string
  drug_name_2: string
  severity: string
  description: string
  is_acknowledged: boolean
}

type MedRefResult = {
  drug_name: string
  rxnorm_code: string | null
  drug_class: string | null
  common_dose: number | null
  dose_unit: string | null
  route: string | null
  frequency: string | null
  is_controlled: boolean
  controlled_schedule: string | null
}

const SEVERITY_COLORS: Record<string, string> = {
  contraindicated: 'bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-300 border-red-300',
  major:           'bg-orange-100 text-orange-800 border-orange-300',
  moderate:        'bg-amber-100 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300 border-amber-300',
  minor:           'bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300 border-yellow-300',
}

function MedicationsTab({ participantId }: { participantId: number }) {
  const [medications, setMedications]     = useState<MedicationRow[]>([])
  const [alerts, setAlerts]               = useState<InteractionAlert[]>([])
  const [loading, setLoading]             = useState(false)
  const [showAddForm, setShowAddForm]     = useState(false)
  const [saving, setSaving]               = useState(false)
  const [searchQuery, setSearchQuery]     = useState('')
  const [searchResults, setSearchResults] = useState<MedRefResult[]>([])

  const blankForm = {
    drug_name: '', rxnorm_code: '', dose: '', dose_unit: 'mg', route: 'oral',
    frequency: 'daily', is_prn: false, prn_indication: '', start_date: new Date().toISOString().slice(0, 10),
    is_controlled: false, controlled_schedule: '',
  }
  const [form, setForm] = useState(blankForm)

  // Load medications + unacknowledged interaction alerts on first activation
  useEffect(() => {
    if (loading || medications.length > 0) return
    setLoading(true)
    Promise.all([
      axios.get(`/participants/${participantId}/medications`),
      axios.get(`/participants/${participantId}/medications/interactions`),
    ]).then(([medResp, alertResp]) => {
      setMedications(medResp.data.medications ?? medResp.data)
      setAlerts(alertResp.data)
    }).catch(() => {}).finally(() => setLoading(false))
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // Debounced reference typeahead search
  useEffect(() => {
    if (searchQuery.length < 2) { setSearchResults([]); return }
    const t = setTimeout(() => {
      axios.get('/medications/reference/search', { params: { q: searchQuery } })
        .then(r => setSearchResults(r.data))
        .catch(() => setSearchResults([]))
    }, 300)
    return () => clearTimeout(t)
  }, [searchQuery])

  const applyRefResult = (ref: MedRefResult) => {
    setForm(f => ({
      ...f,
      drug_name:          ref.drug_name,
      rxnorm_code:        ref.rxnorm_code ?? '',
      dose:               ref.common_dose?.toString() ?? '',
      dose_unit:          ref.dose_unit ?? 'mg',
      route:              ref.route ?? 'oral',
      frequency:          ref.frequency ?? 'daily',
      is_controlled:      ref.is_controlled,
      controlled_schedule: ref.controlled_schedule ?? '',
    }))
    setSearchQuery(ref.drug_name)
    setSearchResults([])
  }

  const handleAdd = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      const resp = await axios.post(`/participants/${participantId}/medications`, {
        ...form,
        dose:    form.dose ? parseFloat(form.dose) : null,
        is_prn:  form.is_prn,
      })
      setMedications(prev => [resp.data.medication, ...prev])
      if (resp.data.new_alerts?.length) {
        setAlerts(prev => [...resp.data.new_alerts, ...prev])
      }
      setShowAddForm(false)
      setForm(blankForm)
      setSearchQuery('')
    } catch {
      // keep form open
    } finally {
      setSaving(false)
    }
  }

  const handleDiscontinue = async (med: MedicationRow) => {
    if (!confirm(`Discontinue ${med.drug_name}?`)) return
    try {
      await axios.put(`/participants/${participantId}/medications/${med.id}/discontinue`, {
        reason: 'Discontinued by clinician',
      })
      setMedications(prev => prev.map(m => m.id === med.id ? { ...m, status: 'discontinued' } : m))
    } catch {/* noop */}
  }

  const handleAcknowledgeAlert = async (alert: InteractionAlert) => {
    try {
      await axios.post(
        `/participants/${participantId}/medications/0/interactions/${alert.id}/acknowledge`,
        { acknowledgement_note: 'Reviewed and accepted' }
      )
      setAlerts(prev => prev.filter(a => a.id !== alert.id))
    } catch {/* noop */}
  }

  if (loading) return <LoadingSpinner />

  const activeMeds   = medications.filter(m => m.status === 'active' || m.status === 'prn')
  const inactiveMeds = medications.filter(m => m.status === 'discontinued' || m.status === 'on_hold')

  return (
    <div className="space-y-6">

      {/* Interaction alert banner */}
      {alerts.length > 0 && (
        <div className="space-y-2">
          {alerts.map(alert => (
            <div key={alert.id} className={`border rounded-lg p-3 flex items-start justify-between gap-4 ${SEVERITY_COLORS[alert.severity] ?? 'bg-gray-50 border-gray-200'}`}>
              <div>
                <span className="font-semibold text-sm uppercase">{alert.severity}:</span>
                <span className="ml-2 text-sm">{alert.drug_name_1} + {alert.drug_name_2}</span>
                <p className="text-xs mt-0.5 opacity-80">{alert.description}</p>
              </div>
              <button
                onClick={() => handleAcknowledgeAlert(alert)}
                className="text-xs px-2 py-1 bg-white dark:bg-slate-800 border border-current rounded hover:opacity-80 whitespace-nowrap shrink-0"
              >
                Acknowledge
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Header row */}
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">
          Active Medications
          {activeMeds.length > 0 && (
            <span className="ml-2 text-xs px-2 py-0.5 bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 rounded-full">{activeMeds.length}</span>
          )}
        </h3>
        <button
          onClick={() => setShowAddForm(v => !v)}
          data-testid="add-medication-btn"
          className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          {showAddForm ? 'Cancel' : '+ Add Medication'}
        </button>
      </div>

      {/* Add medication form */}
      {showAddForm && (
        <form onSubmit={handleAdd} data-testid="add-medication-form" className="bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded-lg p-4 space-y-4">
          {/* Drug name with typeahead */}
          <div className="relative">
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Drug Name *</label>
            <input
              type="text"
              value={searchQuery || form.drug_name}
              onChange={e => { setSearchQuery(e.target.value); setForm(f => ({ ...f, drug_name: e.target.value })) }}
              placeholder="Search medications..."
              className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100"
              required
            />
            {searchResults.length > 0 && (
              <div className="absolute z-20 w-full mt-1 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                {searchResults.map(r => (
                  <button
                    key={r.drug_name}
                    type="button"
                    onClick={() => applyRefResult(r)}
                    className="w-full text-left px-3 py-2 text-sm hover:bg-blue-50 dark:hover:bg-slate-700 border-b border-gray-100 dark:border-slate-700 last:border-0"
                  >
                    <span className="font-medium dark:text-slate-200">{r.drug_name}</span>
                    {r.drug_class && <span className="ml-2 text-xs text-gray-400 dark:text-slate-500">{r.drug_class}</span>}
                    {r.common_dose && <span className="ml-2 text-xs text-gray-500 dark:text-slate-400">{r.common_dose} {r.dose_unit}</span>}
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Dosing row */}
          <div className="grid grid-cols-4 gap-3">
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Dose</label>
              <input type="number" step="0.001" value={form.dose}
                onChange={e => setForm(f => ({ ...f, dose: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Unit</label>
              <select value={form.dose_unit} onChange={e => setForm(f => ({ ...f, dose_unit: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800">
                {['mg','mcg','ml','units','tab','cap','patch','drop'].map(u => <option key={u}>{u}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Route</label>
              <select value={form.route} onChange={e => setForm(f => ({ ...f, route: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800">
                {['oral','IV','IM','subcut','topical','inhaled','sublingual','rectal','nasal','optic','otic'].map(r => <option key={r}>{r}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Frequency</label>
              <select value={form.frequency} onChange={e => setForm(f => ({ ...f, frequency: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800">
                {['daily','BID','TID','QID','Q4H','Q6H','Q8H','Q12H','PRN','weekly','monthly','once'].map(f => <option key={f}>{f}</option>)}
              </select>
            </div>
          </div>

          {/* PRN toggle */}
          <label className="flex items-center gap-2 text-sm cursor-pointer">
            <input type="checkbox" checked={form.is_prn} onChange={e => setForm(f => ({ ...f, is_prn: e.target.checked }))} />
            <span>PRN (as needed)</span>
          </label>

          {/* Start date */}
          <div>
            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Start Date *</label>
            <input type="date" value={form.start_date} onChange={e => setForm(f => ({ ...f, start_date: e.target.value }))}
              className="mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" required />
          </div>

          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setShowAddForm(false)} className="text-xs px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
            <button type="submit" disabled={saving} className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Add Medication'}
            </button>
          </div>
        </form>
      )}

      {/* Active medications table */}
      {activeMeds.length === 0 ? (
        <div className="py-8 text-center text-gray-400 dark:text-slate-500 text-sm">No active medications on file.</div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50 dark:bg-slate-700/50 text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide">
              <tr>
                <th className="px-4 py-2 text-left">Medication</th>
                <th className="px-4 py-2 text-left">Dose / Route</th>
                <th className="px-4 py-2 text-left">Frequency</th>
                <th className="px-4 py-2 text-left">Status</th>
                <th className="px-4 py-2 text-left">Start Date</th>
                <th className="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
              {activeMeds.map(med => (
                <tr key={med.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                  <td className="px-4 py-2.5 font-medium text-gray-900 dark:text-slate-100">
                    {med.drug_name}
                    {med.is_controlled && (
                      <span className="ml-2 text-xs px-1.5 py-0.5 bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 rounded">
                        C-{med.controlled_schedule}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-gray-600 dark:text-slate-400">
                    {med.dose ? `${med.dose} ${med.dose_unit}` : '-'} {med.route && `(${med.route})`}
                  </td>
                  <td className="px-4 py-2.5 text-gray-600 dark:text-slate-400">{med.frequency ?? '-'}</td>
                  <td className="px-4 py-2.5">
                    <span className={`inline-block text-xs px-2 py-0.5 rounded-full font-medium ${
                      med.status === 'active' ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' :
                      med.status === 'prn'    ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300' :
                      med.status === 'on_hold'? 'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300' :
                      'bg-gray-100 text-gray-500'
                    }`}>{med.status}</span>
                  </td>
                  <td className="px-4 py-2.5 text-gray-500 dark:text-slate-400 text-xs">{med.start_date}</td>
                  <td className="px-4 py-2.5 text-right">
                    <button
                      onClick={() => handleDiscontinue(med)}
                      className="text-xs text-red-600 dark:text-red-400 hover:text-red-800 hover:underline"
                    >
                      Discontinue
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Discontinued medications (collapsed section) */}
      {inactiveMeds.length > 0 && (
        <details className="mt-4">
          <summary className="text-xs font-medium text-gray-500 dark:text-slate-400 cursor-pointer hover:text-gray-700 dark:hover:text-slate-300">
            Discontinued / On-Hold ({inactiveMeds.length})
          </summary>
          <div className="mt-2 overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700 opacity-70">
            <table className="min-w-full text-sm">
              <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
                {inactiveMeds.map(med => (
                  <tr key={med.id} className="bg-gray-50 dark:bg-slate-800/50">
                    <td className="px-4 py-2 text-gray-400 dark:text-slate-500 line-through">{med.drug_name}</td>
                    <td className="px-4 py-2 text-xs text-gray-400 dark:text-slate-500">{med.dose ? `${med.dose} ${med.dose_unit}` : '-'}</td>
                    <td className="px-4 py-2 text-xs text-gray-400 dark:text-slate-500">{med.frequency ?? '-'}</td>
                    <td className="px-4 py-2"><span className="text-xs text-gray-400 dark:text-slate-500">{med.status}</span></td>
                    <td className="px-4 py-2 text-xs text-gray-400 dark:text-slate-500">{med.end_date ?? '-'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </details>
      )}
    </div>
  )
}

// ─── EmarTab ───────────────────────────────────────────────────────────────────
// Electronic Medication Administration Record grid for a single date.
// Date picker defaults to today. Data lazy-loaded on tab activation.
// Each row = one scheduled dose. Nurse selects given/refused/held/missed.
// Controlled substances show a "Witness required" badge.

type EmarRow = {
  id: number
  scheduled_time: string
  administered_at: string | null
  status: string
  dose_given: string | null
  route_given: string | null
  reason_not_given: string | null
  notes: string | null
  medication: {
    id: number
    drug_name: string
    dose: number | null
    dose_unit: string | null
    route: string | null
    frequency: string | null
    is_controlled: boolean
    controlled_schedule: string | null
  } | null
  administered_by: { first_name: string; last_name: string } | null
  witness: { first_name: string; last_name: string } | null
}

const EMAR_STATUS_COLORS: Record<string, string> = {
  scheduled:     'bg-gray-100 text-gray-600',
  given:         'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300',
  refused:       'bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300',
  held:          'bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300',
  not_available: 'bg-orange-100 text-orange-700',
  late:          'bg-red-200 text-red-800 dark:text-red-300',
  missed:        'bg-red-100 dark:bg-red-900/60 text-red-600 dark:text-red-400',
}

function EmarTab({ participantId }: { participantId: number }) {
  const today = new Date().toISOString().slice(0, 10)
  const [date, setDate]           = useState(today)
  const [records, setRecords]     = useState<EmarRow[]>([])
  const [loading, setLoading]     = useState(false)
  const [chartingId, setChartingId] = useState<number | null>(null)
  const [chartForm, setChartForm] = useState<Record<string, string>>({})

  const loadEmar = (d: string) => {
    setLoading(true)
    axios.get(`/participants/${participantId}/emar`, { params: { date: d } })
      .then(r => setRecords(r.data))
      .catch(() => setRecords([]))
      .finally(() => setLoading(false))
  }

  // Load today's eMAR on first tab activation
  useEffect(() => { loadEmar(today) }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const handleDateChange = (d: string) => {
    setDate(d)
    loadEmar(d)
    setChartingId(null)
  }

  const startCharting = (record: EmarRow) => {
    setChartingId(record.id)
    setChartForm({ status: 'given', administered_at: new Date().toISOString().slice(0, 16) })
  }

  const submitCharting = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!chartingId) return
    try {
      await axios.post(`/participants/${participantId}/emar/${chartingId}/administer`, chartForm)
      // Refresh the day's eMAR after charting
      loadEmar(date)
      setChartingId(null)
    } catch {/* noop */}
  }

  const formatTime = (iso: string) =>
    new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })

  return (
    <div className="space-y-4">
      {/* Date selector */}
      <div className="flex items-center gap-3">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-slate-300">eMAR</h3>
        <input
          type="date"
          value={date}
          max={today}
          onChange={e => handleDateChange(e.target.value)}
          data-testid="emar-date-picker"
          className="text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-800 dark:text-slate-100"
        />
        <button onClick={() => handleDateChange(today)} className="text-xs text-blue-600 dark:text-blue-400 hover:underline">Today</button>
      </div>

      {loading && <LoadingSpinner />}

      {!loading && records.length === 0 && (
        <div className="py-8 text-center text-gray-400 dark:text-slate-500 text-sm">
          No eMAR records for {date}.
          <p className="text-xs mt-1">Records are generated nightly for scheduled medications.</p>
        </div>
      )}

      {!loading && records.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50 dark:bg-slate-700/50 text-xs text-gray-500 dark:text-slate-400 uppercase tracking-wide">
              <tr>
                <th className="px-4 py-2 text-left">Time</th>
                <th className="px-4 py-2 text-left">Medication</th>
                <th className="px-4 py-2 text-left">Ordered Dose</th>
                <th className="px-4 py-2 text-left">Status</th>
                <th className="px-4 py-2 text-left">Administered By</th>
                <th className="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
              {records.map(record => (
                <React.Fragment key={record.id}>
                  <tr className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                    <td className="px-4 py-2.5 text-gray-700 dark:text-slate-300 font-mono text-xs whitespace-nowrap">
                      {formatTime(record.scheduled_time)}
                    </td>
                    <td className="px-4 py-2.5">
                      <span className="font-medium text-gray-900 dark:text-slate-100">{record.medication?.drug_name ?? '-'}</span>
                      {record.medication?.is_controlled && (
                        <span className="ml-2 text-xs px-1.5 py-0.5 bg-red-100 dark:bg-red-900/60 text-red-700 dark:text-red-300 rounded">
                          C-{record.medication.controlled_schedule} · Witness req.
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-2.5 text-gray-600 dark:text-slate-400 text-xs">
                      {record.medication?.dose ? `${record.medication.dose} ${record.medication.dose_unit}` : '-'}
                      {record.medication?.route && ` (${record.medication.route})`}
                    </td>
                    <td className="px-4 py-2.5">
                      <span className={`inline-block text-xs px-2 py-0.5 rounded-full font-medium ${EMAR_STATUS_COLORS[record.status] ?? 'bg-gray-100 text-gray-500'}`}>
                        {record.status}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 text-gray-500 dark:text-slate-400 text-xs">
                      {record.administered_by
                        ? `${record.administered_by.first_name} ${record.administered_by.last_name}`
                        : record.status === 'scheduled' || record.status === 'late' ? '-' : '-'}
                      {record.witness && (
                        <span className="block text-gray-400 dark:text-slate-500">Witness: {record.witness.first_name} {record.witness.last_name}</span>
                      )}
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      {(record.status === 'scheduled' || record.status === 'late') && (
                        <button
                          onClick={() => startCharting(record)}
                          data-testid={`chart-btn-${record.id}`}
                          className="text-xs px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700"
                        >
                          Chart
                        </button>
                      )}
                    </td>
                  </tr>

                  {/* Inline charting row */}
                  {chartingId === record.id && (
                    <tr>
                      <td colSpan={6} className="px-4 py-3 bg-blue-50 dark:bg-blue-950/60 border-t border-blue-200 dark:border-blue-800">
                        <form onSubmit={submitCharting} className="flex flex-wrap items-end gap-3">
                          <div>
                            <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Status</label>
                            <select
                              value={chartForm.status}
                              onChange={e => setChartForm(f => ({ ...f, status: e.target.value }))}
                              className="block mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-800 dark:text-slate-100"
                            >
                              {['given','refused','held','not_available','missed'].map(s => (
                                <option key={s} value={s}>{s}</option>
                              ))}
                            </select>
                          </div>
                          {chartForm.status === 'given' && (
                            <div>
                              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Given At</label>
                              <input type="datetime-local" value={chartForm.administered_at ?? ''}
                                onChange={e => setChartForm(f => ({ ...f, administered_at: e.target.value }))}
                                className="block mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1 bg-white dark:bg-slate-800 dark:text-slate-100" />
                            </div>
                          )}
                          {['refused','held','not_available','missed'].includes(chartForm.status) && (
                            <div>
                              <label className="text-xs font-medium text-gray-600 dark:text-slate-400">Reason *</label>
                              <input type="text" value={chartForm.reason_not_given ?? ''}
                                onChange={e => setChartForm(f => ({ ...f, reason_not_given: e.target.value }))}
                                required className="block mt-1 text-sm border border-gray-300 dark:border-slate-600 rounded px-2 py-1 w-48 bg-white dark:bg-slate-800 dark:text-slate-100"
                                placeholder="Reason..." />
                            </div>
                          )}
                          <div className="flex gap-2">
                            <button type="button" onClick={() => setChartingId(null)}
                              className="text-xs px-3 py-1.5 border border-gray-300 dark:border-slate-600 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700">Cancel</button>
                            <button type="submit"
                              className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded hover:bg-blue-700">Save</button>
                          </div>
                        </form>
                      </td>
                    </tr>
                  )}
                </React.Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

// ─── MedReconTab ───────────────────────────────────────────────────────────────
// 5-step medication reconciliation wizard (CMS PACE regulation requirement).
// Step 1: Select prior source + reconciliation type → POST .../start
// Step 2: Enter prior medications (from discharge summary / pharmacy printout)
// Step 3: View generated comparison (matched / prior-only / current-only)
// Step 4: Apply decisions per medication (keep / discontinue / add / modify)
// Step 5: Provider approval → locks the record permanently
//
// Data flow: lazy-loaded on first tab activation from med-reconciliation history.
// An in-progress reconciliation skips directly to the relevant step.
// ──────────────────────────────────────────────────────────────────────────────

type RecStatus = 'in_progress' | 'decisions_made' | 'approved'

interface PriorMed {
  drug_name: string
  dose: string | null
  dose_unit: string | null
  frequency: string | null
  route: string | null
  prescriber: string | null
  notes: string | null
}

interface MedRec {
  id: number
  status: RecStatus
  reconciliation_type: string
  prior_source: string
  prior_medications: PriorMed[]
  reconciled_medications: unknown[]
  has_discrepancies: boolean
  reconciled_by: { first_name: string; last_name: string } | null
  approved_by: { first_name: string; last_name: string } | null
  approved_at: string | null
  created_at: string
}

interface ComparisonResult {
  matched: Array<{ prior: PriorMed; current: Record<string,unknown>; recommendation: string }>
  priorOnly: Array<{ prior: PriorMed; recommendation: string }>
  currentOnly: Array<{ current: Record<string,unknown>; recommendation: string }>
}

const PRIOR_SOURCES = [
  { value: 'discharge_summary', label: 'Discharge Summary' },
  { value: 'pharmacy_printout', label: 'Pharmacy Printout' },
  { value: 'patient_reported',  label: 'Patient/Family Reported' },
  { value: 'transfer_records',  label: 'Transfer Records' },
]

const RECON_TYPES = [
  { value: 'enrollment',    label: 'Enrollment' },
  { value: 'post_hospital', label: 'Post-Hospital' },
  { value: 'idt_review',    label: 'IDT Review' },
  { value: 'routine',       label: 'Routine' },
]

function MedReconTab({ participantId }: { participantId: number }) {
  // Wizard step: 1-5; null = loading/landing
  const [step, setStep] = useState<1 | 2 | 3 | 4 | 5>(1)
  const [rec, setRec] = useState<MedRec | null>(null)
  const [comparison, setComparison] = useState<ComparisonResult | null>(null)
  const [history, setHistory] = useState<MedRec[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Step 1 form state
  const [startForm, setStartForm] = useState({ prior_source: '', type: '' })

  // Step 2: prior medications entry
  const blankPriorMed: PriorMed = { drug_name: '', dose: '', dose_unit: '', frequency: '', route: 'oral', prescriber: '', notes: '' }
  const [priorMeds, setPriorMeds] = useState<PriorMed[]>([{ ...blankPriorMed }])

  // Step 4: decisions
  const [decisions, setDecisions] = useState<Array<{
    drug_name: string; medication_id: number | null; action: string; notes: string;
    new_dose?: string; new_frequency?: string; new_route?: string;
    prior_medication?: Record<string, unknown>
  }>>([])

  // Load history on first mount — check if there's an active reconciliation
  // useEffect: loads once on mount so clinician sees current status immediately
  useEffect(() => {
    axios.get(`/participants/${participantId}/med-reconciliation/history`)
      .then(r => {
        const recs: MedRec[] = r.data.data ?? []
        setHistory(recs)
        // If there's an active rec, jump directly to its step
        const active = recs.find(r => r.status === 'in_progress' || r.status === 'decisions_made')
        if (active) {
          setRec(active)
          setPriorMeds(active.prior_medications.length ? active.prior_medications : [{ ...blankPriorMed }])
          setStep(active.status === 'decisions_made' ? 4 : 2)
        }
      })
      .catch(() => setError('Failed to load reconciliation history.'))
      .finally(() => setLoading(false))
  }, [participantId])

  // Step 1 → start reconciliation
  function handleStart(e: React.FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    axios.post(`/participants/${participantId}/med-reconciliation/start`, startForm)
      .then(r => { setRec(r.data); setStep(2) })
      .catch(() => setError('Failed to start reconciliation.'))
      .finally(() => setSaving(false))
  }

  // Step 2 → persist prior medications to backend, then load comparison
  function handleSavePriorMeds(e: React.FormEvent) {
    e.preventDefault()
    if (!rec) return
    setSaving(true)
    setError(null)
    const filtered = priorMeds.filter(m => m.drug_name.trim() !== '')
    // POST prior meds to backend; comparison endpoint reads from DB
    axios.post(`/participants/${participantId}/med-reconciliation/prior-meds`, { medications: filtered })
      .then(() => {
        setPriorMeds(filtered)
        loadComparison()
      })
      .catch(() => setError('Failed to save prior medications.'))
      .finally(() => setSaving(false))
  }

  // Load comparison from backend (Step 3)
  function loadComparison() {
    setLoading(true)
    setError(null)
    axios.get(`/participants/${participantId}/med-reconciliation/comparison`)
      .then(r => {
        setComparison(r.data.comparison)
        // Pre-populate decisions from comparison
        const d: typeof decisions = []
        r.data.comparison.matched.forEach((m: ComparisonResult['matched'][0]) => {
          d.push({ drug_name: m.prior.drug_name, medication_id: (m.current as any).id ?? null, action: 'keep', notes: '' })
        })
        r.data.comparison.priorOnly.forEach((m: ComparisonResult['priorOnly'][0]) => {
          d.push({ drug_name: m.prior.drug_name, medication_id: null, action: 'add', notes: '', prior_medication: m.prior as unknown as Record<string, unknown> })
        })
        r.data.comparison.currentOnly.forEach((m: ComparisonResult['currentOnly'][0]) => {
          d.push({ drug_name: (m.current as any).drug_name, medication_id: (m.current as any).id ?? null, action: 'keep', notes: '' })
        })
        setDecisions(d)
        setStep(3)
      })
      .catch(() => setError('Failed to load comparison. Make sure prior medications are saved.'))
      .finally(() => setLoading(false))
  }

  // Step 4 → apply decisions
  function handleApplyDecisions(e: React.FormEvent) {
    e.preventDefault()
    setSaving(true)
    setError(null)
    axios.post(`/participants/${participantId}/med-reconciliation/decisions`, { decisions })
      .then(r => { setRec(r.data); setStep(5) })
      .catch(err => setError(err.response?.data?.message ?? 'Failed to apply decisions.'))
      .finally(() => setSaving(false))
  }

  // Step 5 → provider approval
  function handleApprove() {
    setSaving(true)
    setError(null)
    axios.post(`/participants/${participantId}/med-reconciliation/approve`)
      .then(r => {
        setRec(r.data)
        setHistory(h => [r.data, ...h.filter(x => x.id !== r.data.id)])
        setStep(1)  // reset wizard; landing will show history
        setComparison(null)
      })
      .catch(err => setError(err.response?.data?.message ?? 'Failed to approve reconciliation.'))
      .finally(() => setSaving(false))
  }

  if (loading) return <div className="py-10 text-center text-gray-400 dark:text-slate-500 text-sm">Loading...</div>

  return (
    <div>
      {error && (
        <div className="mb-4 bg-red-50 dark:bg-red-950/60 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm px-4 py-3 rounded">
          {error}
        </div>
      )}

      {/* Step indicator */}
      <div className="flex items-center gap-2 mb-6">
        {[1,2,3,4,5].map(s => (
          <React.Fragment key={s}>
            <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold
              ${step === s ? 'bg-blue-600 text-white' : step > s ? 'bg-green-500 text-white' : 'bg-gray-200 dark:bg-slate-700 text-gray-500 dark:text-slate-400'}`}>
              {s}
            </div>
            {s < 5 && <div className={`h-0.5 flex-1 ${step > s ? 'bg-green-500' : 'bg-gray-200 dark:bg-slate-700'}`} />}
          </React.Fragment>
        ))}
      </div>
      <div className="flex gap-6 text-xs text-gray-500 dark:text-slate-400 mb-6 -mt-4">
        {['Start', 'Prior Meds', 'Compare', 'Decisions', 'Approve'].map((label, i) => (
          <span key={i} className={`flex-1 ${step === i+1 ? 'text-blue-600 dark:text-blue-400 font-semibold' : ''}`}>{label}</span>
        ))}
      </div>

      {/* ── Step 1: Start ── */}
      {step === 1 && (
        <div className="max-w-lg">
          <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Start Medication Reconciliation</h3>
          <p className="text-sm text-gray-500 dark:text-slate-400 mb-4">Select the source of the prior medication list and the reconciliation type.</p>
          <form onSubmit={handleStart} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Prior Medication Source</label>
              <select value={startForm.prior_source}
                onChange={e => setStartForm(f => ({ ...f, prior_source: e.target.value }))}
                required
                className="block w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">Select source…</option>
                {PRIOR_SOURCES.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">Reconciliation Type</label>
              <select value={startForm.type}
                onChange={e => setStartForm(f => ({ ...f, type: e.target.value }))}
                required
                className="block w-full border border-gray-300 dark:border-slate-600 rounded-md px-3 py-2 text-sm bg-white dark:bg-slate-800 dark:text-slate-100 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">Select type…</option>
                {RECON_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
              </select>
            </div>
            <button type="submit" disabled={saving}
              className="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Starting…' : 'Start Reconciliation →'}
            </button>
          </form>

          {/* Past reconciliations */}
          {history.length > 0 && (
            <div className="mt-8">
              <h4 className="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-2">Reconciliation History</h4>
              <div className="space-y-2">
                {history.map(h => (
                  <div key={h.id} className="flex items-center justify-between border border-gray-200 dark:border-slate-700 rounded px-3 py-2 text-sm">
                    <div>
                      <span className="font-medium dark:text-slate-200">{RECON_TYPES.find(t => t.value === h.reconciliation_type)?.label ?? h.reconciliation_type}</span>
                      <span className="text-gray-400 dark:text-slate-500 ml-2">·</span>
                      <span className="text-gray-500 dark:text-slate-400 ml-2">{PRIOR_SOURCES.find(s => s.value === h.prior_source)?.label ?? h.prior_source}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      {h.has_discrepancies && <span className="text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded">Discrepancies</span>}
                      <span className={`text-xs px-1.5 py-0.5 rounded font-medium ${
                        h.status === 'approved' ? 'bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300' :
                        h.status === 'decisions_made' ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300' :
                        'bg-gray-100 text-gray-600'}`}>
                        {h.status === 'approved' ? 'Approved' : h.status === 'decisions_made' ? 'Pending Approval' : 'In Progress'}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* ── Step 2: Enter prior medications ── */}
      {step === 2 && rec && (
        <div>
          <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Enter Prior Medications</h3>
          <p className="text-sm text-gray-500 dark:text-slate-400 mb-4">
            Enter medications from the <strong>{PRIOR_SOURCES.find(s => s.value === rec.prior_source)?.label}</strong>.
            Add all medications listed on the source document.
          </p>
          <form onSubmit={handleSavePriorMeds}>
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm border border-gray-200 dark:border-slate-700 rounded">
                <thead className="bg-gray-50 dark:bg-slate-700/50">
                  <tr>
                    {['Drug Name *', 'Dose', 'Unit', 'Frequency', 'Route', 'Prescriber', 'Notes', ''].map(h => (
                      <th key={h} className="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-slate-400">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {priorMeds.map((m, i) => (
                    <tr key={i} className="border-t border-gray-100 dark:border-slate-700/50">
                      <td className="px-1 py-1"><input required value={m.drug_name} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, drug_name: e.target.value} : x))}
                        className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-32 bg-white dark:bg-slate-700 dark:text-slate-100" placeholder="Drug name" /></td>
                      <td className="px-1 py-1"><input value={m.dose ?? ''} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, dose: e.target.value} : x))}
                        className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-16 bg-white dark:bg-slate-700 dark:text-slate-100" placeholder="Dose" /></td>
                      <td className="px-1 py-1"><input value={m.dose_unit ?? ''} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, dose_unit: e.target.value} : x))}
                        className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-14 bg-white dark:bg-slate-700 dark:text-slate-100" placeholder="mg" /></td>
                      <td className="px-1 py-1">
                        <select value={m.frequency ?? ''} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, frequency: e.target.value} : x))}
                          className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm bg-white dark:bg-slate-700 dark:text-slate-100">
                          <option value=""></option>
                          {['daily','twice_daily','three_times_daily','four_times_daily','weekly','monthly','as_needed','nightly','every_other_day'].map(f =>
                            <option key={f} value={f}>{f.replace(/_/g,' ')}</option>)}
                        </select>
                      </td>
                      <td className="px-1 py-1">
                        <select value={m.route ?? 'oral'} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, route: e.target.value} : x))}
                          className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm bg-white dark:bg-slate-700 dark:text-slate-100">
                          {['oral','sublingual','topical','inhaled','intravenous','intramuscular','subcutaneous','transdermal','ophthalmic','otic','nasal','rectal'].map(r =>
                            <option key={r} value={r}>{r}</option>)}
                        </select>
                      </td>
                      <td className="px-1 py-1"><input value={m.prescriber ?? ''} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, prescriber: e.target.value} : x))}
                        className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-28 bg-white dark:bg-slate-700 dark:text-slate-100" placeholder="Prescriber" /></td>
                      <td className="px-1 py-1"><input value={m.notes ?? ''} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, notes: e.target.value} : x))}
                        className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-28 bg-white dark:bg-slate-700 dark:text-slate-100" placeholder="Notes" /></td>
                      <td className="px-1 py-1">
                        {priorMeds.length > 1 && (
                          <button type="button" onClick={() => setPriorMeds(ms => ms.filter((_,j) => j !== i))}
                            className="text-red-400 hover:text-red-600 text-xs">✕</button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <button type="button" onClick={() => setPriorMeds(ms => [...ms, { ...blankPriorMed }])}
              className="mt-2 text-sm text-blue-600 dark:text-blue-400 hover:text-blue-700">+ Add medication</button>
            <div className="flex gap-3 mt-4">
              <button type="button" onClick={() => setStep(1)} className="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700">← Back</button>
              <button type="submit" disabled={saving}
                className="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50">
                {saving ? 'Saving…' : 'Generate Comparison →'}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* ── Step 3: View comparison ── */}
      {step === 3 && comparison && (
        <div>
          <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Medication Comparison</h3>
          <p className="text-sm text-gray-500 dark:text-slate-400 mb-4">Review prior medications vs current active medications. Proceed to Step 4 to apply decisions.</p>

          {/* Matched */}
          {comparison.matched.length > 0 && (
            <div className="mb-4">
              <h4 className="text-xs font-semibold text-green-700 dark:text-green-300 uppercase mb-1">Matched ({comparison.matched.length})</h4>
              <div className="space-y-1">
                {comparison.matched.map((m, i) => (
                  <div key={i} className="flex items-center gap-2 bg-green-50 dark:bg-green-950/60 border border-green-200 dark:border-green-800 rounded px-3 py-2 text-sm">
                    <span className="w-2 h-2 rounded-full bg-green-500 flex-shrink-0" />
                    <span className="font-medium w-40">{m.prior.drug_name}</span>
                    <span className="text-gray-400 dark:text-slate-500 text-xs">Prior: {m.prior.dose} {m.prior.dose_unit} {m.prior.frequency}</span>
                    <span className="text-gray-400 dark:text-slate-500 text-xs ml-auto">Current: {(m.current as any).dose} {(m.current as any).dose_unit} {(m.current as any).frequency}</span>
                    <span className="ml-2 text-xs bg-green-100 dark:bg-green-900/60 text-green-700 dark:text-green-300 px-1.5 py-0.5 rounded">Keep</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Prior only */}
          {comparison.priorOnly.length > 0 && (
            <div className="mb-4">
              <h4 className="text-xs font-semibold text-amber-700 dark:text-amber-300 uppercase mb-1">Prior List Only: Not in Current ({comparison.priorOnly.length})</h4>
              <div className="space-y-1">
                {comparison.priorOnly.map((m, i) => (
                  <div key={i} className="flex items-center gap-2 bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 rounded px-3 py-2 text-sm">
                    <span className="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0" />
                    <span className="font-medium w-40">{m.prior.drug_name}</span>
                    <span className="text-gray-400 dark:text-slate-500 text-xs">{m.prior.dose} {m.prior.dose_unit} {m.prior.frequency}</span>
                    <span className="ml-auto text-xs bg-amber-100 dark:bg-amber-900/60 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded">Add or Ignore</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Current only */}
          {comparison.currentOnly.length > 0 && (
            <div className="mb-4">
              <h4 className="text-xs font-semibold text-blue-700 dark:text-blue-300 uppercase mb-1">Current Only: Not on Prior List ({comparison.currentOnly.length})</h4>
              <div className="space-y-1">
                {comparison.currentOnly.map((m, i) => (
                  <div key={i} className="flex items-center gap-2 bg-blue-50 dark:bg-blue-950/60 border border-blue-200 dark:border-blue-800 rounded px-3 py-2 text-sm">
                    <span className="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0" />
                    <span className="font-medium w-40">{(m.current as any).drug_name}</span>
                    <span className="text-gray-400 dark:text-slate-500 text-xs">{(m.current as any).dose} {(m.current as any).dose_unit} {(m.current as any).frequency}</span>
                    <span className="ml-auto text-xs bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded">Keep or Discontinue</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="flex gap-3 mt-4">
            <button onClick={() => setStep(2)} className="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700">← Back</button>
            <button onClick={() => setStep(4)} className="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700">Apply Decisions →</button>
          </div>
        </div>
      )}

      {/* ── Step 4: Apply decisions ── */}
      {step === 4 && (
        <div>
          <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Apply Decisions</h3>
          <p className="text-sm text-gray-500 dark:text-slate-400 mb-4">For each medication, choose an action. Decisions will be applied to the active medication list.</p>
          <form onSubmit={handleApplyDecisions}>
            <div className="space-y-2">
              {decisions.map((d, i) => (
                <div key={i} className="border border-gray-200 dark:border-slate-700 rounded px-3 py-3 flex flex-wrap gap-3 items-start">
                  <div className="font-medium text-sm w-36 dark:text-slate-200">{d.drug_name}</div>
                  <div>
                    <select value={d.action} onChange={e => setDecisions(ds => ds.map((x,j) => j===i ? {...x, action: e.target.value} : x))}
                      className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm bg-white dark:bg-slate-700 dark:text-slate-100">
                      <option value="keep">Keep</option>
                      <option value="discontinue">Discontinue</option>
                      <option value="add">Add</option>
                      <option value="modify">Modify</option>
                    </select>
                  </div>
                  {d.action === 'modify' && (
                    <>
                      <input value={d.new_dose ?? ''} onChange={e => setDecisions(ds => ds.map((x,j) => j===i ? {...x, new_dose: e.target.value} : x))}
                        placeholder="New dose" className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-20 bg-white dark:bg-slate-700 dark:text-slate-100" />
                      <input value={d.new_frequency ?? ''} onChange={e => setDecisions(ds => ds.map((x,j) => j===i ? {...x, new_frequency: e.target.value} : x))}
                        placeholder="New frequency" className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-28 bg-white dark:bg-slate-700 dark:text-slate-100" />
                      <input value={d.new_route ?? ''} onChange={e => setDecisions(ds => ds.map((x,j) => j===i ? {...x, new_route: e.target.value} : x))}
                        placeholder="New route (opt)" className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm w-24 bg-white dark:bg-slate-700 dark:text-slate-100" />
                    </>
                  )}
                  <input value={d.notes} onChange={e => setDecisions(ds => ds.map((x,j) => j===i ? {...x, notes: e.target.value} : x))}
                    placeholder="Notes (optional)" className="border border-gray-300 dark:border-slate-600 rounded px-2 py-1 text-sm flex-1 min-w-32 bg-white dark:bg-slate-700 dark:text-slate-100" />
                </div>
              ))}
            </div>
            <div className="flex gap-3 mt-4">
              <button type="button" onClick={() => setStep(3)} className="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700">← Back</button>
              <button type="submit" disabled={saving}
                className="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50">
                {saving ? 'Saving…' : 'Submit Decisions →'}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* ── Step 5: Provider approval ── */}
      {step === 5 && rec && (
        <div className="max-w-lg">
          <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100 mb-1">Provider Approval</h3>
          <p className="text-sm text-gray-500 dark:text-slate-400 mb-4">
            Review the reconciliation summary and approve to lock the record. Once approved, no further changes can be made.
          </p>
          <div className="border border-gray-200 dark:border-slate-700 rounded p-4 bg-gray-50 dark:bg-slate-800 mb-4 text-sm space-y-1">
            <div><span className="text-gray-500 dark:text-slate-400">Type:</span> <span className="font-medium dark:text-slate-200">{RECON_TYPES.find(t => t.value === rec.reconciliation_type)?.label}</span></div>
            <div><span className="text-gray-500 dark:text-slate-400">Source:</span> <span className="font-medium dark:text-slate-200">{PRIOR_SOURCES.find(s => s.value === rec.prior_source)?.label}</span></div>
            <div><span className="text-gray-500 dark:text-slate-400">Reconciled by:</span> <span className="font-medium dark:text-slate-200">{rec.reconciled_by ? `${rec.reconciled_by.first_name} ${rec.reconciled_by.last_name}` : '-'}</span></div>
            {rec.has_discrepancies && (
              <div className="mt-2 bg-amber-50 dark:bg-amber-950/60 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300 text-xs px-3 py-2 rounded">
                <ExclamationTriangleIcon className="w-3.5 h-3.5 inline mr-1" />This reconciliation has documented discrepancies requiring clinical follow-up.
              </div>
            )}
          </div>
          <div className="flex gap-3">
            <button onClick={() => setStep(4)} className="text-sm px-4 py-2 border border-gray-300 dark:border-slate-600 dark:text-slate-300 rounded hover:bg-gray-50 dark:hover:bg-slate-700">← Back</button>
            <button onClick={handleApprove} disabled={saving}
              className="bg-green-600 text-white text-sm px-4 py-2 rounded hover:bg-green-700 disabled:opacity-50">
              {saving ? 'Approving…' : '✓ Approve & Lock Record'}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

// ─── Main Page Component ───────────────────────────────────────────────────────
// Renders the two-section tab navigation and delegates to tab components.
// CLINICAL tabs: blue active underline.  ADMIN tabs: slate active underline.

// ─── ImmunizationsTab ─────────────────────────────────────────────────────────
// Lazy-loads immunization records from GET /participants/{id}/immunizations.
// Allows recording new immunizations or refusals (primary_care + nursing only).

interface ImmunizationRecord {
  id:                       number
  vaccine_type:             string
  vaccine_name:             string
  cvx_code:                 string | null
  administered_date:        string
  administered_at_location: string | null
  lot_number:               string | null
  manufacturer:             string | null
  dose_number:              number | null
  next_dose_due:            string | null
  refused:                  boolean
  refusal_reason:           string | null
  vis_given:                boolean
  vis_publication_date:     string | null
  administered_by:          { id: number; first_name: string; last_name: string } | null
}

const VACCINE_TYPE_LABELS: Record<string, string> = {
  influenza:            'Influenza (Flu)',
  pneumococcal_ppsv23: 'Pneumococcal PPSV23',
  pneumococcal_pcv15:  'Pneumococcal PCV15',
  pneumococcal_pcv20:  'Pneumococcal PCV20',
  covid_19:            'COVID-19',
  tdap:                'Tdap',
  shingles:            'Shingles (Zoster)',
  hepatitis_b:         'Hepatitis B',
  other:               'Other',
}

function ImmunizationsTab({ participantId }: { participantId: number }) {
  const [immunizations, setImmunizations] = useState<ImmunizationRecord[]>([])
  const [loading, setLoading]             = useState(true)
  const [showForm, setShowForm]           = useState(false)
  const [saving, setSaving]               = useState(false)
  const blankImmForm = {
    vaccine_type: 'influenza', vaccine_name: '', administered_date: new Date().toISOString().slice(0, 10),
    lot_number: '', manufacturer: '', administered_at_location: '', dose_number: '',
    next_dose_due: '', refused: false, refusal_reason: '',
    vis_given: false, vis_publication_date: '',
  }
  const [form, setForm]                   = useState(blankImmForm)

  useEffect(() => {
    axios.get(`/participants/${participantId}/immunizations`)
      .then(r => setImmunizations(r.data.data ?? r.data))
      .finally(() => setLoading(false))
  }, [participantId])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    axios.post(`/participants/${participantId}/immunizations`, form)
      .then(r => {
        setImmunizations(prev => [r.data, ...prev])
        setShowForm(false)
        setForm(blankImmForm)
      })
      .finally(() => setSaving(false))
  }

  if (loading) return <div className="p-6 text-gray-400 dark:text-slate-500 text-sm">Loading immunizations…</div>

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">Immunization Record</h3>
        <button onClick={() => setShowForm(v => !v)}
          className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700">
          + Record Immunization
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-gray-50 dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4 space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Vaccine Type</label>
              <select value={form.vaccine_type} onChange={e => setForm(f => ({ ...f, vaccine_type: e.target.value, vaccine_name: VACCINE_TYPE_LABELS[e.target.value] ?? '' }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100">
                {Object.entries(VACCINE_TYPE_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Vaccine Name / Brand</label>
              <input value={form.vaccine_name} onChange={e => setForm(f => ({ ...f, vaccine_name: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" required />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Date Administered</label>
              <input type="date" value={form.administered_date} onChange={e => setForm(f => ({ ...f, administered_date: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" required />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Lot Number</label>
              <input value={form.lot_number} onChange={e => setForm(f => ({ ...f, lot_number: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Manufacturer</label>
              <input value={form.manufacturer} onChange={e => setForm(f => ({ ...f, manufacturer: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Location</label>
              <input value={form.administered_at_location} onChange={e => setForm(f => ({ ...f, administered_at_location: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
            </div>
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
            <input type="checkbox" checked={form.refused} onChange={e => setForm(f => ({ ...f, refused: e.target.checked }))} />
            Patient Refused
          </label>
          {form.refused && (
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Refusal Reason</label>
              <input value={form.refusal_reason} onChange={e => setForm(f => ({ ...f, refusal_reason: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
            </div>
          )}
          {/* VIS documentation — QW-11 (42 USC 300aa-26) */}
          {!form.refused && (
            <div className="border border-indigo-200 dark:border-indigo-800 rounded p-3 bg-indigo-50 dark:bg-indigo-950/40 space-y-2">
              <p className="text-xs font-semibold text-indigo-700 dark:text-indigo-300">Vaccine Information Statement (VIS)</p>
              <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                <input type="checkbox" checked={form.vis_given} onChange={e => setForm(f => ({ ...f, vis_given: e.target.checked }))} />
                VIS given to patient / representative
              </label>
              {form.vis_given && (
                <div>
                  <label className="text-xs font-medium text-gray-700 dark:text-slate-300">VIS Publication Date</label>
                  <input type="date" value={form.vis_publication_date} onChange={e => setForm(f => ({ ...f, vis_publication_date: e.target.value }))}
                    className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
                </div>
              )}
            </div>
          )}
          <div className="flex gap-2 justify-end">
            <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 border border-gray-300 dark:border-slate-600 rounded text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700">Cancel</button>
            <button type="submit" disabled={saving} className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Save'}
            </button>
          </div>
        </form>
      )}

      {immunizations.length === 0
        ? <p className="text-gray-400 dark:text-slate-500 text-sm py-4">No immunizations on file.</p>
        : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 dark:border-slate-700 text-xs text-gray-500 dark:text-slate-400 uppercase">
                <th className="text-left py-2 font-medium">Vaccine</th>
                <th className="text-left py-2 font-medium">Date</th>
                <th className="text-left py-2 font-medium">Lot / Mfr</th>
                <th className="text-left py-2 font-medium">Status</th>
                <th className="text-left py-2 font-medium">VIS</th>
                <th className="text-left py-2 font-medium">Next Due</th>
              </tr>
            </thead>
            <tbody>
              {immunizations.map(imm => (
                <tr key={imm.id} className="border-b border-gray-100 dark:border-slate-700 last:border-0 hover:bg-gray-50 dark:hover:bg-slate-700/50">
                  <td className="py-2 font-medium text-gray-900 dark:text-slate-100">{VACCINE_TYPE_LABELS[imm.vaccine_type] ?? imm.vaccine_type}</td>
                  <td className="py-2 text-gray-600 dark:text-slate-400">{new Date(imm.administered_date).toLocaleDateString()}</td>
                  <td className="py-2 text-gray-500 dark:text-slate-400 text-xs">{[imm.lot_number, imm.manufacturer].filter(Boolean).join(' / ') || '-'}</td>
                  <td className="py-2">
                    {imm.refused
                      ? <span className="px-1.5 py-0.5 rounded text-xs bg-yellow-100 dark:bg-yellow-900/60 text-yellow-800 dark:text-yellow-300">Refused</span>
                      : <span className="px-1.5 py-0.5 rounded text-xs bg-green-100 dark:bg-green-900/60 text-green-800 dark:text-green-300">Administered</span>
                    }
                  </td>
                  {/* VIS column — QW-11 */}
                  <td className="py-2 text-xs">
                    {imm.refused ? (
                      <span className="text-gray-400 dark:text-slate-500">N/A</span>
                    ) : imm.vis_given ? (
                      <span className="text-green-600 dark:text-green-400">
                        Given{imm.vis_publication_date ? ` (${new Date(imm.vis_publication_date.slice(0, 10)).toLocaleDateString()})` : ''}
                      </span>
                    ) : (
                      <span className="text-amber-600 dark:text-amber-400">Not documented</span>
                    )}
                  </td>
                  <td className="py-2 text-gray-500 dark:text-slate-400 text-xs">{imm.next_dose_due ? new Date(imm.next_dose_due.slice(0, 10)).toLocaleDateString() : '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )
      }
    </div>
  )
}

// ─── ProceduresTab ────────────────────────────────────────────────────────────
// Lazy-loads procedure history from GET /participants/{id}/procedures.
// Records CPT/SNOMED-coded procedures with source attribution.

interface ProcedureRecord {
  id:             number
  procedure_name: string
  cpt_code:       string | null
  snomed_code:    string | null
  performed_date: string
  facility:       string | null
  body_site:      string | null
  outcome:        string | null
  notes:          string | null
  source:         string
  performed_by:   { id: number; first_name: string; last_name: string } | null
}

function ProceduresTab({ participantId }: { participantId: number }) {
  const [procedures, setProcedures] = useState<ProcedureRecord[]>([])
  const [loading, setLoading]       = useState(true)
  const [showForm, setShowForm]     = useState(false)
  const [saving, setSaving]         = useState(false)
  const [form, setForm]             = useState({
    procedure_name: '', cpt_code: '', snomed_code: '', performed_date: new Date().toISOString().slice(0, 10),
    facility: '', body_site: '', outcome: '', notes: '', source: 'internal',
  })

  useEffect(() => {
    axios.get(`/participants/${participantId}/procedures`)
      .then(r => setProcedures(r.data.data ?? r.data))
      .finally(() => setLoading(false))
  }, [participantId])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    axios.post(`/participants/${participantId}/procedures`, form)
      .then(r => {
        setProcedures(prev => [r.data, ...prev])
        setShowForm(false)
        setForm({ procedure_name: '', cpt_code: '', snomed_code: '', performed_date: new Date().toISOString().slice(0, 10), facility: '', body_site: '', outcome: '', notes: '', source: 'internal' })
      })
      .finally(() => setSaving(false))
  }

  if (loading) return <div className="p-6 text-gray-400 dark:text-slate-500 text-sm">Loading procedures…</div>

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">Procedure History</h3>
        <button onClick={() => setShowForm(v => !v)}
          className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700">
          + Add Procedure
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-gray-50 dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4 space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div className="col-span-2">
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Procedure Name *</label>
              <input value={form.procedure_name} onChange={e => setForm(f => ({ ...f, procedure_name: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" required />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">CPT Code</label>
              <input value={form.cpt_code} onChange={e => setForm(f => ({ ...f, cpt_code: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" placeholder="e.g. 99213" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Performed Date *</label>
              <input type="date" value={form.performed_date} onChange={e => setForm(f => ({ ...f, performed_date: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" required />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Source</label>
              <select value={form.source} onChange={e => setForm(f => ({ ...f, source: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100">
                <option value="internal">Internal (PACE)</option>
                <option value="external_report">External Report</option>
                <option value="patient_reported">Patient Reported</option>
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Facility</label>
              <input value={form.facility} onChange={e => setForm(f => ({ ...f, facility: e.target.value }))}
                className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
            </div>
          </div>
          <div className="flex gap-2 justify-end">
            <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 border border-gray-300 dark:border-slate-600 rounded text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700">Cancel</button>
            <button type="submit" disabled={saving} className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Save'}
            </button>
          </div>
        </form>
      )}

      {procedures.length === 0
        ? <p className="text-gray-400 dark:text-slate-500 text-sm py-4">No procedures on file.</p>
        : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 dark:border-slate-700 text-xs text-gray-500 dark:text-slate-400 uppercase">
                <th className="text-left py-2 font-medium">Procedure</th>
                <th className="text-left py-2 font-medium">Date</th>
                <th className="text-left py-2 font-medium">CPT</th>
                <th className="text-left py-2 font-medium">Facility</th>
                <th className="text-left py-2 font-medium">Source</th>
              </tr>
            </thead>
            <tbody>
              {procedures.map(proc => (
                <tr key={proc.id} className="border-b border-gray-100 dark:border-slate-700 last:border-0 hover:bg-gray-50 dark:hover:bg-slate-700/50">
                  <td className="py-2 font-medium text-gray-900 dark:text-slate-100">{proc.procedure_name}</td>
                  <td className="py-2 text-gray-600 dark:text-slate-400">{new Date(proc.performed_date).toLocaleDateString()}</td>
                  <td className="py-2 font-mono text-xs text-gray-500 dark:text-slate-400">{proc.cpt_code ?? '-'}</td>
                  <td className="py-2 text-gray-500 dark:text-slate-400 text-xs">{proc.facility ?? '-'}</td>
                  <td className="py-2">
                    <span className={`px-1.5 py-0.5 rounded text-xs ${
                      proc.source === 'internal'        ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300' :
                      proc.source === 'external_report' ? 'bg-orange-100 dark:bg-orange-900/60 text-orange-700 dark:text-orange-300' :
                      'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-300'
                    }`}>
                      {proc.source === 'internal' ? 'PACE' : proc.source === 'external_report' ? 'External' : 'Pt. Reported'}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )
      }
    </div>
  )
}

// ─── SdohTab ──────────────────────────────────────────────────────────────────
// Social Determinants of Health screening history.
// Each screening record covers 6 SDOH domains (USCDI v3 requirement).
// Lazy-loads from GET /participants/{id}/social-determinants.

interface SdohRecord {
  id:                    number
  assessed_at:           string
  housing_stability:     string | null
  food_security:         string | null
  transportation_access: string | null
  social_isolation_risk: string | null
  caregiver_strain:      string | null
  financial_strain:      string | null
  safety_concerns:       string | null
  notes:                 string | null
  assessed_by:           { id: number; first_name: string; last_name: string } | null
}

const SDOH_RISK_COLOR: Record<string, string> = {
  stable:          'text-green-700 dark:text-green-300',
  at_risk:         'text-yellow-700 dark:text-yellow-300',
  high_risk:       'text-red-700 dark:text-red-300',
  adequate:        'text-green-700 dark:text-green-300',
  inadequate:      'text-red-700 dark:text-red-300',
  limited:         'text-red-700 dark:text-red-300',
  available:       'text-green-700 dark:text-green-300',
  isolated:        'text-red-700 dark:text-red-300',
  low:             'text-green-700 dark:text-green-300',
  moderate:        'text-yellow-700 dark:text-yellow-300',
  high:            'text-red-700 dark:text-red-300',
  none:            'text-green-700 dark:text-green-300',
  minor:           'text-yellow-700 dark:text-yellow-300',
  severe:          'text-red-700 dark:text-red-300',
}

function SdohTab({ participantId }: { participantId: number }) {
  const [records, setRecords] = useState<SdohRecord[]>([])
  const [loading, setLoading] = useState(true)
  const [showForm, setShowForm] = useState(false)
  const [saving, setSaving]    = useState(false)
  const [form, setForm]        = useState({
    housing_stability: '', food_security: '', transportation_access: '',
    social_isolation_risk: '', caregiver_strain: '', financial_strain: '',
    safety_concerns: '', notes: '',
  })

  useEffect(() => {
    axios.get(`/participants/${participantId}/social-determinants`)
      .then(r => setRecords(r.data.data ?? r.data))
      .finally(() => setLoading(false))
  }, [participantId])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    axios.post(`/participants/${participantId}/social-determinants`, form)
      .then(r => {
        setRecords(prev => [r.data, ...prev])
        setShowForm(false)
      })
      .finally(() => setSaving(false))
  }

  const DOMAINS = [
    { key: 'housing_stability',     label: 'Housing Stability',     options: ['stable','at_risk','high_risk'] },
    { key: 'food_security',         label: 'Food Security',         options: ['adequate','at_risk','inadequate'] },
    { key: 'transportation_access', label: 'Transportation Access', options: ['available','limited'] },
    { key: 'social_isolation_risk', label: 'Social Isolation',      options: ['none','at_risk','isolated'] },
    { key: 'caregiver_strain',      label: 'Caregiver Strain',      options: ['low','moderate','high'] },
    { key: 'financial_strain',      label: 'Financial Strain',      options: ['none','moderate','severe'] },
  ] as const

  if (loading) return <div className="p-6 text-gray-400 dark:text-slate-500 text-sm">Loading SDOH screenings…</div>

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">Social Determinants of Health</h3>
          <p className="text-xs text-gray-400 dark:text-slate-500 mt-0.5">USCDI v3 · PRAPARE-aligned screening</p>
        </div>
        <button onClick={() => setShowForm(v => !v)}
          className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700">
          + New Screening
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-gray-50 dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700 p-4 space-y-3">
          <div className="grid grid-cols-2 gap-3">
            {DOMAINS.map(d => (
              <div key={d.key}>
                <label className="text-xs font-medium text-gray-700 dark:text-slate-300">{d.label}</label>
                <select value={(form as any)[d.key]} onChange={e => setForm(f => ({ ...f, [d.key]: e.target.value }))}
                  className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100">
                  <option value="">Not assessed</option>
                  {d.options.map(o => <option key={o} value={o}>{o.replace(/_/g, ' ')}</option>)}
                </select>
              </div>
            ))}
          </div>
          <div>
            <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Safety Concerns</label>
            <input value={form.safety_concerns} onChange={e => setForm(f => ({ ...f, safety_concerns: e.target.value }))}
              className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" placeholder="e.g. domestic violence, unsafe living conditions" />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-700 dark:text-slate-300">Notes</label>
            <textarea value={form.notes} onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
              rows={2} className="mt-1 block w-full border border-gray-300 dark:border-slate-600 rounded text-sm px-2 py-1.5 bg-white dark:bg-slate-800 dark:text-slate-100" />
          </div>
          <div className="flex gap-2 justify-end">
            <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 border border-gray-300 dark:border-slate-600 rounded text-gray-600 dark:text-slate-300 hover:bg-gray-100 dark:hover:bg-slate-700">Cancel</button>
            <button type="submit" disabled={saving} className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Save Screening'}
            </button>
          </div>
        </form>
      )}

      {records.length === 0
        ? <p className="text-gray-400 dark:text-slate-500 text-sm py-4">No SDOH screenings on file.</p>
        : records.map(rec => (
          <div key={rec.id} className="border border-gray-200 dark:border-slate-700 rounded-lg p-4 space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                {new Date(rec.assessed_at).toLocaleDateString()}
              </span>
              {rec.assessed_by && (
                <span className="text-xs text-gray-400 dark:text-slate-500">
                  Assessed by {rec.assessed_by.first_name} {rec.assessed_by.last_name}
                </span>
              )}
            </div>
            <div className="grid grid-cols-3 gap-x-4 gap-y-1 text-sm">
              {DOMAINS.map(d => rec[d.key as keyof SdohRecord] && (
                <div key={d.key} className="flex items-baseline gap-1">
                  <span className="text-xs text-gray-500 dark:text-slate-400">{d.label}:</span>
                  <span className={`text-xs font-medium capitalize ${SDOH_RISK_COLOR[(rec[d.key as keyof SdohRecord] as string)] ?? 'text-gray-700 dark:text-slate-300'}`}>
                    {(rec[d.key as keyof SdohRecord] as string).replace(/_/g, ' ')}
                  </span>
                </div>
              ))}
            </div>
            {rec.safety_concerns && (
              <p className="text-xs text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-950/60 px-2 py-1 rounded flex items-center gap-1"><ExclamationTriangleIcon className="w-3 h-3 shrink-0" /> Safety: {rec.safety_concerns}</p>
            )}
            {rec.notes && <p className="text-xs text-gray-500 dark:text-slate-400">{rec.notes}</p>}
          </div>
        ))
      }
    </div>
  )
}

// ── W4-1: Participant Grievances Tab ──────────────────────────────────────────
// Lazy-loads grievances for this participant from GET /grievances?participant_id=N.
// Visible to qa_compliance, it_admin, super_admin. (42 CFR §460.120–§460.121)
function ParticipantGrievancesTab({ participantId }: { participantId: number }) {
  const [items, setItems]       = React.useState<Array<{id:number; grievance_type:string; priority:string; status:string; subject:string; received_at:string; deadline_at:string|null; is_overdue:boolean}>>([])
  const [loaded, setLoaded]     = React.useState(false)
  const [loading, setLoading]   = React.useState(false)

  React.useEffect(() => {
    if (loaded) return
    setLoading(true)
    import('axios').then(({ default: ax }) =>
      ax.get('/grievances', { params: { participant_id: participantId, per_page: 50 } })
        .then(res => {
          const rows = Array.isArray(res.data) ? res.data : ((res.data as { data: unknown[] }).data ?? [])
          setItems(rows as typeof items)
          setLoaded(true)
        })
        .finally(() => setLoading(false))
    )
  }, [])

  if (loading) return <p className="py-8 text-center text-sm text-gray-500 dark:text-slate-400">Loading…</p>

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100">Grievances</h3>
        <a href="/grievances" className="text-sm text-blue-600 dark:text-blue-400 hover:underline">
          View all grievances
        </a>
      </div>
      {items.length === 0 ? (
        <p className="py-8 text-center text-sm text-gray-500 dark:text-slate-400">No grievances on file.</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
            <thead className="bg-gray-50 dark:bg-slate-700/50">
              <tr>
                {['Type','Priority','Status','Subject','Received','Deadline'].map(h => (
                  <th key={h} className="px-4 py-3 text-left font-semibold text-gray-600 dark:text-slate-300">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-slate-700 bg-white dark:bg-slate-800">
              {items.map(g => (
                <tr key={g.id} className={`hover:bg-gray-50 dark:hover:bg-slate-700/50 ${g.is_overdue ? 'bg-red-50/40 dark:bg-red-950/20' : ''}`}>
                  <td className="px-4 py-3 capitalize text-gray-700 dark:text-slate-300">{g.grievance_type.replace(/_/g, ' ')}</td>
                  <td className="px-4 py-3">
                    <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${g.priority === 'urgent' ? 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-300' : 'bg-blue-100 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300'}`}>
                      {g.priority === 'urgent' ? 'Urgent' : 'Standard'}
                    </span>
                  </td>
                  <td className="px-4 py-3 capitalize text-gray-700 dark:text-slate-300">{g.status.replace(/_/g, ' ')}</td>
                  <td className="px-4 py-3 text-gray-700 dark:text-slate-300 max-w-xs truncate">{g.subject}</td>
                  <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{new Date(g.received_at).toLocaleDateString()}</td>
                  <td className="px-4 py-3">
                    {g.deadline_at ? (
                      <span className={`text-xs ${g.is_overdue ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-600 dark:text-slate-400'}`}>
                        {new Date(g.deadline_at).toLocaleDateString()}
                      </span>
                    ) : <span className="text-gray-400 dark:text-slate-500">-</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

// ── W4-1: Participant Consents Tab ─────────────────────────────────────────────
// Lazy-loads consent records for this participant from GET /participants/{id}/consents.
// Visible to enrollment, qa_compliance, it_admin, super_admin. (HIPAA 45 CFR §164.520)
function ParticipantConsentsTab({ participantId }: { participantId: number }) {
  const [items, setItems]       = React.useState<Array<{id:number; consent_type:string; document_title:string; status:string; acknowledged_at:string|null; expires_at:string|null; created_at:string}>>([])
  const [loaded, setLoaded]     = React.useState(false)
  const [loading, setLoading]   = React.useState(false)

  React.useEffect(() => {
    if (loaded) return
    setLoading(true)
    import('axios').then(({ default: ax }) =>
      ax.get(`/participants/${participantId}/consents`)
        .then(res => {
          const rows = Array.isArray(res.data) ? res.data : ((res.data as { data: unknown[] }).data ?? [])
          setItems(rows as typeof items)
          setLoaded(true)
        })
        .finally(() => setLoading(false))
    )
  }, [])

  const statusColor: Record<string, string> = {
    pending:  'bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300',
    acknowledged: 'bg-green-50 dark:bg-green-950/60 text-green-700 dark:text-green-300',
    revoked:  'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300',
    expired:  'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-slate-400',
  }

  if (loading) return <p className="py-8 text-center text-sm text-gray-500 dark:text-slate-400">Loading…</p>

  return (
    <div className="space-y-3">
      <h3 className="text-base font-semibold text-gray-900 dark:text-slate-100 mb-4">Consent Records</h3>
      {items.length === 0 ? (
        <p className="py-8 text-center text-sm text-gray-500 dark:text-slate-400">No consent records on file.</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
            <thead className="bg-gray-50 dark:bg-slate-700/50">
              <tr>
                {['Document','Type','Status','Acknowledged','Expires','Created'].map(h => (
                  <th key={h} className="px-4 py-3 text-left font-semibold text-gray-600 dark:text-slate-300">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-slate-700 bg-white dark:bg-slate-800">
              {items.map(c => (
                <tr key={c.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                  <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{c.document_title}</td>
                  <td className="px-4 py-3 capitalize text-gray-700 dark:text-slate-300">{c.consent_type.replace(/_/g, ' ')}</td>
                  <td className="px-4 py-3">
                    <span className={`text-xs font-medium px-2 py-0.5 rounded-full capitalize ${statusColor[c.status] ?? 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300'}`}>
                      {c.status}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                    {c.acknowledged_at ? new Date(c.acknowledged_at).toLocaleDateString() : <span className="text-amber-600 dark:text-amber-400 font-medium text-xs">Pending</span>}
                  </td>
                  <td className="px-4 py-3 text-gray-500 dark:text-slate-400">
                    {c.expires_at ? new Date(c.expires_at).toLocaleDateString() : <span className="text-gray-400 dark:text-slate-500 text-xs">-</span>}
                  </td>
                  <td className="px-4 py-3 text-gray-500 dark:text-slate-400">{new Date(c.created_at).toLocaleDateString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

type Tab =
  | 'overview'
  | 'chart' | 'vitals' | 'assessments' | 'problems' | 'allergies' | 'adl' | 'careplan'
  | 'medications' | 'emar' | 'med-recon'
  | 'immunizations' | 'procedures' | 'sdoh'
  | 'contacts' | 'flags' | 'insurance' | 'documents' | 'audit' | 'transfers'
  | 'grievances' | 'consents'

export default function ParticipantShow({
  participant, addresses, contacts, flags, insurances, auditLogs,
  canEdit, canDelete, canViewAudit,
  problems, allergies, lifeThreateningAllergyCount, vitals, icd10Codes, noteTemplates,
  hasMultipleSites, completedTransfers,
}: Props) {
  const { auth } = usePage<{ auth: { user: { department: string; is_super_admin: boolean } } }>().props

  const canManageTransfers = auth.user.is_super_admin ||
    ['enrollment', 'it_admin'].includes(auth.user.department)

  // W4-1: Grievances visible to qa_compliance + it_admin + super_admin (42 CFR §460.120)
  const canViewGrievances = auth.user.is_super_admin ||
    ['qa_compliance', 'it_admin'].includes(auth.user.department)

  // W4-1: Consents visible to enrollment + qa_compliance + it_admin + super_admin
  const canViewConsents = auth.user.is_super_admin ||
    ['enrollment', 'qa_compliance', 'it_admin'].includes(auth.user.department)

  // Read ?tab= from URL so deep-links (e.g. from Clinical/CarePlans row-click) land on the right tab.
  const [activeTab, setActiveTab] = useState<Tab>(() => {
    const params = new URLSearchParams(window.location.search)
    const raw = params.get('tab') as Tab | null
    const valid: Tab[] = ['overview', 'chart', 'vitals', 'assessments', 'problems',
      'allergies', 'adl', 'careplan', 'medications', 'emar', 'med-recon',
      'immunizations', 'procedures', 'sdoh',
      'contacts', 'flags', 'insurance', 'documents', 'audit', 'transfers',
      'grievances', 'consents']
    return raw && valid.includes(raw) ? raw : 'overview'
  })

  const [editOpen, setEditOpen] = useState(false)

  // switchTab: updates React state AND syncs the URL query string so the browser
  // back/forward buttons work and the user can share/bookmark a specific tab.
  const switchTab = (tab: Tab) => {
    setActiveTab(tab)
    const url = new URL(window.location.href)
    url.searchParams.set('tab', tab)
    window.history.replaceState(null, '', url.pathname + url.search)
  }

  const activeFlags = flags.filter(f => f.is_active)

  // ── Row 1: CLINICAL tabs (blue active underline) — primary clinical workflow ─
  const clinicalTabs: { id: Tab; label: string; count?: number }[] = [
    { id: 'chart',         label: 'Chart' },
    { id: 'vitals',        label: 'Vitals',       count: vitals.length },
    { id: 'assessments',   label: 'Assessments' },
    { id: 'medications',   label: 'Medications' },
    { id: 'emar',          label: 'eMAR' },
    { id: 'med-recon',     label: 'Med Recon' },
    { id: 'problems',      label: 'Diagnoses',    count: problems.length },
    { id: 'allergies',     label: 'Allergies',    count: allergies.length },
    { id: 'adl',           label: 'ADL' },
    { id: 'careplan',      label: 'Care Plan' },
    { id: 'immunizations', label: 'Immunizations' },
    { id: 'procedures',    label: 'Procedures' },
  ]

  // ── Row 2: ADMIN tabs (slate active underline) — overview + care coordination ─
  const adminTabs: { id: Tab; label: string; count?: number; hidden?: boolean }[] = [
    { id: 'overview',  label: 'Facesheet' },
    { id: 'contacts',  label: 'Contacts',    count: contacts.length },
    { id: 'flags',     label: 'Flags',       count: activeFlags.length },
    { id: 'insurance', label: 'Insurance',   count: insurances.length },
    { id: 'documents', label: 'Documents' },
    { id: 'sdoh',      label: 'SDOH' },
    { id: 'transfers',  label: 'Transfers',   hidden: !canManageTransfers },
    { id: 'grievances', label: 'Grievances',  hidden: !canViewGrievances },
    { id: 'consents',   label: 'Consents',    hidden: !canViewConsents },
    { id: 'audit',      label: 'Audit Trail', hidden: !canViewAudit },
  ]

  return (
    <AppShell>
      <Head title={`${participant.first_name} ${participant.last_name} | ${participant.mrn}`} />

      {/* Breadcrumb */}
      <div className="px-6 pt-4 pb-0 text-sm text-gray-500">
        <Link href="/participants" className="hover:text-blue-600">Participants</Link>
        <span className="mx-2">/</span>
        <span className="text-gray-900">{participant.mrn}</span>
      </div>

      {/* Sticky participant header (avatar, name, MRN, flag chips) */}
      <ParticipantHeader
        participant={participant}
        activeFlags={activeFlags}
        canDelete={canDelete}
        canEdit={canEdit}
        onTabChange={switchTab}
        onEdit={() => setEditOpen(true)}
      />

      {/* Edit participant modal — enrollment/it_admin only */}
      {editOpen && (
        <EditParticipantModal
          participant={participant}
          contacts={contacts}
          department={auth.user.department}
          onClose={() => setEditOpen(false)}
        />
      )}

      {/* Life-threatening allergy banner — persists across all tabs */}
      {lifeThreateningAllergyCount > 0 && (
        <LifeThreateningBanner
          count={lifeThreateningAllergyCount}
          onViewAllergies={() => switchTab('allergies')}
        />
      )}

      {/* ── Two-row tab navigation ──────────────────────────────────────────── */}
      {/*                                                                       */}
      {/* Row 1 (CLINICAL, blue): primary clinical workflow tabs.               */}
      {/* Row 2 (ADMIN, slate): facesheet + care coordination + admin tabs.     */}
      {/* Both rows always visible. Each row scrolls horizontally if needed.    */}

      {/* ── Row 1: Clinical tabs ────────────────────────────────────────────── */}
      <div className="border-b border-gray-200 dark:border-slate-700 px-6 bg-white dark:bg-slate-800 overflow-x-auto">
        <nav className="flex items-center gap-0 -mb-px min-w-max" role="tablist" aria-label="Clinical tabs">
          <span className="mr-3 px-2 py-0.5 text-xs font-semibold text-blue-500 bg-blue-50 dark:bg-blue-950/60 rounded border border-blue-200 dark:border-blue-800 whitespace-nowrap select-none">
            CLINICAL
          </span>
          {clinicalTabs.map(tab => (
            <button
              key={tab.id}
              role="tab"
              aria-selected={activeTab === tab.id}
              data-testid={`tab-${tab.id}`}
              onClick={() => switchTab(tab.id)}
              className={`px-3 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
                activeTab === tab.id
                  ? 'border-blue-600 text-blue-600 dark:text-blue-400'
                  : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 hover:border-gray-300 dark:hover:border-slate-600'
              }`}
            >
              {tab.label}
              {tab.count != null && tab.count > 0 && (
                <span className={`ml-1.5 text-xs px-1.5 py-0.5 rounded-full font-medium ${
                  activeTab === tab.id ? 'bg-blue-100 dark:bg-blue-900/60 text-blue-700 dark:text-blue-300' : 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'
                }`}>{tab.count}</span>
              )}
            </button>
          ))}
        </nav>
      </div>

      {/* ── Row 2: Admin tabs ───────────────────────────────────────────────── */}
      <div className="border-b border-gray-200 dark:border-slate-700 px-6 bg-gray-50 dark:bg-slate-900 overflow-x-auto">
        <nav className="flex items-center gap-0 -mb-px min-w-max" role="tablist" aria-label="Admin tabs">
          <span className="mr-3 px-2 py-0.5 text-xs font-semibold text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 rounded border border-slate-200 dark:border-slate-700 whitespace-nowrap select-none">
            ADMIN
          </span>
          {adminTabs.filter(t => !t.hidden).map(tab => (
            <button
              key={tab.id}
              role="tab"
              aria-selected={activeTab === tab.id}
              data-testid={`tab-${tab.id}`}
              onClick={() => switchTab(tab.id)}
              className={`px-3 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
                activeTab === tab.id
                  ? 'border-slate-600 text-slate-700 dark:text-slate-200'
                  : 'border-transparent text-gray-500 dark:text-slate-400 hover:text-gray-700 dark:hover:text-slate-200 hover:border-gray-300 dark:hover:border-slate-600'
              }`}
            >
              {tab.label}
              {tab.count != null && tab.count > 0 && (
                <span className={`ml-1.5 text-xs px-1.5 py-0.5 rounded-full font-medium ${
                  activeTab === tab.id ? 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200' : 'bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-slate-400'
                }`}>{tab.count}</span>
              )}
            </button>
          ))}
        </nav>
      </div>

      {/* ── Tab content panels ─────────────────────────────────────────────── */}
      <div className="px-6 py-6">
        {activeTab === 'overview'    && <OverviewTab     participant={participant} addresses={addresses} contacts={contacts} flags={flags} problems={problems} allergies={allergies} vitals={vitals} insurances={insurances} />}
        {activeTab === 'chart'       && <ChartTab        participantId={participant.id} noteTemplates={noteTemplates} hasMultipleSites={hasMultipleSites} />}
        {activeTab === 'vitals'      && <VitalsTab       participantId={participant.id} initialVitals={vitals} completedTransfers={completedTransfers} />}
        {activeTab === 'assessments' && <AssessmentsTab  participantId={participant.id} />}
        {activeTab === 'problems'    && <ProblemsTab     participantId={participant.id} initialProblems={problems} icd10Codes={icd10Codes} />}
        {activeTab === 'allergies'   && <AllergiesTab    participantId={participant.id} initialAllergies={allergies} />}
        {activeTab === 'adl'         && <AdlTab          participantId={participant.id} />}
        {activeTab === 'careplan'    && <CarePlanTab     participantId={participant.id} />}
        {activeTab === 'medications'   && <MedicationsTab    participantId={participant.id} />}
        {activeTab === 'emar'          && <EmarTab           participantId={participant.id} />}
        {activeTab === 'med-recon'     && <MedReconTab       participantId={participant.id} />}
        {activeTab === 'immunizations' && <ImmunizationsTab  participantId={participant.id} />}
        {activeTab === 'procedures'    && <ProceduresTab     participantId={participant.id} />}
        {activeTab === 'sdoh'          && <SdohTab           participantId={participant.id} />}
        {activeTab === 'contacts'    && <ContactsTab     participantId={participant.id} initialContacts={contacts} />}
        {activeTab === 'flags'       && <FlagsTab        participantId={participant.id} initialFlags={flags} />}
        {activeTab === 'insurance'   && <InsuranceTab    insurances={insurances} />}
        {activeTab === 'documents'   && <DocumentsTab  participantId={participant.id} />}
        {activeTab === 'transfers' && canManageTransfers && (
          <TransfersTab
            participantId={participant.id}
            currentSiteId={participant.site.id}
            canManageTransfers={canManageTransfers}
          />
        )}
        {activeTab === 'grievances' && canViewGrievances && <ParticipantGrievancesTab participantId={participant.id} />}
        {activeTab === 'consents'   && canViewConsents   && <ParticipantConsentsTab   participantId={participant.id} />}
        {activeTab === 'audit' && canViewAudit && <AuditTab logs={auditLogs} />}
      </div>
    </AppShell>
  )
}
