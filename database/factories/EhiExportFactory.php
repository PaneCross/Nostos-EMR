<?php

namespace Database\Factories;

use App\Models\EhiExport;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EhiExportFactory extends Factory
{
    protected $model = EhiExport::class;

    public function definition(): array
    {
        return [
            'participant_id'       => Participant::factory(),
            'tenant_id'            => Tenant::factory(),
            'requested_by_user_id' => User::factory(),
            'token'                => bin2hex(random_bytes(32)),
            'file_path'            => null,
            'status'               => 'pending',
            'expires_at'           => now()->addHours(24),
            'downloaded_at'        => null,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status'    => 'ready',
            'file_path' => 'ehi-exports/test/export.zip',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status'     => 'expired',
            'expires_at' => now()->subHours(2),
        ]);
    }
}
