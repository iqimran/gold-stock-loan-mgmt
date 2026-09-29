import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { type PermissionGroup } from '@/types';

interface PermissionChecklistProps {
    groups: PermissionGroup[];
    value: string[];
    onChange: (value: string[]) => void;
    disabled?: boolean;
}

export function PermissionChecklist({ groups, value, onChange, disabled = false }: PermissionChecklistProps) {
    const toggle = (name: string, checked: boolean) => onChange(checked ? [...value, name] : value.filter((item) => item !== name));

    const toggleGroup = (group: PermissionGroup, checked: boolean) => {
        const names = group.permissions.map((permission) => permission.name);
        onChange(checked ? Array.from(new Set([...value, ...names])) : value.filter((item) => !names.includes(item)));
    };

    return (
        <div className="grid gap-4 md:grid-cols-2">
            {groups.map((group) => {
                const allChecked = group.permissions.every((permission) => value.includes(permission.name));

                return (
                    <fieldset key={group.group} className="rounded-lg border p-4" disabled={disabled}>
                        <legend className="px-1 text-sm font-medium">{group.group}</legend>
                        <div className="mb-3 flex items-center gap-2 border-b pb-2">
                            <Checkbox
                                id={`group-${group.group}`}
                                checked={allChecked}
                                disabled={disabled}
                                onCheckedChange={(checked) => toggleGroup(group, checked === true)}
                            />
                            <Label htmlFor={`group-${group.group}`} className="text-muted-foreground text-xs">
                                Select all
                            </Label>
                        </div>
                        <div className="grid gap-2">
                            {group.permissions.map((permission) => (
                                <div key={permission.name} className="flex items-center gap-2">
                                    <Checkbox
                                        id={`permission-${permission.name}`}
                                        checked={value.includes(permission.name)}
                                        disabled={disabled}
                                        onCheckedChange={(checked) => toggle(permission.name, checked === true)}
                                    />
                                    <Label htmlFor={`permission-${permission.name}`} className="font-normal">
                                        {permission.label}
                                    </Label>
                                </div>
                            ))}
                        </div>
                    </fieldset>
                );
            })}
        </div>
    );
}
