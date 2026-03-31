import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';

export default function Error500() {
    return (
        <AppShell>
            <Head title="Server Error" />
            <div className="flex flex-col items-center justify-center min-h-[60vh] text-center">
                <div className="w-16 h-16 rounded-full bg-red-50 dark:bg-red-950/60 flex items-center justify-center mb-4">
                    <svg className="w-8 h-8 text-red-400" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                </div>
                <p className="text-5xl font-bold text-slate-200 mb-2">500</p>
                <h1 className="text-2xl font-bold text-slate-800 dark:text-slate-200 mb-2">Something Went Wrong</h1>
                <p className="text-slate-500 dark:text-slate-400 text-sm max-w-md mb-2">
                    An unexpected server error occurred. The incident has been logged.
                </p>
                <p className="text-slate-400 text-xs mb-6">
                    If this keeps happening, contact your IT administrator.
                </p>
                <div className="flex gap-3">
                    <button
                        onClick={() => window.location.reload()}
                        className="border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-lg px-5 py-2.5 text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors"
                    >
                        Try Again
                    </button>
                    <Link
                        href="/"
                        className="bg-blue-600 text-white rounded-lg px-5 py-2.5 text-sm font-medium hover:bg-blue-700 transition-colors"
                    >
                        Return to Dashboard
                    </Link>
                </div>
            </div>
        </AppShell>
    );
}
