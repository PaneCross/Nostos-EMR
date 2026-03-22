<?php

// ─── AdlController ───────────────────────────────────────────────────────────
// Manages ADL (Activities of Daily Living) records and thresholds.
// New ADL records trigger AdlRecordObserver → AdlThresholdService breach check.
// Threshold updates require primary_care admin or idt admin role.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdlRecordRequest;
use App\Http\Requests\UpdateAdlThresholdRequest;
use App\Models\AdlRecord;
use App\Models\AdlThreshold;
use App\Models\AuditLog;
use App\Models\Participant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdlController extends Controller
{
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }

    /**
     * GET /participants/{participant}/adl
     * Returns the latest ADL record per category plus 90-day history.
     *
     * Response shape:
     * {
     *   latest: { bathing: AdlRecord, dressing: AdlRecord, ... },
     *   history: [AdlRecord, ...],
     *   thresholds: { bathing: AdlThreshold, ... }
     * }
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        // Latest record per category (subquery for performance)
        $latest = $participant->adlRecords()
            ->with('recordedBy:id,first_name,last_name')
            ->whereIn('id', function ($sub) use ($participant) {
                $sub->selectRaw('MAX(id)')
                    ->from('emr_adl_records')
                    ->where('participant_id', $participant->id)
                    ->groupBy('adl_category');
            })
            ->get()
            ->keyBy('adl_category');

        // 90-day history for sparklines
        $history = $participant->adlRecords()
            ->with('recordedBy:id,first_name,last_name')
            ->where('recorded_at', '>=', now()->subDays(90))
            ->orderByDesc('recorded_at')
            ->get();

        $thresholds = AdlThreshold::forParticipant($participant->id);

        return response()->json([
            'latest'     => $latest,
            'history'    => $history,
            'thresholds' => $thresholds,
        ]);
    }

    /**
     * POST /participants/{participant}/adl
     * Records a new ADL observation. Observer fires threshold breach check.
     */
    public function store(StoreAdlRecordRequest $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $record = AdlRecord::create(array_merge($request->validated(), [
            'participant_id'      => $participant->id,
            'tenant_id'           => $user->tenant_id,
            'recorded_by_user_id' => $user->id,
            'recorded_at'         => $request->input('recorded_at', now()),
        ]));
        // Observer AdlRecordObserver::created() fires here

        AuditLog::record(
            action: 'participant.adl.recorded',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "ADL '{$record->adl_category}' recorded as '{$record->independence_level}' for {$participant->mrn}"
                . ($record->threshold_breached ? ' [THRESHOLD BREACHED]' : ''),
            newValues: $request->validated(),
        );

        return response()->json($record->load('recordedBy:id,first_name,last_name'), 201);
    }

    /**
     * GET /participants/{participant}/adl/thresholds
     * Returns all configured thresholds for this participant, keyed by adl_category.
     */
    public function thresholds(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        return response()->json(AdlThreshold::forParticipant($participant->id));
    }

    /**
     * PUT /participants/{participant}/adl/thresholds
     * Upserts thresholds for one or more ADL categories.
     * Restricted to primary_care admin and idt admin.
     */
    public function updateThresholds(UpdateAdlThresholdRequest $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        // Only primary_care admin or idt admin may configure thresholds
        $canSetThresholds = $user->isAdmin()
            && in_array($user->department, ['primary_care', 'idt'], true);

        abort_unless($canSetThresholds, 403, 'Only Primary Care Admin and IDT Admin may set ADL thresholds.');

        $thresholds = $request->validated()['thresholds'];
        $upserted   = [];

        foreach ($thresholds as $category => $level) {
            $upserted[] = AdlThreshold::updateOrCreate(
                ['participant_id' => $participant->id, 'adl_category' => $category],
                ['threshold_level' => $level, 'set_by_user_id' => $user->id, 'set_at' => now()]
            );
        }

        AuditLog::record(
            action: 'participant.adl.thresholds_updated',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "ADL thresholds updated for {$participant->mrn} (" . count($upserted) . ' categories)',
            newValues: $thresholds,
        );

        return response()->json(AdlThreshold::forParticipant($participant->id));
    }
}
