<?php

namespace App\Enums;

/**
 * Catalogue of application permissions. Seeded by RolesAndPermissionsSeeder.
 *
 * Operational permissions are declared up-front so roles can be configured before each
 * module is built; each module enforces them (policies / Gate checks) when it is implemented.
 * Sensitive actions (docs/05-auth.md) each have their own permission: customers.archive,
 * loans.close, loans.cancel, collateral.release, payments.reverse, reports.export,
 * settings.manage and the user/role administration permissions.
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
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';

    // Customers
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersArchive = 'customers.archive';

    // Loans
    case LoansView = 'loans.view';
    case LoansCreate = 'loans.create';
    case LoansUpdate = 'loans.update';
    case LoansClose = 'loans.close';
    case LoansCancel = 'loans.cancel';

    // Collateral
    case CollateralView = 'collateral.view';
    case CollateralCreate = 'collateral.create';
    case CollateralUpdate = 'collateral.update';
    case CollateralRelease = 'collateral.release';

    // Payments
    case PaymentsView = 'payments.view';
    case PaymentsCreate = 'payments.create';
    case PaymentsReverse = 'payments.reverse';

    // Reports
    case ReportsView = 'reports.view';
    case ReportsExport = 'reports.export';

    public function label(): string
    {
        return match ($this) {
            self::UsersView => 'View users',
            self::UsersCreate => 'Create users',
            self::UsersUpdate => 'Update users',
            self::UsersDeactivate => 'Activate / deactivate users',
            self::RolesView => 'View roles',
            self::RolesManage => 'Manage roles & permissions',
            self::SettingsManage => 'Manage settings',
            self::AuditView => 'View audit log',
            self::CustomersView => 'View customers',
            self::CustomersCreate => 'Create customers',
            self::CustomersUpdate => 'Update customers',
            self::CustomersArchive => 'Archive customers',
            self::LoansView => 'View loans',
            self::LoansCreate => 'Create loans',
            self::LoansUpdate => 'Update loans',
            self::LoansClose => 'Close loans',
            self::LoansCancel => 'Cancel loans',
            self::CollateralView => 'View collateral',
            self::CollateralCreate => 'Add collateral',
            self::CollateralUpdate => 'Update collateral',
            self::CollateralRelease => 'Release collateral',
            self::PaymentsView => 'View payments',
            self::PaymentsCreate => 'Record payments',
            self::PaymentsReverse => 'Reverse payments',
            self::ReportsView => 'View reports',
            self::ReportsExport => 'Export reports',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::UsersView, self::UsersCreate, self::UsersUpdate, self::UsersDeactivate,
            self::RolesView, self::RolesManage, self::SettingsManage, self::AuditView => 'Administration',
            self::CustomersView, self::CustomersCreate, self::CustomersUpdate, self::CustomersArchive => 'Customers',
            self::LoansView, self::LoansCreate, self::LoansUpdate, self::LoansClose, self::LoansCancel => 'Loans',
            self::CollateralView, self::CollateralCreate, self::CollateralUpdate, self::CollateralRelease => 'Collateral',
            self::PaymentsView, self::PaymentsCreate, self::PaymentsReverse => 'Payments',
            self::ReportsView, self::ReportsExport => 'Reports',
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
