import React, { useState, useEffect, useRef } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';

type Step = 'email' | 'code';

interface PageErrors {
    oauth?: string;
    [key: string]: string | undefined;
}

export default function Login() {
    const { errors } = usePage<{ errors: PageErrors }>().props;

    const [step, setStep] = useState<Step>('email');
    const [email, setEmail] = useState('');
    const [code, setCode] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(errors.oauth ?? null);
    const [success, setSuccess] = useState<string | null>(null);
    const [countdown, setCountdown] = useState(0);

    const codeInputRef = useRef<HTMLInputElement>(null);

    // Countdown timer for resend
    useEffect(() => {
        if (countdown <= 0) return;
        const t = setTimeout(() => setCountdown(c => c - 1), 1000);
        return () => clearTimeout(t);
    }, [countdown]);

    // Focus code input when step changes
    useEffect(() => {
        if (step === 'code') {
            setTimeout(() => codeInputRef.current?.focus(), 100);
        }
    }, [step]);

    const handleRequestOtp = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!email) return;

        setLoading(true);
        setError(null);

        try {
            await axios.post('/auth/request-otp', { email });
            setStep('code');
            setCountdown(60);
            setSuccess(`A 6-digit sign-in code was sent to ${email}`);
        } catch (err: any) {
            const msg = err.response?.data?.message ?? 'Something went wrong. Please try again.';
            setError(msg);
        } finally {
            setLoading(false);
        }
    };

    const handleVerifyOtp = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!code || code.length < 6) return;

        setLoading(true);
        setError(null);

        try {
            const response = await axios.post('/auth/verify-otp', { email, code });
            window.location.href = response.data.redirect;
        } catch (err: any) {
            const msg = err.response?.data?.message ?? 'Invalid code. Please try again.';
            setError(msg);
            setCode('');
        } finally {
            setLoading(false);
        }
    };

    const handleResend = async () => {
        if (countdown > 0) return;
        setLoading(true);
        setError(null);

        try {
            await axios.post('/auth/request-otp', { email });
            setCountdown(60);
            setSuccess('A new code has been sent.');
        } catch (err: any) {
            setError(err.response?.data?.message ?? 'Failed to resend. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    const handleCodeInput = (value: string) => {
        const digits = value.replace(/\D/g, '').slice(0, 6);
        setCode(digits);
    };

    return (
        <>
            <Head title="Sign In" />
            <div className="min-h-screen bg-gradient-to-br from-slate-900 via-slate-800 to-blue-950 flex items-center justify-center p-4">
                <div className="w-full max-w-md">
                    {/* Card */}
                    <div className="bg-white rounded-2xl shadow-2xl overflow-hidden">
                        {/* Header */}
                        <div className="bg-[#0f172a] px-8 py-8 text-center">
                            <div className="inline-flex items-center justify-center w-14 h-14 bg-blue-600 rounded-xl mb-4">
                                <svg className="w-8 h-8 text-white" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                </svg>
                            </div>
                            <h1 className="text-2xl font-bold text-white tracking-tight">
                                Nostos<span className="text-blue-400">EMR</span>
                            </h1>
                            <p className="text-slate-400 text-sm mt-1">PACE Electronic Medical Records</p>
                        </div>

                        <div className="px-8 py-8">
                            {/* Step indicator */}
                            {step === 'email' ? (
                                <>
                                    <h2 className="text-lg font-semibold text-slate-800 mb-1">Sign in to your account</h2>
                                    <p className="text-sm text-slate-500 mb-6">
                                        Enter your work email to receive a sign-in code
                                    </p>

                                    {/* Flash messages */}
                                    {error && (
                                        <div className="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm flex items-start gap-2">
                                            <svg className="w-4 h-4 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                                            </svg>
                                            {error}
                                        </div>
                                    )}

                                    <form onSubmit={handleRequestOtp} className="space-y-4">
                                        <div>
                                            <label className="block text-sm font-medium text-slate-700 mb-1.5">
                                                Work Email Address
                                            </label>
                                            <input
                                                type="email"
                                                value={email}
                                                onChange={e => setEmail(e.target.value)}
                                                placeholder="you@yourorganization.com"
                                                required
                                                autoFocus
                                                className="w-full px-4 py-2.5 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-800 text-sm placeholder:text-slate-400"
                                            />
                                        </div>

                                        <button
                                            type="submit"
                                            disabled={loading || !email}
                                            className="w-full bg-blue-600 text-white rounded-lg py-2.5 px-4 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center justify-center gap-2"
                                        >
                                            {loading ? (
                                                <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                </svg>
                                            ) : null}
                                            Send Sign-In Code
                                        </button>
                                    </form>

                                    {/* Divider */}
                                    <div className="relative my-6">
                                        <div className="absolute inset-0 flex items-center">
                                            <div className="w-full border-t border-slate-200" />
                                        </div>
                                        <div className="relative flex justify-center">
                                            <span className="bg-white px-3 text-xs text-slate-400">or continue with</span>
                                        </div>
                                    </div>

                                    {/* Social buttons */}
                                    <div className="grid grid-cols-2 gap-3">
                                        <a
                                            href="/auth/google/redirect"
                                            className="flex items-center justify-center gap-2 border border-slate-300 rounded-lg py-2.5 px-3 text-sm text-slate-600 hover:bg-slate-50 transition-colors font-medium"
                                        >
                                            <svg className="w-4 h-4" viewBox="0 0 24 24">
                                                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                                                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                                                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                                                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                                            </svg>
                                            Google
                                        </a>
                                        <a
                                            href="/auth/yahoo/redirect"
                                            className="flex items-center justify-center gap-2 border border-slate-300 rounded-lg py-2.5 px-3 text-sm text-slate-600 hover:bg-slate-50 transition-colors font-medium"
                                        >
                                            <svg className="w-4 h-4" viewBox="0 0 24 24" fill="#6001D2">
                                                <path d="M0 0l6.25 12.75L0 24h4.5l3.75-7.5L12 24h4.5L6.75 0H0zm18 0l-4.5 9h4.5L24 0h-6z" />
                                            </svg>
                                            Yahoo
                                        </a>
                                    </div>
                                </>
                            ) : (
                                <>
                                    {/* Back button */}
                                    <button
                                        onClick={() => { setStep('email'); setError(null); setSuccess(null); setCode(''); }}
                                        className="flex items-center gap-1 text-sm text-blue-600 hover:text-blue-800 mb-4 transition-colors"
                                    >
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                                        </svg>
                                        Back
                                    </button>

                                    <h2 className="text-lg font-semibold text-slate-800 mb-1">Enter your sign-in code</h2>
                                    <p className="text-sm text-slate-500 mb-6">
                                        We sent a 6-digit code to <span className="font-medium text-slate-700">{email}</span>.
                                        It expires in 10 minutes.
                                    </p>

                                    {/* Success/error */}
                                    {success && (
                                        <div className="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">
                                            {success}
                                        </div>
                                    )}
                                    {error && (
                                        <div className="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                                            {error}
                                        </div>
                                    )}

                                    <form onSubmit={handleVerifyOtp} className="space-y-4">
                                        <div>
                                            <label className="block text-sm font-medium text-slate-700 mb-1.5">
                                                6-Digit Code
                                            </label>
                                            <input
                                                ref={codeInputRef}
                                                type="text"
                                                inputMode="numeric"
                                                pattern="\d{6}"
                                                maxLength={6}
                                                value={code}
                                                onChange={e => handleCodeInput(e.target.value)}
                                                placeholder="000000"
                                                className="w-full px-4 py-3 rounded-lg border border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-slate-800 text-2xl text-center tracking-[0.5em] font-mono placeholder:text-slate-300 placeholder:tracking-normal placeholder:text-base"
                                            />
                                        </div>

                                        <button
                                            type="submit"
                                            disabled={loading || code.length < 6}
                                            className="w-full bg-blue-600 text-white rounded-lg py-2.5 px-4 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center justify-center gap-2"
                                        >
                                            {loading && (
                                                <svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                </svg>
                                            )}
                                            Verify Code & Sign In
                                        </button>
                                    </form>

                                    <div className="text-center mt-4">
                                        <button
                                            onClick={handleResend}
                                            disabled={countdown > 0 || loading}
                                            className="text-sm text-blue-600 hover:text-blue-800 disabled:text-slate-400 disabled:cursor-not-allowed transition-colors"
                                        >
                                            {countdown > 0
                                                ? `Resend code in ${countdown}s`
                                                : "Didn't receive it? Resend code"}
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>

                        {/* HIPAA notice */}
                        <div className="bg-slate-50 border-t border-slate-200 px-8 py-4">
                            <p className="text-xs text-slate-400 text-center leading-relaxed">
                                This system contains protected health information (PHI). Unauthorized access is
                                prohibited and monitored. All access is logged for compliance purposes.
                            </p>
                        </div>
                    </div>

                    <p className="text-center text-slate-500 text-xs mt-4">
                        No account? Contact your IT administrator.
                    </p>
                </div>
            </div>
        </>
    );
}
