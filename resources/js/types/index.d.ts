export interface Tenant {
    id: number;
    name: string;
    transport_mode: 'direct' | 'broker';
    auto_logout_minutes: number;
}

export interface Site {
    id: number;
    name: string;
}

export interface AuthUser {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    department: Department;
    department_label: string;
    role: 'admin' | 'standard' | 'super_admin';
    is_admin: boolean;
    is_super_admin: boolean;
    tenant: Tenant | null;
    site: Site | null;
}

export interface RealUser {
    id: number;
    first_name: string;
    last_name: string;
    is_super_admin: true;
}

export interface ImpersonationUser {
    id: number;
    first_name: string;
    last_name: string;
    department: Department;
    department_label: string;
    role: string;
}

export interface ImpersonationState {
    active: boolean;
    user: ImpersonationUser | null;
    viewing_as_dept: string | null;
}

export type Department =
    | 'primary_care'
    | 'therapies'
    | 'social_work'
    | 'behavioral_health'
    | 'dietary'
    | 'activities'
    | 'home_care'
    | 'transportation'
    | 'pharmacy'
    | 'idt'
    | 'enrollment'
    | 'finance'
    | 'qa_compliance'
    | 'it_admin'
    | 'executive'
    | 'super_admin';

export interface SiteContext {
    id: number;
    name: string;
}

export interface ModulePermission {
    view: boolean;
    create: boolean;
    edit: boolean;
    delete: boolean;
    export: boolean;
}

export type PermissionMap = Record<string, ModulePermission>;

export interface NavItem {
    label: string;
    module: string;
    href: string;
}

export interface NavGroup {
    label: string;
    icon: string;
    items: NavItem[];
}

// ── Global Echo / Pusher declarations (set by echo.js) ────────────────────────
declare global {
    interface Window {
        Echo?: {
            channel: (name: string) => {
                listen: (event: string, callback: (payload: unknown) => void) => void;
            };
            private: (name: string) => {
                listen: (event: string, callback: (payload: unknown) => void) => void;
            };
            leaveChannel: (name: string) => void;
        };
        Pusher?: unknown;
    }
}

export interface PageProps {
    auth: {
        user: AuthUser | null;
        real_user: RealUser | null; // non-null only when super-admin is impersonating
    };
    permissions: PermissionMap;
    nav_groups: NavGroup[];
    impersonation: ImpersonationState;
    // Phase 10B: site context for executive + SA dept users
    site_context: SiteContext | null;
    available_sites: SiteContext[];
    flash: {
        success: string | null;
        error: string | null;
    };
    ziggy: Record<string, unknown>;
    [key: string]: unknown;
}
