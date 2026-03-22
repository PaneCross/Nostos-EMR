<?php

namespace App\Listeners;

use App\Events\ParticipantFlagUpdated;
use App\Services\TransportBridgeService;

class SyncFlagsToTransport
{
    public function __construct(private TransportBridgeService $bridge) {}

    public function handle(ParticipantFlagUpdated $event): void
    {
        // Only sync if this is a transport-relevant flag (wheelchair, stretcher, oxygen, behavioral)
        if ($event->flag->isTransportRelevant()) {
            $this->bridge->syncFlags($event->flag->participant);
        }
    }
}
