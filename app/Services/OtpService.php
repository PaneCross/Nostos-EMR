<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public const CODE_LENGTH   = 6;
    public const EXPIRE_MINUTES = 10;
    public const MAX_ATTEMPTS  = 3;

    /**
     * Generate a 6-digit OTP, persist it, and send via email.
     * Always returns true (don't confirm whether email exists to callers).
     */
    public function sendOtp(string $email, string $ip): bool
    {
        $user = User::where('email', $email)
            ->where('is_active', true)
            ->first();

        if (! $user) {
            // Silently succeed — do NOT reveal whether email is registered
            return true;
        }

        $code = $this->generateCode();

        // Invalidate any existing unused codes for this user
        OtpCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->whereNull('expires_at') // not yet used or expired check
            ->orWhere(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->whereNull('used_at')
                  ->where('expires_at', '>', now());
            })
            ->update(['used_at' => now()]);

        OtpCode::create([
            'user_id'   => $user->id,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::EXPIRE_MINUTES),
            'ip_address' => $ip,
            'attempts'   => 0,
        ]);

        // Send OTP email
        Mail::to($user->email)->send(new \App\Mail\OtpMail($user, $code));

        AuditLog::record(
            action: 'otp_sent',
            tenantId: $user->tenant_id,
            userId: $user->id,
            description: "OTP sent to {$user->email}",
        );

        return true;
    }

    /**
     * Verify a submitted OTP code.
     *
     * @return array{success: bool, user: ?User, error: ?string}
     */
    public function verifyOtp(string $email, string $code, string $ip): array
    {
        $user = User::where('email', $email)->where('is_active', true)->first();

        if (! $user) {
            return $this->failure('Invalid credentials.');
        }

        if ($user->isLocked()) {
            AuditLog::record(
                action: 'otp_failed',
                tenantId: $user->tenant_id,
                userId: $user->id,
                description: 'Login attempted on locked account',
            );

            return $this->failure('Account is temporarily locked. Please try again later.');
        }

        $otp = OtpCode::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp) {
            return $this->failure('Code has expired or was not requested. Please request a new code.');
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            AuditLog::record(
                action: 'otp_failed',
                tenantId: $user->tenant_id,
                userId: $user->id,
                description: 'Max OTP attempts exceeded',
            );
            $otp->update(['used_at' => now()]);  // invalidate
            $user->incrementFailedAttempts();

            return $this->failure('Too many failed attempts. Please request a new code.');
        }

        if (! $otp->verifyCode($code)) {
            $otp->increment('attempts');
            $user->incrementFailedAttempts();

            AuditLog::record(
                action: 'otp_failed',
                tenantId: $user->tenant_id,
                userId: $user->id,
                description: "Invalid OTP attempt #{$otp->fresh()->attempts}",
            );

            $remaining = self::MAX_ATTEMPTS - $otp->fresh()->attempts;

            return $this->failure("Incorrect code. {$remaining} attempt(s) remaining.");
        }

        // Success!
        $otp->markUsed();
        $user->resetFailedAttempts();
        $user->update(['last_login_at' => now()]);

        AuditLog::record(
            action: 'login',
            tenantId: $user->tenant_id,
            userId: $user->id,
            description: 'Successful OTP login',
            newValues: ['ip' => $ip],
        );

        return ['success' => true, 'user' => $user, 'error' => null];
    }

    public function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function failure(string $error): array
    {
        return ['success' => false, 'user' => null, 'error' => $error];
    }
}
