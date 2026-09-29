import { ConfirmDialog } from '@/components/confirm-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Roles & Permissions', href: '/admin/roles' }];

interface RoleRow {
    id: number;
    name: string;
    is_system: boolean;
    is_admin: boolean;
    users_count: number;
    permissions_count: number;
}

export default function RolesIndex({ roles }: { roles: RoleRow[] }) {
    const can = useCan();
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [pending, setPending] = useState<RoleRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const destroy = () => {
        if (!pending) return;

        router.delete(route('admin.roles.destroy', pending.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setPending(null);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Roles & Permissions" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title="Roles & Permissions"
                        description="Admin has full access. Other roles receive only the permissions granted here."
                    />
                    {can('roles.manage') && (
                        <Button asChild>
                            <Link href={route('admin.roles.create')}>
                                <Plus className="size-4" /> New role
                            </Link>
                        </Button>
                    )}
                </div>

                <InputError message={errors.role} />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Role</th>
                                <th className="px-4 py-3 font-medium">Users</th>
                                <th className="px-4 py-3 font-medium">Permissions</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {roles.map((role) => (
                                <tr key={role.id} className="border-t">
                                    <td className="px-4 py-3 font-medium">
                                        <span className="mr-2">{role.name}</span>
                                        {role.is_system && <Badge variant="outline">System</Badge>}
                                    </td>
                                    <td className="px-4 py-3">{role.users_count}</td>
                                    <td className="px-4 py-3">{role.is_admin ? 'All' : role.permissions_count}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex justify-end gap-2">
                                            {can('roles.manage') && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={route('admin.roles.edit', role.id)}>{role.is_admin ? 'View' : 'Edit'}</Link>
                                                </Button>
                                            )}
                                            {can('roles.manage') && !role.is_system && (
                                                <Button variant="destructive" size="sm" onClick={() => setPending(role)}>
                                                    Delete
                                                </Button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                title={`Delete role ${pending?.name}?`}
                description="Roles that are still assigned to users cannot be deleted."
                confirmLabel="Delete"
                destructive
                processing={processing}
                onConfirm={destroy}
            />
        </AppLayout>
    );
}
