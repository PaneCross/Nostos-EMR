<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Clear rate limiters so tests don't bleed into each other
        RateLimiter::clear('otp-request:127.0.0.1');
        RateLimiter::clear('otp-verify:127.0.0.1');

        $this->tenant = Tenant::factory()->create([
            'name' => 'Test PACE Org',
            'slug' => 'test-pace',
            'auto_logout_minutes' => 15,
        ]);

        $this->user = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'email'      => 'margaret.primary_care@test.test',
            'department' => 'primary_care',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
    }

    // ─── OTP Request ──────────────────────────────────────────────────────────

    public function test_otp_request_returns_generic_success_for_valid_email(): void
    {
        $response = $this->postJson('/auth/request-otp', ['email' => $this->user->email]);

        $response->assertOk()
                 ->assertJsonFragment(['message' => 'If that email is registered, a sign-in code has been sent.']);

        $this->assertDatabaseHas('shared_otp_codes', ['user_id' => $this->user->id]);
        Mail::assertSent(\App\Mail\OtpMail::class);
    }

    public function test_otp_request_returns_generic_success_for_unknown_email(): void
    {
        // Must not reveal whether email is registered
        $response = $this->postJson('/auth/request-otp', ['email' => 'ghost@nowhere.test']);

        $response->assertOk()
                 ->assertJsonFragment(['message' => 'If that email is registered, a sign-in code has been sent.']);

        Mail::assertNothingSent();
    }

    public function test_otp_request_validates_email_format(): void
    {
        $response = $this->postJson('/auth/request-otp', ['email' => 'not-an-email']);

        $response->assertStatus(422);
    }

    // ─── OTP Verify ───────────────────────────────────────────────────────────

    public function test_otp_verify_success_logs_in_and_redirects(): void
    {
        $code = '123456';
        OtpCode::create([
            'user_id'    => $this->user->id,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
            'attempts'   => 0,
        ]);

        $response = $this->postJson('/auth/verify-otp', [
            'email' => $this->user->email,
            'code'  => $code,
        ]);

        $response->assertOk()
                 ->assertJsonPath('redirect', "/dashboard/{$this->user->department}");

        $this->assertAuthenticatedAs($this->user);

        $this->assertDatabaseHas('shared_audit_logs', [
            'user_id' => $this->user->id,
            'action'  => 'login',
        ]);
    }

    public function test_otp_verify_fails_with_wrong_code(): void
    {
        OtpCode::create([
            'user_id'    => $this->user->id,
            'code_hash'  => Hash::make('111111'),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
            'attempts'   => 0,
        ]);

        $response = $this->postJson('/auth/verify-otp', [
            'email' => $this->user->email,
            'code'  => '999999',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();

        $this->assertDatabaseHas('shared_audit_logs', [
            'user_id' => $this->user->id,
            'action'  => 'otp_failed',
        ]);
    }

    public function test_otp_verify_fails_with_expired_code(): void
    {
        OtpCode::create([
            'user_id'    => $this->user->id,
            'code_hash'  => Hash::make('123456'),
            'expires_at' => now()->subMinutes(1),   // already expired
            'ip_address' => '127.0.0.1',
            'attempts'   => 0,
        ]);

        $response = $this->postJson('/auth/verify-otp', [
            'email' => $this->user->email,
            'code'  => '123456',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_otp_verify_locks_after_max_attempts(): void
    {
        $otp = OtpCode::create([
            'user_id'    => $this->user->id,
            'code_hash'  => Hash::make('111111'),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
            'attempts'   => 3,  // already at max
        ]);

        $response = $this->postJson('/auth/verify-otp', [
            'email' => $this->user->email,
            'code'  => '999999',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Too many', $response->json('message'));
        $this->assertGuest();
    }

    // ─── Logout ───────────────────────────────────────────────────────────────

    public function test_logout_invalidates_session_and_logs_event(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/auth/logout');

        $response->assertRedirectToRoute('login');
        $this->assertGuest();

        $this->assertDatabaseHas('shared_audit_logs', [
            'user_id' => $this->user->id,
            'action'  => 'logout',
        ]);
    }

    public function test_session_timeout_logs_correct_action(): void
    {
        $this->actingAs($this->user);

        $response = $this->post('/auth/logout', ['timeout' => true]);

        $response->assertRedirectToRoute('login');

        $this->assertDatabaseHas('shared_audit_logs', [
            'user_id' => $this->user->id,
            'action'  => 'session_timeout',
        ]);
    }

    // ─── Login page ───────────────────────────────────────────────────────────

    public function test_login_page_is_accessible_to_guests(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_authenticated_user_cannot_access_login_page(): void
    {
        $this->actingAs($this->user)->get('/login')->assertRedirect();
    }

    // ─── OTP Code Reuse ───────────────────────────────────────────────────────

    public function test_otp_code_cannot_be_reused_after_successful_login(): void
    {
        $code = '654321';
        OtpCode::create([
            'user_id'    => $this->user->id,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
            'attempts'   => 0,
        ]);

        // First use: success
        $first = $this->postJson('/auth/verify-otp', [
            'email' => $this->user->email,
            'code'  => $code,
        ]);
        $first->assertOk();
        $this->assertAuthenticatedAs($this->user);

        // Log out so we can attempt the second use
        $this->post('/auth/logout');

        // Second use: the OTP is now marked used — no valid OTP exists
        $second = $this->postJson('/auth/verify-otp', [
            'email' => $this->user->email,
            'code'  => $code,
        ]);
        $second->assertStatus(422);
        $this->assertGuest();
    }

    // ─── Rate Limiting ────────────────────────────────────────────────────────

    public function test_otp_request_rate_limited_after_5_attempts(): void
    {
        // Make 5 valid requests (all succeed with generic message)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/request-otp', ['email' => $this->user->email])
                 ->assertOk();
        }

        // 6th request should be rate-limited
        $response = $this->postJson('/auth/request-otp', ['email' => $this->user->email]);
        $response->assertStatus(429);
        $this->assertStringContainsString('Too many', $response->json('message'));
    }

    public function test_otp_verify_rate_limited_after_5_attempts(): void
    {
        OtpCode::create([
            'user_id'    => $this->user->id,
            'code_hash'  => Hash::make('000000'),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
            'attempts'   => 0,
        ]);

        // Make 5 verify attempts with wrong code
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/verify-otp', [
                'email' => $this->user->email,
                'code'  => '111111',
            ]);
        }

        // 6th attempt should be rate-limited at the HTTP layer (before OTP logic)
        $this->postJson('/auth/verify-otp', [
            'email' => $this->user->email,
            'code'  => '111111',
        ])->assertStatus(429);
    }
}
