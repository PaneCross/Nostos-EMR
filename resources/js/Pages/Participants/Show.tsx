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
import { useState, useEffect } from 'react'
import {
  LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
} from 'recharts'

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
  created_at:          string
}

interface Vital {
  id:                number
  recorded_at:       string
  bp_systolic:       number | null
  bp_diastolic:      number | null
  pulse:             number | null
  temperature_f:     number | null
  respiratory_rate:  number | null
  o2_saturation:     number | null
  weight_lbs:        number | null
  pain_score:        number | null
  notes:             string | null
  recorded_by:       { id: number; first_name: string; last_name: string } | null
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
  description:          string
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
}

// ─── Display Constants ────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
  enrolled:    'bg-green-100 text-green-800',
  referred:    'bg-blue-100 text-blue-800',
  intake:      'bg-indigo-100 text-indigo-800',
  pending:     'bg-yellow-100 text-yellow-800',
  disenrolled: 'bg-gray-100 text-gray-600',
  deceased:    'bg-gray-100 text-gray-500',
}

const FLAG_SEVERITY_COLORS: Record<string, string> = {
  low:      'bg-blue-100 text-blue-700 border-blue-200',
  medium:   'bg-yellow-100 text-yellow-800 border-yellow-200',
  high:     'bg-orange-100 text-orange-800 border-orange-200',
  critical: 'bg-red-100 text-red-700 border-red-200',
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
  emergency:   'bg-red-100 text-red-700',
  poa:         'bg-purple-100 text-purple-700',
  next_of_kin: 'bg-blue-100 text-blue-700',
  caregiver:   'bg-green-100 text-green-700',
  pcp:         'bg-indigo-100 text-indigo-700',
  specialist:  'bg-indigo-100 text-indigo-600',
  other:       'bg-gray-100 text-gray-600',
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
  independent:      'text-green-700 bg-green-50 border-green-200',
  supervision:      'text-lime-700 bg-lime-50 border-lime-200',
  limited_assist:   'text-yellow-700 bg-yellow-50 border-yellow-200',
  extensive_assist: 'text-orange-700 bg-orange-50 border-orange-200',
  total_dependent:  'text-red-700 bg-red-50 border-red-200',
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
}

const ALLERGY_TYPE_LABELS: Record<string, string> = {
  drug: 'Drug', food: 'Food', environmental: 'Environmental',
  dietary_restriction: 'Dietary Restriction', latex: 'Latex', contrast: 'Contrast',
}

const ALLERGY_SEVERITY_COLORS: Record<string, string> = {
  life_threatening: 'text-red-700 bg-red-50 border-red-300',
  severe:           'text-orange-700 bg-orange-50 border-orange-200',
  moderate:         'text-amber-700 bg-amber-50 border-amber-200',
  mild:             'text-yellow-700 bg-yellow-50 border-yellow-200',
  intolerance:      'text-blue-700 bg-blue-50 border-blue-200',
}

// ─── Utility Helpers ──────────────────────────────────────────────────────────

function age(dob: string): number {
  const d   = new Date(dob)
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
      <dt className="text-xs font-medium text-gray-500 uppercase tracking-wide">{label}</dt>
      <dd className="mt-0.5 text-sm text-gray-900">{value || <span className="text-gray-300">—</span>}</dd>
    </div>
  )
}

function LoadingSpinner() {
  return (
    <div className="py-12 text-center text-gray-400 text-sm animate-pulse">
      Loading…
    </div>
  )
}

// ─── Sticky Participant Header ─────────────────────────────────────────────────
// Shown at the top of every tab. Displays avatar, name, MRN, DOB, and active flag chips.
function ParticipantHeader({ participant, activeFlags, canDelete }: {
  participant: Participant
  activeFlags: Flag[]
  canDelete:   boolean
}) {
  const [deleting, setDeleting] = useState(false)

  const handleDelete = () => {
    if (!confirm(`Deactivate ${participant.mrn}? This cannot be undone from the UI.`)) return
    setDeleting(true)
    router.delete(`/participants/${participant.id}`)
  }

  return (
    <div className="bg-white border-b border-gray-200 px-6 py-4 sticky top-0 z-20 shadow-sm">
      <div className="flex items-start gap-4">
        <div className="w-14 h-14 rounded-full bg-gradient-to-br from-blue-400 to-indigo-500 flex items-center justify-center text-white text-xl font-bold flex-shrink-0">
          {participant.first_name[0]}{participant.last_name[0]}
        </div>

        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <h1 className="text-lg font-semibold text-gray-900">
              {participant.first_name} {participant.last_name}
              {participant.preferred_name && (
                <span className="text-gray-400 font-normal text-base ml-1">"{participant.preferred_name}"</span>
              )}
            </h1>
            <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLORS[participant.enrollment_status] ?? ''}`}>
              {participant.enrollment_status}
            </span>
          </div>

          <div className="flex items-center gap-3 mt-1 flex-wrap">
            <span className="font-mono text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">{participant.mrn}</span>
            <span className="text-xs text-gray-500">
              {new Date(participant.dob).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
              <span className="ml-1 text-gray-400">({age(participant.dob)} yrs)</span>
            </span>
            <span className="text-xs bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded">{participant.site.name}</span>
          </div>

          {activeFlags.length > 0 && (
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
            </div>
          )}
        </div>

        <div className="flex items-center gap-2 flex-shrink-0">
          <Link href={`/participants/${participant.id}`} className="text-xs px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
            Care Plan
          </Link>
          <Link href={`/participants/${participant.id}`} className="text-xs px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
            Schedule
          </Link>
          {canDelete && (
            <button
              onClick={handleDelete}
              disabled={deleting}
              data-testid="deactivate-btn"
              className="text-xs px-3 py-1.5 border border-red-200 text-red-600 rounded-lg hover:bg-red-50 transition-colors disabled:opacity-50"
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
      <span className="text-lg leading-none">⚠</span>
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
  useEffect(() => {
    const style = document.createElement('style')
    style.id = 'facesheet-print-style'
    style.innerHTML = `
      @media print {
        body > * { display: none !important; }
        #facesheet-print { display: block !important; position: fixed; top: 0; left: 0; width: 100%; }
        #facesheet-no-print { display: none !important; }
      }
    `
    document.head.appendChild(style)
    return () => { document.getElementById('facesheet-print-style')?.remove() }
  }, [])

  const handlePrint = () => window.print()

  // ── Severity colour for allergy badges
  const allergySeverityColor = (sev: Allergy['severity']): string => {
    if (sev === 'life_threatening') return 'bg-red-100 text-red-800 border border-red-300'
    if (sev === 'severe')           return 'bg-orange-100 text-orange-800 border border-orange-200'
    if (sev === 'moderate')         return 'bg-yellow-100 text-yellow-800 border border-yellow-200'
    return 'bg-gray-100 text-gray-600 border border-gray-200'
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
      <div id="facesheet-print" className="bg-white border border-gray-300 rounded-lg shadow-sm font-sans text-gray-900 text-[13px] leading-snug">

        {/* ── Header ────────────────────────────────────────────────────────── */}
        <div className="bg-slate-800 text-white px-5 py-3 rounded-t-lg flex items-center justify-between">
          <div>
            <p className="text-xs font-medium text-slate-300 uppercase tracking-widest">{participant.tenant.name}</p>
            <p className="text-lg font-bold tracking-tight">PACE Participant Facesheet</p>
          </div>
          <div className="text-right text-xs text-slate-300">
            <p className="font-semibold text-white text-sm">CONFIDENTIAL</p>
            <p>HIPAA Protected Health Information</p>
            <p className="mt-0.5">Printed: {printDate}</p>
          </div>
        </div>

        {/* ── Patient identity strip ────────────────────────────────────────── */}
        <div className="border-b border-gray-200 px-5 py-3 flex flex-wrap gap-x-8 gap-y-1 bg-slate-50">
          <div>
            <span className="text-xs text-gray-500 uppercase tracking-wide">Name</span>
            <p className="font-bold text-base text-gray-900">
              {participant.last_name}, {participant.first_name}
              {participant.preferred_name && <span className="font-normal text-gray-500 text-sm ml-2">"{participant.preferred_name}"</span>}
            </p>
          </div>
          <div>
            <span className="text-xs text-gray-500 uppercase tracking-wide">DOB</span>
            <p className="font-semibold">{participant.dob ? new Date(participant.dob + 'T12:00:00').toLocaleDateString('en-US') : '—'}</p>
          </div>
          <div>
            <span className="text-xs text-gray-500 uppercase tracking-wide">Age</span>
            <p className="font-semibold">{age(participant.dob)} yrs</p>
          </div>
          <div>
            <span className="text-xs text-gray-500 uppercase tracking-wide">Gender</span>
            <p className="font-semibold">{participant.gender ?? '—'}</p>
          </div>
          <div>
            <span className="text-xs text-gray-500 uppercase tracking-wide">Language</span>
            <p className="font-semibold">
              {participant.primary_language}
              {participant.interpreter_needed && <span className="ml-1 text-amber-700">(Interp. needed{participant.interpreter_language ? `: ${participant.interpreter_language}` : ''})</span>}
            </p>
          </div>
          <div>
            <span className="text-xs text-gray-500 uppercase tracking-wide">MRN</span>
            <p className="font-mono font-bold text-slate-700">{participant.mrn}</p>
          </div>
          <div>
            <span className="text-xs text-gray-500 uppercase tracking-wide">Status</span>
            <p className="font-semibold capitalize">{participant.enrollment_status}</p>
          </div>
          <div>
            <span className="text-xs text-gray-500 uppercase tracking-wide">Site</span>
            <p className="font-semibold">{participant.site.name}</p>
          </div>
          <div>
            <span className="text-xs text-gray-500 uppercase tracking-wide">NF Eligible</span>
            <p className="font-semibold">{participant.nursing_facility_eligible ? 'Yes' : 'No'}</p>
          </div>
        </div>

        {/* ── IDs strip ────────────────────────────────────────────────────── */}
        <div className="border-b border-gray-200 px-5 py-2 flex flex-wrap gap-x-8 gap-y-0.5 bg-white">
          {[
            { label: 'Medicare ID',   value: participant.medicare_id },
            { label: 'Medicaid ID',   value: participant.medicaid_id },
            { label: 'H-Number',      value: participant.h_number },
            { label: 'Contract ID',   value: participant.pace_contract_id },
            { label: 'SSN (last 4)',  value: participant.ssn_last_four ? `•••–••–${participant.ssn_last_four}` : null },
            { label: 'Enrolled',      value: participant.enrollment_date ? new Date(participant.enrollment_date + 'T12:00:00').toLocaleDateString('en-US') : null },
          ].map(({ label, value }) => value ? (
            <div key={label} className="flex items-center gap-1.5">
              <span className="text-[11px] text-gray-400 uppercase tracking-wide">{label}:</span>
              <span className="font-mono text-[12px] text-gray-800 font-medium">{value}</span>
            </div>
          ) : null)}
        </div>

        {/* ── 3-column body ────────────────────────────────────────────────── */}
        <div className="grid grid-cols-1 lg:grid-cols-3 divide-y lg:divide-y-0 lg:divide-x divide-gray-200 px-0">

          {/* ── Col 1: Address + Emergency Contacts + Enrollment ────────────── */}
          <div className="p-4 space-y-4">
            {/* Address */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 border-b border-gray-100 pb-1">Home Address</h4>
              {primary ? (
                <div className="text-[13px]">
                  <p>{primary.street}{primary.unit ? `, ${primary.unit}` : ''}</p>
                  <p>{primary.city}, {primary.state} {primary.zip}</p>
                  <span className="text-[11px] text-gray-400 capitalize">{primary.address_type.replace('_', ' ')}</span>
                </div>
              ) : <p className="text-gray-400 text-[12px]">No address on file</p>}
            </section>

            {/* Emergency Contacts */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 border-b border-gray-100 pb-1">Emergency Contacts</h4>
              {emergencyContacts.length === 0
                ? <p className="text-gray-400 text-[12px]">None on file</p>
                : emergencyContacts.map((c, i) => (
                  <div key={c.id} className={`${i > 0 ? 'mt-2 pt-2 border-t border-gray-100' : ''}`}>
                    <p className="font-semibold">{c.first_name} {c.last_name}
                      {c.relationship && <span className="font-normal text-gray-500"> — {c.relationship}</span>}
                    </p>
                    {c.phone_primary && <p className="text-gray-700">{c.phone_primary}</p>}
                    {c.phone_secondary && <p className="text-gray-500">{c.phone_secondary}</p>}
                    {c.is_legal_representative && (
                      <span className="inline-block text-[10px] bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded font-medium mt-0.5">Legal Representative</span>
                    )}
                  </div>
                ))
              }
            </section>

            {/* Enrollment dates */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 border-b border-gray-100 pb-1">Enrollment</h4>
              <dl className="space-y-0.5">
                <div className="flex justify-between">
                  <dt className="text-gray-500">Enrolled:</dt>
                  <dd className="font-medium">{participant.enrollment_date ? new Date(participant.enrollment_date + 'T12:00:00').toLocaleDateString('en-US') : '—'}</dd>
                </div>
                {participant.nf_certification_date && (
                  <div className="flex justify-between">
                    <dt className="text-gray-500">NF Cert:</dt>
                    <dd className="font-medium">{new Date(participant.nf_certification_date + 'T12:00:00').toLocaleDateString('en-US')}</dd>
                  </div>
                )}
                {participant.disenrollment_date && (
                  <div className="flex justify-between">
                    <dt className="text-gray-500">Disenrolled:</dt>
                    <dd className="font-medium">{new Date(participant.disenrollment_date + 'T12:00:00').toLocaleDateString('en-US')}</dd>
                  </div>
                )}
              </dl>
            </section>
          </div>

          {/* ── Col 2: Allergies + Active Diagnoses ─────────────────────────── */}
          <div className="p-4 space-y-4">
            {/* Allergies */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 border-b border-gray-100 pb-1">Allergies / Dietary Restrictions</h4>
              {activeAllergies.length === 0 ? (
                <span className="inline-block text-[12px] font-bold bg-green-50 text-green-700 border border-green-200 px-2 py-1 rounded">NKDA — No Known Drug Allergies</span>
              ) : (
                <div className="space-y-1">
                  {lifeThreateningA.length > 0 && (
                    <div className="bg-red-50 border border-red-200 rounded p-1.5 mb-2">
                      <p className="text-[10px] font-bold text-red-700 uppercase tracking-wide mb-1">⚠ Life-Threatening</p>
                      {lifeThreateningA.map(a => (
                        <p key={a.id} className="text-[12px] font-semibold text-red-800">
                          {a.allergen_name}
                          <span className="font-normal text-red-600 ml-1">({allergyTypeLabel[a.allergy_type] ?? a.allergy_type})</span>
                          {a.reaction_description && <span className="font-normal text-red-600"> — {a.reaction_description}</span>}
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
                          <span className="text-gray-500 ml-1">({allergyTypeLabel[a.allergy_type] ?? a.allergy_type})</span>
                          {a.reaction_description && <span className="text-gray-500"> — {a.reaction_description}</span>}
                        </span>
                      </div>
                    ))
                  }
                </div>
              )}
            </section>

            {/* Active Diagnoses */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 border-b border-gray-100 pb-1">Active Diagnoses</h4>
              {activeProblems.length === 0
                ? <p className="text-gray-400 text-[12px]">None recorded</p>
                : (
                  <div className="space-y-1">
                    {activeProblems.map(p => (
                      <div key={p.id} className="flex items-start gap-1.5">
                        <span className="font-mono text-[11px] text-slate-600 bg-slate-100 px-1 py-0.5 rounded shrink-0">{p.icd10_code}</span>
                        <span className="text-[12px]">
                          {p.is_primary_diagnosis && <span className="text-blue-700 font-semibold">[Primary] </span>}
                          {p.description}
                          {p.status === 'chronic' && <span className="text-gray-400 ml-1 text-[11px]">(Chronic)</span>}
                        </span>
                      </div>
                    ))}
                  </div>
                )
              }
            </section>
          </div>

          {/* ── Col 3: Transport/Clinical Flags + Insurance + Latest Vitals ─── */}
          <div className="p-4 space-y-4">
            {/* Transport Needs */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 border-b border-gray-100 pb-1">Transport / Equipment Needs</h4>
              {transportF.length === 0
                ? <p className="text-gray-400 text-[12px]">None</p>
                : (
                  <div className="flex flex-wrap gap-1">
                    {transportF.map(f => (
                      <span key={f.id} className="text-[11px] bg-amber-100 text-amber-800 border border-amber-200 px-1.5 py-0.5 rounded font-medium">
                        {FLAG_LABELS[f.flag_type] ?? f.flag_type}
                      </span>
                    ))}
                  </div>
                )
              }
            </section>

            {/* Clinical / Safety Flags */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 border-b border-gray-100 pb-1">Clinical / Safety Flags</h4>
              {clinicalF.length === 0
                ? <p className="text-gray-400 text-[12px]">None</p>
                : (
                  <div className="flex flex-wrap gap-1">
                    {clinicalF.map(f => (
                      <span key={f.id} className={`text-[11px] px-1.5 py-0.5 rounded font-medium border ${
                        f.flag_type === 'dnr'     ? 'bg-red-100 text-red-800 border-red-200' :
                        f.flag_type === 'hospice' ? 'bg-purple-100 text-purple-800 border-purple-200' :
                        f.severity === 'critical' ? 'bg-red-100 text-red-700 border-red-200' :
                        f.severity === 'high'     ? 'bg-orange-100 text-orange-800 border-orange-200' :
                        'bg-slate-100 text-slate-700 border-slate-200'
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
                <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 border-b border-gray-100 pb-1">Advance Directive</h4>
                <div className="flex flex-wrap gap-1 items-center">
                  <span className={`text-[11px] px-1.5 py-0.5 rounded font-medium border ${
                    participant.advance_directive_status === 'has_directive'
                      ? 'bg-purple-100 text-purple-800 border-purple-200'
                      : 'bg-gray-100 text-gray-600 border-gray-200'
                  }`}>
                    {participant.advance_directive_status === 'has_directive'    ? 'Has Directive' :
                     participant.advance_directive_status === 'declined_directive' ? 'Declined' :
                     'Incapacitated — No Directive'}
                  </span>
                  {participant.advance_directive_type && (
                    <span className="text-[11px] px-1.5 py-0.5 rounded font-medium border bg-slate-100 text-slate-700 border-slate-200">
                      {participant.advance_directive_type === 'dnr'              ? 'DNR' :
                       participant.advance_directive_type === 'polst'            ? 'POLST' :
                       participant.advance_directive_type === 'living_will'      ? 'Living Will' :
                       participant.advance_directive_type === 'healthcare_proxy' ? 'Healthcare Proxy' :
                       'Combined'}
                    </span>
                  )}
                  {participant.advance_directive_reviewed_at && (
                    <span className="text-[10px] text-gray-400 ml-1">
                      Reviewed {new Date(participant.advance_directive_reviewed_at).toLocaleDateString()}
                    </span>
                  )}
                </div>
              </section>
            )}

            {/* Insurance */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 border-b border-gray-100 pb-1">Insurance Coverages</h4>
              {insurances.filter(i => i.is_active).length === 0
                ? <p className="text-gray-400 text-[12px]">None on file</p>
                : insurances.filter(i => i.is_active).map(ins => (
                  <div key={ins.id} className="flex items-center justify-between">
                    <span className="text-[12px] font-medium">{PAYER_LABELS[ins.payer_type] ?? ins.payer_type}</span>
                    {ins.member_id && <span className="font-mono text-[11px] text-gray-500">{ins.member_id}</span>}
                  </div>
                ))
              }
            </section>

            {/* Latest Vitals */}
            <section>
              <h4 className="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 border-b border-gray-100 pb-1">
                Latest Vitals
                {latestVital && <span className="ml-2 font-normal text-gray-400 normal-case tracking-normal">{new Date(latestVital.recorded_at).toLocaleDateString('en-US')}</span>}
              </h4>
              {!latestVital
                ? <p className="text-gray-400 text-[12px]">No vitals recorded</p>
                : (
                  <dl className="grid grid-cols-2 gap-x-4 gap-y-0.5">
                    {latestVital.bp_systolic != null && latestVital.bp_diastolic != null && (
                      <><dt className="text-gray-500 text-[11px]">BP</dt><dd className="font-semibold text-[12px]">{latestVital.bp_systolic}/{latestVital.bp_diastolic} mmHg</dd></>
                    )}
                    {latestVital.pulse != null && (
                      <><dt className="text-gray-500 text-[11px]">Pulse</dt><dd className="font-semibold text-[12px]">{latestVital.pulse} bpm</dd></>
                    )}
                    {latestVital.o2_saturation != null && (
                      <><dt className="text-gray-500 text-[11px]">O₂ Sat</dt><dd className="font-semibold text-[12px]">{latestVital.o2_saturation}%</dd></>
                    )}
                    {latestVital.temperature_f != null && (
                      <><dt className="text-gray-500 text-[11px]">Temp</dt><dd className="font-semibold text-[12px]">{latestVital.temperature_f}°F</dd></>
                    )}
                    {latestVital.weight_lbs != null && (
                      <><dt className="text-gray-500 text-[11px]">Weight</dt><dd className="font-semibold text-[12px]">{latestVital.weight_lbs} lbs</dd></>
                    )}
                    {latestVital.pain_score != null && (
                      <><dt className="text-gray-500 text-[11px]">Pain</dt><dd className="font-semibold text-[12px]">{latestVital.pain_score}/10</dd></>
                    )}
                  </dl>
                )
              }
            </section>
          </div>
        </div>

        {/* ── Footer ───────────────────────────────────────────────────────── */}
        <div className="border-t border-gray-200 bg-slate-50 px-5 py-2 rounded-b-lg flex items-center justify-between text-[10px] text-gray-400">
          <p>This document contains Protected Health Information (PHI). Unauthorized disclosure is prohibited by HIPAA (45 CFR §164.502).</p>
          <p className="shrink-0 ml-4">MRN: <span className="font-mono text-gray-600">{participant.mrn}</span> · {printDate}</p>
        </div>
      </div>
    </div>
  )
}

