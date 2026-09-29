import Heading from '@/components/heading';
import { UserForm } from '@/features/users/user-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type PermissionGroup } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users', href: '/admin/users' },
    { title: 'New user', href: '/admin/users/create' },
];

export default function CreateUser({ roles, permissionGroups }: { roles: string[]; permissionGroups: PermissionGroup[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New user" />
            <div className="p-4 md:p-6">
                <Heading title="New user" description="Create a staff account and assign its role." />
                <UserForm roles={roles} permissionGroups={permissionGroups} />
            </div>
        </AppLayout>
    );
}
