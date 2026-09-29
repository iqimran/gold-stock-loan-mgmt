import { type NavGroup } from '@/types';
import { Contact, HandCoins, LayoutGrid, ReceiptText, ShieldCheck, Users } from 'lucide-react';

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
        title: 'Lending',
        items: [
            { title: 'Customers', url: '/customers', icon: Contact, permission: 'customers.view' },
            { title: 'Loans', url: '/loans', icon: HandCoins, permission: 'loans.view' },
            { title: 'Payments', url: '/payments', icon: ReceiptText, permission: 'payments.view' },
        ],
    },
    {
        title: 'Administration',
        items: [
            { title: 'Users', url: '/admin/users', icon: Users, permission: 'users.view' },
            { title: 'Roles & Permissions', url: '/admin/roles', icon: ShieldCheck, permission: 'roles.view' },
        ],
    },
];
