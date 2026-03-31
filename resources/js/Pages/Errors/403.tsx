import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';

export default function Error403({ module }: { module?: string }) {
    return (
        <AppShell>
            <Head title="Access Denied" />
            <div className="flex flex-col items-center justify-center min-h-[60vh] text-center">
                <div className="w-16 h-16 rounded-full bg-red-100 dark:bg-red-900/60 flex items-center justify-center mb-4">
                    <svg className="w-8 h-8 text-red-500" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </div>
                <h1 className="text-2xl font-bold text-slate-800 dark:text-slate-200 mb-2">Access Denied</h1>
                <p className="text-slate-500 dark:text-slate-400 text-sm max-w-md mb-1">
                    You don&apos;t have permission to access{module ? ` the <strong>${module}</strong> module` : ' this page'}.
                </p>
                <p className="text-slate-400 text-xs mb-6">
                    This access attempt has been logged. Contact your IT administrator if you believe this is an error.
                </p>
                <Link
                    href="/"
                    className="bg-blue-600 text-white rounded-lg px-5 py-2.5 text-sm font-medium hover:bg-blue-700 transition-colors"
                >
                    Return to Dashboard
                </Link>
            </div>
        </AppShell>
    );
}