// ─── CLINICAL TABS ────────────────────────────────────────────────────────────

// ─── Note Card ────────────────────────────────────────────────────────────────
// Expandable card for a single clinical note. Shown inside ChartTab.
// Draft notes display a Sign button; signed notes are read-only.
function NoteCard({ note, participantId, onSign, signingId, onAddendum }: {
  note:          ClinicalNote
  participantId: number
  onSign:        (id: number) => void
  signingId:     number | null
  onAddendum:    (parentNoteId: number) => void
}) {
  const [expanded, setExpanded] = useState(false)

  const preview = note.note_type === 'soap'
    ? [note.subjective, note.objective, note.assessment, note.plan].filter(Boolean).join(' · ').slice(0, 160)
    : ((note.content?.notes as string) ?? '').slice(0, 160)

  return (
    <div data-testid={`note-${note.id}`} className="bg-white border border-gray-200 rounded-lg px-4 py-3">
      <div className="flex items-start justify-between gap-2">
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-sm font-medium text-gray-900">
              {NOTE_TYPE_LABELS[note.note_type] ?? note.note_type}
            </span>
            <span className={`text-xs px-1.5 py-0.5 rounded-full font-medium ${
              note.status === 'signed'  ? 'bg-green-100 text-green-700' :
              note.status === 'amended' ? 'bg-indigo-100 text-indigo-700' :
                                          'bg-yellow-100 text-yellow-700'
            }`}>
              {note.status}
            </span>
            {note.is_late_entry && (
              <span className="text-xs bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded-full">Late Entry</span>
            )}
          </div>
          <div className="text-xs text-gray-500 mt-0.5">
            {new Date(note.visit_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
            {note.author && ` · ${note.author.first_name} ${note.author.last_name}`}
          </div>
          {!expanded && preview && (
            <p className="text-xs text-gray-500 mt-1.5 line-clamp-2">{preview}{preview.length === 160 ? '…' : ''}</p>
          )}
        </div>

        <div className="flex items-center gap-1 flex-shrink-0">
          {note.status === 'draft' && (
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
            className="text-xs px-2.5 py-1 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors"
          >
            {expanded ? 'Collapse' : 'Expand'}
          </button>
        </div>
      </div>

      {/* Expanded note content */}
      {expanded && (
        <div className="mt-3 pt-3 border-t border-gray-100">
          {note.note_type === 'soap' ? (
            <div className="grid grid-cols-2 gap-4">
              {([['Subjective (S)', note.subjective], ['Objective (O)', note.objective],
                 ['Assessment (A)', note.assessment], ['Plan (P)', note.plan]] as [string, string|null][])
                .filter(([, val]) => val)
                .map(([label, val]) => (
                  <div key={label}>
                    <dt className="text-xs font-semibold text-gray-500 uppercase tracking-wide">{label}</dt>
                    <dd className="mt-1 text-xs text-gray-800 whitespace-pre-wrap">{val}</dd>
                  </div>
                ))
              }
            </div>
          ) : (
            <p className="text-xs text-gray-700 whitespace-pre-wrap">
              {(note.content?.notes as string) ?? ''}
            </p>
          )}
          {note.signed_at && (
            <div className="mt-3 pt-2 border-t border-gray-100 flex items-center justify-between">
              <p className="text-xs text-gray-400">
                Signed {new Date(note.signed_at).toLocaleString('en-US')}
              </p>
              <button
                onClick={() => onAddendum(note.id)}
                data-testid={`addendum-note-${note.id}`}
                className="text-xs px-2.5 py-1 border border-blue-300 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors"
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
function ChartTab({ participantId, noteTemplates }: {
  participantId: number
  noteTemplates: Record<string, { label: string; departments: string[] }>
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

  const { auth } = usePage<{ auth: { user: { department: string } } }>().props
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
    try {
      const { data } = await axios.post(`/participants/${participantId}/notes/${noteId}/sign`)
      setNotes(n => n!.map(note => note.id === noteId ? { ...note, ...data } : note))
    } catch {
      // sign button resets; user can retry
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
          <h3 className="text-sm font-semibold text-gray-700">Clinical Notes ({notes.length})</h3>
          <div className="flex gap-1">
            {(['all', 'draft', 'signed'] as const).map(s => (
              <button
                key={s}
                onClick={() => setStatusFilter(s)}
                className={`text-xs px-2 py-0.5 rounded-full border transition-colors ${
                  statusFilter === s
                    ? 'bg-blue-600 text-white border-blue-600'
                    : 'text-gray-500 border-gray-200 hover:border-gray-300'
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
          className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4 space-y-3"
        >
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="text-xs font-medium text-gray-600">Note Type</label>
              <select
                value={form.note_type}
                onChange={e => setForm(f => ({ ...f, note_type: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
              >
                {Object.entries(noteTemplates).map(([key, tmpl]) => (
                  <option key={key} value={key}>{tmpl.label}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600">Visit Type</label>
              <select
                value={form.visit_type}
                onChange={e => setForm(f => ({ ...f, visit_type: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
              >
                {['in_center','home_visit','telehealth','phone'].map(t => (
                  <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600">Visit Date</label>
              <input
                type="date"
                value={form.visit_date}
                max={today()}
                onChange={e => setForm(f => ({ ...f, visit_date: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
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
                  <label className="text-xs font-medium text-gray-600">{label}</label>
                  <textarea
                    rows={3}
                    value={form[field] as string}
                    onChange={e => setForm(f => ({ ...f, [field]: e.target.value }))}
                    placeholder={`Enter ${label}…`}
                    className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white resize-none"
                  />
                </div>
              ))}
            </div>
          ) : (
            <div>
              <label className="text-xs font-medium text-gray-600">
                Notes — {noteTemplates[form.note_type]?.label ?? form.note_type}
              </label>
              <textarea
                rows={5}
                value={form.content_notes}
                onChange={e => setForm(f => ({ ...f, content_notes: e.target.value }))}
                placeholder="Enter clinical notes…"
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white resize-none"
              />
            </div>
          )}

          {/* Late entry toggle */}
          <div className="flex items-center gap-3 flex-wrap">
            <label className="flex items-center gap-1.5 text-xs text-gray-600">
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
                className="flex-1 text-xs border border-gray-300 rounded px-2 py-1 bg-white"
              />
            )}
          </div>

          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => { setShowForm(false); setForm(blankNote) }}
              className="text-xs px-3 py-1.5 border rounded-lg hover:bg-gray-50"
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
          <p className="text-sm text-gray-400 py-8 text-center">
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
          />
        ))}
      </div>
    </div>
  )
}

// ─── Vitals Tab ───────────────────────────────────────────────────────────────
// Pre-loaded vitals (last 100). Recharts line chart for BP trend (last 30 readings).
// Out-of-range values highlighted: BP systolic > 180 → red, O2 < 92 → red.
function VitalsTab({ participantId, initialVitals }: {
  participantId: number
  initialVitals: Vital[]
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
  }

  // Table column headers — those with a chartKey become clickable buttons
  const TABLE_HEADERS: { label: string; chartKey?: string }[] = [
    { label: 'Date / Time' },
    { label: 'BP',     chartKey: 'bp' },
    { label: 'Pulse',  chartKey: 'pulse' },
    { label: 'Temp',   chartKey: 'temperature_f' },
    { label: 'RR',     chartKey: 'respiratory_rate' },
    { label: 'O₂%',    chartKey: 'o2_saturation' },
    { label: 'Weight', chartKey: 'weight_lbs' },
    { label: 'Pain',   chartKey: 'pain_score' },
  ]

  const blankForm = {
    bp_systolic: '', bp_diastolic: '', pulse: '', temperature_f: '',
    respiratory_rate: '', o2_saturation: '', weight_lbs: '', pain_score: '', notes: '',
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
  }))

  const handleRecord = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      const payload = Object.fromEntries(
        Object.entries(form).map(([k, v]) => [k, v === '' ? null : (k === 'notes' ? v : Number(v))])
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
        <h3 className="text-sm font-semibold text-gray-700">Vital Signs ({vitals.length} records)</h3>
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
          className="bg-blue-50 border border-blue-200 rounded-lg p-4 grid grid-cols-4 gap-3"
        >
          {([
            ['bp_systolic',     'BP Systolic',  'mmHg', '1'],
            ['bp_diastolic',    'BP Diastolic', 'mmHg', '1'],
            ['pulse',           'Pulse',        'bpm',  '1'],
            ['temperature_f',   'Temp',         '°F',   '0.1'],
            ['respiratory_rate','Resp Rate',    '/min', '1'],
            ['o2_saturation',   'O₂ Sat',       '%',    '1'],
            ['weight_lbs',      'Weight',       'lbs',  '0.1'],
            ['pain_score',      'Pain',         '0–10', '1'],
          ] as [string, string, string, string][]).map(([field, label, unit, step]) => (
            <div key={field}>
              <label className="text-xs font-medium text-gray-600">{label} <span className="text-gray-400 font-normal">({unit})</span></label>
              <input
                type="number"
                step={step}
                value={form[field as keyof typeof form]}
                onChange={e => setForm(f => ({ ...f, [field]: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
              />
            </div>
          ))}
          <div className="col-span-4">
            <label className="text-xs font-medium text-gray-600">Notes (optional)</label>
            <input
              type="text"
              value={form.notes}
              onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            />
          </div>
          <div className="col-span-4 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border rounded-lg hover:bg-gray-50">Cancel</button>
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
          <div className="bg-white border border-gray-200 rounded-lg p-4 text-center text-sm text-gray-400 py-10">
            No {cfg.label} data recorded yet. Click a column header below to switch views.
          </div>
        )
        return (
          <div className="bg-white border border-gray-200 rounded-lg p-4">
            <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">
              {cfg.label} Trend <span className="font-normal text-gray-400">(last 30 readings · click column headers to switch)</span>
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
              </LineChart>
            </ResponsiveContainer>
          </div>
        )
      })()}

      {/* Vitals table (most recent 20) */}
      <div className="border border-gray-200 rounded-lg overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50">
            <tr>
              {TABLE_HEADERS.map(h => (
                <th key={h.label} className="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide whitespace-nowrap">
                  {h.chartKey ? (
                    <button
                      onClick={() => setActiveChart(h.chartKey!)}
                      className={`transition-colors ${
                        activeChart === h.chartKey
                          ? 'text-blue-600 border-b-2 border-blue-500 pb-0.5'
                          : 'text-gray-400 hover:text-gray-700'
                      }`}
                    >
                      {h.label}
                    </button>
                  ) : (
                    <span className="text-gray-500">{h.label}</span>
                  )}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {vitals.length === 0 && (
              <tr><td colSpan={8} className="px-4 py-6 text-center text-gray-400 text-sm">No vitals recorded.</td></tr>
            )}
            {vitals.slice(0, 20).map(v => (
              <tr key={v.id} className="bg-white hover:bg-gray-50">
                <td className="px-3 py-2 text-xs text-gray-500 whitespace-nowrap">
                  {new Date(v.recorded_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
                </td>
                <td className={`px-3 py-2 text-xs font-mono ${highSys(v.bp_systolic) ? 'text-red-600 font-semibold' : 'text-gray-700'}`}>
                  {v.bp_systolic != null ? `${v.bp_systolic}/${v.bp_diastolic}` : '—'}
                </td>
                <td className="px-3 py-2 text-xs text-gray-700">{v.pulse ?? '—'}</td>
                <td className="px-3 py-2 text-xs text-gray-700">{v.temperature_f != null ? `${v.temperature_f}°` : '—'}</td>
                <td className="px-3 py-2 text-xs text-gray-700">{v.respiratory_rate ?? '—'}</td>
                <td className={`px-3 py-2 text-xs font-mono ${lowO2(v.o2_saturation) ? 'text-red-600 font-semibold' : 'text-gray-700'}`}>
                  {v.o2_saturation != null ? `${v.o2_saturation}%` : '—'}
                </td>
                <td className="px-3 py-2 text-xs text-gray-700">{v.weight_lbs != null ? `${v.weight_lbs} lbs` : '—'}</td>
                <td className="px-3 py-2 text-xs text-gray-700">{v.pain_score ?? '—'}</td>
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
function AssessmentsTab({ participantId }: { participantId: number }) {
  const [assessments, setAssessments] = useState<Assessment[] | null>(null)
  const [loading, setLoading]         = useState(false)
  const [error, setError]             = useState<string | null>(null)
  const [showForm, setShowForm]       = useState(false)
  const [saving, setSaving]           = useState(false)

  const blankForm = {
    assessment_type: 'phq9_depression', score: '', completed_at: today(), next_due_date: '', notes: '',
  }
  const [form, setForm] = useState(blankForm)

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
      const { data } = await axios.post(`/participants/${participantId}/assessments`, {
        assessment_type: form.assessment_type,
        score:           form.score === '' ? null : Number(form.score),
        completed_at:    form.completed_at,
        next_due_date:   form.next_due_date || null,
        responses:       { notes: form.notes },
      })
      setAssessments(a => [data, ...(a ?? [])])
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
  if (!assessments) return null

  const now = new Date()

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-semibold text-gray-700">Assessments ({assessments.length})</h3>
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
          className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4 grid grid-cols-2 gap-3"
        >
          <div>
            <label className="text-xs font-medium text-gray-600">Type</label>
            <select
              value={form.assessment_type}
              onChange={e => setForm(f => ({ ...f, assessment_type: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            >
              {Object.entries(ASSESSMENT_LABELS).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Score (optional)</label>
            <input
              type="number"
              value={form.score}
              onChange={e => setForm(f => ({ ...f, score: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Completed Date *</label>
            <input
              required type="date" value={form.completed_at} max={today()}
              onChange={e => setForm(f => ({ ...f, completed_at: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Next Due Date</label>
            <input
              type="date" value={form.next_due_date} min={today()}
              onChange={e => setForm(f => ({ ...f, next_due_date: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            />
          </div>
          <div className="col-span-2">
            <label className="text-xs font-medium text-gray-600">Notes / Findings</label>
            <textarea
              rows={3} value={form.notes}
              onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white resize-none"
            />
          </div>
          <div className="col-span-2 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border rounded-lg hover:bg-gray-50">Cancel</button>
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
          <p className="text-sm text-gray-400 py-8 text-center">No assessments on file.</p>
        )}
        {assessments.map(a => {
          const dueDate  = a.next_due_date ? new Date(a.next_due_date) : null
          const overdue  = dueDate && dueDate < now
          const dueSoon  = !overdue && dueDate && dueDate <= new Date(Date.now() + 14 * 86400000)
          return (
            <div key={a.id} data-testid={`assessment-${a.id}`}
              className="bg-white border border-gray-200 rounded-lg px-4 py-3 flex items-start justify-between gap-3">
              <div>
                <div className="flex items-center gap-2">
                  <span className="text-sm font-medium text-gray-900">
                    {ASSESSMENT_LABELS[a.assessment_type] ?? a.assessment_type}
                  </span>
                  {a.score != null && (
                    <span className="text-xs bg-indigo-50 text-indigo-700 px-1.5 py-0.5 rounded">Score: {a.score}</span>
                  )}
                </div>
                <div className="text-xs text-gray-500 mt-0.5">
                  Completed {new Date(a.completed_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                  {a.authored_by && ` · ${a.authored_by.first_name} ${a.authored_by.last_name}`}
                </div>
              </div>
              {dueDate && (
                <span className={`text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0 ${
                  overdue  ? 'bg-red-100 text-red-700' :
                  dueSoon  ? 'bg-yellow-100 text-yellow-700' :
                             'bg-gray-100 text-gray-500'
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

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!selectedCode) return
    setSaving(true)
    try {
      const { data } = await axios.post(`/participants/${participantId}/problems`, {
        icd10_code:           selectedCode.code,
        description:          selectedCode.description,
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
      router.reload({ only: ['problems'] })
    } catch {
      // form stays open
    } finally {
      setSaving(false)
    }
  }

  const STATUS_COLORS: Record<string, string> = {
    active:   'bg-red-100 text-red-700',
    chronic:  'bg-orange-100 text-orange-700',
    resolved: 'bg-green-100 text-green-700',
    ruled_out:'bg-gray-100 text-gray-500',
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
        <h3 className="text-sm font-semibold text-gray-700">Problem List ({problems.length})</h3>
        <button
          onClick={() => setShowForm(v => !v)}
          data-testid="add-problem-btn"
          className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          {showForm ? 'Cancel' : '+ Add Problem'}
        </button>
      </div>

      {/* Add problem form with ICD-10 typeahead */}
      {showForm && (
        <form
          onSubmit={handleCreate}
          data-testid="problem-form"
          className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4 space-y-3"
        >
          {/* ICD-10 typeahead */}
          <div className="relative">
            <label className="text-xs font-medium text-gray-600">ICD-10 Code / Diagnosis *</label>
            {selectedCode ? (
              <div className="mt-1 flex items-center gap-2 bg-white border border-blue-300 rounded px-3 py-2">
                <span className="font-mono text-sm text-blue-700">{selectedCode.code}</span>
                <span className="text-sm text-gray-700 flex-1">{selectedCode.description}</span>
                <button
                  type="button"
                  onClick={() => { setSelectedCode(null); setIcd10Query('') }}
                  className="text-xs text-gray-400 hover:text-gray-600"
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
                  className="w-full mt-1 text-sm border border-gray-300 rounded px-3 py-1.5 bg-white"
                />
                {showSuggestions && suggestions.length > 0 && (
                  <ul className="absolute z-10 bg-white border border-gray-200 rounded-lg shadow-lg mt-1 max-h-48 overflow-y-auto w-full">
                    {suggestions.map(c => (
                      <li key={c.code}>
                        <button
                          type="button"
                          onMouseDown={() => { setSelectedCode(c); setIcd10Query(c.code); setShowSuggestions(false) }}
                          className="w-full text-left px-3 py-2 hover:bg-blue-50 text-sm flex items-start gap-2"
                        >
                          <span className="font-mono text-blue-600 flex-shrink-0">{c.code}</span>
                          <span className="text-gray-700 flex-1">{c.description}</span>
                          {c.category && <span className="text-xs text-gray-400 flex-shrink-0">{c.category}</span>}
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
              <label className="text-xs font-medium text-gray-600">Status</label>
              <select
                value={form.status}
                onChange={e => setForm(f => ({ ...f, status: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
              >
                {statusOrder.map(s => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600">Onset Date</label>
              <input
                type="date" value={form.onset_date} max={today()}
                onChange={e => setForm(f => ({ ...f, onset_date: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
              />
            </div>
            <div className="flex items-end pb-1.5">
              <label className="flex items-center gap-2 text-xs text-gray-600 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.is_primary_diagnosis}
                  onChange={e => setForm(f => ({ ...f, is_primary_diagnosis: e.target.checked }))}
                />
                Primary diagnosis
              </label>
            </div>
          </div>

          <div className="flex justify-end gap-2">
            <button type="button"
              onClick={() => { setShowForm(false); setSelectedCode(null); setIcd10Query(''); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border rounded-lg hover:bg-gray-50">Cancel</button>
            <button type="submit" disabled={saving || !selectedCode} data-testid="save-problem-btn"
              className="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Add Problem'}
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
            <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2 flex items-center gap-2">
              <span className={`inline-block w-2 h-2 rounded-full ${STATUS_DOT[status]}`} />
              {status.replace('_', ' ')} ({group.length})
            </h4>
            <div className="space-y-1.5">
              {group.map(p => (
                <div
                  key={p.id}
                  data-testid={`problem-${p.id}`}
                  className="bg-white border border-gray-200 rounded-lg px-4 py-2.5 flex items-center gap-3"
                >
                  <span className="font-mono text-sm text-blue-600 flex-shrink-0">{p.icd10_code}</span>
                  <span className="text-sm text-gray-800 flex-1">{p.description}</span>
                  {p.is_primary_diagnosis && (
                    <span className="text-xs bg-red-100 text-red-700 px-1.5 py-0.5 rounded flex-shrink-0">Primary</span>
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
        <p className="text-sm text-gray-400 py-8 text-center">No problems on file.</p>
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
        <h3 className="text-sm font-semibold text-gray-700">Allergies & Restrictions ({allergies.length})</h3>
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
        <div className="bg-red-50 border border-red-300 rounded-lg px-4 py-3 mb-4" data-testid="lt-allergy-list">
          <h4 className="text-sm font-semibold text-red-700 mb-2">⚠ Life-Threatening Allergies</h4>
          <div className="space-y-1">
            {lifeThreats.map(a => (
              <div key={a.id} className="flex items-center gap-2 text-sm text-red-800">
                <span className="font-semibold">{a.allergen_name}</span>
                {a.reaction_description && (
                  <span className="text-red-600">— {a.reaction_description}</span>
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
          className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4 grid grid-cols-2 gap-3"
        >
          <div>
            <label className="text-xs font-medium text-gray-600">Type</label>
            <select
              value={form.allergy_type}
              onChange={e => setForm(f => ({ ...f, allergy_type: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            >
              {Object.entries(ALLERGY_TYPE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Allergen / Item *</label>
            <input
              required value={form.allergen_name}
              onChange={e => setForm(f => ({ ...f, allergen_name: e.target.value }))}
              placeholder="e.g. Penicillin, Shellfish, Low sodium diet"
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Severity</label>
            <select
              value={form.severity}
              onChange={e => setForm(f => ({ ...f, severity: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            >
              {Object.keys(ALLERGY_SEVERITY_COLORS).map(s => (
                <option key={s} value={s}>{s.replace('_', ' ')}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Reaction</label>
            <input
              value={form.reaction_description}
              onChange={e => setForm(f => ({ ...f, reaction_description: e.target.value }))}
              placeholder="e.g. Anaphylaxis, Rash, GI upset"
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            />
          </div>
          <div className="col-span-2 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border rounded-lg hover:bg-gray-50">Cancel</button>
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
          <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
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
                  <span className="text-xs bg-gray-100 text-gray-400 px-1.5 py-0.5 rounded flex-shrink-0">Inactive</span>
                )}
              </div>
            ))}
          </div>
        </div>
      ))}

      {allergies.length === 0 && (
        <p className="text-sm text-gray-400 py-8 text-center">No allergies or dietary restrictions on file.</p>
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
        <h3 className="text-sm font-semibold text-gray-700">ADL Tracking</h3>
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
          className="bg-blue-50 border border-blue-200 rounded-lg p-4 grid grid-cols-3 gap-3"
        >
          <div>
            <label className="text-xs font-medium text-gray-600">Category</label>
            <select
              value={form.adl_category}
              onChange={e => setForm(f => ({ ...f, adl_category: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            >
              {ADL_CATEGORIES.map(c => (
                <option key={c} value={c}>{ADL_CATEGORY_LABELS[c]}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Independence Level</label>
            <select
              value={form.independence_level}
              onChange={e => setForm(f => ({ ...f, independence_level: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            >
              {Object.entries(ADL_LEVEL_LABELS).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Notes (optional)</label>
            <input
              value={form.notes}
              onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white"
            />
          </div>
          <div className="col-span-3 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border rounded-lg hover:bg-gray-50">Cancel</button>
            <button type="submit" disabled={saving} data-testid="save-adl-btn"
              className="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Record ADL'}
            </button>
          </div>
        </form>
      )}

      {/* Current functional status grid — 2 columns, one card per ADL category */}
      <div>
        <h4 className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Current Functional Status</h4>
        <div className="grid grid-cols-2 gap-2">
          {ADL_CATEGORIES.map(cat => {
            const record    = latest?.[cat]
            const threshold = thresholds?.[cat]
            const level     = record?.level
            return (
              <div
                key={cat}
                data-testid={`adl-${cat}`}
                className="bg-white border border-gray-200 rounded-lg px-4 py-3 flex items-center justify-between"
              >
                <div>
                  <div className="text-xs font-medium text-gray-700">{ADL_CATEGORY_LABELS[cat]}</div>
                  {threshold && (
                    <div className="text-xs text-gray-400 mt-0.5">
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
                    <span className="text-xs text-gray-300">Not recorded</span>
                  )}
                  {record?.recorded_at && (
                    <div className="text-xs text-gray-400 mt-0.5">
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
        <h3 className="text-sm font-semibold text-gray-700">Contacts ({contacts.length})</h3>
        <button
          onClick={() => setShowForm(v => !v)}
          data-testid="add-contact-btn"
          className="text-xs px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          {showForm ? 'Cancel' : '+ Add Contact'}
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} data-testid="contact-form" className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4 grid grid-cols-2 gap-3">
          <div className="col-span-2 grid grid-cols-3 gap-3">
            <div>
              <label className="text-xs font-medium text-gray-600">Type</label>
              <select
                value={form.contact_type}
                onChange={e => setForm(f => ({ ...f, contact_type: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5"
              >
                {['emergency','next_of_kin','poa','caregiver','pcp','specialist','other'].map(t => (
                  <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600">First Name *</label>
              <input required value={form.first_name}
                onChange={e => setForm(f => ({ ...f, first_name: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600">Last Name *</label>
              <input required value={form.last_name}
                onChange={e => setForm(f => ({ ...f, last_name: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5" />
            </div>
          </div>

          <div>
            <label className="text-xs font-medium text-gray-600">Relationship</label>
            <input value={form.relationship}
              onChange={e => setForm(f => ({ ...f, relationship: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5" />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Primary Phone</label>
            <PhoneInput value={form.phone_primary}
              onChange={v => setForm(f => ({ ...f, phone_primary: v }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5" />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Secondary Phone</label>
            <PhoneInput value={form.phone_secondary}
              onChange={v => setForm(f => ({ ...f, phone_secondary: v }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5" />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Email</label>
            <input type="email" value={form.email}
              onChange={e => setForm(f => ({ ...f, email: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5" />
          </div>

          <div className="flex items-center gap-4 col-span-2">
            <label className="flex items-center gap-1.5 text-xs text-gray-700">
              <input type="checkbox" checked={form.is_emergency_contact}
                onChange={e => setForm(f => ({ ...f, is_emergency_contact: e.target.checked }))} />
              Emergency contact
            </label>
            <label className="flex items-center gap-1.5 text-xs text-gray-700">
              <input type="checkbox" checked={form.is_legal_representative}
                onChange={e => setForm(f => ({ ...f, is_legal_representative: e.target.checked }))} />
              Legal representative (POA)
            </label>
          </div>

          <div className="col-span-2 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border rounded-lg hover:bg-gray-50">Cancel</button>
            <button type="submit" disabled={saving} data-testid="save-contact-btn"
              className="text-xs px-4 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Save Contact'}
            </button>
          </div>
        </form>
      )}

      <div className="space-y-2">
        {contacts.length === 0 && (
          <p className="text-sm text-gray-400 py-4 text-center">No contacts on file.</p>
        )}
        {contacts.map(c => (
          <div key={c.id} className="bg-white border border-gray-200 rounded-lg px-4 py-3 flex items-start justify-between gap-3">
            <div className="flex items-start gap-3">
              <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium mt-0.5 ${CONTACT_TYPE_COLORS[c.contact_type] ?? 'bg-gray-100 text-gray-600'}`}>
                {c.contact_type.replace(/_/g, ' ')}
              </span>
              <div>
                <div className="font-medium text-sm text-gray-900">
                  {c.first_name} {c.last_name}
                  {c.is_legal_representative && <span className="ml-2 text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded">POA</span>}
                  {c.is_emergency_contact    && <span className="ml-1 text-xs bg-red-100 text-red-700 px-1.5 py-0.5 rounded">Emergency</span>}
                </div>
                {c.relationship && <div className="text-xs text-gray-500">{c.relationship}</div>}
                <div className="text-xs text-gray-500 mt-0.5">
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
        <h3 className="text-sm font-semibold text-gray-700">Active Flags ({active.length})</h3>
        <button
          onClick={() => setShowForm(v => !v)}
          data-testid="add-flag-btn"
          className="text-xs px-3 py-1.5 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition-colors"
        >
          {showForm ? 'Cancel' : '+ Add Flag'}
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleAdd} data-testid="flag-form" className="bg-orange-50 border border-orange-200 rounded-lg p-4 mb-4 grid grid-cols-3 gap-3">
          <div>
            <label className="text-xs font-medium text-gray-600">Flag Type</label>
            <select value={form.flag_type}
              onChange={e => setForm(f => ({ ...f, flag_type: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5">
              {Object.entries(FLAG_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Severity</label>
            <select value={form.severity}
              onChange={e => setForm(f => ({ ...f, severity: e.target.value }))}
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5">
              {['low','medium','high','critical'].map(s => <option key={s} value={s}>{s}</option>)}
            </select>
          </div>
          <div>
            <label className="text-xs font-medium text-gray-600">Description</label>
            <input value={form.description}
              onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
              placeholder="Optional detail"
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5" />
          </div>
          <div className="col-span-3 flex justify-end gap-2">
            <button type="button" onClick={() => { setShowForm(false); setForm(blankForm) }}
              className="text-xs px-3 py-1.5 border rounded-lg hover:bg-gray-50">Cancel</button>
            <button type="submit" disabled={saving} data-testid="save-flag-btn"
              className="text-xs px-4 py-1.5 bg-orange-500 text-white rounded-lg hover:bg-orange-600 disabled:opacity-50">
              {saving ? 'Saving…' : 'Add Flag'}
            </button>
          </div>
        </form>
      )}

      <div className="space-y-2 mb-6">
        {active.length === 0 && <p className="text-sm text-gray-400 py-4 text-center">No active flags.</p>}
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
              className="flex-shrink-0 text-xs px-2.5 py-1 border border-current rounded-lg hover:bg-white/50 transition-colors disabled:opacity-50">
              {resolvingId === f.id ? 'Resolving…' : 'Resolve'}
            </button>
          </div>
        ))}
      </div>

      {resolved.length > 0 && (
        <>
          <h4 className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">Resolved ({resolved.length})</h4>
          <div className="space-y-1">
            {resolved.map(f => (
              <div key={f.id} className="bg-gray-50 border border-gray-200 rounded px-4 py-2 flex items-center justify-between opacity-60">
                <span className="text-sm">{FLAG_LABELS[f.flag_type]} <span className="text-xs text-gray-400">({f.severity})</span></span>
                {f.resolved_at && <span className="text-xs text-gray-400">{new Date(f.resolved_at).toLocaleDateString('en-US')}</span>}
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
      <h3 className="text-sm font-semibold text-gray-700 mb-4">Insurance Coverage ({insurances.length})</h3>
      {insurances.length === 0 && (
        <p className="text-sm text-gray-400 py-4 text-center">No insurance records on file.</p>
      )}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        {insurances.map(ins => (
          <div key={ins.id} className="bg-white border border-gray-200 rounded-lg p-4">
            <div className="flex items-center justify-between mb-2">
              <span className="font-semibold text-sm text-gray-900">{PAYER_LABELS[ins.payer_type] ?? ins.payer_type}</span>
              <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${ins.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
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

const FILE_ICONS: Record<string, string> = {
  pdf:  '📄',
  jpeg: '🖼️',
  png:  '🖼️',
  docx: '📝',
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
        <h3 className="text-sm font-semibold text-gray-700">
          Documents {total > 0 && <span className="text-gray-400 font-normal">({total})</span>}
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
                : 'bg-white text-gray-600 border-gray-200 hover:border-blue-300 hover:text-blue-600'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {/* Upload modal */}
      {showUpload && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <div className="flex items-center justify-between mb-4">
              <h4 className="text-base font-semibold text-gray-900">Upload Document</h4>
              <button onClick={() => setShowUpload(false)} className="text-gray-400 hover:text-gray-600">
                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <form onSubmit={handleUpload} className="space-y-4">
              {/* File picker */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">File <span className="text-red-500">*</span></label>
                <input
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png,.docx"
                  onChange={e => setSelectedFile(e.target.files?.[0] ?? null)}
                  className="block w-full text-sm text-gray-700 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                  required
                />
                <p className="text-[10px] text-gray-400 mt-1">PDF, JPEG, PNG, DOCX — max 20 MB</p>
              </div>

              {/* Category */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Category <span className="text-red-500">*</span></label>
                <select
                  value={uploadCategory}
                  onChange={e => setUploadCategory(e.target.value)}
                  className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
                  {Object.entries(CATEGORY_LABELS).map(([k, l]) => (
                    <option key={k} value={k}>{l}</option>
                  ))}
                </select>
              </div>

              {/* Description */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Description <span className="text-gray-400">(optional)</span></label>
                <input
                  type="text"
                  maxLength={500}
                  value={uploadDescription}
                  onChange={e => setUploadDescription(e.target.value)}
                  placeholder="Brief description of the document…"
                  className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
              </div>

              {uploadError && (
                <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                  {uploadError}
                </p>
              )}

              <div className="flex gap-3 pt-1">
                <button
                  type="button"
                  onClick={() => setShowUpload(false)}
                  className="flex-1 px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors"
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
          {[1,2,3].map(i => <div key={i} className="h-14 bg-gray-100 rounded-lg" />)}
        </div>
      ) : docs.length === 0 ? (
        <div className="text-center py-12 text-gray-400">
          <svg className="w-10 h-10 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
          </svg>
          <p className="text-sm font-medium">No documents{activeCategory !== 'all' ? ` in ${CATEGORY_LABELS[activeCategory]}` : ''}</p>
          <p className="text-xs mt-1">Use the Upload button to add documents.</p>
        </div>
      ) : (
        <div className="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white overflow-hidden">
          {docs.map(doc => (
            <div key={doc.id} className="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 transition-colors">
              {/* File type icon */}
              <span className="text-xl flex-shrink-0 w-8 text-center" title={doc.file_type.toUpperCase()}>
                {FILE_ICONS[doc.file_type] ?? '📎'}
              </span>

              {/* File info */}
              <div className="min-w-0 flex-1">
                <button
                  onClick={() => handleDownload(doc)}
                  className="text-sm font-medium text-blue-600 hover:underline truncate block text-left max-w-full"
                  title={doc.file_name}
                >
                  {doc.file_name}
                </button>
                <div className="flex items-center gap-2 flex-wrap mt-0.5">
                  <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600">
                    {doc.category_label}
                  </span>
                  {doc.description && (
                    <span className="text-[11px] text-gray-400 truncate max-w-xs">{doc.description}</span>
                  )}
                </div>
                <p className="text-[10px] text-gray-400 mt-0.5">
                  {doc.file_size} · Uploaded by {doc.uploaded_by} · {doc.uploaded_at?.split('T')[0] ?? ''}
                </p>
              </div>

              {/* Actions */}
              <div className="flex items-center gap-1 flex-shrink-0">
                <button
                  onClick={() => handleDownload(doc)}
                  className="p-1.5 text-gray-400 hover:text-blue-600 rounded hover:bg-blue-50 transition-colors"
                  title="Download"
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                  </svg>
                </button>
                <button
                  onClick={() => handleDelete(doc)}
                  className="p-1.5 text-gray-400 hover:text-red-600 rounded hover:bg-red-50 transition-colors"
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
      <h3 className="text-sm font-semibold text-gray-700 mb-4">Audit Trail ({logs.length} entries)</h3>
      <div className="border border-gray-200 rounded-lg overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50">
            <tr>
              {['Timestamp', 'Action', 'Description'].map(h => (
                <th key={h} className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {logs.length === 0 && (
              <tr><td colSpan={3} className="px-4 py-6 text-center text-gray-400">No audit entries.</td></tr>
            )}
            {logs.map(e => (
              <tr key={e.id} className="bg-white hover:bg-gray-50">
                <td className="px-4 py-2 text-xs text-gray-500 whitespace-nowrap">
                  {new Date(e.created_at).toLocaleString('en-US')}
                </td>
                <td className="px-4 py-2 font-mono text-xs text-gray-700">{e.action}</td>
                <td className="px-4 py-2 text-xs text-gray-600">{e.description}</td>
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
  pending:   'bg-yellow-50 text-yellow-700 border border-yellow-200',
  approved:  'bg-blue-50 text-blue-700 border border-blue-200',
  completed: 'bg-green-50 text-green-700 border border-green-200',
  cancelled: 'bg-gray-50 text-gray-500 border border-gray-200',
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

  const [form, setForm] = useState({
    to_site_id:            '',
    transfer_reason:       '',
    transfer_reason_notes: '',
    effective_date:        '',
  })
  const [formErr, setFormErr] = useState<Record<string, string>>({})

  // ── Lazy load ──────────────────────────────────────────────────────────────
  useEffect(() => {
    Promise.all([
      axios.get(`/participants/${participantId}/transfers`),
      canManageTransfers ? axios.get('/locations').catch(() => ({ data: { data: [] } })) : Promise.resolve({ data: { data: [] } }),
    ]).then(([txRes, locRes]) => {
      setTransfers(txRes.data.transfers ?? [])
      // Sites come from shared_sites; for now we derive from existing transfer records + current
      // Fall back: if no locations endpoint returns sites, just use site names from transfer records
      const sitesFromTransfers: TransferSite[] = []
      const seen = new Set<number>()
      txRes.data.transfers?.forEach((t: SiteTransfer) => {
        if (t.to_site && !seen.has(t.to_site.id))   { sitesFromTransfers.push(t.to_site);   seen.add(t.to_site.id) }
        if (t.from_site && !seen.has(t.from_site.id)) { sitesFromTransfers.push(t.from_site); seen.add(t.from_site.id) }
      })
      setSites(sitesFromTransfers)
    }).finally(() => setLoading(false))
  }, [participantId])

  // ── Fetch sites for modal on open ─────────────────────────────────────────
  useEffect(() => {
    if (!showModal || sites.length > 0) return
    axios.get(`/participants/${participantId}/transfers`).then(r => {
      setTransfers(r.data.transfers ?? [])
    })
  }, [showModal])

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
  if (loading) return <p className="text-sm text-gray-400 py-6 text-center">Loading transfer history…</p>

  return (
    <div className="space-y-4">
      {/* Amber pending banner */}
      {activePendingTransfer && (
        <div className="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 flex items-start gap-3">
          <span className="text-amber-500 text-lg">⏳</span>
          <div className="flex-1 text-sm">
            <p className="font-semibold text-amber-800">
              Transfer {activePendingTransfer.status === 'pending' ? 'Pending Approval' : 'Approved — Awaiting Effective Date'}
            </p>
            <p className="text-amber-700 mt-0.5">
              To: <strong>{activePendingTransfer.to_site?.name ?? '—'}</strong> · Effective: {activePendingTransfer.effective_date ?? '—'} · Reason: {activePendingTransfer.transfer_reason_label}
            </p>
          </div>
        </div>
      )}

      {/* Header row */}
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">Transfer History ({transfers.length})</h3>
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
      <div className="border border-gray-200 rounded-lg overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50">
            <tr>
              {['Status', 'From', 'To', 'Reason', 'Effective Date', 'Requested By', 'Actions'].map(h => (
                <th key={h} className="px-4 py-2 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {transfers.length === 0 && (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-gray-400">No transfer history.</td></tr>
            )}
            {transfers.map(t => (
              <tr key={t.id} className="bg-white hover:bg-gray-50">
                <td className="px-4 py-2">
                  <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${STATUS_PILL[t.status] ?? ''}`}>
                    {t.status.charAt(0).toUpperCase() + t.status.slice(1)}
                  </span>
                </td>
                <td className="px-4 py-2 text-xs text-gray-700">{t.from_site?.name ?? '—'}</td>
                <td className="px-4 py-2 text-xs text-gray-700">{t.to_site?.name ?? '—'}</td>
                <td className="px-4 py-2 text-xs text-gray-600">{t.transfer_reason_label}</td>
                <td className="px-4 py-2 text-xs text-gray-700">{t.effective_date ?? '—'}</td>
                <td className="px-4 py-2 text-xs text-gray-500">{t.requested_by?.name ?? '—'}</td>
                <td className="px-4 py-2 text-xs space-x-2 whitespace-nowrap">
                  {canManageTransfers && t.status === 'pending' && (
                    <>
                      <button
                        disabled={actionId === t.id}
                        onClick={() => handleApprove(t)}
                        className="text-blue-600 hover:underline disabled:opacity-50"
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

      {/* Request Transfer Modal */}
      {showModal && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50" onClick={e => { if (e.target === e.currentTarget) setShowModal(false) }}>
          <div className="bg-white rounded-xl shadow-xl w-full max-w-lg p-6 space-y-4">
            <h3 className="text-base font-semibold text-gray-900">Request Site Transfer</h3>

            {formErr._global && (
              <div className="bg-red-50 text-red-700 text-sm px-3 py-2 rounded border border-red-200">{formErr._global}</div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4">
              {/* To Site — free-text ID input (sites from server) */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Destination Site ID</label>
                <input
                  type="number"
                  value={form.to_site_id}
                  onChange={e => setForm(f => ({ ...f, to_site_id: e.target.value }))}
                  placeholder="Enter site ID"
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                {formErr.to_site_id && <p className="text-xs text-red-500 mt-1">{formErr.to_site_id}</p>}
              </div>

              {/* Reason */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Transfer Reason</label>
                <select
                  value={form.transfer_reason}
                  onChange={e => setForm(f => ({ ...f, transfer_reason: e.target.value }))}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
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
                <label className="block text-xs font-medium text-gray-700 mb-1">Notes (optional)</label>
                <textarea
                  value={form.transfer_reason_notes}
                  onChange={e => setForm(f => ({ ...f, transfer_reason_notes: e.target.value }))}
                  rows={2}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>

              {/* Effective Date */}
              <div>
                <label className="block text-xs font-medium text-gray-700 mb-1">Effective Date</label>
                <input
                  type="date"
                  value={form.effective_date}
                  onChange={e => setForm(f => ({ ...f, effective_date: e.target.value }))}
                  min={new Date().toISOString().split('T')[0]}
                  className="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                {formErr.effective_date && <p className="text-xs text-red-500 mt-1">{formErr.effective_date}</p>}
              </div>

              <div className="flex justify-end gap-3 pt-2">
                <button type="button" onClick={() => setShowModal(false)} className="text-sm px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
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
// Each domain goal shows description, outcomes, interventions, status badge,
// and an inline edit form (for users with edit permission).
// IDT Admin + Primary Care Admin get Approve and New Version buttons.
function CarePlanTab({ participantId, canApprove }: { participantId: number; canApprove: boolean }) {
  const [plan, setPlan]       = React.useState<any>(null)
  const [loaded, setLoaded]   = React.useState(false)
  const [loading, setLoading] = React.useState(false)
  const [editDomain, setEditDomain] = React.useState<string | null>(null)
  const [editForm, setEditForm]     = React.useState<any>({})
  const [saving, setSaving]   = React.useState(false)
  const [approving, setApproving] = React.useState(false)

  React.useEffect(() => {
    if (loaded) return
    setLoading(true)
    axios.get(`/participants/${participantId}/careplan`)
      .then(r => { setPlan(r.data); setLoaded(true) })
      .catch(() => setLoaded(true))
      .finally(() => setLoading(false))
  }, [participantId, loaded])

  const STATUS_BADGE: Record<string, string> = {
    active:       'bg-blue-50 text-blue-700 ring-blue-600/20',
    met:          'bg-green-50 text-green-700 ring-green-600/20',
    modified:     'bg-amber-50 text-amber-700 ring-amber-600/20',
    discontinued: 'bg-gray-50 text-gray-700 ring-gray-600/20',
  }

  const PLAN_STATUS_BADGE: Record<string, string> = {
    draft:        'bg-slate-100 text-slate-600',
    active:       'bg-green-100 text-green-700',
    under_review: 'bg-amber-100 text-amber-700',
    archived:     'bg-gray-100 text-gray-500',
  }

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

  const saveGoal = async (carePlanId: number) => {
    setSaving(true)
    try {
      const { data } = await axios.put(
        `/participants/${participantId}/careplan/${carePlanId}/goals/${editDomain}`,
        editForm
      )
      setPlan((prev: any) => ({
        ...prev,
        goals: prev.goals.map((g: any) => g.domain === editDomain ? data : g)
      }))
      setEditDomain(null)
    } catch { /* ignore */ } finally { setSaving(false) }
  }

  const approvePlan = async () => {
    if (!plan || !window.confirm('Approve and activate this care plan? It will replace the current active plan.')) return
    setApproving(true)
    try {
      const { data } = await axios.post(`/participants/${participantId}/careplan/${plan.id}/approve`)
      setPlan(data)
    } catch { /* ignore */ } finally { setApproving(false) }
  }

  if (loading) return <div className="py-12 text-center text-sm text-gray-500">Loading care plan…</div>
  if (!plan)   return (
    <div className="py-12 text-center">
      <p className="text-gray-500 text-sm mb-3">No care plan found for this participant.</p>
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
      <div className="flex items-start justify-between gap-4 p-4 bg-white rounded-xl border border-slate-200">
        <div>
          <div className="flex items-center gap-2">
            <h3 className="font-semibold text-slate-800">Care Plan v{plan.version}</h3>
            <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ${PLAN_STATUS_BADGE[plan.status] ?? ''}`}>
              {plan.status?.replace('_', ' ').toUpperCase()}
            </span>
          </div>
          <p className="text-xs text-slate-500 mt-1">
            {plan.effective_date ? `Effective: ${plan.effective_date}` : 'Draft — not yet effective'}
            {plan.review_due_date && ` · Review due: ${plan.review_due_date}`}
            {daysUntilReview !== null && daysUntilReview <= 30 && (
              <span className="ml-1.5 text-amber-600 font-medium">({daysUntilReview}d)</span>
            )}
          </p>
          {plan.approved_by && (
            <p className="text-xs text-slate-400 mt-0.5">
              Approved by {plan.approved_by.first_name} {plan.approved_by.last_name}
            </p>
          )}
        </div>
        {canApprove && plan.status !== 'active' && (
          <div className="flex items-center gap-2 shrink-0">
            <button
              onClick={approvePlan}
              disabled={approving}
              data-testid="approve-care-plan-btn"
              className="px-3 py-1.5 text-xs font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
            >
              {approving ? 'Approving…' : 'Approve Plan'}
            </button>
          </div>
        )}
      </div>

      {/* Overall goals text */}
      {plan.overall_goals_text && (
        <div className="px-4 py-3 bg-blue-50 border border-blue-100 rounded-xl">
          <p className="text-xs font-semibold text-blue-800 mb-1">Overall Care Goals</p>
          <p className="text-sm text-blue-900">{plan.overall_goals_text}</p>
        </div>
      )}

      {/* Domain goals grid */}
      <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
        {goals.length === 0 && (
          <p className="text-sm text-slate-500 col-span-2 text-center py-6">No domain goals recorded yet.</p>
        )}
        {goals.map((goal: any) => (
          <div
            key={goal.domain}
            data-testid={`goal-card-${goal.domain}`}
            className="rounded-xl border border-slate-200 bg-white p-4 space-y-2"
          >
            <div className="flex items-center justify-between">
              <h4 className="text-sm font-semibold text-slate-800 capitalize">
                {goal.domain.replace(/_/g, ' ')}
              </h4>
              <div className="flex items-center gap-1.5">
                <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset ${STATUS_BADGE[goal.status] ?? ''}`}>
                  {goal.status?.toUpperCase()}
                </span>
                {editDomain !== goal.domain && (
                  <button
                    onClick={() => openEdit(goal)}
                    data-testid={`edit-goal-${goal.domain}`}
                    className="text-[11px] text-blue-600 hover:underline"
                  >
                    Edit
                  </button>
                )}
              </div>
            </div>

            {editDomain === goal.domain ? (
              <div className="space-y-2 pt-1">
                <div>
                  <label className="block text-[10px] font-medium text-slate-600 mb-0.5">Goal</label>
                  <textarea
                    rows={2}
                    value={editForm.goal_description}
                    onChange={e => setEditForm((f: any) => ({ ...f, goal_description: e.target.value }))}
                    className="w-full text-xs border border-slate-300 rounded px-2 py-1 resize-none focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-[10px] font-medium text-slate-600 mb-0.5">Outcomes</label>
                  <textarea
                    rows={2}
                    value={editForm.measurable_outcomes}
                    onChange={e => setEditForm((f: any) => ({ ...f, measurable_outcomes: e.target.value }))}
                    className="w-full text-xs border border-slate-300 rounded px-2 py-1 resize-none focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <label className="block text-[10px] font-medium text-slate-600 mb-0.5">Status</label>
                    <select
                      value={editForm.status}
                      onChange={e => setEditForm((f: any) => ({ ...f, status: e.target.value }))}
                      className="w-full text-xs border border-slate-300 rounded px-2 py-1 focus:ring-blue-500 focus:border-blue-500"
                    >
                      {['active','met','modified','discontinued'].map(s => (
                        <option key={s} value={s}>{s}</option>
                      ))}
                    </select>
                  </div>
                  <div>
                    <label className="block text-[10px] font-medium text-slate-600 mb-0.5">Target Date</label>
                    <input
                      type="date"
                      value={editForm.target_date}
                      onChange={e => setEditForm((f: any) => ({ ...f, target_date: e.target.value }))}
                      className="w-full text-xs border border-slate-300 rounded px-2 py-1 focus:ring-blue-500 focus:border-blue-500"
                    />
                  </div>
                </div>
                <div className="flex gap-2 pt-1">
                  <button
                    onClick={() => saveGoal(plan.id)}
                    disabled={saving}
                    data-testid={`save-goal-${goal.domain}`}
                    className="px-3 py-1 text-[11px] font-medium bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
                  >
                    {saving ? 'Saving…' : 'Save'}
                  </button>
                  <button
                    onClick={() => setEditDomain(null)}
                    className="px-3 py-1 text-[11px] text-slate-600 border border-slate-300 rounded hover:bg-slate-50"
                  >
                    Cancel
                  </button>
                </div>
              </div>
            ) : (
              <div className="space-y-1.5">
                <p className="text-xs text-slate-700">{goal.goal_description}</p>
                {goal.measurable_outcomes && (
                  <div>
                    <p className="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Outcomes</p>
                    <p className="text-[11px] text-slate-600">{goal.measurable_outcomes}</p>
                  </div>
                )}
                {goal.target_date && (
                  <p className="text-[10px] text-slate-400">Target: {goal.target_date?.split('T')[0]}</p>
                )}
              </div>
            )}
          </div>
        ))}
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
  contraindicated: 'bg-red-100 text-red-800 border-red-300',
  major:           'bg-orange-100 text-orange-800 border-orange-300',
  moderate:        'bg-amber-100 text-amber-800 border-amber-300',
  minor:           'bg-yellow-100 text-yellow-800 border-yellow-300',
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
                className="text-xs px-2 py-1 bg-white border border-current rounded hover:opacity-80 whitespace-nowrap shrink-0"
              >
                Acknowledge
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Header row */}
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-700">
          Active Medications
          {activeMeds.length > 0 && (
            <span className="ml-2 text-xs px-2 py-0.5 bg-blue-100 text-blue-700 rounded-full">{activeMeds.length}</span>
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
        <form onSubmit={handleAdd} data-testid="add-medication-form" className="bg-blue-50 border border-blue-200 rounded-lg p-4 space-y-4">
          {/* Drug name with typeahead */}
          <div className="relative">
            <label className="text-xs font-medium text-gray-600">Drug Name *</label>
            <input
              type="text"
              value={searchQuery || form.drug_name}
              onChange={e => { setSearchQuery(e.target.value); setForm(f => ({ ...f, drug_name: e.target.value })) }}
              placeholder="Search medications..."
              className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5"
              required
            />
            {searchResults.length > 0 && (
              <div className="absolute z-20 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                {searchResults.map(r => (
                  <button
                    key={r.drug_name}
                    type="button"
                    onClick={() => applyRefResult(r)}
                    className="w-full text-left px-3 py-2 text-sm hover:bg-blue-50 border-b border-gray-100 last:border-0"
                  >
                    <span className="font-medium">{r.drug_name}</span>
                    {r.drug_class && <span className="ml-2 text-xs text-gray-400">{r.drug_class}</span>}
                    {r.common_dose && <span className="ml-2 text-xs text-gray-500">{r.common_dose} {r.dose_unit}</span>}
                  </button>
                ))}
              </div>
            )}
          </div>

          {/* Dosing row */}
          <div className="grid grid-cols-4 gap-3">
            <div>
              <label className="text-xs font-medium text-gray-600">Dose</label>
              <input type="number" step="0.001" value={form.dose}
                onChange={e => setForm(f => ({ ...f, dose: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600">Unit</label>
              <select value={form.dose_unit} onChange={e => setForm(f => ({ ...f, dose_unit: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white">
                {['mg','mcg','ml','units','tab','cap','patch','drop'].map(u => <option key={u}>{u}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600">Route</label>
              <select value={form.route} onChange={e => setForm(f => ({ ...f, route: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white">
                {['oral','IV','IM','subcut','topical','inhaled','sublingual','rectal','nasal','optic','otic'].map(r => <option key={r}>{r}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-600">Frequency</label>
              <select value={form.frequency} onChange={e => setForm(f => ({ ...f, frequency: e.target.value }))}
                className="w-full mt-1 text-sm border border-gray-300 rounded px-2 py-1.5 bg-white">
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
            <label className="text-xs font-medium text-gray-600">Start Date *</label>
            <input type="date" value={form.start_date} onChange={e => setForm(f => ({ ...f, start_date: e.target.value }))}
              className="mt-1 text-sm border border-gray-300 rounded px-2 py-1.5" required />
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
        <div className="py-8 text-center text-gray-400 text-sm">No active medications on file.</div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
              <tr>
                <th className="px-4 py-2 text-left">Medication</th>
                <th className="px-4 py-2 text-left">Dose / Route</th>
                <th className="px-4 py-2 text-left">Frequency</th>
                <th className="px-4 py-2 text-left">Status</th>
                <th className="px-4 py-2 text-left">Start Date</th>
                <th className="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {activeMeds.map(med => (
                <tr key={med.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2.5 font-medium text-gray-900">
                    {med.drug_name}
                    {med.is_controlled && (
                      <span className="ml-2 text-xs px-1.5 py-0.5 bg-red-100 text-red-700 rounded">
                        C-{med.controlled_schedule}
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-2.5 text-gray-600">
                    {med.dose ? `${med.dose} ${med.dose_unit}` : '—'} {med.route && `(${med.route})`}
                  </td>
                  <td className="px-4 py-2.5 text-gray-600">{med.frequency ?? '—'}</td>
                  <td className="px-4 py-2.5">
                    <span className={`inline-block text-xs px-2 py-0.5 rounded-full font-medium ${
                      med.status === 'active' ? 'bg-green-100 text-green-700' :
                      med.status === 'prn'    ? 'bg-blue-100 text-blue-700' :
                      med.status === 'on_hold'? 'bg-amber-100 text-amber-700' :
                      'bg-gray-100 text-gray-500'
                    }`}>{med.status}</span>
                  </td>
                  <td className="px-4 py-2.5 text-gray-500 text-xs">{med.start_date}</td>
                  <td className="px-4 py-2.5 text-right">
                    <button
                      onClick={() => handleDiscontinue(med)}
                      className="text-xs text-red-600 hover:text-red-800 hover:underline"
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
          <summary className="text-xs font-medium text-gray-500 cursor-pointer hover:text-gray-700">
            Discontinued / On-Hold ({inactiveMeds.length})
          </summary>
          <div className="mt-2 overflow-x-auto rounded-lg border border-gray-200 opacity-70">
            <table className="min-w-full text-sm">
              <tbody className="divide-y divide-gray-100">
                {inactiveMeds.map(med => (
                  <tr key={med.id} className="bg-gray-50">
                    <td className="px-4 py-2 text-gray-400 line-through">{med.drug_name}</td>
                    <td className="px-4 py-2 text-xs text-gray-400">{med.dose ? `${med.dose} ${med.dose_unit}` : '—'}</td>
                    <td className="px-4 py-2 text-xs text-gray-400">{med.frequency ?? '—'}</td>
                    <td className="px-4 py-2"><span className="text-xs text-gray-400">{med.status}</span></td>
                    <td className="px-4 py-2 text-xs text-gray-400">{med.end_date ?? '—'}</td>
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
  given:         'bg-green-100 text-green-700',
  refused:       'bg-red-100 text-red-700',
  held:          'bg-amber-100 text-amber-700',
  not_available: 'bg-orange-100 text-orange-700',
  late:          'bg-red-200 text-red-800',
  missed:        'bg-red-100 text-red-600',
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
        <h3 className="text-sm font-semibold text-gray-700">eMAR</h3>
        <input
          type="date"
          value={date}
          max={today}
          onChange={e => handleDateChange(e.target.value)}
          data-testid="emar-date-picker"
          className="text-sm border border-gray-300 rounded px-2 py-1"
        />
        <button onClick={() => handleDateChange(today)} className="text-xs text-blue-600 hover:underline">Today</button>
      </div>

      {loading && <LoadingSpinner />}

      {!loading && records.length === 0 && (
        <div className="py-8 text-center text-gray-400 text-sm">
          No eMAR records for {date}.
          <p className="text-xs mt-1">Records are generated nightly for scheduled medications.</p>
        </div>
      )}

      {!loading && records.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-gray-200">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
              <tr>
                <th className="px-4 py-2 text-left">Time</th>
                <th className="px-4 py-2 text-left">Medication</th>
                <th className="px-4 py-2 text-left">Ordered Dose</th>
                <th className="px-4 py-2 text-left">Status</th>
                <th className="px-4 py-2 text-left">Administered By</th>
                <th className="px-4 py-2"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {records.map(record => (
                <React.Fragment key={record.id}>
                  <tr className="hover:bg-gray-50">
                    <td className="px-4 py-2.5 text-gray-700 font-mono text-xs whitespace-nowrap">
                      {formatTime(record.scheduled_time)}
                    </td>
                    <td className="px-4 py-2.5">
                      <span className="font-medium text-gray-900">{record.medication?.drug_name ?? '—'}</span>
                      {record.medication?.is_controlled && (
                        <span className="ml-2 text-xs px-1.5 py-0.5 bg-red-100 text-red-700 rounded">
                          C-{record.medication.controlled_schedule} · Witness req.
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-2.5 text-gray-600 text-xs">
                      {record.medication?.dose ? `${record.medication.dose} ${record.medication.dose_unit}` : '—'}
                      {record.medication?.route && ` (${record.medication.route})`}
                    </td>
                    <td className="px-4 py-2.5">
                      <span className={`inline-block text-xs px-2 py-0.5 rounded-full font-medium ${EMAR_STATUS_COLORS[record.status] ?? 'bg-gray-100 text-gray-500'}`}>
                        {record.status}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 text-gray-500 text-xs">
                      {record.administered_by
                        ? `${record.administered_by.first_name} ${record.administered_by.last_name}`
                        : record.status === 'scheduled' || record.status === 'late' ? '—' : '—'}
                      {record.witness && (
                        <span className="block text-gray-400">Witness: {record.witness.first_name} {record.witness.last_name}</span>
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
                      <td colSpan={6} className="px-4 py-3 bg-blue-50 border-t border-blue-200">
                        <form onSubmit={submitCharting} className="flex flex-wrap items-end gap-3">
                          <div>
                            <label className="text-xs font-medium text-gray-600">Status</label>
                            <select
                              value={chartForm.status}
                              onChange={e => setChartForm(f => ({ ...f, status: e.target.value }))}
                              className="block mt-1 text-sm border border-gray-300 rounded px-2 py-1 bg-white"
                            >
                              {['given','refused','held','not_available','missed'].map(s => (
                                <option key={s} value={s}>{s}</option>
                              ))}
                            </select>
                          </div>
                          {chartForm.status === 'given' && (
                            <div>
                              <label className="text-xs font-medium text-gray-600">Given At</label>
                              <input type="datetime-local" value={chartForm.administered_at ?? ''}
                                onChange={e => setChartForm(f => ({ ...f, administered_at: e.target.value }))}
                                className="block mt-1 text-sm border border-gray-300 rounded px-2 py-1" />
                            </div>
                          )}
                          {['refused','held','not_available','missed'].includes(chartForm.status) && (
                            <div>
                              <label className="text-xs font-medium text-gray-600">Reason *</label>
                              <input type="text" value={chartForm.reason_not_given ?? ''}
                                onChange={e => setChartForm(f => ({ ...f, reason_not_given: e.target.value }))}
                                required className="block mt-1 text-sm border border-gray-300 rounded px-2 py-1 w-48"
                                placeholder="Reason..." />
                            </div>
                          )}
                          <div className="flex gap-2">
                            <button type="button" onClick={() => setChartingId(null)}
                              className="text-xs px-3 py-1.5 border border-gray-300 rounded hover:bg-gray-50">Cancel</button>
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

  if (loading) return <div className="py-10 text-center text-gray-400 text-sm">Loading...</div>

  return (
    <div>
      {error && (
        <div className="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded">
          {error}
        </div>
      )}

      {/* Step indicator */}
      <div className="flex items-center gap-2 mb-6">
        {[1,2,3,4,5].map(s => (
          <React.Fragment key={s}>
            <div className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold
              ${step === s ? 'bg-blue-600 text-white' : step > s ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'}`}>
              {s}
            </div>
            {s < 5 && <div className={`h-0.5 flex-1 ${step > s ? 'bg-green-500' : 'bg-gray-200'}`} />}
          </React.Fragment>
        ))}
      </div>
      <div className="flex gap-6 text-xs text-gray-500 mb-6 -mt-4">
        {['Start', 'Prior Meds', 'Compare', 'Decisions', 'Approve'].map((label, i) => (
          <span key={i} className={`flex-1 ${step === i+1 ? 'text-blue-600 font-semibold' : ''}`}>{label}</span>
        ))}
      </div>

      {/* ── Step 1: Start ── */}
      {step === 1 && (
        <div className="max-w-lg">
          <h3 className="text-base font-semibold text-gray-900 mb-1">Start Medication Reconciliation</h3>
          <p className="text-sm text-gray-500 mb-4">Select the source of the prior medication list and the reconciliation type.</p>
          <form onSubmit={handleStart} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Prior Medication Source</label>
              <select value={startForm.prior_source}
                onChange={e => setStartForm(f => ({ ...f, prior_source: e.target.value }))}
                required
                className="block w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">
                <option value="">Select source…</option>
                {PRIOR_SOURCES.map(s => <option key={s.value} value={s.value}>{s.label}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Reconciliation Type</label>
              <select value={startForm.type}
                onChange={e => setStartForm(f => ({ ...f, type: e.target.value }))}
                required
                className="block w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-blue-500">
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
              <h4 className="text-sm font-semibold text-gray-700 mb-2">Reconciliation History</h4>
              <div className="space-y-2">
                {history.map(h => (
                  <div key={h.id} className="flex items-center justify-between border border-gray-200 rounded px-3 py-2 text-sm">
                    <div>
                      <span className="font-medium">{RECON_TYPES.find(t => t.value === h.reconciliation_type)?.label ?? h.reconciliation_type}</span>
                      <span className="text-gray-400 ml-2">·</span>
                      <span className="text-gray-500 ml-2">{PRIOR_SOURCES.find(s => s.value === h.prior_source)?.label ?? h.prior_source}</span>
                    </div>
                    <div className="flex items-center gap-2">
                      {h.has_discrepancies && <span className="text-xs bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded">Discrepancies</span>}
                      <span className={`text-xs px-1.5 py-0.5 rounded font-medium ${
                        h.status === 'approved' ? 'bg-green-100 text-green-700' :
                        h.status === 'decisions_made' ? 'bg-blue-100 text-blue-700' :
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
          <h3 className="text-base font-semibold text-gray-900 mb-1">Enter Prior Medications</h3>
          <p className="text-sm text-gray-500 mb-4">
            Enter medications from the <strong>{PRIOR_SOURCES.find(s => s.value === rec.prior_source)?.label}</strong>.
            Add all medications listed on the source document.
          </p>
          <form onSubmit={handleSavePriorMeds}>
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm border border-gray-200 rounded">
                <thead className="bg-gray-50">
                  <tr>
                    {['Drug Name *', 'Dose', 'Unit', 'Frequency', 'Route', 'Prescriber', 'Notes', ''].map(h => (
                      <th key={h} className="px-2 py-1.5 text-left text-xs font-medium text-gray-500">{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {priorMeds.map((m, i) => (
                    <tr key={i} className="border-t border-gray-100">
                      <td className="px-1 py-1"><input required value={m.drug_name} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, drug_name: e.target.value} : x))}
                        className="border border-gray-300 rounded px-2 py-1 text-sm w-32" placeholder="Drug name" /></td>
                      <td className="px-1 py-1"><input value={m.dose ?? ''} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, dose: e.target.value} : x))}
                        className="border border-gray-300 rounded px-2 py-1 text-sm w-16" placeholder="Dose" /></td>
                      <td className="px-1 py-1"><input value={m.dose_unit ?? ''} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, dose_unit: e.target.value} : x))}
                        className="border border-gray-300 rounded px-2 py-1 text-sm w-14" placeholder="mg" /></td>
                      <td className="px-1 py-1">
                        <select value={m.frequency ?? ''} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, frequency: e.target.value} : x))}
                          className="border border-gray-300 rounded px-2 py-1 text-sm">
                          <option value="">—</option>
                          {['daily','twice_daily','three_times_daily','four_times_daily','weekly','monthly','as_needed','nightly','every_other_day'].map(f =>
                            <option key={f} value={f}>{f.replace(/_/g,' ')}</option>)}
                        </select>
                      </td>
                      <td className="px-1 py-1">
                        <select value={m.route ?? 'oral'} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, route: e.target.value} : x))}
                          className="border border-gray-300 rounded px-2 py-1 text-sm">
                          {['oral','sublingual','topical','inhaled','intravenous','intramuscular','subcutaneous','transdermal','ophthalmic','otic','nasal','rectal'].map(r =>
                            <option key={r} value={r}>{r}</option>)}
                        </select>
                      </td>
                      <td className="px-1 py-1"><input value={m.prescriber ?? ''} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, prescriber: e.target.value} : x))}
                        className="border border-gray-300 rounded px-2 py-1 text-sm w-28" placeholder="Prescriber" /></td>
                      <td className="px-1 py-1"><input value={m.notes ?? ''} onChange={e => setPriorMeds(ms => ms.map((x,j) => j===i ? {...x, notes: e.target.value} : x))}
                        className="border border-gray-300 rounded px-2 py-1 text-sm w-28" placeholder="Notes" /></td>
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
              className="mt-2 text-sm text-blue-600 hover:text-blue-700">+ Add medication</button>
            <div className="flex gap-3 mt-4">
              <button type="button" onClick={() => setStep(1)} className="text-sm px-4 py-2 border border-gray-300 rounded hover:bg-gray-50">← Back</button>
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
          <h3 className="text-base font-semibold text-gray-900 mb-1">Medication Comparison</h3>
          <p className="text-sm text-gray-500 mb-4">Review prior medications vs current active medications. Proceed to Step 4 to apply decisions.</p>

          {/* Matched */}
          {comparison.matched.length > 0 && (
            <div className="mb-4">
              <h4 className="text-xs font-semibold text-green-700 uppercase mb-1">Matched ({comparison.matched.length})</h4>
              <div className="space-y-1">
                {comparison.matched.map((m, i) => (
                  <div key={i} className="flex items-center gap-2 bg-green-50 border border-green-200 rounded px-3 py-2 text-sm">
                    <span className="w-2 h-2 rounded-full bg-green-500 flex-shrink-0" />
                    <span className="font-medium w-40">{m.prior.drug_name}</span>
                    <span className="text-gray-400 text-xs">Prior: {m.prior.dose} {m.prior.dose_unit} {m.prior.frequency}</span>
                    <span className="text-gray-400 text-xs ml-auto">Current: {(m.current as any).dose} {(m.current as any).dose_unit} {(m.current as any).frequency}</span>
                    <span className="ml-2 text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded">Keep</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Prior only */}
          {comparison.priorOnly.length > 0 && (
            <div className="mb-4">
              <h4 className="text-xs font-semibold text-amber-700 uppercase mb-1">Prior List Only — Not in Current ({comparison.priorOnly.length})</h4>
              <div className="space-y-1">
                {comparison.priorOnly.map((m, i) => (
                  <div key={i} className="flex items-center gap-2 bg-amber-50 border border-amber-200 rounded px-3 py-2 text-sm">
                    <span className="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0" />
                    <span className="font-medium w-40">{m.prior.drug_name}</span>
                    <span className="text-gray-400 text-xs">{m.prior.dose} {m.prior.dose_unit} {m.prior.frequency}</span>
                    <span className="ml-auto text-xs bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded">Add or Ignore</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Current only */}
          {comparison.currentOnly.length > 0 && (
            <div className="mb-4">
              <h4 className="text-xs font-semibold text-blue-700 uppercase mb-1">Current Only — Not on Prior List ({comparison.currentOnly.length})</h4>
              <div className="space-y-1">
                {comparison.currentOnly.map((m, i) => (
                  <div key={i} className="flex items-center gap-2 bg-blue-50 border border-blue-200 rounded px-3 py-2 text-sm">
                    <span className="w-2 h-2 rounded-full bg-blue-500 flex-shrink-0" />
                    <span className="font-medium w-40">{(m.current as any).drug_name}</span>
                    <span className="text-gray-400 text-xs">{(m.current as any).dose} {(m.current as any).dose_unit} {(m.current as any).frequency}</span>
                    <span className="ml-auto text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">Keep or Discontinue</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="flex gap-3 mt-4">
            <button onClick={() => setStep(2)} className="text-sm px-4 py-2 border border-gray-300 rounded hover:bg-gray-50">← Back</button>
            <button onClick={() => setStep(4)} className="bg-blue-600 text-white text-sm px-4 py-2 rounded hover:bg-blue-700">Apply Decisions →</button>
          </div>
        </div>
      )}

      {/* ── Step 4: Apply decisions ── */}
      {step === 4 && (
        <div>
          <h3 className="text-base font-semibold text-gray-900 mb-1">Apply Decisions</h3>
          <p className="text-sm text-gray-500 mb-4">For each medication, choose an action. Decisions will be applied to the active medication list.</p>
          <form onSubmit={handleApplyDecisions}>
            <div className="space-y-2">
              {decisions.map((d, i) => (
                <div key={i} className="border border-gray-200 rounded px-3 py-3 flex flex-wrap gap-3 items-start">
                  <div className="font-medium text-sm w-36">{d.drug_name}</div>
                  <div>
                    <select value={d.action} onChange={e => setDecisions(ds => ds.map((x,j) => j===i ? {...x, action: e.target.value} : x))}
                      className="border border-gray-300 rounded px-2 py-1 text-sm">
                      <option value="keep">Keep</option>
                      <option value="discontinue">Discontinue</option>
                      <option value="add">Add</option>
                      <option value="modify">Modify</option>
                    </select>
                  </div>
                  {d.action === 'modify' && (
                    <>
                      <input value={d.new_dose ?? ''} onChange={e => setDecisions(ds => ds.map((x,j) => j===i ? {...x, new_dose: e.target.value} : x))}
                        placeholder="New dose" className="border border-gray-300 rounded px-2 py-1 text-sm w-20" />
                      <input value={d.new_frequency ?? ''} onChange={e => setDecisions(ds => ds.map((x,j) => j===i ? {...x, new_frequency: e.target.value} : x))}
                        placeholder="New frequency" className="border border-gray-300 rounded px-2 py-1 text-sm w-28" />
                      <input value={d.new_route ?? ''} onChange={e => setDecisions(ds => ds.map((x,j) => j===i ? {...x, new_route: e.target.value} : x))}
                        placeholder="New route (opt)" className="border border-gray-300 rounded px-2 py-1 text-sm w-24" />
                    </>
                  )}
                  <input value={d.notes} onChange={e => setDecisions(ds => ds.map((x,j) => j===i ? {...x, notes: e.target.value} : x))}
                    placeholder="Notes (optional)" className="border border-gray-300 rounded px-2 py-1 text-sm flex-1 min-w-32" />
                </div>
              ))}
            </div>
            <div className="flex gap-3 mt-4">
              <button type="button" onClick={() => setStep(3)} className="text-sm px-4 py-2 border border-gray-300 rounded hover:bg-gray-50">← Back</button>
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
          <h3 className="text-base font-semibold text-gray-900 mb-1">Provider Approval</h3>
          <p className="text-sm text-gray-500 mb-4">
            Review the reconciliation summary and approve to lock the record. Once approved, no further changes can be made.
          </p>
          <div className="border border-gray-200 rounded p-4 bg-gray-50 mb-4 text-sm space-y-1">
            <div><span className="text-gray-500">Type:</span> <span className="font-medium">{RECON_TYPES.find(t => t.value === rec.reconciliation_type)?.label}</span></div>
            <div><span className="text-gray-500">Source:</span> <span className="font-medium">{PRIOR_SOURCES.find(s => s.value === rec.prior_source)?.label}</span></div>
            <div><span className="text-gray-500">Reconciled by:</span> <span className="font-medium">{rec.reconciled_by ? `${rec.reconciled_by.first_name} ${rec.reconciled_by.last_name}` : '—'}</span></div>
            {rec.has_discrepancies && (
              <div className="mt-2 bg-amber-50 border border-amber-200 text-amber-700 text-xs px-3 py-2 rounded">
                ⚠ This reconciliation has documented discrepancies requiring clinical follow-up.
              </div>
            )}
          </div>
          <div className="flex gap-3">
            <button onClick={() => setStep(4)} className="text-sm px-4 py-2 border border-gray-300 rounded hover:bg-gray-50">← Back</button>
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
  const [form, setForm]                   = useState({
    vaccine_type: 'influenza', vaccine_name: '', administered_date: new Date().toISOString().slice(0, 10),
    lot_number: '', manufacturer: '', administered_at_location: '', dose_number: '',
    next_dose_due: '', refused: false, refusal_reason: '',
  })

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
        setForm({ vaccine_type: 'influenza', vaccine_name: '', administered_date: new Date().toISOString().slice(0, 10), lot_number: '', manufacturer: '', administered_at_location: '', dose_number: '', next_dose_due: '', refused: false, refusal_reason: '' })
      })
      .finally(() => setSaving(false))
  }

  if (loading) return <div className="p-6 text-gray-400 text-sm">Loading immunizations…</div>

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-base font-semibold text-gray-900">Immunization Record</h3>
        <button onClick={() => setShowForm(v => !v)}
          className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700">
          + Record Immunization
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-gray-50 rounded-lg border p-4 space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="text-xs font-medium text-gray-700">Vaccine Type</label>
              <select value={form.vaccine_type} onChange={e => setForm(f => ({ ...f, vaccine_type: e.target.value, vaccine_name: VACCINE_TYPE_LABELS[e.target.value] ?? '' }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5">
                {Object.entries(VACCINE_TYPE_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700">Vaccine Name / Brand</label>
              <input value={form.vaccine_name} onChange={e => setForm(f => ({ ...f, vaccine_name: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5" required />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700">Date Administered</label>
              <input type="date" value={form.administered_date} onChange={e => setForm(f => ({ ...f, administered_date: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5" required />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700">Lot Number</label>
              <input value={form.lot_number} onChange={e => setForm(f => ({ ...f, lot_number: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700">Manufacturer</label>
              <input value={form.manufacturer} onChange={e => setForm(f => ({ ...f, manufacturer: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700">Location</label>
              <input value={form.administered_at_location} onChange={e => setForm(f => ({ ...f, administered_at_location: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5" />
            </div>
          </div>
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" checked={form.refused} onChange={e => setForm(f => ({ ...f, refused: e.target.checked }))} />
            Patient Refused
          </label>
          {form.refused && (
            <div>
              <label className="text-xs font-medium text-gray-700">Refusal Reason</label>
              <input value={form.refusal_reason} onChange={e => setForm(f => ({ ...f, refusal_reason: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5" />
            </div>
          )}
          <div className="flex gap-2 justify-end">
            <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 border rounded text-gray-600 hover:bg-gray-100">Cancel</button>
            <button type="submit" disabled={saving} className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Save'}
            </button>
          </div>
        </form>
      )}

      {immunizations.length === 0
        ? <p className="text-gray-400 text-sm py-4">No immunizations on file.</p>
        : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-xs text-gray-500 uppercase">
                <th className="text-left py-2 font-medium">Vaccine</th>
                <th className="text-left py-2 font-medium">Date</th>
                <th className="text-left py-2 font-medium">Lot / Mfr</th>
                <th className="text-left py-2 font-medium">Status</th>
                <th className="text-left py-2 font-medium">Next Due</th>
              </tr>
            </thead>
            <tbody>
              {immunizations.map(imm => (
                <tr key={imm.id} className="border-b last:border-0 hover:bg-gray-50">
                  <td className="py-2 font-medium text-gray-900">{VACCINE_TYPE_LABELS[imm.vaccine_type] ?? imm.vaccine_type}</td>
                  <td className="py-2 text-gray-600">{new Date(imm.administered_date).toLocaleDateString()}</td>
                  <td className="py-2 text-gray-500 text-xs">{[imm.lot_number, imm.manufacturer].filter(Boolean).join(' / ') || '—'}</td>
                  <td className="py-2">
                    {imm.refused
                      ? <span className="px-1.5 py-0.5 rounded text-xs bg-yellow-100 text-yellow-800">Refused</span>
                      : <span className="px-1.5 py-0.5 rounded text-xs bg-green-100 text-green-800">Administered</span>
                    }
                  </td>
                  <td className="py-2 text-gray-500 text-xs">{imm.next_dose_due ? new Date(imm.next_dose_due).toLocaleDateString() : '—'}</td>
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

  if (loading) return <div className="p-6 text-gray-400 text-sm">Loading procedures…</div>

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-base font-semibold text-gray-900">Procedure History</h3>
        <button onClick={() => setShowForm(v => !v)}
          className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700">
          + Add Procedure
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-gray-50 rounded-lg border p-4 space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div className="col-span-2">
              <label className="text-xs font-medium text-gray-700">Procedure Name *</label>
              <input value={form.procedure_name} onChange={e => setForm(f => ({ ...f, procedure_name: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5" required />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700">CPT Code</label>
              <input value={form.cpt_code} onChange={e => setForm(f => ({ ...f, cpt_code: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5" placeholder="e.g. 99213" />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700">Performed Date *</label>
              <input type="date" value={form.performed_date} onChange={e => setForm(f => ({ ...f, performed_date: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5" required />
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700">Source</label>
              <select value={form.source} onChange={e => setForm(f => ({ ...f, source: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5">
                <option value="internal">Internal (PACE)</option>
                <option value="external_report">External Report</option>
                <option value="patient_reported">Patient Reported</option>
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-gray-700">Facility</label>
              <input value={form.facility} onChange={e => setForm(f => ({ ...f, facility: e.target.value }))}
                className="mt-1 block w-full border rounded text-sm px-2 py-1.5" />
            </div>
          </div>
          <div className="flex gap-2 justify-end">
            <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 border rounded text-gray-600 hover:bg-gray-100">Cancel</button>
            <button type="submit" disabled={saving} className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Save'}
            </button>
          </div>
        </form>
      )}

      {procedures.length === 0
        ? <p className="text-gray-400 text-sm py-4">No procedures on file.</p>
        : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b text-xs text-gray-500 uppercase">
                <th className="text-left py-2 font-medium">Procedure</th>
                <th className="text-left py-2 font-medium">Date</th>
                <th className="text-left py-2 font-medium">CPT</th>
                <th className="text-left py-2 font-medium">Facility</th>
                <th className="text-left py-2 font-medium">Source</th>
              </tr>
            </thead>
            <tbody>
              {procedures.map(proc => (
                <tr key={proc.id} className="border-b last:border-0 hover:bg-gray-50">
                  <td className="py-2 font-medium text-gray-900">{proc.procedure_name}</td>
                  <td className="py-2 text-gray-600">{new Date(proc.performed_date).toLocaleDateString()}</td>
                  <td className="py-2 font-mono text-xs text-gray-500">{proc.cpt_code ?? '—'}</td>
                  <td className="py-2 text-gray-500 text-xs">{proc.facility ?? '—'}</td>
                  <td className="py-2">
                    <span className={`px-1.5 py-0.5 rounded text-xs ${
                      proc.source === 'internal'        ? 'bg-blue-100 text-blue-700' :
                      proc.source === 'external_report' ? 'bg-orange-100 text-orange-700' :
                      'bg-gray-100 text-gray-600'
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
  stable:          'text-green-700',
  at_risk:         'text-yellow-700',
  high_risk:       'text-red-700',
  adequate:        'text-green-700',
  inadequate:      'text-red-700',
  limited:         'text-red-700',
  available:       'text-green-700',
  isolated:        'text-red-700',
  low:             'text-green-700',
  moderate:        'text-yellow-700',
  high:            'text-red-700',
  none:            'text-green-700',
  minor:           'text-yellow-700',
  severe:          'text-red-700',
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

  if (loading) return <div className="p-6 text-gray-400 text-sm">Loading SDOH screenings…</div>

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-base font-semibold text-gray-900">Social Determinants of Health</h3>
          <p className="text-xs text-gray-400 mt-0.5">USCDI v3 · PRAPARE-aligned screening</p>
        </div>
        <button onClick={() => setShowForm(v => !v)}
          className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700">
          + New Screening
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-gray-50 rounded-lg border p-4 space-y-3">
          <div className="grid grid-cols-2 gap-3">
            {DOMAINS.map(d => (
              <div key={d.key}>
                <label className="text-xs font-medium text-gray-700">{d.label}</label>
                <select value={(form as any)[d.key]} onChange={e => setForm(f => ({ ...f, [d.key]: e.target.value }))}
                  className="mt-1 block w-full border rounded text-sm px-2 py-1.5">
                  <option value="">— Not assessed</option>
                  {d.options.map(o => <option key={o} value={o}>{o.replace(/_/g, ' ')}</option>)}
                </select>
              </div>
            ))}
          </div>
          <div>
            <label className="text-xs font-medium text-gray-700">Safety Concerns</label>
            <input value={form.safety_concerns} onChange={e => setForm(f => ({ ...f, safety_concerns: e.target.value }))}
              className="mt-1 block w-full border rounded text-sm px-2 py-1.5" placeholder="e.g. domestic violence, unsafe living conditions" />
          </div>
          <div>
            <label className="text-xs font-medium text-gray-700">Notes</label>
            <textarea value={form.notes} onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
              rows={2} className="mt-1 block w-full border rounded text-sm px-2 py-1.5" />
          </div>
          <div className="flex gap-2 justify-end">
            <button type="button" onClick={() => setShowForm(false)} className="text-sm px-3 py-1.5 border rounded text-gray-600 hover:bg-gray-100">Cancel</button>
            <button type="submit" disabled={saving} className="text-sm bg-blue-600 text-white px-3 py-1.5 rounded hover:bg-blue-700 disabled:opacity-50">
              {saving ? 'Saving…' : 'Save Screening'}
            </button>
          </div>
        </form>
      )}

      {records.length === 0
        ? <p className="text-gray-400 text-sm py-4">No SDOH screenings on file.</p>
        : records.map(rec => (
          <div key={rec.id} className="border rounded-lg p-4 space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-sm font-semibold text-gray-900">
                {new Date(rec.assessed_at).toLocaleDateString()}
              </span>
              {rec.assessed_by && (
                <span className="text-xs text-gray-400">
                  Assessed by {rec.assessed_by.first_name} {rec.assessed_by.last_name}
                </span>
              )}
            </div>
            <div className="grid grid-cols-3 gap-x-4 gap-y-1 text-sm">
              {DOMAINS.map(d => rec[d.key as keyof SdohRecord] && (
                <div key={d.key} className="flex items-baseline gap-1">
                  <span className="text-xs text-gray-500">{d.label}:</span>
                  <span className={`text-xs font-medium capitalize ${SDOH_RISK_COLOR[(rec[d.key as keyof SdohRecord] as string)] ?? 'text-gray-700'}`}>
                    {(rec[d.key as keyof SdohRecord] as string).replace(/_/g, ' ')}
                  </span>
                </div>
              ))}
            </div>
            {rec.safety_concerns && (
              <p className="text-xs text-red-700 bg-red-50 px-2 py-1 rounded">⚠ Safety: {rec.safety_concerns}</p>
            )}
            {rec.notes && <p className="text-xs text-gray-500">{rec.notes}</p>}
          </div>
        ))
      }
    </div>
  )
}

type Tab =
  | 'overview'
  | 'chart' | 'vitals' | 'assessments' | 'problems' | 'allergies' | 'adl' | 'careplan'
  | 'medications' | 'emar' | 'med-recon'
  | 'immunizations' | 'procedures' | 'sdoh'
  | 'contacts' | 'flags' | 'insurance' | 'documents' | 'audit' | 'transfers'

export default function ParticipantShow({
  participant, addresses, contacts, flags, insurances, auditLogs,
  canEdit, canDelete, canViewAudit,
  problems, allergies, lifeThreateningAllergyCount, vitals, icd10Codes, noteTemplates,
}: Props) {
  const { auth } = usePage<{ auth: { user: { department: string; is_super_admin: boolean } } }>().props

  const canManageTransfers = auth.user.is_super_admin ||
    ['enrollment', 'it_admin'].includes(auth.user.department)

  // Read ?tab= from URL so deep-links (e.g. from Clinical/CarePlans row-click) land on the right tab.
  const [activeTab, setActiveTab] = useState<Tab>(() => {
    const params = new URLSearchParams(window.location.search)
    const raw = params.get('tab') as Tab | null
    const valid: Tab[] = ['overview', 'chart', 'vitals', 'assessments', 'problems',
      'allergies', 'adl', 'careplan', 'medications', 'emar', 'med-recon',
      'contacts', 'flags', 'insurance', 'documents', 'audit', 'transfers']
    return raw && valid.includes(raw) ? raw : 'overview'
  })

  const activeFlags = flags.filter(f => f.is_active)

  // ── CLINICAL section tabs (blue active underline) ──────────────────────────
  const clinicalTabs: { id: Tab; label: string; count?: number }[] = [
    { id: 'chart',       label: 'Chart' },
    { id: 'vitals',      label: 'Vitals',      count: vitals.length },
    { id: 'assessments', label: 'Assessments' },
    { id: 'problems',    label: 'Problems',    count: problems.length },
    { id: 'allergies',   label: 'Allergies',   count: allergies.length },
    { id: 'adl',         label: 'ADL' },
    { id: 'careplan',    label: 'Care Plan' },
    { id: 'medications',   label: 'Medications' },
    { id: 'emar',          label: 'eMAR' },
    { id: 'med-recon',     label: 'Med Recon' },
    { id: 'immunizations', label: 'Immunizations' },
    { id: 'procedures',    label: 'Procedures' },
    { id: 'sdoh',          label: 'SDOH' },
  ]

  // ── ADMIN section tabs (slate active underline) ────────────────────────────
  const adminTabs: { id: Tab; label: string; count?: number; hidden?: boolean }[] = [
    { id: 'contacts',  label: 'Contacts',    count: contacts.length },
    { id: 'flags',     label: 'Flags',       count: activeFlags.length },
    { id: 'insurance', label: 'Insurance',   count: insurances.length },
    { id: 'documents', label: 'Documents' },
    { id: 'transfers', label: 'Transfers',   hidden: !canManageTransfers },
    { id: 'audit',     label: 'Audit Trail', hidden: !canViewAudit },
  ]

  return (
    <AppShell>
      <Head title={`${participant.first_name} ${participant.last_name} — ${participant.mrn}`} />

      {/* Breadcrumb */}
      <div className="px-6 pt-4 pb-0 text-sm text-gray-500">
        <Link href="/participants" className="hover:text-blue-600">Participants</Link>
        <span className="mx-2">/</span>
        <span className="text-gray-900">{participant.mrn}</span>
      </div>

      {/* Sticky participant header (avatar, name, MRN, flag chips) */}
      <ParticipantHeader participant={participant} activeFlags={activeFlags} canDelete={canDelete} />

      {/* Life-threatening allergy banner — persists across all tabs */}
      {lifeThreateningAllergyCount > 0 && (
        <LifeThreateningBanner
          count={lifeThreateningAllergyCount}
          onViewAllergies={() => setActiveTab('allergies')}
        />
      )}

      {/* ── Two-section tab navigation ─────────────────────────────────────── */}
      {/*                                                                       */}
      {/* Layout: [Overview]  CLINICAL [Chart][Vitals][Assessments]…  ADMIN [Contacts]… */}
      {/*                                                                       */}
      {/* Section labels are non-interactive pill badges between tab groups.    */}
      {/* CLINICAL tabs: border-blue-600.  ADMIN tabs: border-slate-600.        */}
      <div className="border-b border-gray-200 px-6 bg-white overflow-x-auto">
        <nav className="flex items-center gap-0 -mb-px min-w-max" role="tablist" aria-label="Participant profile sections">

          {/* Overview — standalone first tab */}
          <button
            role="tab"
            aria-selected={activeTab === 'overview'}
            data-testid="tab-overview"
            onClick={() => setActiveTab('overview')}
            className={`px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
              activeTab === 'overview'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            Overview
          </button>

          {/* CLINICAL section label + tabs */}
          <div className="flex items-center">
            <span className="mx-3 px-2 py-0.5 text-xs font-semibold text-blue-500 bg-blue-50 rounded border border-blue-200 whitespace-nowrap select-none">
              CLINICAL
            </span>
            {clinicalTabs.map(tab => (
              <button
                key={tab.id}
                role="tab"
                aria-selected={activeTab === tab.id}
                data-testid={`tab-${tab.id}`}
                onClick={() => setActiveTab(tab.id)}
                className={`px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
                  activeTab === tab.id
                    ? 'border-blue-600 text-blue-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                }`}
              >
                {tab.label}
                {tab.count != null && tab.count > 0 && (
                  <span className={`ml-1.5 text-xs px-1.5 py-0.5 rounded-full font-medium ${
                    activeTab === tab.id ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-500'
                  }`}>{tab.count}</span>
                )}
              </button>
            ))}
          </div>

          {/* ADMIN section label + tabs */}
          <div className="flex items-center">
            <span className="mx-3 px-2 py-0.5 text-xs font-semibold text-slate-500 bg-slate-50 rounded border border-slate-200 whitespace-nowrap select-none">
              ADMIN
            </span>
            {adminTabs.filter(t => !t.hidden).map(tab => (
              <button
                key={tab.id}
                role="tab"
                aria-selected={activeTab === tab.id}
                data-testid={`tab-${tab.id}`}
                onClick={() => setActiveTab(tab.id)}
                className={`px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap ${
                  activeTab === tab.id
                    ? 'border-slate-600 text-slate-700'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                }`}
              >
                {tab.label}
                {tab.count != null && tab.count > 0 && (
                  <span className={`ml-1.5 text-xs px-1.5 py-0.5 rounded-full font-medium ${
                    activeTab === tab.id ? 'bg-slate-100 text-slate-700' : 'bg-gray-100 text-gray-500'
                  }`}>{tab.count}</span>
                )}
              </button>
            ))}
          </div>
        </nav>
      </div>

      {/* ── Tab content panels ─────────────────────────────────────────────── */}
      <div className="px-6 py-6">
        {activeTab === 'overview'    && <OverviewTab     participant={participant} addresses={addresses} contacts={contacts} flags={flags} problems={problems} allergies={allergies} vitals={vitals} insurances={insurances} />}
        {activeTab === 'chart'       && <ChartTab        participantId={participant.id} noteTemplates={noteTemplates} />}
        {activeTab === 'vitals'      && <VitalsTab       participantId={participant.id} initialVitals={vitals} />}
        {activeTab === 'assessments' && <AssessmentsTab  participantId={participant.id} />}
        {activeTab === 'problems'    && <ProblemsTab     participantId={participant.id} initialProblems={problems} icd10Codes={icd10Codes} />}
        {activeTab === 'allergies'   && <AllergiesTab    participantId={participant.id} initialAllergies={allergies} />}
        {activeTab === 'adl'         && <AdlTab          participantId={participant.id} />}
        {activeTab === 'careplan'    && <CarePlanTab     participantId={participant.id} canApprove={canEdit} />}
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
        {activeTab === 'audit' && canViewAudit && <AuditTab logs={auditLogs} />}
      </div>
    </AppShell>
  )
}
