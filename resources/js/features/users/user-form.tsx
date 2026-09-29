import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { PermissionChecklist } from '@/features/roles/permission-checklist';
import { type ManagedUser } from '@/features/users/types';
import { type PermissionGroup } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

const ADMIN_ROLE = 'Admin';

interface UserFormProps {
    user?: ManagedUser;
    roles: string[];
    permissionGroups: PermissionGroup[];
}

export function UserForm({ user, roles, permissionGroups }: UserFormProps) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
        password_confirmation: '',
        role: user?.role ?? '',
        permissions: user?.permissions ?? ([] as string[]),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (user) {
            put(route('admin.users.update', user.id), { preserveScroll: true });
        } else {
            post(route('admin.users.store'), { preserveScroll: true });
        }
    };

    return (
        <form onSubmit={submit} className="space-y-8">
            <div className="grid gap-6 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="name">Name</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoComplete="off" />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="off"
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="password">{user ? 'New password (leave blank to keep)' : 'Password'}</Label>
                    <Input
                        id="password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        required={!user}
                        autoComplete="new-password"
                    />
                    <InputError message={errors.password} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        required={!user}
                        autoComplete="new-password"
                    />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="role">Role</Label>
                    <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                        <SelectTrigger id="role">
                            <SelectValue placeholder="Select a role" />
                        </SelectTrigger>
                        <SelectContent>
                            {roles.map((role) => (
                                <SelectItem key={role} value={role}>
                                    {role}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.role} />
                </div>
            </div>

            <div className="space-y-3">
                <div>
                    <h3 className="text-sm font-medium">Additional permissions</h3>
                    <p className="text-muted-foreground text-sm">
                        {data.role === ADMIN_ROLE
                            ? 'Admins have full access; additional permissions do not apply.'
                            : 'Granted on top of the permissions provided by the selected role.'}
                    </p>
                </div>
                <PermissionChecklist
                    groups={permissionGroups}
                    value={data.permissions}
                    onChange={(value) => setData('permissions', value)}
                    disabled={data.role === ADMIN_ROLE}
                />
                <InputError message={errors.permissions} />
            </div>

            <div className="flex items-center gap-2">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    {user ? 'Save changes' : 'Create user'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href={route('admin.users.index')}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
