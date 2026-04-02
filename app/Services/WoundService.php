<?php

// ─── WoundService ─────────────────────────────────────────────────────────────
// Business logic for wound record creation and periodic assessment tracking.
//
// CMS quality metric enforcement:
//   Stage 3/4 or unstageable/DTI pressure injuries → critical alert to
//   primary_care + qa_compliance. This aligns with CMS reporting requirements
//   for new pressure injuries in PACE settings.
//
// Assessment lifecycle:
//   deteriorated  → warning alert to primary_care
//   healed        → wound_record.status = 'healed', healed_date = today
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Participant;
use App\Models\WoundAssessment;
use App\Models\WoundRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WoundService
{
    public function __construct(
        private readonly AlertService $alerts,
    ) {}

    // ── Open Wound ─────────────────────────────────────────────────────────────

    /**
     * Open a new wound record for a participant.
     *
     * Stage 3, 4, unstageable, or DTI pressure injuries automatically create
     * a critical alert for primary_care + qa_compliance (CMS quality metric).
     */
    public function open(Participant $participant, array $data): WoundRecord
    {
        $wound = WoundRecord::create(array_merge($data, [
            'participant_id' => $participant->id,
            'tenant_id'      => $participant->tenant_id,
            'site_id'        => $data['site_id'] ?? $participant->site_id,
            'status'         => 'open',
        ]));

        // CMS quality metric: Stage 3+ pressure injuries require immediate notification
        if ($wound->isCriticalStage()) {
            $stageLabel = $wound->stageLabel();
            $name       = $participant->first_name . ' ' . $participant->last_name;

            $this->alerts->create([
                'tenant_id'          => $participant->tenant_id,
                'participant_id'     => $participant->id,
                'source_module'      => 'wound_care',
                'alert_type'         => 'wound_critical_stage',
                'title'              => "Critical Pressure Injury: {$stageLabel}",
                'message'            => "{$name} — {$stageLabel} pressure injury documented at {$wound->location}. Immediate review required.",
                'severity'           => 'critical',
                'target_departments' => ['primary_care', 'qa_compliance'],
                'created_by_system'  => true,
                'metadata'           => ['wound_record_id' => $wound->id, 'stage' => $wound->pressure_injury_stage],
            ]);
        }

        return $wound;
    }

    // ── Add Assessment ─────────────────────────────────────────────────────────

    /**
     * Add a periodic assessment to an existing wound record.
     *
     * If status_change = 'healed': closes the wound record.
     * If status_change = 'deteriorated': creates a warning alert to primary_care.
     */
    public function addAssessment(WoundRecord $wound, array $data): WoundAssessment
    {
        $assessment = WoundAssessment::create(array_merge($data, [
            'wound_record_id' => $wound->id,
            'assessed_at'     => $data['assessed_at'] ?? now(),
        ]));

        // Healed: close the wound record
        if ($assessment->status_change === 'healed') {
            $wound->update([
                'status'      => 'healed',
                'healed_date' => now()->toDateString(),
            ]);
        }

        // Deteriorated: warn primary care
        if ($assessment->status_change === 'deteriorated') {
            $participant = $wound->participant;
            $name        = $participant->first_name . ' ' . $participant->last_name;

            $this->alerts->create([
                'tenant_id'          => $wound->tenant_id,
                'participant_id'     => $wound->participant_id,
                'source_module'      => 'wound_care',
                'alert_type'         => 'wound_deteriorated',
                'title'              => 'Wound Deteriorating',
                'message'            => "{$name} — {$wound->woundTypeLabel()} at {$wound->location} is deteriorating. Review treatment plan.",
                'severity'           => 'warning',
                'target_departments' => ['primary_care'],
                'created_by_system'  => true,
                'metadata'           => ['wound_record_id' => $wound->id],
            ]);
        }

        return $assessment;
    }

    // ── Queries ────────────────────────────────────────────────────────────────

    /** All non-healed wound records for a participant (with assessments eager-loaded). */
    public function getOpenWounds(int $participantId): Collection
    {
        return WoundRecord::forParticipant($participantId)
            ->open()
            ->with(['documentedBy:id,first_name,last_name', 'assessments.assessedBy:id,first_name,last_name'])
            ->orderBy('first_identified_date', 'asc')
            ->get();
    }

    /** All active (non-healed) wound records across a tenant — for QA/nursing dashboards. */
    public function getActiveWoundsByTenant(int $tenantId): Collection
    {
        return WoundRecord::forTenant($tenantId)
            ->open()
            ->with(['participant:id,first_name,last_name,mrn', 'documentedBy:id,first_name,last_name'])
            ->orderBy('first_identified_date', 'asc')
            ->get();
    }
}
