<?php

namespace App\Enums;

/**
 * Catalogue of application permissions. Seeded by RolesAndPermissionsSeeder.
 *
 * Operational permissions for the loan modules (customers, loans, collateral,
 * payments, reports, …) are added here when the module is built (Task 003+).
 */
enum Permission: string
{
    // Administration
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDeactivate = 'users.deactivate';
    case RolesView = 'roles.view';
    case RolesManage = 'roles.manage';

    public function label(): string
    {
        return match ($this) {
            self::UsersView => 'View users',
            self::UsersCreate => 'Create users',
            self::UsersUpdate => 'Update users',
            self::UsersDeactivate => 'Activate / deactivate users',
            self::RolesView => 'View roles',
            self::RolesManage => 'Manage roles & permissions',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::UsersView, self::UsersCreate, self::UsersUpdate, self::UsersDeactivate,
            self::RolesView, self::RolesManage => 'Administration',
        };
    }

    /**
     * Baseline operational permissions for the General User role.
     *
     * @return list<self>
     */
    public static function generalUserDefaults(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Permissions grouped for display: [{group, permissions: [{name, label}]}].
     *
     * @return list<array{group: string, permissions: list<array{name: string, label: string}>}>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $groups[$permission->group()][] = ['name' => $permission->value, 'label' => $permission->label()];
        }

        return array_map(
            fn (string $group, array $permissions) => ['group' => $group, 'permissions' => $permissions],
            array_keys($groups),
            array_values($groups),
        );
    }
}
