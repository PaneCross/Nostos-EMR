<?php

// ─── DrugInteractionService ────────────────────────────────────────────────────
// Checks new medications against the participant's existing active medications
// for known drug-drug interactions, and persists interaction alerts.
//
// Called from MedicationController::store() and ::update() after a medication
// is saved. Interactions are matched against emr_drug_interactions_reference.
//
// Design decisions:
//   - Non-blocking: interactions generate alerts, not hard errors.
//     The controller saves the medication regardless; clinicians see alerts.
//   - Idempotent: checkInteractions() re-checks from scratch; existing alerts
//     for the same pair are NOT duplicated (checked before insert).
//   - Pair normalization: drug_name_1 < drug_name_2 alphabetically to avoid
//     duplicate entries in both orderings in the reference table.
//   - Fail-safe: if the reference table is empty or missing, returns empty array.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\DrugInteractionAlert;
use App\Models\Medication;
use App\Models\Participant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DrugInteractionService
{
    /**
     * Check a newly added/updated medication for interactions with the participant's
     * other active medications. Saves new interaction alerts and returns them.
     *
     * @param  Medication   $newMed   The medication just added or updated.
     * @param  Participant  $participant  The participant this medication belongs to.
     * @return Collection   Collection of newly-created DrugInteractionAlert models.
     */
    public function checkInteractions(Medication $newMed, Participant $participant): Collection
    {
        // Gather all OTHER active medications for this participant
        $otherMeds = Medication::where('participant_id', $participant->id)
            ->where('id', '!=', $newMed->id)
            ->whereIn('status', ['active', 'prn'])
            ->get(['id', 'drug_name']);

        if ($otherMeds->isEmpty()) {
            return collect();
        }

        $newAlerts = collect();

        foreach ($otherMeds as $existingMed) {
            $interaction = $this->findInteraction($newMed->drug_name, $existingMed->drug_name);

            if (!$interaction) {
                continue;
            }

            // Skip if an unacknowledged alert for this drug name pair already exists.
            // Check by drug_name (not medication_id) so re-prescribing the same drug
            // doesn't generate a duplicate alert when the original is still unacknowledged.
            $alreadyExists = DrugInteractionAlert::where('participant_id', $participant->id)
                ->where('is_acknowledged', false)
                ->where(function ($q) use ($newMed, $existingMed) {
                    $q->where(function ($inner) use ($newMed, $existingMed) {
                        $inner->where('drug_name_1', $newMed->drug_name)
                            ->where('drug_name_2', $existingMed->drug_name);
                    })->orWhere(function ($inner) use ($newMed, $existingMed) {
                        $inner->where('drug_name_1', $existingMed->drug_name)
                            ->where('drug_name_2', $newMed->drug_name);
                    });
                })->exists();

            if ($alreadyExists) {
                continue;
            }

            $alert = DrugInteractionAlert::create([
                'participant_id'  => $participant->id,
                'tenant_id'       => $participant->tenant_id,
                'medication_id_1' => $newMed->id,
                'medication_id_2' => $existingMed->id,
                'drug_name_1'     => $newMed->drug_name,
                'drug_name_2'     => $existingMed->drug_name,
                'severity'        => $interaction->severity,
                'description'     => $interaction->description,
            ]);

            $newAlerts->push($alert);

            Log::info('Drug interaction alert created', [
                'participant_id' => $participant->id,
                'drug_1'         => $newMed->drug_name,
                'drug_2'         => $existingMed->drug_name,
                'severity'       => $interaction->severity,
            ]);
        }

        return $newAlerts;
    }

    /**
     * Look up an interaction between two drug names in the reference table.
     * Checks both orderings (a,b) and (b,a) since the table stores normalized pairs.
     *
     * @return object|null  Row from emr_drug_interactions_reference, or null if no interaction.
     */
    public function findInteraction(string $drugName1, string $drugName2): ?object
    {
        try {
            return DB::table('emr_drug_interactions_reference')
                ->where(function ($q) use ($drugName1, $drugName2) {
                    $q->where('drug_name_1', $drugName1)->where('drug_name_2', $drugName2);
                })
                ->orWhere(function ($q) use ($drugName1, $drugName2) {
                    $q->where('drug_name_1', $drugName2)->where('drug_name_2', $drugName1);
                })
                ->first();
        } catch (\Throwable $e) {
            // Reference table may not exist in all environments — fail safe
            Log::warning('DrugInteractionService: could not query reference table', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Retrieve all unacknowledged drug interaction alerts for a participant,
     * ordered by severity (contraindicated first).
     *
     * @return Collection
     */
    public function getUnacknowledgedAlerts(Participant $participant): Collection
    {
        return DrugInteractionAlert::where('participant_id', $participant->id)
            ->where('is_acknowledged', false)
            ->orderByRaw("CASE severity
                WHEN 'contraindicated' THEN 1
                WHEN 'major' THEN 2
                WHEN 'moderate' THEN 3
                WHEN 'minor' THEN 4
                ELSE 5 END")
            ->get();
    }
}
