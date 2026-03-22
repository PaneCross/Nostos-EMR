<?php

namespace Tests\Unit;

use App\Models\OtpCode;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private OtpService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->service = new OtpService();

        $tenant = Tenant::factory()->create();

        $this->user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
    }

    public function test_generated_code_is_exactly_6_digits(): void
    {
        $code = $this->service->generateCode();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_generated_code_has_leading_zeros_when_needed(): void
    {
        // Test that the code is always zero-padded to 6 digits
        // We can't force a specific value, but we verify format consistency
        for ($i = 0; $i < 20; $i++) {
            $code = $this->service->generateCode();
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code, 'Code must be exactly 6 digits including leading zeros');
        }
    }

    public function test_otp_code_expires_after_configured_minutes(): void
    {
        $this->service->sendOtp($this->user->email, '127.0.0.1');

        $otp = OtpCode::where('user_id', $this->user->id)->latest('id')->first();

        $this->assertNotNull($otp);
        $this->assertEqualsWithDelta(
            now()->addMinutes(OtpService::EXPIRE_MINUTES)->timestamp,
            $otp->expires_at->timestamp,
            5 // 5-second tolerance
        );
    }

    public function test_used_otp_code_is_rejected(): void
    {
        $code = '123456';
        $otp  = OtpCode::create([
            'user_id'    => $this->user->id,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'used_at'    => now(),     // already used
            'ip_address' => '127.0.0.1',
            'attempts'   => 0,
        ]);

        $result = $this->service->verifyOtp($this->user->email, $code, '127.0.0.1');

        $this->assertFalse($result['success']);
    }

    public function test_expired_otp_code_is_rejected(): void
    {
        $code = '123456';
        OtpCode::create([
            'user_id'    => $this->user->id,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->subMinute(),   // expired
            'ip_address' => '127.0.0.1',
            'attempts'   => 0,
        ]);

        $result = $this->service->verifyOtp($this->user->email, $code, '127.0.0.1');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('expired', strtolower($result['error']));
    }

    public function test_valid_otp_code_is_accepted(): void
    {
        $code = '654321';
        OtpCode::create([
            'user_id'    => $this->user->id,
            'code_hash'  => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'ip_address' => '127.0.0.1',
            'attempts'   => 0,
        ]);

        $result = $this->service->verifyOtp($this->user->email, $code, '127.0.0.1');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['user']);
        $this->assertEquals($this->user->id, $result['user']->id);
    }

    public function test_send_otp_invalidates_previous_unused_codes(): void
    {
        // Send first OTP
        $this->service->sendOtp($this->user->email, '127.0.0.1');
        $first = OtpCode::where('user_id', $this->user->id)->latest('id')->first();

        // Send second OTP — should invalidate first
        $this->service->sendOtp($this->user->email, '127.0.0.1');

        $first->refresh();
        $this->assertNotNull($first->used_at, 'First OTP should be invalidated when a new one is sent');
    }
}
