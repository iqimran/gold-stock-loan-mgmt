<?php

namespace Tests\Feature\Security;

use App\Enums\Permission;
use App\Models\CollateralItem;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission as PermissionModel;
use Tests\TestCase;

/**
 * Gold Stock & Loan permissions (docs/05-auth.md): registered in the existing RBAC catalogue and
 * enforced by policies, one explicit permission per ability — sensitive actions included.
 */
class LoanPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_loan_module_permissions_are_registered_and_seeded(): void
    {
        $required = [
            'customers.view', 'customers.create', 'customers.update', 'customers.archive',
            'loans.view', 'loans.create', 'loans.update', 'loans.close', 'loans.cancel',
            'collateral.view', 'collateral.create', 'collateral.update', 'collateral.release',
            'payments.view', 'payments.create', 'payments.reverse',
            'reports.view', 'reports.export',
            'settings.manage', 'roles.manage', 'audit.view',
            // User administration keeps the existing granular permissions.
            'users.view', 'users.create', 'users.update', 'users.deactivate',
        ];

        $this->assertSame([], array_diff($required, Permission::names()));
        $this->assertSame([], array_diff($required, PermissionModel::pluck('name')->all()));
    }

    public function test_every_permission_is_labelled_and_grouped_for_the_role_editor(): void
    {
        $grouped = collect(Permission::grouped());

        $this->assertSame(
            ['Administration', 'Customers', 'Loans', 'Collateral', 'Payments', 'Reports'],
            $grouped->pluck('group')->all(),
        );
        $this->assertCount(count(Permission::cases()), $grouped->flatMap(fn ($group) => $group['permissions']));
    }

    public function test_general_user_receives_no_loan_permissions_by_default(): void
    {
        $this->assertSame([], $this->generalUser()->effectivePermissionNames());
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2: Permission, 3: bool}>
     */
    public static function abilities(): array
    {
        return [
            'customer viewAny' => [Customer::class, 'viewAny', Permission::CustomersView, false],
            'customer view' => [Customer::class, 'view', Permission::CustomersView, true],
            'customer create' => [Customer::class, 'create', Permission::CustomersCreate, false],
            'customer update' => [Customer::class, 'update', Permission::CustomersUpdate, true],
            'customer archive' => [Customer::class, 'archive', Permission::CustomersArchive, true],
            'customer restore' => [Customer::class, 'restore', Permission::CustomersArchive, true],
            'loan viewAny' => [Loan::class, 'viewAny', Permission::LoansView, false],
            'loan view' => [Loan::class, 'view', Permission::LoansView, true],
            'loan create' => [Loan::class, 'create', Permission::LoansCreate, false],
            'loan update' => [Loan::class, 'update', Permission::LoansUpdate, true],
            'loan activate' => [Loan::class, 'activate', Permission::LoansUpdate, true],
            'loan close' => [Loan::class, 'close', Permission::LoansClose, true],
            'loan cancel' => [Loan::class, 'cancel', Permission::LoansCancel, true],
            'collateral viewAny' => [CollateralItem::class, 'viewAny', Permission::CollateralView, false],
            'collateral view' => [CollateralItem::class, 'view', Permission::CollateralView, true],
            'collateral create' => [CollateralItem::class, 'create', Permission::CollateralCreate, false],
            'collateral update' => [CollateralItem::class, 'update', Permission::CollateralUpdate, true],
            'collateral release' => [CollateralItem::class, 'release', Permission::CollateralRelease, true],
            'payment viewAny' => [Payment::class, 'viewAny', Permission::PaymentsView, false],
            'payment view' => [Payment::class, 'view', Permission::PaymentsView, true],
            'payment create' => [Payment::class, 'create', Permission::PaymentsCreate, false],
            'payment reverse' => [Payment::class, 'reverse', Permission::PaymentsReverse, true],
        ];
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('abilities')]
    public function test_policy_ability_requires_exactly_its_permission(string $model, string $ability, Permission $permission, bool $onInstance): void
    {
        $argument = $onInstance ? new $model : $model;

        // Every other permission is not enough (e.g. loans.view or loans.update does not allow loans.close).
        $everythingElse = User::factory()->create();
        $everythingElse->givePermissionTo(array_values(array_diff(Permission::names(), [$permission->value])));
        $this->assertFalse(Gate::forUser($everythingElse)->allows($ability, $argument), "{$ability} allowed without {$permission->value}");

        $granted = User::factory()->create();
        $granted->givePermissionTo($permission->value);
        $this->assertTrue(Gate::forUser($granted)->allows($ability, $argument), "{$ability} denied with {$permission->value}");

        $this->assertTrue(Gate::forUser($this->admin())->allows($ability, $argument), "Admin denied {$ability}");
    }

    public function test_permissions_without_a_model_are_gate_abilities(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ReportsView->value);

        $this->assertTrue($user->can('reports.view'));
        $this->assertFalse($user->can('reports.export'));
        $this->assertFalse($user->can('settings.manage'));
        $this->assertFalse($user->can('audit.view'));
    }

    public function test_granted_permissions_are_shared_with_the_ui_for_navigation(): void
    {
        $user = $this->generalUser();
        $user->givePermissionTo([Permission::LoansView->value, Permission::PaymentsCreate->value]);

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === ['loans.view', 'payments.create']));
    }
}
