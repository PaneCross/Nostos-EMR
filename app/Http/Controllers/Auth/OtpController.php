<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class OtpController extends Controller
{
    public function __construct(private OtpService $otpService) {}

    /** Show the login page. */
    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * POST /auth/request-otp
     * Rate-limited: 5/min per IP.
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $key = 'otp-request:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Too many requests. Please wait {$seconds} seconds.",
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $this->otpService->sendOtp($request->input('email'), $request->ip());

        return response()->json([
            'message' => 'If that email is registered, a sign-in code has been sent.',
        ]);
    }

    /**
     * POST /auth/verify-otp
     * Rate-limited: 5/min per IP.
     */
    public function verifyOtp(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code'  => 'required|digits:6',
        ]);

        $key = 'otp-verify:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Too many requests. Please wait {$seconds} seconds.",
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $result = $this->otpService->verifyOtp(
            $request->input('email'),
            $request->input('code'),
            $request->ip(),
        );

        if (! $result['success']) {
            return response()->json(['message' => $result['error']], 422);
        }

        $user = $result['user'];
        Auth::login($user, remember: false);
        $request->session()->regenerate();

        // Store tenant/session info
        $request->session()->put('tenant_id', $user->tenant_id);
        $request->session()->put('user_department', $user->department);

        return response()->json([
            'redirect' => "/dashboard/{$user->department}",
        ]);
    }

    /** POST /auth/logout */
    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if ($user) {
            $isTimeout = $request->boolean('timeout');

            \App\Models\AuditLog::record(
                action: $isTimeout ? 'session_timeout' : 'logout',
                tenantId: $user->tenant_id,
                userId: $user->id,
                description: $isTimeout ? 'Session timed out due to inactivity' : 'User logged out',
            );
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
