import { ConfirmDialog } from '@/components/confirm-dialog';
import Heading from '@/components/heading';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type ManagedUser } from '@/features/users/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginated, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/admin/users' }];

interface UsersIndexProps {
    users: Paginated<ManagedUser>;
    filters: { search: string; status: string };
}

export default function UsersIndex({ users, filters }: UsersIndexProps) {
    const can = useCan();
    const { auth } = usePage<SharedData>().props;
    const [search, setSearch] = useState(filters.search);
    const [status, setStatus] = useState(filters.status);
    const [pending, setPending] = useState<ManagedUser | null>(null);
    const [processing, setProcessing] = useState(false);

    const applyFilters: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('admin.users.index'), { search: search || undefined, status: status || undefined }, { preserveState: true, replace: true });
    };

    const changeStatus = () => {
        if (!pending) return;

        router.patch(
            route('admin.users.status', pending.id),
            { is_active: !pending.is_active },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setPending(null);
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Users" description="Manage staff accounts, roles and access." />
                    {can('users.create') && (
                        <Button asChild>
                            <Link href={route('admin.users.create')}>
                                <Plus className="size-4" /> New user
                            </Link>
                        </Button>
                    )}
                </div>

                <form onSubmit={applyFilters} className="flex flex-col gap-2 sm:flex-row">
                    <Input placeholder="Search name or email" value={search} onChange={(e) => setSearch(e.target.value)} className="sm:max-w-xs" />
                    <select
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        aria-label="Status"
                    >
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <Button type="submit" variant="secondary">
                        Filter
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-4 py-3 font-medium">Name</th>
                                <th className="px-4 py-3 font-medium">Email</th>
                                <th className="px-4 py-3 font-medium">Role</th>
                                <th className="px-4 py-3 font-medium">Status</th>
                                <th className="px-4 py-3 font-medium">Last login</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground px-4 py-8 text-center">
                                        No users found.
                                    </td>
                                </tr>
                            )}
                            {users.data.map((user) => (
                                <tr key={user.id} className="border-t">
                                    <td className="px-4 py-3 font-medium">{user.name}</td>
                                    <td className="px-4 py-3">{user.email}</td>
                                    <td className="px-4 py-3">{user.role ?? '—'}</td>
                                    <td className="px-4 py-3">
                                        <Badge variant={user.is_active ? 'secondary' : 'destructive'}>{user.is_active ? 'Active' : 'Inactive'}</Badge>
                                    </td>
                                    <td className="text-muted-foreground px-4 py-3">
                                        {user.last_login_at ? new Date(user.last_login_at).toLocaleString() : 'Never'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex justify-end gap-2">
                                            {can('users.update') && (
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={route('admin.users.edit', user.id)}>Edit</Link>
                                                </Button>
                                            )}
                                            {can('users.deactivate') && user.id !== auth.user.id && (
                                                <Button
                                                    variant={user.is_active ? 'destructive' : 'secondary'}
                                                    size="sm"
                                                    onClick={() => setPending(user)}
                                                >
                                                    {user.is_active ? 'Deactivate' : 'Activate'}
                                                </Button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination meta={users.meta} />
            </div>

            <ConfirmDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                title={pending?.is_active ? `Deactivate ${pending?.name}?` : `Activate ${pending?.name}?`}
                description={
                    pending?.is_active
                        ? 'The user will be signed out and will no longer be able to log in. Their transaction history is kept.'
                        : 'The user will be able to log in again.'
                }
                confirmLabel={pending?.is_active ? 'Deactivate' : 'Activate'}
                destructive={pending?.is_active}
                processing={processing}
                onConfirm={changeStatus}
            />
        </AppLayout>
    );
}
