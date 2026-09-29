import Heading from '@/components/heading';
import { type ManagedUser } from '@/features/users/types';
import { UserForm } from '@/features/users/user-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type PermissionGroup } from '@/types';
import { Head } from '@inertiajs/react';

interface EditUserProps {
    user: { data: ManagedUser } | ManagedUser;
    roles: string[];
    permissionGroups: PermissionGroup[];
}

export default function EditUser({ user: userProp, roles, permissionGroups }: EditUserProps) {
    const user = 'data' in userProp ? userProp.data : userProp;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Users', href: '/admin/users' },
        { title: user.name, href: `/admin/users/${user.id}/edit` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${user.name}`} />
            <div className="p-4 md:p-6">
                <Heading title={`Edit ${user.name}`} description="Update account details, role and permissions." />
                <UserForm user={user} roles={roles} permissionGroups={permissionGroups} />
            </div>
        </AppLayout>
    );
}
