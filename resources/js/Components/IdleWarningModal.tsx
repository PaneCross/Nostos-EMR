import React from 'react';

interface Props {
    countdown: number;
    onStayLoggedIn: () => void;
}

export default function IdleWarningModal({ countdown, onStayLoggedIn }: Props) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm">
            <div className="bg-white rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
                {/* Warning stripe */}
                <div className="bg-amber-500 px-6 py-4 flex items-center gap-3">
                    <svg className="w-6 h-6 text-white shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <h2 className="text-white font-semibold text-lg">Session Expiring Soon</h2>
                </div>

                <div className="p-6">
                    <p className="text-slate-600 mb-4">
                        You will be automatically logged out due to inactivity in:
                    </p>

                    {/* Countdown */}
                    <div className="flex items-center justify-center my-6">
                        <div className="w-24 h-24 rounded-full border-4 border-amber-400 flex items-center justify-center">
                            <span className="text-4xl font-bold text-amber-600 tabular-nums">{countdown}</span>
                        </div>
                    </div>

                    <p className="text-sm text-slate-500 text-center mb-6">seconds</p>

                    <div className="flex gap-3">
                        <button
                            onClick={onStayLoggedIn}
                            className="flex-1 bg-blue-600 text-white rounded-lg py-2.5 px-4 text-sm font-semibold hover:bg-blue-700 transition-colors"
                        >
                            Stay Logged In
                        </button>
                        <a
                            href="/auth/logout"
                            onClick={(e) => { e.preventDefault(); window.location.href = '/auth/logout'; }}
                            className="flex-1 border border-slate-300 text-slate-600 rounded-lg py-2.5 px-4 text-sm font-semibold hover:bg-slate-50 transition-colors text-center"
                        >
                            Log Out Now
                        </a>
                    </div>

                    <p className="text-xs text-slate-400 text-center mt-4">
                        This system contains PHI. Unauthorized access is prohibited.
                    </p>
                </div>
            </div>
        </div>
    );
}
