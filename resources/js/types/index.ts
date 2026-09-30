import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Permission required to see this item. Visibility only; the server still authorizes. */
    permission?: string;
}

export interface FlashMessages {
    success?: string | null;
    error?: string | null;
    status?: string | null;
}

export interface Currency {
    code: string;
    symbol: string;
}

export interface Branding {
    name: string;
    logo_url: string | null;
}

export interface SharedData {
    name: string;
    branding: Branding;
    currency: Currency;
    auth: Auth;
    flash: FlashMessages;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    roles: string[];
    permissions: string[];
    [key: string]: unknown; // This allows for additional properties...
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: { first: string | null; last: string | null; prev: string | null; next: string | null };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        links: PaginationLink[];
        per_page: number;
        to: number | null;
        total: number;
    };
}

export interface PermissionGroup {
    group: string;
    permissions: { name: string; label: string }[];
}
