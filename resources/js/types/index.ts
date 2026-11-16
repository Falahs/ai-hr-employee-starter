import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    [key: string]: unknown;
    name: string;
    locale: string;
    dir: 'rtl' | 'ltr';
    locales: string[];
    translations: Record<string, string>;
    auth: Auth;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    is_admin: boolean;
    locale: string;
    must_change_password?: boolean;
    permissions?: string[];
    email_verified_at: string | null;
}

export type BreadcrumbItemType = BreadcrumbItem;
