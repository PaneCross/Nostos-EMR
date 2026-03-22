<?php

namespace App\Http\Controllers;

use App\Events\FlagAddedEvent;
use App\Http\Requests\StoreFlagRequest;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\ParticipantFlag;
use Illuminate\Http\Request;

class ParticipantFlagController extends Controller
{
    public function index(Request $request, Participant $participant)
    {
        $this->authorizeForTenant($participant, $request->user());

        return response()->json(
            $participant->flags()
                ->with('createdBy:id,first_name,last_name', 'resolvedBy:id,first_name,last_name')
                ->latest()
                ->get()
        );
    }

    public function store(StoreFlagRequest $request, Participant $participant)
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $flag = ParticipantFlag::create(array_merge($request->validated(), [
            'participant_id'    => $participant->id,
            'tenant_id'         => $user->tenant_id,
            'created_by_user_id'=> $user->id,
            'is_active'         => true,
        ]));

        AuditLog::record(
            action:       'participant.flag.created',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Flag '{$flag->flag_type}' added to {$participant->mrn}",
            newValues:    $request->validated(),
        );

        // Phase 4: broadcast for real-time chart Flags tab + dept dashboard refresh
        broadcast(new FlagAddedEvent($flag))->toOthers();

        return response()->json($flag->load('createdBy:id,first_name,last_name'), 201);
    }

    public function update(StoreFlagRequest $request, Participant $participant, ParticipantFlag $flag)
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($flag->participant_id !== $participant->id, 404);

        $flag->update($request->validated());

        AuditLog::record(
            action:       'participant.flag.updated',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Flag '{$flag->flag_type}' updated for {$participant->mrn}",
        );

        return response()->json($flag->fresh('createdBy:id,first_name,last_name'));
    }

    public function resolve(Request $request, Participant $participant, ParticipantFlag $flag)
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        abort_if($flag->participant_id !== $participant->id, 404);

        $flag->resolve($user);

        AuditLog::record(
            action:       'participant.flag.resolved',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Flag '{$flag->flag_type}' resolved for {$participant->mrn}",
        );

        return response()->json(['resolved' => true]);
    }

    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->tenant_id, 403);
    }
}
