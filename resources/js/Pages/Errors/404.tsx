import React from 'react';
import { Head, Link } from '@inertiajs/react';
import AppShell from '@/Layouts/AppShell';

export default function Error404() {
    return (
        <AppShell>
            <Head title="Page Not Found" />
            <div className="flex flex-col items-center justify-center min-h-[60vh] text-center">
                <div className="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mb-4">
                    <svg className="w-8 h-8 text-slate-400" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                    </svg>
                </div>
                <p className="text-5xl font-bold text-slate-200 mb-2">404</p>
                <h1 className="text-2xl font-bold text-slate-800 mb-2">Page Not Found</h1>
                <p className="text-slate-500 text-sm max-w-md mb-6">
                    The page you're looking for doesn't exist or may have been moved.
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
