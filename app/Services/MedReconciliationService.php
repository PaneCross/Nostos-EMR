<?php

// ─── MedReconciliationService ──────────────────────────────────────────────────
// Orchestrates the 5-step medication reconciliation workflow required by CMS PACE
// regulations at care transitions (enrollment, post-hospital, IDT review, routine).
//
// Workflow summary:
//   Step 1  → startReconciliation() — creates a new 'in_progress' record
//   Step 2  → addPriorMedications() — populates prior_medications JSONB
//   Step 3  → generateComparison()  — diffs prior list vs current active meds
//   Step 4  → applyDecisions()      — executes keep/add/discontinue/modify
//   Step 5  → providerApproval()    — locks the record (status → 'approved')
//
// Idempotency rule: only one active reconciliation (in_progress or decisions_made)
// is allowed per participant at a time. startReconciliation() returns the existing
// active record if one is found, rather than creating a duplicate.
//
// Immutability rule: once a record is 'approved', it cannot be modified.
// Any method that mutates state calls assertNotLocked() first.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MedReconciliation;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use LogicException;

class MedReconciliationService
{
    // ── Step 1: Start ─────────────────────────────────────────────────────────

    /**
     * Create a new in_progress reconciliation record for the given participant.
     * If an active (in_progress or decisions_made) record already exists, it is
     * returned instead of creating a duplicate (idempotent — safe to call again).
     *
     * @param  Participant  $participant
     * @param  string       $priorSource  One of MedReconciliation::SOURCES
     * @param  string       $type         One of MedReconciliation::TYPES
     * @param  User         $user         Clinician initiating the reconciliation
     * @return MedReconciliation
     */
    public function startReconciliation(
        Participant $participant,
        string $priorSource,
        string $type,
        User $user,
    ): MedReconciliation {
        // Return existing active record if found (idempotent)
        $existing = MedReconciliation::where('participant_id', $participant->id)
            ->whereIn('status', ['in_progress', 'decisions_made'])
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $rec = MedReconciliation::create([
            'participant_id'         => $participant->id,
            'tenant_id'              => $participant->tenant_id,
            'reconciled_by_user_id'  => $user->id,
            'reconciling_department' => $user->department,
            'reconciliation_type'    => $type,
            'prior_source'           => $priorSource,
            'prior_medications'      => [],
            'reconciled_medications' => [],
            'status'                 => 'in_progress',
            'has_discrepancies'      => false,
        ]);

        AuditLog::record(
            action: 'participant.med_reconciliation.started',
            tenantId: $participant->tenant_id,
            userId: $user->id,
            resourceType: 'med_reconciliation',
            resourceId: $rec->id,
            description: "Medication reconciliation started ({$type}) from source: {$priorSource}",
        );

        return $rec;
    }

    // ── Step 2: Add prior medications ─────────────────────────────────────────

    /**
     * Replace the prior_medications list on the reconciliation record.
     * Called with the full array from Step 2 of the wizard.
     * Throws if the record is locked (approved).
     *
     * Each entry in $medications:
     *   {drug_name (required), dose, dose_unit, frequency, route, prescriber, notes}
     *
     * @param  MedReconciliation  $rec
     * @param  array              $medications  Array of prior medication entries
     * @throws LogicException if rec is approved
     */
    public function addPriorMedications(MedReconciliation $rec, array $medications): void
    {
        $this->assertNotLocked($rec);

        $rec->update([
            'prior_medications' => $medications,
            'status'            => 'in_progress',
        ]);
    }

    /**
     * Append a single prior medication entry (used by addPriorMedication route).
     *
     * @throws LogicException if rec is approved
     */
    public function addPriorMedication(MedReconciliation $rec, array $medData): void
    {
        $this->assertNotLocked($rec);

        $prior   = $rec->prior_medications ?? [];
        $prior[] = array_intersect_key($medData, array_flip([
            'drug_name', 'dose', 'dose_unit', 'frequency', 'route', 'prescriber', 'notes',
        ]));

        $rec->update(['prior_medications' => $prior]);
    }

    // ── Step 3: Generate comparison ───────────────────────────────────────────

