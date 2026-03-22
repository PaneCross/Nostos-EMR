<?php

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    public function definition(): array
    {
        $plaintext = Str::random(64);

        return [
            'user_id'      => User::factory(),
            'tenant_id'    => Tenant::factory(),
            'token'        => ApiToken::hashToken($plaintext),
            'scopes'       => ApiToken::SCOPES, // all scopes by default in tests
            'name'         => 'Test Integration Token',
            'last_used_at' => null,
            'expires_at'   => null, // never expires by default
        ];
    }

    /**
     * Create a factory that also returns the plaintext token in a callback.
     * Usage: ApiTokenFactory::withPlaintext($plaintext = Str::random(64))
     */
    public static function withPlaintext(string &$plaintext): self
    {
        $plaintext = Str::random(64);
        return (new self())->state([
            'token' => ApiToken::hashToken($plaintext),
        ]);
    }

    /** Token scoped to specific FHIR resources. */
    public function scoped(array $scopes): static
    {
        return $this->state(['scopes' => $scopes]);
    }

    /** Token that expires in the past (invalid). */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    /** Token expiring in the future. */
    public function expiresAt(\DateTimeInterface $at): static
    {
        return $this->state(['expires_at' => $at]);
    }
}
