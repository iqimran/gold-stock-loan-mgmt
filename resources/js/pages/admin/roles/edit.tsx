import Heading from '@/components/heading';
import { type EditableRole, RoleForm } from '@/features/roles/role-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type PermissionGroup } from '@/types';
import { Head } from '@inertiajs/react';

export default function EditRole({ role, permissionGroups }: { role: EditableRole; permissionGroups: PermissionGroup[] }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Roles & Permissions', href: '/admin/roles' },
        { title: role.name, href: `/admin/roles/${role.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Role: ${role.name}`} />
            <div className="p-4 md:p-6">
                <Heading title={role.name} description="Choose the permissions granted by this role." />
                <RoleForm role={role} permissionGroups={permissionGroups} />
            </div>
        </AppLayout>
    );
}