    /**
     * Diff the prior medication list (from Step 2) against the participant's
     * current active medications in emr_medications.
     *
     * Matching is case-insensitive on drug_name.
     *
     * Returns:
     * [
     *   'matched'      => [{prior, current, recommendation: 'keep'}],
     *   'prior_only'   => [{prior, recommendation: 'add_or_ignore'}],
     *   'current_only' => [{current, recommendation: 'keep_or_discontinue'}],
     * ]
     *
     * @param  MedReconciliation  $rec
     * @return array
     */
    public function generateComparison(MedReconciliation $rec): array
    {
        $priorMeds = $rec->prior_medications ?? [];

        // Load current active medications for this participant
        $currentMeds = Medication::where('participant_id', $rec->participant_id)
            ->whereIn('status', ['active', 'prn'])
            ->get(['id', 'drug_name', 'dose', 'dose_unit', 'frequency', 'route', 'status']);

        // Index current meds by normalized drug name for fast lookup
        $currentByName = $currentMeds->keyBy(fn ($m) => strtolower(trim($m->drug_name)));

        $matched     = [];
        $priorOnly   = [];
        $matchedKeys = [];

        foreach ($priorMeds as $prior) {
            $key = strtolower(trim($prior['drug_name'] ?? ''));
            if ($key === '') {
                continue;
            }

            if (isset($currentByName[$key])) {
                $matched[]     = [
                    'prior'          => $prior,
                    'current'        => $currentByName[$key]->toArray(),
                    'recommendation' => 'keep',
                ];
                $matchedKeys[] = $key;
            } else {
                $priorOnly[] = [
                    'prior'          => $prior,
                    'recommendation' => 'add_or_ignore',
                ];
            }
        }

        // Any current med not matched by a prior entry
        $currentOnly = $currentMeds
            ->filter(fn ($m) => !in_array(strtolower(trim($m->drug_name)), $matchedKeys, true))
            ->map(fn ($m) => [
                'current'        => $m->toArray(),
                'recommendation' => 'keep_or_discontinue',
            ])
            ->values()
            ->toArray();

        return compact('matched', 'priorOnly', 'currentOnly');
    }

    // ── Step 4: Apply decisions ───────────────────────────────────────────────

