// ─── SuperAdminDashboard ───────────────────────────────────────────────────────
// Placeholder dashboard for the Nostos Super Admin department.
// Rendered from Dashboard/Index.tsx when department === 'super_admin'.
// The primary SA workspace is the Super Admin Panel at /super-admin-panel;
// this component provides a quick-launch tile from the dashboard route.
// ─────────────────────────────────────────────────────────────────────────────

import React from 'react';
import { Link } from '@inertiajs/react';

export default function SuperAdminDashboard({ departmentLabel }: { departmentLabel: string; role: string }) {
    return (
        <div className="space-y-6">
            {/* Page header */}
            <div>
                <h1 className="text-2xl font-bold text-slate-800 dark:text-slate-200">{departmentLabel} Dashboard</h1>
                <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">Platform-level management for Nostos staff</p>
            </div>

            {/* Quick-launch card */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">

                <Link href="/super-admin-panel" className="block">
                    <div className="bg-white dark:bg-slate-800 rounded-xl border border-amber-200 dark:border-amber-800 p-5 shadow-sm hover:shadow-md hover:border-amber-300 transition-all cursor-pointer group">
                        <div className="flex items-center gap-3 mb-3">
                            <div className="w-9 h-9 rounded-lg bg-amber-100 dark:bg-amber-900/60 flex items-center justify-center shrink-0">
                                <svg className="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" strokeWidth={1.6} viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0012 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75z" />
                                </svg>
                            </div>
                            <div>
                                <p className="text-sm font-semibold text-slate-800 dark:text-slate-200 group-hover:text-amber-700 transition-colors">
                                    Tenant Management
                                </p>
                                <p className="text-xs text-slate-500 dark:text-slate-400">Super Admin Panel</p>
                            </div>
                        </div>
                        <p className="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                            Onboard new PACE organizations, manage tenants, view system health, and monitor platform activity.
                        </p>
                        <div className="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700 flex items-center justify-between">
                            <span className="text-xs text-amber-600 dark:text-amber-400 font-medium">Open panel →</span>
                        </div>
                    </div>
                </Link>

                <div className="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                    <div className="flex items-center gap-3 mb-3">
                        <div className="w-9 h-9 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center shrink-0">
                            <svg className="w-5 h-5 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" strokeWidth={1.6} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <p className="text-sm font-semibold text-slate-700 dark:text-slate-300">System Health</p>
                            <p className="text-xs text-slate-400">Available in Super Admin Panel</p>
                        </div>
                    </div>
                    <p className="text-xs text-slate-400 leading-relaxed">
                        View table row counts, failed jobs, and queue depths. Access from the Super Admin Panel → Health tab.
                    </p>
                </div>

                <div className="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
                    <div className="flex items-center gap-3 mb-3">
                        <div className="w-9 h-9 rounded-lg bg-blue-50 dark:bg-blue-950/60 flex items-center justify-center shrink-0">
                            <svg className="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" strokeWidth={1.6} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                            </svg>
                        </div>
                        <div>
                            <p className="text-sm font-semibold text-slate-700 dark:text-slate-300">User Impersonation</p>
                            <p className="text-xs text-slate-400">Available in header</p>
                        </div>
                    </div>
                    <p className="text-xs text-slate-400 leading-relaxed">
                        Use the "Imitate User" button in the top navigation bar to impersonate any tenant user for support.
                    </p>
                </div>
            </div>
        </div>
    );
}
