import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PermissionChecklist } from '@/features/roles/permission-checklist';
import { type PermissionGroup } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

export interface EditableRole {
    id: number;
    name: string;
    is_system: boolean;
    is_admin: boolean;
    permissions: string[];
}

export function RoleForm({ role, permissionGroups }: { role?: EditableRole; permissionGroups: PermissionGroup[] }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: role?.name ?? '',
        permissions: role?.permissions ?? ([] as string[]),
    });

    const readOnly = role?.is_admin ?? false;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (role) {
            put(route('admin.roles.update', role.id), { preserveScroll: true });
        } else {
            post(route('admin.roles.store'), { preserveScroll: true });
        }
    };

    return (
        <form onSubmit={submit} className="space-y-8">
            {readOnly && (
                <p className="rounded-lg border bg-neutral-50 p-4 text-sm dark:bg-neutral-900">
                    The Admin role always has full access to every feature and cannot be modified.
                </p>
            )}

            <div className="grid max-w-md gap-2">
                <Label htmlFor="name">Role name</Label>
                <Input
                    id="name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    required
                    disabled={role?.is_system}
                    autoComplete="off"
                />
                {role?.is_system && <p className="text-muted-foreground text-xs">System roles cannot be renamed.</p>}
                <InputError message={errors.name} />
            </div>

            <div className="space-y-3">
                <h3 className="text-sm font-medium">Permissions</h3>
                <PermissionChecklist
                    groups={permissionGroups}
                    value={data.permissions}
                    onChange={(value) => setData('permissions', value)}
                    disabled={readOnly}
                />
                <InputError message={errors.permissions} />
            </div>

            <div className="flex items-center gap-2">
                {!readOnly && (
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {role ? 'Save changes' : 'Create role'}
                    </Button>
                )}
                <Button variant="outline" asChild>
                    <Link href={route('admin.roles.index')}>{readOnly ? 'Back' : 'Cancel'}</Link>
                </Button>
            </div>
        </form>
    );
}
