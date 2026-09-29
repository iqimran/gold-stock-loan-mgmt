import { type NavGroup } from '@/types';
import { LayoutGrid, ShieldCheck, Users } from 'lucide-react';

/**
 * Application navigation. Items are hidden when the user lacks `permission`.
 * Feature modules add their entries here as they are implemented.
 */
export const navigation: NavGroup[] = [
    {
        title: 'Overview',
        items: [{ title: 'Dashboard', url: '/dashboard', icon: LayoutGrid }],
    },
    {
        title: 'Administration',
        items: [
            { title: 'Users', url: '/admin/users', icon: Users, permission: 'users.view' },
            { title: 'Roles & Permissions', url: '/admin/roles', icon: ShieldCheck, permission: 'roles.view' },
        ],
    },
];
