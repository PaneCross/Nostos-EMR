<?php

// ─── EMR Note Templates ──────────────────────────────────────────────────────
// Structured field schemas for each clinical note type.
// Each template must have ≥50% dropdown/select/checkbox fields (QA requirement).
//
// Field types:
//   select      → single-choice dropdown  (structured)
//   multiselect → multi-choice dropdown   (structured)
//   checkbox    → boolean toggle          (structured)
//   radio       → single-choice inline    (structured)
//   number      → numeric input           (structured)
//   date        → date picker             (structured)
//   textarea    → free text               (unstructured)
//
// Used by NoteTemplateService; rendered by the NoteComposerModal on the frontend.
// ──────────────────────────────────────────────────────────────────────────────

return [

    // ─── 1. Primary Care SOAP Note ────────────────────────────────────────────
    // 4 SOAP sections: Subjective, Objective, Assessment, Plan
    // Field counts: S=5, O=8, A=4, P=4 → 21 total, 15 structured (71%)
    'soap' => [
        'label'      => 'Primary Care SOAP Note',
        'departments' => ['primary_care'],
        'sections'   => [
            [
                'key'    => 'subjective',
                'label'  => 'Subjective',
                'fields' => [
                    ['key' => 'chief_complaint',    'type' => 'select',   'label' => 'Chief Complaint',
                     'options' => ['Follow-up visit', 'Acute complaint', 'Medication review', 'Chronic disease management', 'Post-hospitalization', 'Annual wellness', 'Other']],
                    ['key' => 'pain_location',      'type' => 'select',   'label' => 'Pain Location',
                     'options' => ['None', 'Head/Neck', 'Chest', 'Abdomen', 'Back', 'Upper extremity', 'Lower extremity', 'Generalized']],
                    ['key' => 'pain_score',         'type' => 'number',   'label' => 'Pain Score (0–10)', 'min' => 0, 'max' => 10],
                    ['key' => 'functional_change',  'type' => 'select',   'label' => 'Functional Change Since Last Visit',
                     'options' => ['Improved', 'Stable', 'Mildly declined', 'Moderately declined', 'Significantly declined']],
                    ['key' => 'subjective_notes',   'type' => 'textarea', 'label' => 'Additional Subjective Notes'],
                ],
            ],
            [
                'key'    => 'objective',
                'label'  => 'Objective',
                'fields' => [
                    ['key' => 'appearance',    'type' => 'select', 'label' => 'General Appearance',
                     'options' => ['Alert and oriented × 4', 'Alert, oriented × 3', 'Alert, oriented × 2', 'Confused', 'Lethargic', 'In apparent distress']],
                    ['key' => 'respiratory',   'type' => 'select', 'label' => 'Respiratory',
                     'options' => ['Clear to auscultation bilaterally', 'Diminished breath sounds', 'Wheezing present', 'Rales/crackles present', 'Rhonchi present']],
                    ['key' => 'cardiovascular','type' => 'select', 'label' => 'Cardiovascular',
                     'options' => ['Regular rate and rhythm', 'Irregular rhythm', 'Murmur present', 'Edema noted', 'Distant heart sounds']],
                    ['key' => 'abdomen',       'type' => 'select', 'label' => 'Abdomen',
                     'options' => ['Soft, non-tender, non-distended', 'Mild tenderness', 'Moderate tenderness', 'Rigid', 'Bowel sounds present', 'Bowel sounds absent']],
                    ['key' => 'extremities',   'type' => 'select', 'label' => 'Extremities',
                     'options' => ['No edema', 'Trace edema', '1+ edema', '2+ edema', '3+ edema', '4+ edema']],
                    ['key' => 'skin',          'type' => 'select', 'label' => 'Skin',
                     'options' => ['Intact, no lesions', 'Dry/scaling', 'Wound present', 'Bruising noted', 'Rash present', 'Jaundice']],
                    ['key' => 'neuro',         'type' => 'select', 'label' => 'Neurological',
                     'options' => ['Intact', 'Mild deficits', 'Moderate deficits', 'Focal deficit present']],
                    ['key' => 'objective_notes','type' => 'textarea', 'label' => 'Additional Objective Findings'],
                ],
            ],
            [
                'key'    => 'assessment',
                'label'  => 'Assessment',
                'fields' => [
                    ['key' => 'overall_status',    'type' => 'select', 'label' => 'Overall Clinical Status',
                     'options' => ['Stable', 'Improving', 'Declining', 'Acute concern', 'Chronic, well-managed', 'Chronic, poorly controlled']],
                    ['key' => 'disease_control',   'type' => 'select', 'label' => 'Primary Disease Control',
                     'options' => ['Well controlled', 'Adequately controlled', 'Poorly controlled', 'Uncontrolled', 'N/A']],
                    ['key' => 'hospitalization_risk', 'type' => 'select', 'label' => 'Hospitalization Risk',
                     'options' => ['Low', 'Moderate', 'High', 'Imminent']],
                    ['key' => 'assessment_notes',  'type' => 'textarea', 'label' => 'Assessment Notes / Active Problems'],
                ],
            ],
            [
                'key'    => 'plan',
                'label'  => 'Plan',
                'fields' => [
                    ['key' => 'medication_changes', 'type' => 'select', 'label' => 'Medication Changes',
                     'options' => ['No changes', 'New medication added', 'Medication discontinued', 'Dose adjusted', 'Multiple changes — see notes']],
                    ['key' => 'referrals',          'type' => 'multiselect', 'label' => 'Referrals / Consultations',
                     'options' => ['None', 'Cardiology', 'Neurology', 'Orthopedics', 'Ophthalmology', 'Podiatry', 'Dermatology', 'Urology', 'Nephrology', 'Psychiatry', 'Social Work', 'PT/OT/ST']],
                    ['key' => 'follow_up',          'type' => 'select', 'label' => 'Follow-Up',
                     'options' => ['1 week', '2 weeks', '1 month', '3 months', '6 months', 'PRN', 'N/A']],
                    ['key' => 'plan_notes',         'type' => 'textarea', 'label' => 'Plan Notes / Orders'],
                ],
            ],
        ],
    ],

    // ─── 2. Nursing Progress Note ─────────────────────────────────────────────
    // Fields: 9 total, 7 structured (78%)
    'progress_nursing' => [
        'label'       => 'Nursing Progress Note',
        'departments' => ['primary_care', 'therapies', 'home_care'],
        'sections'    => [
            [
                'key'    => 'assessment',
                'label'  => 'Focused Assessment',
                'fields' => [
                    ['key' => 'visit_purpose',    'type' => 'select', 'label' => 'Visit Purpose',
                     'options' => ['Routine monitoring', 'Symptom management', 'Wound care', 'Medication administration', 'Post-procedure follow-up', 'Education', 'Other']],
                    ['key' => 'mental_status',    'type' => 'select', 'label' => 'Mental Status',
                     'options' => ['Alert and oriented × 4', 'Alert, oriented × 3', 'Alert, oriented × 2', 'Confused', 'Agitated', 'Lethargic', 'Unresponsive']],
                    ['key' => 'comfort_level',    'type' => 'select', 'label' => 'Comfort Level',
                     'options' => ['No distress', 'Mild discomfort', 'Moderate discomfort', 'Severe distress']],
                    ['key' => 'assessment_notes', 'type' => 'textarea', 'label' => 'Assessment Notes'],
                ],
            ],
            [
                'key'    => 'interventions',
                'label'  => 'Interventions',
                'fields' => [
                    ['key' => 'interventions_performed', 'type' => 'multiselect', 'label' => 'Interventions Performed',
                     'options' => ['Vital signs taken', 'Medication administered', 'Wound care performed', 'Education provided', 'Fall precautions reinforced', 'Comfort measures', 'Specimen collected', 'Referral placed']],
                    ['key' => 'patient_response',        'type' => 'select', 'label' => 'Participant Response',
                     'options' => ['Expected response', 'No change', 'Improved', 'Declined', 'Adverse reaction — documented separately']],
                    ['key' => 'intervention_notes',      'type' => 'textarea', 'label' => 'Intervention Notes'],
                ],
            ],
            [
                'key'    => 'plan',
                'label'  => 'Plan',
                'fields' => [
                    ['key' => 'next_visit',  'type' => 'select', 'label' => 'Next Nursing Visit',
                     'options' => ['Tomorrow', '2–3 days', '1 week', 'PRN', 'Scheduled — see calendar']],
                    ['key' => 'plan_notes',  'type' => 'textarea', 'label' => 'Plan Notes'],
                ],
            ],
        ],
    ],

    // ─── 3. PT/OT/ST Therapy Session ─────────────────────────────────────────
    // Fields: 9 total, 7 structured (78%)
    'therapy_pt' => [
        'label'       => 'PT Therapy Session',
        'departments' => ['therapies'],
        'sections'    => [
            [
                'key'    => 'session',
                'label'  => 'Session Details',
                'fields' => [
                    ['key' => 'session_type',    'type' => 'select', 'label' => 'Session Type',
                     'options' => ['Initial evaluation', 'Treatment session', 'Re-evaluation', 'Discharge planning', 'Home program update']],
                    ['key' => 'goals_addressed', 'type' => 'multiselect', 'label' => 'Goals Addressed',
                     'options' => ['Strength', 'Range of motion', 'Balance', 'Gait training', 'Transfer training', 'Endurance', 'Pain management', 'Fall prevention', 'Home safety']],
                    ['key' => 'interventions',   'type' => 'multiselect', 'label' => 'Interventions',
                     'options' => ['Therapeutic exercise', 'Neuromuscular re-education', 'Manual therapy', 'Gait training', 'Balance activities', 'Modalities', 'Caregiver training', 'Home program instruction']],
                    ['key' => 'assist_level',    'type' => 'select', 'label' => 'Assistance Level During Session',
                     'options' => ['Independent', 'Supervision', 'Contact guard', 'Minimal assist', 'Moderate assist', 'Maximal assist', 'Total assist']],
                ],
            ],
            [
                'key'    => 'response',
                'label'  => 'Response & Status',
                'fields' => [
                    ['key' => 'participant_response', 'type' => 'select', 'label' => 'Participant Response to Treatment',
                     'options' => ['Tolerated well', 'Tolerated with minimal difficulty', 'Moderate difficulty — rest breaks required', 'Session terminated early — see notes', 'No significant response']],
                    ['key' => 'functional_status',   'type' => 'select', 'label' => 'Functional Status vs. Last Session',
                     'options' => ['Improved', 'Maintained', 'Plateaued', 'Declined']],
                    ['key' => 'progress_notes',      'type' => 'textarea', 'label' => 'Progress Notes'],
                ],
            ],
            [
                'key'    => 'plan',
                'label'  => 'Plan',
                'fields' => [
                    ['key' => 'frequency',    'type' => 'select', 'label' => 'Recommended Frequency',
                     'options' => ['3×/week', '2×/week', '1×/week', 'PRN', 'Discharge — goals met', 'Discharge — patient request', 'Continued — see notes']],
                    ['key' => 'plan_notes',   'type' => 'textarea', 'label' => 'Plan Notes'],
                ],
            ],
        ],
    ],

    // OT and ST use the same structure as PT with different label
    'therapy_ot' => [
        'label'       => 'OT Therapy Session',
        'departments' => ['therapies'],
        'sections'    => [
            [
                'key'    => 'session',
                'label'  => 'Session Details',
                'fields' => [
                    ['key' => 'session_type',    'type' => 'select', 'label' => 'Session Type',
                     'options' => ['Initial evaluation', 'Treatment session', 'Re-evaluation', 'Discharge planning', 'Home program update']],
                    ['key' => 'goals_addressed', 'type' => 'multiselect', 'label' => 'Goals Addressed',
                     'options' => ['ADL independence', 'Fine motor skills', 'Cognitive retraining', 'Home management', 'Safety awareness', 'Adaptive equipment training', 'Caregiver education']],
                    ['key' => 'interventions',   'type' => 'multiselect', 'label' => 'Interventions',
                     'options' => ['ADL training', 'Cognitive tasks', 'Fine motor activities', 'Adaptive equipment trial', 'Home assessment', 'Caregiver training', 'Energy conservation']],
                    ['key' => 'assist_level',    'type' => 'select', 'label' => 'Assistance Level During Session',
                     'options' => ['Independent', 'Supervision', 'Minimal assist', 'Moderate assist', 'Maximal assist', 'Total assist']],
                ],
            ],
            [
                'key'    => 'response',
                'label'  => 'Response & Status',
                'fields' => [
                    ['key' => 'participant_response', 'type' => 'select', 'label' => 'Participant Response to Treatment',
                     'options' => ['Tolerated well', 'Tolerated with difficulty', 'Session terminated early — see notes']],
                    ['key' => 'functional_status',   'type' => 'select', 'label' => 'Functional Status vs. Last Session',
                     'options' => ['Improved', 'Maintained', 'Plateaued', 'Declined']],
                    ['key' => 'progress_notes',      'type' => 'textarea', 'label' => 'Progress Notes'],
                ],
            ],
            [
                'key'    => 'plan',
                'label'  => 'Plan',
                'fields' => [
                    ['key' => 'frequency',  'type' => 'select', 'label' => 'Recommended Frequency',
                     'options' => ['3×/week', '2×/week', '1×/week', 'PRN', 'Discharge — goals met', 'Continued — see notes']],
                    ['key' => 'plan_notes', 'type' => 'textarea', 'label' => 'Plan Notes'],
                ],
            ],
        ],
    ],

    'therapy_st' => [
        'label'       => 'ST Therapy Session',
        'departments' => ['therapies'],
        'sections'    => [
            [
                'key'    => 'session',
                'label'  => 'Session Details',
                'fields' => [
                    ['key' => 'session_type',    'type' => 'select', 'label' => 'Session Type',
                     'options' => ['Initial evaluation', 'Treatment session', 'Re-evaluation', 'Discharge planning']],
                    ['key' => 'goals_addressed', 'type' => 'multiselect', 'label' => 'Goals Addressed',
                     'options' => ['Swallowing safety', 'Language/communication', 'Cognitive-communication', 'Aphasia treatment', 'Voice treatment', 'Caregiver education']],
                    ['key' => 'diet_texture',    'type' => 'select', 'label' => 'Current Diet Texture (IDDSI)',
                     'options' => ['Level 7 — Regular', 'Level 6 — Soft & Bite-Sized', 'Level 5 — Minced & Moist', 'Level 4 — Puréed', 'Level 3 — Liquidized', 'Level 2 — Mildly Thick', 'Level 1 — Slightly Thick', 'NPO']],
                    ['key' => 'diet_change',     'type' => 'checkbox', 'label' => 'Diet texture changed this session'],
                ],
            ],
            [
                'key'    => 'response',
                'label'  => 'Response & Status',
                'fields' => [
                    ['key' => 'participant_response', 'type' => 'select', 'label' => 'Participant Response',
                     'options' => ['Tolerated well', 'Partial participation', 'Declined activities', 'Fatigue observed']],
                    ['key' => 'progress_notes',      'type' => 'textarea', 'label' => 'Progress Notes'],
                ],
            ],
            [
                'key'    => 'plan',
                'label'  => 'Plan',
                'fields' => [
                    ['key' => 'frequency',  'type' => 'select', 'label' => 'Recommended Frequency',
                     'options' => ['3×/week', '2×/week', '1×/week', 'PRN', 'Discharge — goals met', 'Continued — see notes']],
                    ['key' => 'plan_notes', 'type' => 'textarea', 'label' => 'Plan Notes'],
                ],
            ],
        ],
    ],

    // ─── 4. Social Work Psychosocial Note ────────────────────────────────────
    // Fields: 10 total, 6 structured (60%)
    'social_work' => [
        'label'       => 'Social Work Psychosocial Note',
        'departments' => ['social_work'],
        'sections'    => [
            [
                'key'    => 'presenting',
                'label'  => 'Presenting Concerns',
                'fields' => [
                    ['key' => 'contact_type',       'type' => 'select', 'label' => 'Contact Type',
                     'options' => ['Scheduled visit', 'Crisis contact', 'Family/caregiver meeting', 'Telephone contact', 'IDT referral', 'Community resource linkage']],
                    ['key' => 'concern_category',   'type' => 'multiselect', 'label' => 'Concern Category',
                     'options' => ['Social isolation', 'Caregiver stress', 'Financial concern', 'Housing instability', 'Safety concern', 'Mental health', 'Substance use', 'Grief/loss', 'Legal/guardianship', 'Benefits/entitlements']],
                    ['key' => 'presenting_notes',   'type' => 'textarea', 'label' => 'Presenting Concerns (Narrative)'],
                ],
            ],
            [
                'key'    => 'assessment',
                'label'  => 'Functional & Social Assessment',
                'fields' => [
                    ['key' => 'social_support',     'type' => 'select', 'label' => 'Social Support System',
                     'options' => ['Strong — family/friends engaged', 'Moderate — some support', 'Minimal — limited contacts', 'Isolated — no identified support']],
                    ['key' => 'caregiver_status',   'type' => 'select', 'label' => 'Caregiver Status',
                     'options' => ['No caregiver needed', 'Caregiver in place, adequate', 'Caregiver in place, stressed', 'Caregiver needed — not in place', 'Agency caregiver', 'N/A']],
                    ['key' => 'assessment_notes',   'type' => 'textarea', 'label' => 'Assessment Notes'],
                ],
            ],
            [
                'key'    => 'interventions',
                'label'  => 'Interventions & Plan',
                'fields' => [
                    ['key' => 'interventions',      'type' => 'multiselect', 'label' => 'Interventions Provided',
                     'options' => ['Counseling provided', 'Crisis intervention', 'Community resources identified', 'Benefits assistance', 'Caregiver support', 'Family meeting facilitated', 'Referral placed', 'IDT communication', 'Safety plan developed']],
                    ['key' => 'follow_up',          'type' => 'select', 'label' => 'Follow-Up',
                     'options' => ['Within 1 week', 'Within 2 weeks', '1 month', 'PRN', 'IDT review']],
                    ['key' => 'plan_notes',         'type' => 'textarea', 'label' => 'Plan Notes'],
                ],
            ],
        ],
    ],

    // ─── 5. Dietary / Nutrition Note ─────────────────────────────────────────
    // Fields: 10 total, 7 structured (70%)
    'dietary' => [
        'label'       => 'Dietary / Nutrition Note',
        'departments' => ['dietary'],
        'sections'    => [
            [
                'key'    => 'weight_review',
                'label'  => 'Weight Review',
                'fields' => [
                    ['key' => 'weight_trend',     'type' => 'select', 'label' => 'Weight Trend',
                     'options' => ['Stable (±1 lb)', 'Gaining (1–5 lbs)', 'Significant gain (>5 lbs)', 'Losing (1–5 lbs)', 'Significant loss (>5 lbs)', 'First visit — no prior weight']],
                    ['key' => 'weight_concern',   'type' => 'checkbox', 'label' => 'Weight change is clinically significant'],
                ],
            ],
            [
                'key'    => 'dietary_assessment',
                'label'  => 'Dietary Intake Assessment',
                'fields' => [
                    ['key' => 'appetite',         'type' => 'select', 'label' => 'Appetite',
                     'options' => ['Good — eating >75% of meals', 'Fair — eating 50–75%', 'Poor — eating <50%', 'Refusing meals', 'Tube feeding only']],
                    ['key' => 'diet_texture',     'type' => 'select', 'label' => 'Current Diet Texture',
                     'options' => ['Regular', 'Soft', 'Mechanical soft', 'Puréed', 'Thickened liquids', 'NPO']],
                    ['key' => 'fluid_intake',     'type' => 'select', 'label' => 'Fluid Intake',
                     'options' => ['Adequate (>1500 mL/day)', 'Borderline (1000–1500 mL)', 'Inadequate (<1000 mL)', 'Unknown']],
                    ['key' => 'intake_notes',     'type' => 'textarea', 'label' => 'Intake Assessment Notes'],
                ],
            ],
            [
                'key'    => 'plan',
                'label'  => 'Nutrition Diagnosis & Plan',
                'fields' => [
                    ['key' => 'nutrition_dx',     'type' => 'select', 'label' => 'Nutrition Diagnosis',
                     'options' => ['Inadequate oral intake', 'Malnutrition', 'Overweight/obesity', 'Food–drug interaction', 'Altered GI function', 'Swallowing difficulty', 'No nutrition diagnosis at this time']],
                    ['key' => 'interventions',    'type' => 'multiselect', 'label' => 'Interventions',
                     'options' => ['Diet order change', 'Oral supplements ordered', 'Meal assistance arranged', 'Feeding technique education', 'Texture modification', 'Fluid restriction', 'Hydration plan', 'IDT communication']],
                    ['key' => 'follow_up',        'type' => 'select', 'label' => 'Follow-Up',
                     'options' => ['1 week', '2 weeks', '1 month', '3 months', 'PRN']],
                    ['key' => 'plan_notes',       'type' => 'textarea', 'label' => 'Plan Notes'],
                ],
            ],
        ],
    ],

    // ─── 6. Home Visit Note ───────────────────────────────────────────────────
    // Fields: 12 total, 9 structured (75%)
    'home_visit' => [
        'label'       => 'Home Visit Note',
        'departments' => ['home_care', 'social_work', 'therapies'],
        'sections'    => [
            [
                'key'    => 'home_safety',
                'label'  => 'Home Safety Assessment',
                'fields' => [
                    ['key' => 'living_situation',  'type' => 'select', 'label' => 'Living Situation',
                     'options' => ['Alone', 'With spouse/partner', 'With adult child(ren)', 'With other family', 'Assisted living', 'Board & care']],
                    ['key' => 'fall_hazards',      'type' => 'multiselect', 'label' => 'Fall Hazards Identified',
                     'options' => ['None identified', 'Loose rugs', 'Poor lighting', 'Clutter in walkways', 'No grab bars', 'Unsecured cords', 'Uneven flooring', 'No handrails']],
                    ['key' => 'fall_hazards_addressed', 'type' => 'checkbox', 'label' => 'Fall hazards addressed during visit'],
                    ['key' => 'home_safety_notes', 'type' => 'textarea', 'label' => 'Home Safety Notes'],
                ],
            ],
            [
                'key'    => 'adl_observation',
                'label'  => 'ADL Observation',
                'fields' => [
                    ['key' => 'adl_status',       'type' => 'select', 'label' => 'Overall ADL Status at Home',
                     'options' => ['Fully independent', 'Mostly independent with some assist', 'Requires moderate assist', 'Requires extensive assist', 'Fully dependent']],
                    ['key' => 'adl_change',        'type' => 'select', 'label' => 'Change in ADL Status Since Last Visit',
                     'options' => ['Improved', 'Stable', 'Mildly declined', 'Significantly declined', 'First visit']],
                ],
            ],
            [
                'key'    => 'caregiver',
                'label'  => 'Caregiver Status',
                'fields' => [
                    ['key' => 'caregiver_present', 'type' => 'checkbox', 'label' => 'Caregiver present during visit'],
                    ['key' => 'caregiver_status',  'type' => 'select', 'label' => 'Caregiver Status',
                     'options' => ['Engaged and capable', 'Engaged but stressed', 'Minimally engaged', 'No caregiver', 'N/A']],
                    ['key' => 'caregiver_needs',   'type' => 'multiselect', 'label' => 'Caregiver Needs Identified',
                     'options' => ['None', 'Education/training', 'Respite care', 'Additional community support', 'Mental health support', 'Financial assistance', 'Referral to social work']],
                ],
            ],
            [
                'key'    => 'plan',
                'label'  => 'Clinical Observations & Plan',
                'fields' => [
                    ['key' => 'clinical_status',  'type' => 'select', 'label' => 'Overall Clinical Status',
                     'options' => ['Stable', 'Improving', 'Declining', 'Acute concern — escalated']],
                    ['key' => 'plan_notes',        'type' => 'textarea', 'label' => 'Clinical Observations & Plan Notes'],
                ],
            ],
        ],
    ],

    // ─── 7. Behavioral Health Note ───────────────────────────────────────────
    // Fields: 19 total, 15 structured (79%)
    'behavioral_health' => [
        'label'       => 'Behavioral Health Note',
        'departments' => ['behavioral_health'],
        'sections'    => [
            [
                'key'    => 'presenting',
                'label'  => 'Presenting Concern',
                'fields' => [
                    ['key' => 'contact_type',       'type' => 'select',      'label' => 'Contact Type',
                     'options' => ['Individual session', 'Crisis contact', 'Group session', 'Family session', 'Telephone contact', 'Collateral contact', 'IDT referral']],
                    ['key' => 'presenting_problem', 'type' => 'multiselect', 'label' => 'Presenting Problem(s)',
                     'options' => ['Anxiety', 'Depression', 'Grief/loss', 'Psychosis', 'Substance use', 'Behavioral concern', 'Cognitive changes', 'Adjustment disorder', 'Family conflict', 'Suicidal ideation', 'Other']],
                    ['key' => 'presenting_notes',   'type' => 'textarea',    'label' => 'Presenting Concern Narrative'],
                ],
            ],
            [
                'key'    => 'mental_status',
                'label'  => 'Mental Status Exam',
                'fields' => [
                    ['key' => 'appearance',      'type' => 'select', 'label' => 'Appearance',
                     'options' => ['Well-groomed, appropriately dressed', 'Disheveled', 'Poorly groomed', 'Appropriate for age']],
                    ['key' => 'mood',            'type' => 'select', 'label' => 'Mood',
                     'options' => ['Euthymic', 'Depressed', 'Elevated', 'Anxious', 'Irritable', 'Labile', 'Flat']],
                    ['key' => 'affect',          'type' => 'select', 'label' => 'Affect',
                     'options' => ['Full range, congruent', 'Restricted', 'Blunted', 'Flat', 'Constricted', 'Incongruent', 'Labile']],
                    ['key' => 'thought_process', 'type' => 'select', 'label' => 'Thought Process',
                     'options' => ['Goal-directed', 'Circumstantial', 'Tangential', 'Disorganized', 'Racing thoughts', 'Blocking']],
                    ['key' => 'cognition',       'type' => 'select', 'label' => 'Cognition',
                     'options' => ['Intact', 'Mild impairment', 'Moderate impairment', 'Severe impairment']],
                    ['key' => 'insight',         'type' => 'select', 'label' => 'Insight',
                     'options' => ['Good', 'Fair', 'Poor', 'Absent']],
                    ['key' => 'mse_notes',       'type' => 'textarea', 'label' => 'MSE Notes'],
                ],
            ],
            [
                'key'    => 'risk',
                'label'  => 'Risk Assessment',
                'fields' => [
                    ['key' => 'suicidal_ideation',   'type' => 'select',   'label' => 'Suicidal Ideation',
                     'options' => ['Denied', 'Passive ideation only', 'Active ideation — no plan', 'Active ideation with plan', 'Active ideation with intent']],
                    ['key' => 'homicidal_ideation',  'type' => 'select',   'label' => 'Homicidal Ideation',
                     'options' => ['Denied', 'Present — no plan', 'Present with plan']],
                    ['key' => 'overall_risk',        'type' => 'radio',    'label' => 'Overall Risk Level',
                     'options' => ['Low', 'Moderate', 'High', 'Imminent']],
                    ['key' => 'safety_plan_updated', 'type' => 'checkbox', 'label' => 'Safety plan reviewed/updated this session'],
                    ['key' => 'risk_notes',          'type' => 'textarea', 'label' => 'Risk Notes'],
                ],
            ],
            [
                'key'    => 'plan',
                'label'  => 'Treatment & Plan',
                'fields' => [
                    ['key' => 'modality',            'type' => 'multiselect', 'label' => 'Therapeutic Modality',
                     'options' => ['CBT techniques', 'DBT skills', 'Motivational interviewing', 'Supportive therapy', 'Psychoeducation', 'Grief work', 'Behavioral activation', 'Crisis intervention']],
                    ['key' => 'medication_concerns', 'type' => 'checkbox',    'label' => 'Medication concerns communicated to prescriber'],
                    ['key' => 'follow_up',           'type' => 'select',      'label' => 'Follow-Up',
                     'options' => ['1 week', '2 weeks', '1 month', 'PRN', 'Crisis follow-up', 'Discharge from BH services']],
                    ['key' => 'plan_notes',          'type' => 'textarea',    'label' => 'Plan Notes'],
                ],
            ],
        ],
    ],

    // ─── 8. Telehealth / Virtual Visit Note ──────────────────────────────────
    // Fields: 11 total, 9 structured (82%)
    'telehealth' => [
        'label'       => 'Telehealth / Virtual Visit Note',
        'departments' => ['primary_care', 'behavioral_health', 'social_work', 'therapies'],
        'sections'    => [
            [
                'key'    => 'session',
                'label'  => 'Telehealth Session',
                'fields' => [
                    ['key' => 'platform',             'type' => 'select',   'label' => 'Telehealth Platform',
                     'options' => ['Video — Zoom for Healthcare', 'Video — Microsoft Teams', 'Video — other platform', 'Telephone — audio only']],
                    ['key' => 'technical_quality',    'type' => 'select',   'label' => 'Connection Quality',
                     'options' => ['Excellent — no disruptions', 'Good — minor disruption', 'Fair — some issues, visit completed', 'Poor — visit rescheduled']],
                    ['key' => 'participant_location', 'type' => 'select',   'label' => 'Participant Location During Visit',
                     'options' => ['Private home', 'Assisted living', 'Family member\'s home', 'Other private location']],
                    ['key' => 'consent_obtained',     'type' => 'checkbox', 'label' => 'Verbal consent for telehealth obtained this visit'],
                ],
            ],
            [
                'key'    => 'clinical',
                'label'  => 'Clinical Review',
                'fields' => [
                    ['key' => 'visit_purpose',       'type' => 'select',   'label' => 'Visit Purpose',
                     'options' => ['Follow-up', 'Acute concern', 'Medication review', 'Mental health check-in', 'Care plan review', 'Post-discharge follow-up', 'Other']],
                    ['key' => 'participant_status',  'type' => 'select',   'label' => 'Participant Presentation',
                     'options' => ['Alert and engaged', 'Alert but fatigued', 'Confused', 'Distressed', 'Unable to assess via video — documented separately']],
                    ['key' => 'clinical_notes',      'type' => 'textarea', 'label' => 'Clinical Findings / Review Notes'],
                ],
            ],
            [
                'key'    => 'plan',
                'label'  => 'Plan',
                'fields' => [
                    ['key' => 'in_person_needed',   'type' => 'checkbox', 'label' => 'In-person follow-up required'],
                    ['key' => 'follow_up_modality', 'type' => 'select',   'label' => 'Next Visit Modality',
                     'options' => ['Telehealth', 'In-center', 'Home visit', 'PRN']],
                    ['key' => 'follow_up_timing',   'type' => 'select',   'label' => 'Follow-Up Timing',
                     'options' => ['Within 48 hours', '1 week', '2 weeks', '1 month', '3 months', 'PRN']],
                    ['key' => 'plan_notes',         'type' => 'textarea', 'label' => 'Plan Notes'],
                ],
            ],
        ],
    ],

    // ─── 9. IDT Meeting Summary ───────────────────────────────────────────────
    // Fields: 12 total, 9 structured (75%)
    'idt_summary' => [
        'label'       => 'IDT Meeting Summary',
        'departments' => ['idt', 'primary_care'],
        'sections'    => [
            [
                'key'    => 'meeting',
                'label'  => 'Meeting Context',
                'fields' => [
                    ['key' => 'meeting_type',               'type' => 'select',      'label' => 'Meeting Type',
                     'options' => ['Regular IDT', 'Care plan review', 'Emergency IDT', 'New participant IDT', 'Hospitalization/discharge IDT', 'Annual reassessment IDT']],
                    ['key' => 'disciplines_present',        'type' => 'multiselect', 'label' => 'Disciplines Present',
                     'options' => ['Primary Care / Nursing', 'Social Work', 'Therapies (PT/OT/ST)', 'Dietary', 'Behavioral Health', 'Activities', 'Home Care', 'Pharmacy', 'Transportation', 'Care Coordination']],
                    ['key' => 'participant_family_present', 'type' => 'checkbox',    'label' => 'Participant and/or family member present'],
                ],
            ],
            [
                'key'    => 'status',
                'label'  => 'Participant Status Review',
                'fields' => [
                    ['key' => 'overall_status',        'type' => 'select',   'label' => 'Overall Status',
                     'options' => ['Stable', 'Improving', 'Declining', 'Acute concern', 'Hospice/comfort care', 'Disenrolled']],
                    ['key' => 'functional_change',     'type' => 'select',   'label' => 'Functional Change Since Last IDT',
                     'options' => ['Improved', 'Stable', 'Mild decline', 'Significant decline', 'First IDT — no prior baseline']],
                    ['key' => 'hospitalization_since', 'type' => 'checkbox', 'label' => 'Hospitalization since last IDT'],
                    ['key' => 'er_visit_since',        'type' => 'checkbox', 'label' => 'ER visit since last IDT'],
                    ['key' => 'status_notes',          'type' => 'textarea', 'label' => 'Status Summary Notes'],
                ],
            ],
            [
                'key'    => 'decisions',
                'label'  => 'IDT Decisions & Action Items',
                'fields' => [
                    ['key' => 'care_plan_updated',   'type' => 'checkbox',    'label' => 'Care plan updated as a result of this IDT'],
                    ['key' => 'referrals_generated', 'type' => 'multiselect', 'label' => 'Referrals / SDRs Generated',
                     'options' => ['None', 'Physical Therapy', 'Occupational Therapy', 'Speech Therapy', 'Social Work', 'Behavioral Health', 'Dietary', 'Home Care', 'Transportation', 'Pharmacy', 'Specialist referral']],
                    ['key' => 'next_review',         'type' => 'select',      'label' => 'Next IDT Review',
                     'options' => ['Next regular IDT (per schedule)', 'Within 30 days — status change', 'Within 60 days', 'Within 6 months — per CMS requirement', 'PRN']],
                    ['key' => 'decisions_notes',     'type' => 'textarea',    'label' => 'Decisions & Action Items Narrative'],
                ],
            ],
        ],
    ],

    // ─── 10. Incident Report ──────────────────────────────────────────────────
    // Fields: 12 total, 10 structured (83%)
    'incident' => [
        'label'       => 'Incident Report',
        'departments' => ['primary_care', 'home_care', 'activities', 'transportation', 'it_admin', 'qa_compliance'],
        'sections'    => [
            [
                'key'    => 'incident_details',
                'label'  => 'Incident Details',
                'fields' => [
                    ['key' => 'incident_type',      'type' => 'select',      'label' => 'Incident Type',
                     'options' => ['Fall — no injury', 'Fall — with injury', 'Near miss', 'Medication error', 'Behavioral incident', 'Elopement/wandering', 'Transportation incident', 'Abuse/neglect concern', 'Equipment failure', 'Other']],
                    ['key' => 'incident_location',  'type' => 'select',      'label' => 'Location of Incident',
                     'options' => ['PACE day center', 'Participant home', 'In transport vehicle', 'Community setting', 'Medical office/facility', 'Other']],
                    ['key' => 'witnessed',          'type' => 'radio',       'label' => 'Was Incident Witnessed?',
                     'options' => ['Yes — by staff', 'Yes — by family/caregiver', 'Not witnessed — discovered after', 'Reported by participant']],
                    ['key' => 'immediate_response', 'type' => 'multiselect', 'label' => 'Immediate Response Actions',
                     'options' => ['First aid provided', 'Vitals assessed', '911 called', 'Physician notified', 'Family notified', 'Participant assessed — no injury', 'Transport arranged', 'Documentation completed']],
                ],
            ],
            [
                'key'    => 'injury_assessment',
                'label'  => 'Injury / Harm Assessment',
                'fields' => [
                    ['key' => 'injury_present',     'type' => 'radio',       'label' => 'Injury Present?',
                     'options' => ['No injury', 'Minor injury — treated on site', 'Moderate injury — required medical attention', 'Serious injury — emergency care', 'Death']],
                    ['key' => 'injury_location',    'type' => 'multiselect', 'label' => 'Injury Location (if applicable)',
                     'options' => ['N/A', 'Head/face', 'Neck', 'Chest', 'Back', 'Abdomen', 'Hip', 'Upper extremity', 'Lower extremity', 'Skin/soft tissue']],
                    ['key' => 'incident_narrative', 'type' => 'textarea',    'label' => 'Incident Narrative (describe sequence of events)'],
                ],
            ],
            [
                'key'    => 'follow_up',
                'label'  => 'Follow-Up & Reporting',
                'fields' => [
                    ['key' => 'state_report_required',   'type' => 'checkbox', 'label' => 'State/CMS reportable incident'],
                    ['key' => 'family_notified',         'type' => 'checkbox', 'label' => 'Family/legally authorized representative notified'],
                    ['key' => 'supervisor_notified',     'type' => 'checkbox', 'label' => 'Supervisor / QA notified'],
                    ['key' => 'preventability',          'type' => 'select',   'label' => 'Preventability Assessment',
                     'options' => ['Preventable — corrective action underway', 'Possibly preventable — under review', 'Not preventable', 'Undetermined at this time']],
                    ['key' => 'corrective_action_notes', 'type' => 'textarea', 'label' => 'Corrective Action / Follow-Up Notes'],
                ],
            ],
        ],
    ],

    // ─── 11. Addendum ─────────────────────────────────────────────────────────
    // Fields: 4 total, 3 structured (75%)
    'addendum' => [
        'label'       => 'Addendum',
        'departments' => ['primary_care', 'behavioral_health', 'social_work', 'therapies', 'dietary', 'home_care', 'idt'],
        'sections'    => [
            [
                'key'    => 'addendum',
                'label'  => 'Addendum Details',
                'fields' => [
                    ['key' => 'addendum_reason',             'type' => 'select',   'label' => 'Reason for Addendum',
                     'options' => ['Missing information — not available at time of original note', 'Correction to factual error', 'Lab/test results received after signing', 'Clarification of clinical findings', 'Additional collateral information', 'Documentation error — detail updated', 'Other']],
                    ['key' => 'original_note_date',          'type' => 'date',     'label' => 'Date of Original Note Being Addended'],
                    ['key' => 'information_available_since', 'type' => 'select',   'label' => 'When Was This Information Available?',
                     'options' => ['Same day as original note', 'Next business day', '2–7 days later', 'More than 1 week later', 'N/A']],
                    ['key' => 'addendum_content',            'type' => 'textarea', 'label' => 'Addendum Content'],
                ],
            ],
        ],
    ],

    // ─── W4-8: Transition of Care Note ───────────────────────────────────────
    // Auto-created as DRAFT from HL7 ADT A01 (admission) and A03 (discharge).
    // Pre-populated with transition_type and facility from the ADT payload.
    // All departments can view; clinical staff complete and sign after the event.
    // Field counts: 10 structured, 3 free-text → 13 total (77% structured).
    'transition_of_care' => [
        'label'       => 'Transition of Care',
        'departments' => [
            'primary_care', 'therapies', 'social_work', 'behavioral_health',
            'dietary', 'home_care', 'idt', 'pharmacy', 'nursing', 'it_admin',
        ],
        'sections'    => [
            [
                'key'    => 'transition_details',
                'label'  => 'Transition Details',
                'fields' => [
                    ['key' => 'transition_type',     'type' => 'select',   'label' => 'Transition Type',
                     'options' => ['hospital_admission', 'hospital_discharge', 'snf_admission', 'snf_discharge', 'er_visit', 'home_health_referral', 'hospice_referral', 'other']],
                    ['key' => 'facility',            'type' => 'select',   'label' => 'Receiving / Sending Facility',
                     'options' => ['PACE Center', 'External Hospital', 'Skilled Nursing Facility', 'Emergency Department', 'Home', 'Hospice', 'Other']],
                    ['key' => 'event_date',          'type' => 'date',     'label' => 'Transition Date'],
                    ['key' => 'transport_mode',      'type' => 'select',   'label' => 'Mode of Transport',
                     'options' => ['Ambulance', 'PACE van', 'Family/caregiver vehicle', 'Personal vehicle', 'N/A']],
                    ['key' => 'accompanied_by',      'type' => 'select',   'label' => 'Accompanied By',
                     'options' => ['Family member', 'PACE staff', 'Caregiver', 'Unaccompanied', 'N/A']],
                ],
            ],
            [
                'key'    => 'clinical_status',
                'label'  => 'Clinical Status at Transition',
                'fields' => [
                    ['key' => 'primary_diagnosis',   'type' => 'select',   'label' => 'Primary Reason for Transition',
                     'options' => ['Acute illness', 'Injury/fall', 'Cardiac event', 'Respiratory event', 'Stroke/TIA', 'Infection', 'Surgical procedure', 'Behavioral/psych crisis', 'Elective procedure', 'Other']],
                    ['key' => 'functional_status',   'type' => 'select',   'label' => 'Functional Status at Transition',
                     'options' => ['Independent', 'Minimal assist', 'Moderate assist', 'Max assist', 'Dependent', 'Bedbound']],
                    ['key' => 'medications_reconciled', 'type' => 'checkbox', 'label' => 'Medications reconciled at transition'],
                    ['key' => 'care_plan_updated',   'type' => 'checkbox', 'label' => 'Care plan reviewed/updated for transition'],
                    ['key' => 'clinical_notes',      'type' => 'textarea', 'label' => 'Clinical Summary / Reason for Transition'],
                ],
            ],
            [
                'key'    => 'follow_up',
                'label'  => 'Follow-Up Plan',
                'fields' => [
                    ['key' => 'follow_up_timeframe', 'type' => 'select',   'label' => 'Follow-Up Contact Timeframe',
                     'options' => ['Within 24 hours', 'Within 48 hours', 'Within 72 hours', 'Within 1 week', 'As needed']],
                    ['key' => 'idt_notified',        'type' => 'checkbox', 'label' => 'IDT team notified of transition'],
                    ['key' => 'sdr_required',        'type' => 'checkbox', 'label' => 'SDR required (discharge only)'],
                    ['key' => 'follow_up_notes',     'type' => 'textarea', 'label' => 'Follow-Up Instructions / Plan Notes'],
                ],
            ],
        ],
    ],

    // ─── W4-8: Podiatry Note ─────────────────────────────────────────────────
    // Required PACE podiatry service per 42 CFR §460.92.
    // Documents routine foot care, pathology findings, and treatment provided.
    // Field counts: 11 structured, 2 free-text → 13 total (85% structured).
    'podiatry' => [
        'label'       => 'Podiatry',
        'departments' => [
            'primary_care', 'therapies', 'home_care', 'idt', 'it_admin',
        ],
        'sections'    => [
            [
                'key'    => 'visit',
                'label'  => 'Visit Details',
                'fields' => [
                    ['key' => 'visit_reason',      'type' => 'select',      'label' => 'Reason for Visit',
                     'options' => ['Routine nail care', 'Wound care', 'New concern', 'Follow-up', 'Diabetic foot exam', 'Orthotic fitting', 'Post-surgical follow-up']],
                    ['key' => 'diabetes_dx',       'type' => 'checkbox',    'label' => 'Participant has diabetes diagnosis'],
                    ['key' => 'peripheral_vascular','type' => 'checkbox',   'label' => 'Peripheral vascular disease documented'],
                ],
            ],
            [
                'key'    => 'exam',
                'label'  => 'Foot Examination',
                'fields' => [
                    ['key' => 'skin_integrity',    'type' => 'select',      'label' => 'Skin Integrity',
                     'options' => ['Intact, no lesions', 'Callus/hyperkeratosis', 'Fissures present', 'Skin breakdown', 'Wound/ulcer present', 'Blister']],
                    ['key' => 'nail_condition',    'type' => 'select',      'label' => 'Nail Condition',
                     'options' => ['Normal', 'Thickened', 'Ingrown', 'Fungal', 'Absent', 'Discolored', 'Brittle']],
                    ['key' => 'circulation',       'type' => 'select',      'label' => 'Circulation (Dorsalis Pedis / PT Pulses)',
                     'options' => ['Normal bilaterally', 'Diminished left', 'Diminished right', 'Diminished bilaterally', 'Absent left', 'Absent right', 'Not assessed']],
                    ['key' => 'sensation',         'type' => 'select',      'label' => 'Sensation (Monofilament)',
                     'options' => ['Intact bilaterally', 'Decreased left', 'Decreased right', 'Decreased bilaterally', 'Absent', 'Not tested']],
                    ['key' => 'deformity',         'type' => 'multiselect', 'label' => 'Deformity / Structural Finding',
                     'options' => ['None', 'Hallux valgus', 'Hammer toe', 'Claw toe', 'Charcot deformity', 'Flat foot', 'High arch', 'Amputation (partial)', 'Other']],
                    ['key' => 'wound_present',     'type' => 'checkbox',    'label' => 'Active wound present (triggers wound care protocol)'],
                    ['key' => 'exam_notes',        'type' => 'textarea',    'label' => 'Examination Findings'],
                ],
            ],
            [
                'key'    => 'treatment',
                'label'  => 'Treatment',
                'fields' => [
                    ['key' => 'treatment_provided', 'type' => 'multiselect', 'label' => 'Treatment Provided',
                     'options' => ['Nail trimming', 'Debridement', 'Callus reduction', 'Wound dressing', 'Padding applied', 'Orthotics evaluated', 'Patient education', 'Referral placed', 'No treatment — monitoring only']],
                    ['key' => 'follow_up_interval', 'type' => 'select',      'label' => 'Follow-Up Interval',
                     'options' => ['2 weeks', '4 weeks', '6 weeks', '8 weeks', '3 months', '6 months', 'PRN', 'Urgent referral']],
                    ['key' => 'treatment_notes',    'type' => 'textarea',    'label' => 'Treatment Notes / Recommendations'],
                ],
            ],
        ],
    ],

];
