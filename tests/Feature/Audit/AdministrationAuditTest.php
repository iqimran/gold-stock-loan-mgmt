<?php

namespace Tests\Feature\Audit;

use App\Domain\Collateral\CollateralService;
use App\Domain\Loan\LoanService;
use App\Domain\Payment\PaymentService;
use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Users, roles, permissions and sign-ins are audited (never a password or token), and the audit log
 * screen / API show every entry to holders of audit.view only.
 */
class AdministrationAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'S3cure-Passw0rd!';

    private function audit(string $event): AuditLog
    {
        return AuditLog::query()->where('event', $event)->latest('id')->firstOrFail();
    }

    private function assertNoSecret(AuditLog $log, string ...$secrets): void
    {
        $row = AuditLog::query()->toBase()->where('id', $log->id)->first(['old_values', 'new_values', 'description']);
        $raw = "{$row->old_values} {$row->new_values} {$row->description}";

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    // ── users, roles, permissions ───────────────────────────────────────────────────────────

    public function test_user_account_and_access_changes_are_audited_without_passwords(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Rahim Cashier', 'email' => 'rahim@example.com', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'role' => SystemRole::GeneralUser->value, 'permissions' => [Permission::PaymentsCreate->value, Permission::LoansView->value],
        ])->assertRedirect();
        $user = User::where('email', 'rahim@example.com')->firstOrFail();

        $log = $this->audit('user.created');
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(User::class, $log->auditable_type);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertSame([
            'name' => 'Rahim Cashier', 'email' => 'rahim@example.com', 'is_active' => true, 'role' => SystemRole::GeneralUser->value,
            'permissions' => [Permission::LoansView->value, Permission::PaymentsCreate->value],
        ], $log->new_values);
        $this->assertNoSecret($log, self::PASSWORD, $user->password);

        $newPassword = 'An0ther-Secret!';
        $this->actingAs($admin)->put("/admin/users/{$user->id}", [
            'name' => 'Rahim Cashier', 'email' => 'rahim@example.com', 'password' => $newPassword, 'password_confirmation' => $newPassword,
            'role' => SystemRole::GeneralUser->value, 'permissions' => [Permission::PaymentsCreate->value, Permission::PaymentsReverse->value],
        ])->assertRedirect();

        $log = $this->audit('user.updated');
        $this->assertSame(['permissions' => [Permission::LoansView->value, Permission::PaymentsCreate->value]], $log->old_values);
        $this->assertSame(['permissions' => [Permission::PaymentsCreate->value, Permission::PaymentsReverse->value], 'password_changed' => true], $log->new_values);
        $this->assertNoSecret($log, $newPassword, $user->fresh()->password);

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/status", ['is_active' => false])->assertRedirect();
        $log = $this->audit('user.deactivated');
        $this->assertSame(['is_active' => true], $log->old_values);
        $this->assertSame(['is_active' => false], $log->new_values);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_role_permission_changes_are_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/roles', ['name' => 'Counter', 'permissions' => [Permission::PaymentsCreate->value]])->assertRedirect();
        $role = Role::where('name', 'Counter')->firstOrFail();
        $this->assertSame(['name' => 'Counter', 'permissions' => [Permission::PaymentsCreate->value]], $this->audit('role.created')->new_values);

        $this->actingAs($admin)->put("/admin/roles/{$role->id}", ['name' => 'Counter', 'permissions' => [Permission::PaymentsCreate->value, Permission::PaymentsReverse->value]])->assertRedirect();
        $log = $this->audit('role.updated');
        $this->assertSame(['permissions' => [Permission::PaymentsCreate->value]], $log->old_values);
        $this->assertSame(['permissions' => [Permission::PaymentsCreate->value, Permission::PaymentsReverse->value]], $log->new_values);

        // Saving the same permissions again is not a change.
        $this->actingAs($admin)->put("/admin/roles/{$role->id}", ['name' => 'Counter', 'permissions' => [Permission::PaymentsReverse->value, Permission::PaymentsCreate->value]]);
        $this->assertSame(1, AuditLog::query()->where('event', 'role.updated')->count());

        $this->actingAs($admin)->delete("/admin/roles/{$role->id}")->assertRedirect();
        $log = $this->audit('role.deleted');
        $this->assertSame(['name' => 'Counter', 'permissions' => [Permission::PaymentsCreate->value, Permission::PaymentsReverse->value]], $log->old_values);
    }

    // ── sign-ins ────────────────────────────────────────────────────────────────────────────

    public function test_sign_ins_and_api_tokens_are_audited_without_credentials(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.com']);

        $this->post('/login', ['email' => 'staff@example.com', 'password' => 'wrong-password'])->assertSessionHasErrors();
        $failed = $this->audit('auth.login_failed');
        $this->assertNull($failed->user_id);
        $this->assertSame('staff@example.com', $failed->new_values['email']);
        $this->assertNoSecret($failed, 'wrong-password');

        $this->post('/login', ['email' => 'staff@example.com', 'password' => 'password'])->assertRedirect();
        $login = $this->audit('auth.login');
        $this->assertSame($user->id, $login->user_id);
        $this->assertNotNull($login->ip_address);
        $this->assertNoSecret($login, 'password');
        auth()->logout();

        $token = $this->postJson('/api/v1/auth/token', ['email' => 'staff@example.com', 'password' => 'password', 'device_name' => 'Counter PC'])
            ->assertCreated()
            ->json('token');
        $issued = $this->audit('auth.token_issued');
        $this->assertSame($user->id, $issued->user_id);
        $this->assertSame('Counter PC', $issued->new_values['device']);
        $this->assertNoSecret($issued, $token, explode('|', $token)[1]);

        $this->postJson('/api/v1/auth/token', ['email' => 'staff@example.com', 'password' => 'nope', 'device_name' => 'X'])->assertUnprocessable();
        $this->assertNoSecret($this->audit('auth.token_failed'), 'nope');
    }

    // ── audit log screen / API ──────────────────────────────────────────────────────────────

    private function loanWithActivity(User $staff): Loan
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $loan = app(LoanService::class)->create(Customer::factory()->create(), [
            'principal' => '10000', 'interest_rate' => '2', 'interest_rate_type' => 'monthly', 'interest_period_unit' => 'month', 'start_date' => '2026-10-01',
        ], $staff);
        app(CollateralService::class)->add($loan, ['type' => 'gold', 'weight_grams' => '10', 'karat' => '22', 'estimated_value' => '90000'], $staff);
        app(LoanService::class)->activate($loan, $staff);
        Carbon::setTestNow('2026-10-20 10:00:00');
        app(PaymentService::class)->post($loan->fresh(), ['type' => 'interest', 'amount' => '100', 'method' => 'cash', 'payment_date' => '2026-10-20'], $staff);

        return $loan;
    }

    public function test_audit_log_screen_needs_audit_view(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::AuditView->value);

        $this->actingAs($viewer)->get('/admin/audit-logs')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/audit-logs/index'));
        $this->actingAs($this->admin())->get('/admin/audit-logs')->assertOk();

        $other = User::factory()->create();
        $other->givePermissionTo([Permission::UsersView->value, Permission::ReportsView->value]);
        $this->actingAs($other)->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs($other)->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_audit_log_lists_and_filters_entries(): void
    {
        $staff = User::factory()->create(['name' => 'Karim Staff']);
        $loan = $this->loanWithActivity($staff);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/audit-logs')
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 4) // loan created, collateral added, loan activated, payment recorded
                ->where('logs.data.0.event', 'payment.created') // newest first
                ->where('logs.data.0.event_label', 'Payment recorded')
                ->where('logs.data.0.entity.type', 'Payment')
                ->where('logs.data.0.user.name', 'Karim Staff')
                ->where('logs.data.0.new_values.amount', '100.00')
                ->has('events')
                ->where('areas', ['loan', 'payment', 'collateral', 'customer', 'settings', 'user', 'role', 'auth']));

        // By area, by action, by record number (a loan number also finds its payments and collateral), by user.
        $this->actingAs($admin)->get('/admin/audit-logs?area=loan')->assertInertia(fn (Assert $page) => $page->has('logs.data', 2));
        $this->actingAs($admin)->get('/admin/audit-logs?event=collateral.created')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.entity.type', 'Collateral'));
        $this->actingAs($admin)->get("/admin/audit-logs?reference={$loan->loan_no}")->assertInertia(fn (Assert $page) => $page->has('logs.data', 4));
        $this->actingAs($admin)->get("/admin/audit-logs?user={$staff->id}")->assertInertia(fn (Assert $page) => $page->has('logs.data', 4));
        $this->actingAs($admin)->get('/admin/audit-logs?system=1')->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));

        // Automatic status changes show as System.
        Carbon::setTestNow('2026-11-02 00:05:00');
        $this->artisan('loans:process-interest')->assertSuccessful();
        $this->actingAs($admin)->get('/admin/audit-logs?system=1')
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.user', null)->where('logs.data.0.new_values.action', 'marked_overdue'));
        $this->actingAs($admin)->get('/admin/audit-logs?event=nope')->assertSessionHasErrors('event');

        $this->actingAs($admin)->getJson("/api/v1/audit-logs?reference={$loan->loan_no}&area=payment")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entity.reference', Loan::find($loan->id)->payments()->value('receipt_no'));
    }
}