    /**
     * Execute each clinician decision against emr_medications and record the
     * outcome in reconciled_medications + changes_made JSONB on the record.
     *
     * Each decision in $decisions:
     *   {
     *     drug_name:     string (for display + matching),
     *     medication_id: int|null (null for 'add' actions on prior-only drugs),
     *     action:        'keep' | 'discontinue' | 'add' | 'modify',
     *     notes:         string|null,
     *     new_dose:      string|null    (required for 'modify'),
     *     new_frequency: string|null    (required for 'modify'),
     *     new_route:     string|null    (optional for 'modify'),
     *   }
     *
     * Sets status → 'decisions_made'.
     * Sets has_discrepancies → true if any prior-only drug was NOT added.
     *
     * @throws LogicException if rec is approved
     */
    public function applyDecisions(MedReconciliation $rec, array $decisions, User $user): void
    {
        $this->assertNotLocked($rec);

        $reconciledMeds = [];
        $changesMade    = [];
        $hasDiscrepancy = false;

        foreach ($decisions as $decision) {
            $action       = $decision['action'];
            $medicationId = $decision['medication_id'] ?? null;
            $drugName     = $decision['drug_name'] ?? 'Unknown';
            $notes        = $decision['notes'] ?? null;

            $changeEntry = [
                'drug_name'     => $drugName,
                'action'        => $action,
                'notes'         => $notes,
                'medication_id' => $medicationId,
            ];

            switch ($action) {
                case 'keep':
                    // No change to the medication record; just acknowledge it.
                    break;

                case 'discontinue':
                    if ($medicationId) {
                        $med = Medication::find($medicationId);
                        if ($med && $med->participant_id === $rec->participant_id) {
                            $med->discontinue($notes ?? 'Discontinued via medication reconciliation');
                            $changeEntry['discontinued'] = true;
                        }
                    }
                    break;

                case 'add':
                    // Add a medication from the prior list into emr_medications.
                    $prior = $decision['prior_medication'] ?? [];

                    // Only pass frequency if it matches the DB enum; prior lists may use
                    // free-text values (e.g. 'twice_daily') that the enum doesn't accept.
                    $validFrequencies = ['daily', 'BID', 'TID', 'QID', 'Q4H', 'Q6H', 'Q8H', 'Q12H', 'PRN', 'weekly', 'monthly', 'once'];
                    $priorFrequency   = in_array($prior['frequency'] ?? null, $validFrequencies, true)
                        ? $prior['frequency']
                        : null;

                    $newMed = Medication::create([
                        'participant_id'              => $rec->participant_id,
                        'tenant_id'                   => $rec->tenant_id,
                        'drug_name'                   => $prior['drug_name'] ?? $drugName,
                        'dose'                        => $prior['dose'] ?? null,
                        'dose_unit'                   => $prior['dose_unit'] ?? null,
                        'route'                       => $prior['route'] ?? 'oral',
                        'frequency'                   => $priorFrequency,
                        'is_prn'                      => false,
                        'status'                      => 'active',
                        'is_controlled'               => false,
                        'prescribing_provider_user_id'=> $user->id,
                        'prescribed_date'             => now()->toDateString(),
                        'start_date'                  => now()->toDateString(),
                        'pharmacy_notes'              => $notes,
                    ]);
                    $changeEntry['medication_id'] = $newMed->id;
                    $changeEntry['added']         = true;
                    $medicationId                 = $newMed->id;
                    break;

                case 'modify':
                    if ($medicationId) {
                        $med = Medication::find($medicationId);
                        if ($med && $med->participant_id === $rec->participant_id) {
                            $updates = array_filter([
                                'dose'      => $decision['new_dose'] ?? null,
                                'frequency' => $decision['new_frequency'] ?? null,
                                'route'     => $decision['new_route'] ?? null,
                            ], fn ($v) => $v !== null);
                            if (!empty($updates)) {
                                $med->update($updates);
                            }
                            $changeEntry['modified'] = $updates;
                        }
                    }
                    break;
            }

            // Track discrepancy: prior-only drug that was deliberately NOT added
            if ($action === 'keep' && !$medicationId) {
                // Prior-only drug marked 'keep' without adding it = discrepancy
                $hasDiscrepancy = true;
            }

            $reconciledMeds[] = [
                'drug_name'     => $drugName,
                'medication_id' => $changeEntry['medication_id'] ?? $medicationId,
                'action'        => $action,
                'notes'         => $notes,
            ];
            $changesMade[] = $changeEntry;
        }

        $rec->update([
            'reconciled_medications' => $reconciledMeds,
            'changes_made'           => $changesMade,
            'has_discrepancies'      => $hasDiscrepancy,
            'status'                 => 'decisions_made',
        ]);

        Log::info('MedReconciliation decisions applied', [
            'reconciliation_id' => $rec->id,
            'participant_id'    => $rec->participant_id,
            'changes_count'     => count($changesMade),
        ]);
    }

    // ── Step 5: Provider approval ─────────────────────────────────────────────

    /**
     * Lock the reconciliation record by setting status → 'approved'.
     * Must be called by a provider in APPROVER_DEPARTMENTS.
     * Once approved, the record is immutable.
     *
     * @param  MedReconciliation  $rec
     * @param  User               $provider  Must be in MedReconciliation::APPROVER_DEPARTMENTS
     * @throws LogicException if rec is already approved or not in decisions_made state
     */
    public function providerApproval(MedReconciliation $rec, User $provider): void
    {
        $this->assertNotLocked($rec);

        if ($rec->status !== 'decisions_made') {
            throw new LogicException(
                'Reconciliation must be in decisions_made state before provider approval. ' .
                "Current status: {$rec->status}"
            );
        }

        $rec->update([
            'approved_by_user_id' => $provider->id,
            'approved_at'         => now(),
            'reconciled_at'       => now(),
            'status'              => 'approved',
        ]);

        AuditLog::record(
            action: 'participant.med_reconciliation.approved',
            tenantId: $rec->tenant_id,
            userId: $provider->id,
            resourceType: 'med_reconciliation',
            resourceId: $rec->id,
            description: "Medication reconciliation approved and locked by {$provider->first_name} {$provider->last_name}",
        );
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Guard: throw if the record is in 'approved' state (immutable).
     *
     * @throws LogicException
     */
    private function assertNotLocked(MedReconciliation $rec): void
    {
        if ($rec->isLocked()) {
            throw new LogicException(
                "Reconciliation #{$rec->id} is approved and locked. No further changes are permitted."
            );
        }
    }
}
