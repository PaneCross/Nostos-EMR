// ─── AppShell Layout ──────────────────────────────────────────────────────────
// Root layout wrapper used by every authenticated page.
// Composes: Sidebar (nav + user footer) · TopBar (search, notifications, user chip)
// Handles: sidebar collapse/expand, Cmd+K global search, HIPAA idle timeout,
//          super-admin impersonation banner + "Imitate User" dropdown.
//
// Super-admin UI:
//   - TopBar: "Imitate User" button (before notification bell). Opens a searchable
//     dropdown listing all tenant users. Clicking a user starts impersonation.
//   - ImpersonationBanner: amber bar rendered below TopBar when impersonation.active.
//     Displays impersonated user's name/dept and an "Exit Impersonation" button.
//   - Sidebar footer: "Dashboard View" <select> visible only to super-admin when NOT
//     impersonating. Controls which dept's module cards Dashboard/Index.tsx renders.
//     This ONLY affects the dashboard — all other pages remain fully accessible.
//
// ⚠ DEV NOTE: The phase indicator in the sidebar footer is temporary and will
// be removed before production handoff. Update PHASE_LABEL below each phase.
// ──────────────────────────────────────────────────────────────────────────────

import React, { useState, useEffect, useCallback, useRef, ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { PageProps, NavGroup, Department, ImpersonationUser, SiteContext } from '@/types';
import IdleWarningModal from '@/Components/IdleWarningModal';
import GlobalSearch from '@/Components/GlobalSearch';
import NotificationBell from '@/Components/NotificationBell';
import ThemeToggle from '@/Components/ThemeToggle';

// ─── Development phase indicator (TEMPORARY) ─────────────────────────────────
// Update this string at the start of each phase. Remove the entire indicator
// component from Sidebar before final production handoff.
const PHASE_LABEL = 'Phase 12B';

// ─── Department label map ─────────────────────────────────────────────────────
// Used by the "Dashboard View" selector. Mirrors User::DEPARTMENT_LABELS on the backend.
const DEPARTMENT_OPTIONS: { value: Department; label: string }[] = [
    { value: 'it_admin',          label: 'IT Admin' },
    { value: 'primary_care',      label: 'Primary Care' },
    { value: 'therapies',         label: 'Therapies' },
    { value: 'social_work',       label: 'Social Work' },
    { value: 'behavioral_health', label: 'Behavioral Health' },
    { value: 'dietary',           label: 'Dietary' },
    { value: 'activities',        label: 'Activities' },
    { value: 'home_care',         label: 'Home Care' },
    { value: 'transportation',    label: 'Transportation' },
    { value: 'pharmacy',          label: 'Pharmacy' },
    { value: 'idt',               label: 'IDT' },
    { value: 'enrollment',        label: 'Enrollment' },
    { value: 'finance',           label: 'Finance' },
    { value: 'qa_compliance',     label: 'QA / Compliance' },
    { value: 'executive',         label: 'Executive / Leadership' },
    { value: 'super_admin',       label: 'Nostos Super Admin' },
];

// ─── Icon helpers (inline SVG — no icon library dependency) ──────────────────
// Each value is the inner <path> for a 24×24 Heroicons outline icon.
const icons: Record<string, ReactNode> = {
    home:     <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />,
    users:    <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />,
    clipboard:<path strokeLinecap="round" strokeLinejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />,
    team:     <path strokeLinecap="round" strokeLinejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />,
    calendar: <path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />,
    truck:    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" />,
    dollar:   <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />,
    chart:    <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />,
    settings: <path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />,
    chat:     <path strokeLinecap="round" strokeLinejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />,
};

// ─── NavIcon ──────────────────────────────────────────────────────────────────
// Renders a 24×24 outline SVG from the icons map above. Falls back to a hamburger.
function NavIcon({ name }: { name: string }) {
    return (
        <svg className="w-5 h-5 shrink-0" fill="none" stroke="currentColor" strokeWidth={1.6} viewBox="0 0 24 24">
            {icons[name] ?? <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16M4 18h16" />}
        </svg>
    );
}

// ─── Sidebar ──────────────────────────────────────────────────────────────────
// Fixed-width left navigation. Collapses to icon-only mode when collapsed=true.
// Sections (top → bottom): logo · fixed Dashboard link · grouped nav · user footer
// Super-admin extra: "Dashboard View" selector above user footer (hidden when impersonating).
function Sidebar({ collapsed, navGroups, currentPath }: {
    collapsed:   boolean;
    navGroups:   NavGroup[];
    currentPath: string;
}) {
    const { auth, impersonation } = usePage<PageProps>().props;
    const user = auth.user!;

    // ── Chat unread badge ─────────────────────────────────────────────────────
    // Fetched once on mount. Updated in real-time via the user's personal Reverb
    // channel (ChatActivityEvent). Cleared when the user visits /chat.
    const [chatUnread, setChatUnread] = useState(0);

    const fetchChatUnread = useCallback(async () => {
        try {
            const { data } = await axios.get('/chat/unread-count');
            setChatUnread(data.unread_count ?? 0);
        } catch {
            // Silently ignore — network errors shouldn't break the nav
        }
    }, []);

    useEffect(() => {
        fetchChatUnread();
    }, [fetchChatUnread]);

    // Real-time: subscribe to personal user channel for chat activity events
    useEffect(() => {
        if (!window.Echo || !user.id) return;
        const ch = window.Echo.private(`user.${user.id}`);
        ch.listen('.chat.activity', () => {
            // Re-fetch the authoritative count rather than incrementing blindly
            fetchChatUnread();
        });
        return () => {
            window.Echo?.leaveChannel(`private-user.${user.id}`);
        };
    }, [user.id, fetchChatUnread]);

    // Clear badge when user visits /chat
    useEffect(() => {
        if (currentPath.startsWith('/chat')) {
            setChatUnread(0);
        }
    }, [currentPath]);

    // Track which nav groups are open (expanded). Groups with an active child are
    // auto-opened. Others toggle on click.
    const [expanded, setExpanded] = useState<string[]>([]);

    const toggle = (label: string) =>
        setExpanded(prev => prev.includes(label) ? prev.filter(l => l !== label) : [...prev, label]);

    // Collect all nav item hrefs for sibling-aware active detection.
    // isActive: a nav item is only "active" if no more-specific sibling also matches.
    // This prevents /idt from appearing active when the URL is /idt/meetings.
    const allNavHrefs = navGroups.flatMap(g => g.items.map(i => i.href));
    const isActive = (href: string) => {
        if (!currentPath.startsWith(href)) return false;
        return !allNavHrefs.some(
            other => other !== href && currentPath.startsWith(other) && other.length > href.length
        );
    };
    const groupHasActive = (g: NavGroup) => g.items.some(i => isActive(i.href));

    // ── Collapsed flyout (Part B) ─────────────────────────────────────────────
    // When the sidebar is icon-only, hovering a group header shows a flyout panel
    // listing that group's items. Uses fixed positioning to escape overflow clipping.
    const [hoveredGroup, setHoveredGroup] = useState<string | null>(null);
    const [flyoutY, setFlyoutY]           = useState(0);
    const hideTimerRef                    = useRef<ReturnType<typeof setTimeout> | null>(null);

    const showFlyout = (label: string, e: React.MouseEvent<HTMLButtonElement>) => {
        if (!collapsed) return;
        if (hideTimerRef.current) clearTimeout(hideTimerRef.current);
        const rect = e.currentTarget.getBoundingClientRect();
        setFlyoutY(rect.top);
        setHoveredGroup(label);
    };
    const hideFlyout  = () => { hideTimerRef.current = setTimeout(() => setHoveredGroup(null), 120); };
    const keepFlyout  = () => { if (hideTimerRef.current) clearTimeout(hideTimerRef.current); };

    // Clear flyout whenever sidebar expands
    useEffect(() => { if (!collapsed) setHoveredGroup(null); }, [collapsed]);

    const handleLogout = () => {
        router.post('/auth/logout');
    };

    // ── Dashboard View selector (super-admin only, hidden when impersonating) ──
    // Current selected dept: prefer viewing_as_dept from server, default 'it_admin'.
    const viewingAsDept = impersonation.viewing_as_dept ?? 'it_admin';

    const handleDashboardViewChange = (dept: string) => {
        axios.post('/super-admin/view-as', { department: dept })
            .then(() => {
                // If already on dashboard, navigate to the new dept dashboard.
                // Otherwise just update the server-side session state.
                if (currentPath.startsWith('/dashboard')) {
                    router.visit(`/dashboard/${dept}`);
                }
            });
    };

    return (
        <aside className={`flex flex-col h-full bg-slate-900 dark:bg-slate-950 transition-all duration-300 ${collapsed ? 'w-16' : 'w-64'} shrink-0`}>

            {/* ── Logo ──────────────────────────────────────────────────────── */}
            <div className="flex items-center gap-3 px-4 py-5 border-b border-slate-800">
                <div className="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center shrink-0">
                    <svg className="w-5 h-5 text-white" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                </div>
                {!collapsed && (
                    <span className="text-white font-semibold text-base tracking-tight leading-tight">
                        Nostos<span className="text-blue-400">EMR</span>
                    </span>
                )}
            </div>

            {/* ── Navigation ────────────────────────────────────────────────── */}
            <nav className="flex-1 overflow-y-auto py-4 px-2 space-y-0.5">

                {/* Fixed top link: Dashboard — routes to /dashboard/{department} for the logged-in user */}
                <Link
                    href={`/dashboard/${user.department}`}
                    data-testid="nav-dashboard"
                    title={collapsed ? 'Dashboard' : undefined}
                    className={`nav-item ${isActive('/dashboard') ? 'active' : ''}`}
                >
                    <NavIcon name="home" />
                    {!collapsed && <span className="flex-1 text-left">Dashboard</span>}
                </Link>

                {/* Divider between fixed links and grouped module nav */}
                {!collapsed && (
                    <div className="border-t border-slate-800 my-2" />
                )}

                {/* Grouped nav items (Participants, Clinical, Transport, etc.)
                    Groups are driven by the nav_groups prop from HandleInertiaRequests */}
                {navGroups.map(group => {
                    const open = expanded.includes(group.label) || groupHasActive(group);

                    return (
                        <div key={group.label}>
                            {/* Group header button — toggles expand/collapse.
                                In collapsed mode: hover triggers the flyout panel. */}
                            <button
                                onClick={() => toggle(group.label)}
                                onMouseEnter={e => showFlyout(group.label, e)}
                                onMouseLeave={hideFlyout}
                                data-testid={`nav-group-${group.label.toLowerCase().replace(/\s+/g, '-')}`}
                                className={`nav-item ${groupHasActive(group) ? 'text-slate-100' : ''}`}
                                title={collapsed ? group.label : undefined}
                            >
                                <NavIcon name={group.icon} />
                                {!collapsed && (
                                    <>
                                        <span className="flex-1 text-left">{group.label}</span>
                                        <svg className={`w-4 h-4 transition-transform ${open ? 'rotate-90' : ''}`} fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </>
                                )}
                            </button>

                            {/* Sub-items shown when group is open and sidebar is expanded */}
                            {open && !collapsed && (
                                <div className="ml-4 mt-0.5 space-y-0.5">
                                    {group.items.map(item => (
                                        <Link
                                            key={item.module}
                                            href={item.href}
                                            data-testid={`nav-item-${item.module}`}
                                            className={`nav-item pl-6 text-xs ${isActive(item.href) ? 'active' : ''}`}
                                        >
                                            <span className="w-1.5 h-1.5 rounded-full bg-current shrink-0 opacity-60" />
                                            <span className="flex-1">{item.label}</span>
                                            {item.module === 'chat' && chatUnread > 0 && (
                                                <span
                                                    data-testid="chat-unread-badge"
                                                    className="ml-1 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-blue-500 px-1 text-[9px] font-bold text-white"
                                                >
                                                    {chatUnread > 99 ? '99+' : chatUnread}
                                                </span>
                                            )}
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}
            </nav>

            {/* ── User footer ────────────────────────────────────────────────── */}
            <div className="border-t border-slate-800 p-3 space-y-2">

                {/* ── TEMPORARY phase indicator (remove before production) ─── */}
                {!collapsed && (
                    <div className="px-3 py-1.5 rounded-lg bg-blue-900/40 border border-blue-800/60">
                        <p className="text-[10px] font-medium text-blue-300 uppercase tracking-widest">Dev Build</p>
                        <p className="text-xs text-blue-200 font-semibold mt-0.5">{PHASE_LABEL}</p>
                    </div>
                )}

                {/* ── Dashboard View selector (super-admin only, not while impersonating) ──
                    Only affects which module cards render on Dashboard/Index.tsx.
                    All other pages remain fully accessible without selecting a department. */}
                {user.is_super_admin && !impersonation.active && !collapsed && (
                    <div className="px-3 py-2 rounded-lg bg-slate-800/60 border border-slate-700/60">
                        <label className="block text-[10px] font-medium text-slate-400 uppercase tracking-widest mb-1">
                            Dashboard View
                        </label>
                        <select
                            value={viewingAsDept}
                            onChange={e => handleDashboardViewChange(e.target.value)}
                            data-testid="dashboard-view-select"
                            className="w-full bg-slate-700 text-slate-100 text-xs rounded px-2 py-1.5 border border-slate-600 focus:outline-none focus:ring-1 focus:ring-blue-500"
                        >
                            {DEPARTMENT_OPTIONS.map(opt => (
                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                            ))}
                        </select>
                        <p className="text-[9px] text-slate-500 mt-1 leading-tight">
                            Controls dashboard module cards only. All pages remain accessible.
                        </p>
                    </div>
                )}

                {/* User info + logout */}
                {collapsed ? (
                    <button onClick={handleLogout} className="nav-item justify-center" title="Log out">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth={1.6} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                        </svg>
                    </button>
                ) : (
                    <div className="space-y-2">
                        <div className="px-3 py-2">
                            <p className="text-slate-100 text-sm font-medium truncate">{user.first_name} {user.last_name}</p>
                            <div className="flex items-center gap-1.5 mt-0.5">
                                <span className="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-800 text-blue-200">
                                    {user.department_label}
                                </span>
                                <span className="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-700 text-slate-300">
                                    {user.role}
                                </span>
                            </div>
                        </div>
                        <button
                            onClick={handleLogout}
                            data-testid="logout-btn"
                            className="nav-item text-red-400 hover:text-red-300 hover:bg-red-900/20"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                            </svg>
                            Log out
                        </button>
                    </div>
                )}
            </div>
            {/* ── Collapsed flyout panel ────────────────────────────────────── */}
            {/* Shown via hover when sidebar is icon-only. Fixed-positioned to escape
                the nav's overflow-y-auto container. Stays open while cursor is over
                either the trigger button OR the panel (debounced 120ms hide). */}
            {collapsed && hoveredGroup !== null && (() => {
                const group = navGroups.find(g => g.label === hoveredGroup);
                if (!group) return null;
                return (
                    <div
                        className="fixed z-50 bg-slate-900 dark:bg-slate-950 border border-slate-700 rounded-lg shadow-xl py-1 min-w-[192px]"
                        style={{ left: 68, top: flyoutY }}
                        onMouseEnter={keepFlyout}
                        onMouseLeave={hideFlyout}
                    >
                        <p className="px-3 py-1.5 text-[10px] font-semibold text-slate-400 uppercase tracking-wider">
                            {group.label}
                        </p>
                        {group.items.map(item => (
                            <Link
                                key={item.module}
                                href={item.href}
                                onClick={() => setHoveredGroup(null)}
                                className={`flex items-center gap-2 px-3 py-2 text-sm transition-colors ${
                                    isActive(item.href)
                                        ? 'bg-blue-600/20 text-white'
                                        : 'text-slate-300 hover:bg-slate-700 hover:text-white'
                                }`}
                            >
                                <span className="w-1.5 h-1.5 rounded-full bg-current opacity-60 shrink-0" />
                                <span className="flex-1">{item.label}</span>
                                {item.module === 'chat' && chatUnread > 0 && (
                                    <span className="flex h-4 min-w-[16px] items-center justify-center rounded-full bg-blue-500 px-1 text-[9px] font-bold text-white">
                                        {chatUnread > 99 ? '99+' : chatUnread}
                                    </span>
                                )}
                            </Link>
                        ))}
                    </div>
                );
            })()}
        </aside>
    );
}

// ─── ImitateUserDropdown ───────────────────────────────────────────────────────
// Searchable dropdown for super-admin to start user impersonation.
// Loads the tenant user list lazily (on first open). Filters by name/dept in-place.
// Clicking a user POSTs to /super-admin/impersonate/{id} then reloads the page.
function ImitateUserDropdown({ impersonating }: { impersonating: boolean }) {
    const [open, setOpen]         = useState(false);
    const [query, setQuery]       = useState('');
    const [users, setUsers]       = useState<ImpersonationUser[]>([]);
    const [loading, setLoading]   = useState(false);
    const [starting, setStarting] = useState<number | null>(null);
    const panelRef                = useRef<HTMLDivElement>(null);

    // Load tenant user list once when dropdown is first opened.
    useEffect(() => {
        if (!open || users.length > 0) return;
        setLoading(true);
        axios.get('/super-admin/users')
            .then(res => setUsers(res.data.users ?? []))
            .finally(() => setLoading(false));
    }, [open]);

    // Close on outside click.
    useEffect(() => {
        if (!open) return;
        const handler = (e: MouseEvent) => {
            if (panelRef.current && !panelRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [open]);

    const filtered = users.filter(u => {
        const q = query.toLowerCase();
        return (
            `${u.first_name} ${u.last_name}`.toLowerCase().includes(q) ||
            u.department_label.toLowerCase().includes(q) ||
            u.role.toLowerCase().includes(q)
        );
    });

    const startImpersonation = (userId: number) => {
        setStarting(userId);
        axios.post(`/super-admin/impersonate/${userId}`)
            .then(() => {
                setOpen(false);
                router.reload();
            })
            .catch(() => setStarting(null));
    };

    // Button appearance: amber ring + "exit" indicator when already impersonating.
    const btnClass = impersonating
        ? 'p-1.5 rounded-lg bg-amber-100 text-amber-700 ring-2 ring-amber-400 hover:bg-amber-200 transition-colors'
        : 'p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 transition-colors';

    return (
        <div className="relative" ref={panelRef}>
            {/* Trigger button: person-switch icon */}
            <button
                onClick={() => setOpen(prev => !prev)}
                data-testid="imitate-user-btn"
                className={btnClass}
                aria-label="Imitate user"
                title={impersonating ? 'Currently impersonating a user' : 'Imitate user'}
            >
                {/* Heroicons: user-circle with arrow (user-switch concept) */}
                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth={1.6} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                    <path strokeLinecap="round" strokeLinejoin="round" d="M17.25 12l2.25 2.25-2.25 2.25M21 14.25h-6" />
                </svg>
            </button>

            {/* Dropdown panel */}
            {open && (
                <div className="absolute right-0 top-10 w-80 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 z-50 overflow-hidden">
                    <div className="px-3 py-2.5 border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/80">
                        <p className="text-xs font-semibold text-slate-700 dark:text-slate-200 mb-2">Imitate User</p>
                        {/* Search input */}
                        <input
                            type="text"
                            value={query}
                            onChange={e => setQuery(e.target.value)}
                            placeholder="Search by name or department…"
                            autoFocus
                            data-testid="imitate-search-input"
                            className="w-full text-xs px-2.5 py-1.5 border border-slate-200 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-1 focus:ring-blue-400 bg-white dark:bg-slate-700 dark:text-slate-100 dark:placeholder-slate-400"
                        />
                    </div>

                    <div className="max-h-64 overflow-y-auto">
                        {loading && (
                            <p className="text-xs text-slate-500 dark:text-slate-400 px-4 py-3 text-center">Loading users…</p>
                        )}
                        {!loading && filtered.length === 0 && (
                            <p className="text-xs text-slate-500 dark:text-slate-400 px-4 py-3 text-center">No users found</p>
                        )}
                        {!loading && filtered.map(u => (
                            <button
                                key={u.id}
                                onClick={() => startImpersonation(u.id)}
                                disabled={starting === u.id}
                                data-testid={`imitate-user-${u.id}`}
                                className="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-blue-50 dark:hover:bg-slate-700 text-left transition-colors disabled:opacity-50"
                            >
                                {/* Avatar initials */}
                                <div className="w-7 h-7 rounded-full bg-slate-200 dark:bg-slate-600 text-slate-600 dark:text-slate-300 flex items-center justify-center text-[10px] font-semibold shrink-0">
                                    {u.first_name[0]}{u.last_name[0]}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className="text-xs font-medium text-slate-800 dark:text-slate-100 truncate">
                                        {u.first_name} {u.last_name}
                                    </p>
                                    <p className="text-[10px] text-slate-500 dark:text-slate-400 truncate">
                                        {u.department_label} · {u.role}
                                    </p>
                                </div>
                                {starting === u.id && (
                                    <span className="text-[10px] text-blue-500">Starting…</span>
                                )}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── TopBar ───────────────────────────────────────────────────────────────────
// Horizontal bar spanning the top of the main content area.
// Contains: sidebar toggle · tenant/site display · global search · notifications · user chip
// Super-admin extra: "Imitate User" button (before notification bell).
function TopBar({ onToggleSidebar, onOpenSearch, theme, onThemeChange }: {
    onToggleSidebar: () => void;
    onOpenSearch: () => void;
    theme: 'light' | 'dark';
    onThemeChange: (t: 'light' | 'dark') => void;
}) {
    const { auth, impersonation, site_context, available_sites } = usePage<PageProps>().props;
    const user = auth.user!;
    // canSwitchSite: executive + SA dept + SA role users when multiple sites available
    const canSwitchSite = (user.is_super_admin || user.department === 'super_admin' || user.department === 'executive')
        && available_sites.length > 1;;

    return (
        <header className="h-14 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center px-4 gap-4 shrink-0 z-10">
            {/* Sidebar collapse/expand toggle */}
            <button
                onClick={onToggleSidebar}
                data-testid="sidebar-toggle"
                className="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400 transition-colors"
                aria-label="Toggle sidebar"
            >
                <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>

            {/* Current tenant + site name (site_context takes precedence for executive/SA) */}
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 text-sm">
                    <span className="font-semibold text-slate-800 dark:text-slate-100 truncate">{user.tenant?.name}</span>
                    {(site_context ?? user.site) && (
                        <>
                            <span className="text-slate-400 dark:text-slate-500">·</span>
                            <span className="text-slate-500 dark:text-slate-400 truncate">{(site_context ?? user.site)?.name}</span>
                        </>
                    )}
                </div>
            </div>

            {/* Site switcher — executive + SA dept users when multiple sites exist */}
            {canSwitchSite && (
                <SiteSwitcherDropdown current={site_context} sites={available_sites} />
            )}

            {/* Global search trigger button — also activated by Cmd+K / Ctrl+K */}
            <button
                onClick={onOpenSearch}
                data-testid="search-trigger"
                className="hidden sm:flex items-center gap-2 px-3 py-1.5 text-sm text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 rounded-lg transition-colors"
                aria-label="Open global search"
            >
                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z" />
                </svg>
                <span>Search participants…</span>
                <kbd className="ml-1 px-1.5 py-0.5 text-[10px] font-medium bg-white dark:bg-slate-600 border border-slate-200 dark:border-slate-500 rounded shadow-sm">
                    ⌘K
                </kbd>
            </button>

            {/* Right side: theme toggle · super-admin imitate btn · notification bell · help · user chip */}
            <div className="flex items-center gap-3">
                {/* Theme toggle — always visible */}
                <ThemeToggle theme={theme} onChange={onThemeChange} />
                {/* "Imitate User" button — super-admin only.
                    Shown regardless of impersonation state so SA can switch users. */}
                {user.is_super_admin && (
                    <ImitateUserDropdown impersonating={impersonation.active} />
                )}

                {/* Notification bell — real-time alerts via Reverb (Phase 4) */}
                <NotificationBell />

                {/* Help icon — placeholder */}
                <button className="p-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-500 dark:text-slate-400" aria-label="Help">
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth={1.6} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                    </svg>
                </button>

                {/* Logged-in user avatar chip.
                    When impersonating, show impersonated user's initials with amber ring. */}
                <div className="flex items-center gap-2 pl-2 border-l border-slate-200 dark:border-slate-600">
                    <div className={`w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-semibold
                        ${impersonation.active ? 'bg-amber-500 ring-2 ring-amber-300' : 'bg-blue-600'}`}>
                        {user.first_name[0]}{user.last_name[0]}
                    </div>
                    <div className="hidden sm:block text-right">
                        <p className="text-xs font-medium text-slate-800 dark:text-slate-100 leading-none">{user.first_name} {user.last_name}</p>
                        <p className="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5">{user.department_label}</p>
                    </div>
                </div>
            </div>
        </header>
    );
}

// ─── ImpersonationBanner ──────────────────────────────────────────────────────
// Amber bar rendered directly below TopBar when super-admin is impersonating a user.
// Displays: "Viewing as {name} · {dept_label}" + "Exit Impersonation" button.
// POSTs DELETE /super-admin/impersonate to stop, then reloads to restore full SA view.
function ImpersonationBanner() {
    const { impersonation } = usePage<PageProps>().props;
    const [stopping, setStopping] = useState(false);

    if (!impersonation.active || !impersonation.user) return null;

    const { first_name, last_name, department_label } = impersonation.user;

    const stopImpersonation = () => {
        setStopping(true);
        axios.delete('/super-admin/impersonate')
            .then(() => router.reload())
            .catch(() => setStopping(false));
    };

    return (
        <div
            data-testid="impersonation-banner"
            className="bg-amber-400 text-amber-900 px-4 py-2 flex items-center gap-2 text-sm font-semibold shrink-0"
        >
            {/* Eye icon */}
            <svg className="w-4 h-4 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>

            <span>
                Viewing as <strong>{first_name} {last_name}</strong>
                <span className="mx-1 opacity-60">·</span>
                {department_label}
            </span>

            <button
                onClick={stopImpersonation}
                disabled={stopping}
                data-testid="exit-impersonation-btn"
                className="ml-auto px-3 py-0.5 bg-amber-700 text-white rounded text-xs font-semibold hover:bg-amber-800 transition-colors disabled:opacity-50"
            >
                {stopping ? 'Exiting…' : 'Exit Impersonation'}
            </button>
        </div>
    );
}

// ─── SiteSwitcherDropdown ─────────────────────────────────────────────────────
// Dropdown for executive + SA dept users to switch the active site context.
// Shown in TopBar when available_sites has more than one entry.
// POSTs to /site-context/switch then reloads to refresh tenant-scoped data.
function SiteSwitcherDropdown({ current, sites }: { current: SiteContext | null; sites: SiteContext[] }) {
    const [open, setOpen]       = useState(false);
    const [switching, setSwitching] = useState<number | null>(null);
    const panelRef              = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const handler = (e: MouseEvent) => {
            if (panelRef.current && !panelRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [open]);

    const switchSite = (siteId: number) => {
        setSwitching(siteId);
        axios.post('/site-context/switch', { site_id: siteId })
            .then(() => {
                setOpen(false);
                router.reload();
            })
            .catch(() => setSwitching(null));
    };

    return (
        <div className="relative" ref={panelRef}>
            <button
                onClick={() => setOpen(prev => !prev)}
                data-testid="site-switcher-btn"
                className="flex items-center gap-1.5 px-2 py-1 rounded-lg hover:bg-slate-100 text-slate-600 text-xs font-medium transition-colors"
                title="Switch active site"
            >
                {/* Building icon */}
                <svg className="w-4 h-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" strokeWidth={1.6} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                </svg>
                <span className="max-w-[120px] truncate">{current?.name ?? 'All Sites'}</span>
                <svg className="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            {open && (
                <div className="absolute right-0 top-9 w-56 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-slate-200 dark:border-slate-700 z-50 overflow-hidden">
                    <div className="px-3 py-2 border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-700/50">
                        <p className="text-xs font-semibold text-slate-600 dark:text-slate-300">Switch Active Site</p>
                    </div>
                    <div className="max-h-48 overflow-y-auto">
                        {sites.map(site => (
                            <button
                                key={site.id}
                                onClick={() => switchSite(site.id)}
                                disabled={switching === site.id || site.id === current?.id}
                                data-testid={`site-option-${site.id}`}
                                className="w-full flex items-center gap-2 px-3 py-2 hover:bg-blue-50 dark:hover:bg-slate-700 text-left text-xs transition-colors disabled:opacity-60"
                            >
                                <span className={`w-1.5 h-1.5 rounded-full shrink-0 ${site.id === current?.id ? 'bg-blue-500' : 'bg-slate-300 dark:bg-slate-500'}`} />
                                <span className="flex-1 truncate text-slate-700 dark:text-slate-200">{site.name}</span>
                                {site.id === current?.id && (
                                    <span className="text-[10px] text-blue-500 font-medium">Active</span>
                                )}
                                {switching === site.id && (
                                    <span className="text-[10px] text-slate-400">Switching…</span>
                                )}
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── NostosAdminBanner ────────────────────────────────────────────────────────
// Amber banner shown below TopBar when the authenticated user has department='super_admin'
// (Nostos staff member, cross-tenant access). Distinguishes from impersonation banner.
// No "Exit" button — this is their actual dept, not a temporary view.
function NostosAdminBanner() {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user!;

    // Only show for Nostos SA dept users who are NOT impersonating
    if (user.department !== 'super_admin') return null;

    return (
        <div
            data-testid="nostos-admin-banner"
            className="bg-amber-50 border-b border-amber-200 text-amber-800 px-4 py-1.5 flex items-center gap-2 text-xs font-medium shrink-0"
        >
            {/* Shield icon */}
            <svg className="w-3.5 h-3.5 shrink-0 text-amber-600" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
            </svg>
            <span>Nostos Super Admin: cross-tenant access active</span>
        </div>
    );
}

// ─── CriticalAlertBanner ──────────────────────────────────────────────────────
// Full-width red banner that appears below the TopBar for unacknowledged critical
// alerts. Multiple alerts stack — each is individually dismissible.
// Dismissed state stored in sessionStorage so banners don't re-appear on nav.
// Chat alerts include a "Go to chat" deep-link via metadata.channel_id.
// Polls /alerts?per_page=5 on mount and refreshes on Reverb alert.created events.
function CriticalAlertBanner() {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user!;

    interface CritItem {
        id: number;
        title: string;
        message: string;
        source_module: string;
        metadata?: { channel_id?: number } | null;
    }

    const [banners, setBanners] = useState<CritItem[]>([]);
    const sessionKey = `dismissed_critical_alerts`;

    const getDismissed = (): number[] => {
        try { return JSON.parse(sessionStorage.getItem(sessionKey) ?? '[]'); }
        catch { return []; }
    };

    const fetchCriticals = useCallback(async () => {
        try {
            const { data } = await axios.get('/alerts', { params: { per_page: 10 } });
            const dismissed = getDismissed();
            const items: CritItem[] = (data.data ?? data)
                .filter((a: any) =>
                    a.severity === 'critical' &&
                    !a.acknowledged_at &&
                    !dismissed.includes(a.id)
                )
                .map((a: any) => ({
                    id: a.id, title: a.title, message: a.message,
                    source_module: a.source_module, metadata: a.metadata,
                }));
            setBanners(items);
        } catch { /* ignore */ }
    }, []);

    useEffect(() => { fetchCriticals(); }, [fetchCriticals]);

    // Refresh on real-time critical alert
    useEffect(() => {
        if (!window.Echo || !user.tenant?.id) return;
        const ch = window.Echo.channel(`tenant.${user.tenant.id}`);
        ch.listen('.alert.created', (_: unknown) => { fetchCriticals(); });
        return () => { window.Echo?.leaveChannel(`tenant.${user.tenant!.id}`); };
    }, [user.tenant?.id, fetchCriticals]);

    const dismiss = (id: number) => {
        const dismissed = [...getDismissed(), id];
        sessionStorage.setItem(sessionKey, JSON.stringify(dismissed));
        setBanners(prev => prev.filter(b => b.id !== id));
    };

    if (banners.length === 0) return null;

    return (
        <div className="shrink-0" data-testid="critical-alert-banners">
            {banners.map(banner => (
                <div
                    key={banner.id}
                    data-testid={`critical-banner-${banner.id}`}
                    className="flex items-start gap-3 px-4 py-2.5 bg-red-600 text-white text-xs font-medium"
                >
                    {/* Warning icon */}
                    <svg className="w-4 h-4 mt-0.5 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <div className="flex-1 min-w-0">
                        <span className="font-bold mr-1.5">{banner.title}:</span>
                        <span className="opacity-90">{banner.message}</span>
                        {banner.source_module === 'chat' && banner.metadata?.channel_id && (
                            <a
                                href={`/chat?channel=${banner.metadata.channel_id}`}
                                data-testid={`critical-banner-link-${banner.id}`}
                                className="ml-2 underline font-semibold hover:opacity-80 whitespace-nowrap"
                            >
                                Go to chat
                            </a>
                        )}
                    </div>
                    <button
                        onClick={() => dismiss(banner.id)}
                        data-testid={`critical-banner-dismiss-${banner.id}`}
                        aria-label="Dismiss alert"
                        className="shrink-0 p-0.5 hover:opacity-70 rounded"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            ))}
        </div>
    );
}

// ─── AppShell (default export) ────────────────────────────────────────────────
// Wraps every authenticated page. Children are rendered in the main content area.
// Optional breadcrumbs array renders a breadcrumb trail below the TopBar.
export default function AppShell({ children, breadcrumbs }: {
    children:     ReactNode;
    breadcrumbs?: Array<{ label: string; href?: string }>;
}) {
    const { auth, nav_groups } = usePage<PageProps>().props;
    const user = auth.user!;

    // ── Theme preference ─────────────────────────────────────────────────────
    // Initialized from the server-persisted value (auth.user.theme_preference).
    // localStorage acts as a FOUC backup (read by app.blade.php inline script).
    // Changes are persisted server-side via POST /user/theme.
    const [theme, setTheme] = useState<'light' | 'dark'>(() => {
        try {
            return (localStorage.getItem('nostos_theme') as 'light' | 'dark') ?? user.theme_preference ?? 'light';
        } catch {
            return user.theme_preference ?? 'light';
        }
    });

    // Apply the 'dark' class to <html> whenever theme changes.
    // This enables all Tailwind dark: variants throughout the app.
    useEffect(() => {
        document.documentElement.classList.toggle('dark', theme === 'dark');
        try { localStorage.setItem('nostos_theme', theme); } catch { /* ignore */ }
    }, [theme]);

    const handleThemeChange = (t: 'light' | 'dark') => {
        setTheme(t);
        // Persist to server in the background — failure is non-critical (localStorage covers this session)
        axios.post('/user/theme', { theme: t }).catch(() => { /* silent */ });
    };

    // Persist sidebar collapsed state in localStorage so it survives page navigation
    const [collapsed, setCollapsed] = useState(() => {
        try { return localStorage.getItem('sidebar_collapsed') === 'true'; } catch { return false; }
    });

    const [showSearch, setShowSearch]       = useState(false);
    const [showIdleWarning, setShowIdleWarning] = useState(false);
    const [countdown, setCountdown]         = useState(60);

    // Auto-logout timeout comes from the tenant's settings (default 15 min)
    const autoLogoutMinutes = user.tenant?.auto_logout_minutes ?? 15;

    const toggleSidebar = () => {
        setCollapsed(prev => {
            const next = !prev;
            localStorage.setItem('sidebar_collapsed', String(next));
            return next;
        });
    };

    // ── Global search: open with Cmd+K / Ctrl+K ─────────────────────────────
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                setShowSearch(prev => !prev);
            }
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, []);

    // ── HIPAA idle timer ─────────────────────────────────────────────────────
    // Shows a warning modal 1 minute before auto-logout. Resets on any activity.
    const resetTimer = useCallback(() => {
        setShowIdleWarning(false);
        setCountdown(60);
    }, []);

    useEffect(() => {
        let warningTimer: ReturnType<typeof setTimeout>;
        let countdownInterval: ReturnType<typeof setInterval>;

        const events = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'];

        const startTimers = () => {
            clearTimeout(warningTimer);
            clearInterval(countdownInterval);

            const warningAt = (autoLogoutMinutes - 1) * 60 * 1000;

            warningTimer = setTimeout(() => {
                setShowIdleWarning(true);
                setCountdown(60);

                countdownInterval = setInterval(() => {
                    setCountdown(prev => {
                        if (prev <= 1) {
                            clearInterval(countdownInterval);
                            router.post('/auth/logout', { timeout: true });
                            return 0;
                        }
                        return prev - 1;
                    });
                }, 1000);
            }, warningAt);
        };

        const handleActivity = () => {
            if (!showIdleWarning) startTimers();
        };

        events.forEach(e => window.addEventListener(e, handleActivity, { passive: true }));
        startTimers();

        return () => {
            events.forEach(e => window.removeEventListener(e, handleActivity));
            clearTimeout(warningTimer);
            clearInterval(countdownInterval);
        };
    }, [autoLogoutMinutes, showIdleWarning]);

    const currentPath = window.location.pathname;

    return (
        <div className="flex h-screen overflow-hidden bg-slate-50 dark:bg-slate-900">
            {/* Sidebar navigation */}
            <Sidebar collapsed={collapsed} navGroups={nav_groups} currentPath={currentPath} />

            {/* Main content column */}
            <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
                <TopBar
                    onToggleSidebar={toggleSidebar}
                    onOpenSearch={() => setShowSearch(true)}
                    theme={theme}
                    onThemeChange={handleThemeChange}
                />

                {/* Amber impersonation banner — visible only when super-admin is imitating a user */}
                <ImpersonationBanner />

                {/* Nostos SA dept banner — always visible for department='super_admin' staff */}
                <NostosAdminBanner />

                {/* Critical alert banners — full-width red bar for each unacknowledged critical alert */}
                <CriticalAlertBanner />

                {/* Optional page breadcrumb */}
                {breadcrumbs && breadcrumbs.length > 0 && (
                    <div className="bg-white dark:bg-slate-800 border-b border-slate-100 dark:border-slate-700 px-6 py-2">
                        <nav className="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                            {breadcrumbs.map((crumb, i) => (
                                <React.Fragment key={i}>
                                    {i > 0 && <span>/</span>}
                                    {crumb.href ? (
                                        <Link href={crumb.href} className="hover:text-slate-700 dark:hover:text-slate-200">{crumb.label}</Link>
                                    ) : (
                                        <span className="text-slate-700 dark:text-slate-200 font-medium">{crumb.label}</span>
                                    )}
                                </React.Fragment>
                            ))}
                        </nav>
                    </div>
                )}

                {/* Page-specific content */}
                <main className="flex-1 overflow-y-auto p-6">
                    {children}
                </main>
            </div>

            {/* HIPAA idle warning modal */}
            {showIdleWarning && (
                <IdleWarningModal
                    countdown={countdown}
                    onStayLoggedIn={resetTimer}
                />
            )}

            {/* Global participant search modal (Cmd+K) */}
            <GlobalSearch open={showSearch} onClose={() => setShowSearch(false)} />
        </div>
    );
}
