import Heading from '@/components/heading';
import { RoleForm } from '@/features/roles/role-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type PermissionGroup } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Roles & Permissions', href: '/admin/roles' },
    { title: 'New role', href: '/admin/roles/create' },
];

export default function CreateRole({ permissionGroups }: { permissionGroups: PermissionGroup[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New role" />
            <div className="p-4 md:p-6">
                <Heading title="New role" description="Create a role and choose the permissions it grants." />
                <RoleForm permissionGroups={permissionGroups} />
            </div>
        </AppLayout>
    );
}
