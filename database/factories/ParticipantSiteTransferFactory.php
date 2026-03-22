<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\ParticipantSiteTransfer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ParticipantSiteTransferFactory extends Factory
{
    protected $model = ParticipantSiteTransfer::class;

    public function definition(): array
    {
        $participant = Participant::factory()->create();
        $toSite      = Site::factory()->create(['tenant_id' => $participant->tenant_id]);
        $requester   = User::factory()->create([
            'tenant_id'  => $participant->tenant_id,
            'department' => 'enrollment',
        ]);

        return [
            'participant_id'         => $participant->id,
            'tenant_id'              => $participant->tenant_id,
            'from_site_id'           => $participant->site_id,
            'to_site_id'             => $toSite->id,
            'transfer_reason'        => $this->faker->randomElement(ParticipantSiteTransfer::TRANSFER_REASONS),
            'transfer_reason_notes'  => $this->faker->optional()->sentence(),
            'requested_by_user_id'   => $requester->id,
            'requested_at'           => now()->subDays(2),
            'approved_by_user_id'    => null,
            'approved_at'            => null,
            'effective_date'         => now()->addDays(7)->toDateString(),
            'status'                 => 'pending',
            'notification_sent'      => false,
        ];
    }

    /** Transfer that has been approved. */
    public function approved(): static
    {
        return $this->state(function (array $attrs) {
            $approver = User::factory()->create([
                'tenant_id'  => $attrs['tenant_id'],
                'department' => 'enrollment',
            ]);
            return [
                'status'               => 'approved',
                'approved_by_user_id'  => $approver->id,
                'approved_at'          => now()->subDay(),
            ];
        });
    }

    /** Approved transfer whose effective_date is today or in the past — ready for completion. */
    public function dueForCompletion(): static
    {
        return $this->approved()->state([
            'effective_date' => now()->toDateString(),
        ]);
    }

    /** Completed transfer (participant already moved). */
    public function completed(): static
    {
        return $this->approved()->state([
            'status'          => 'completed',
            'effective_date'  => now()->subDays(3)->toDateString(),
            'notification_sent' => true,
        ]);
    }

    /** Cancelled transfer. */
    public function cancelled(): static
    {
        return $this->state([
            'status' => 'cancelled',
        ]);
    }
}
