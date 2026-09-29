<?php

namespace Tests\Feature\Loans;

use App\Enums\LoanEventType;
use App\Enums\LoanStatus;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoanApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(Permission ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (Permission $permission) => $permission->value, $permissions));
        Sanctum::actingAs($user);

        return $user;
    }

    private function valid(Customer $customer, array $overrides = []): array
    {
        return [
            'customer' => $customer->customer_no, 'principal' => '50000.50', 'interest_rate' => '2.25',
            'interest_rate_type' => 'monthly', 'interest_period_unit' => 'month', 'start_date' => '2026-10-01',
            'notes' => 'Two bangles', ...$overrides,
        ];
    }

    // ── create ──────────────────────────────────────────────────────────────────────────────

    public function test_valid_loan_is_created_as_a_draft(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $user = $this->actingWith(Permission::LoansCreate, Permission::LoansUpdate);
        $customer = Customer::factory()->create();

        $this->postJson('/api/v1/loans', $this->valid($customer))
            ->assertCreated()
            ->assertJsonPath('data.loan_no', 'LN-202610-000001')
            ->assertJsonPath('data.customer.customer_no', $customer->customer_no)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.principal', '50000.50')
            ->assertJsonPath('data.outstanding_principal', '50000.50')
            ->assertJsonPath('data.interest_rate', '2.2500')
            ->assertJsonPath('data.interest_rate_type', 'monthly')
            ->assertJsonPath('data.interest_period_unit', 'month')
            ->assertJsonPath('data.start_date', '2026-10-01')
            ->assertJsonPath('data.next_due_date', null)
            ->assertJsonPath('data.actions.activate', true)
            ->assertJsonPath('data.actions.close', false)
            ->assertJsonMissingPath('data.id');

        $loan = Loan::firstOrFail();
        $this->assertSame($user->id, $loan->created_by);
        $event = $loan->events()->sole();
        $this->assertSame(LoanEventType::Created, $event->event_type);
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame('50000.50', $event->payload['terms']['principal']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function invalidLoans(): array
    {
        return [
            'missing fields' => [['principal' => null, 'interest_rate' => null, 'interest_rate_type' => null, 'interest_period_unit' => null, 'start_date' => null], ['principal', 'interest_rate', 'interest_rate_type', 'interest_period_unit', 'start_date']],
            'zero principal' => [['principal' => '0'], ['principal']],
            'negative principal' => [['principal' => '-100'], ['principal']],
            'three decimals' => [['principal' => '100.005'], ['principal']],
            'principal above DECIMAL(18,2)' => [['principal' => '10000000000000000'], ['principal']],
            'non-numeric principal' => [['principal' => 'ten thousand'], ['principal']],
            'negative rate' => [['interest_rate' => '-1'], ['interest_rate']],
            'rate with five decimals' => [['interest_rate' => '2.12345'], ['interest_rate']],
            'unknown rate type' => [['interest_rate_type' => 'daily'], ['interest_rate_type']],
            'unknown period unit' => [['interest_period_unit' => 'week'], ['interest_period_unit']],
            'bad start date' => [['start_date' => '01/10/2026'], ['start_date']],
            'unknown customer' => [['customer' => 'CUS-000000-000000'], ['customer']],
            'status is not accepted from input' => [['status' => 'active', 'principal' => '0'], ['principal']],
        ];
    }

    #[DataProvider('invalidLoans')]
    public function test_invalid_loan_data_is_rejected(array $overrides, array $errors): void
    {
        $this->actingWith(Permission::LoansCreate);

        $this->postJson('/api/v1/loans', $this->valid(Customer::factory()->create(), $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertDatabaseCount('loans', 0);
    }

    public function test_loans_cannot_be_created_for_archived_customers(): void
    {
        $this->actingWith(Permission::LoansCreate);

        $this->postJson('/api/v1/loans', $this->valid(Customer::factory()->archived()->create()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer' => 'does not exist or is archived']);
    }

    // ── update ──────────────────────────────────────────────────────────────────────────────

    public function test_draft_terms_are_editable_and_outstanding_follows_principal(): void
    {
        $this->actingWith(Permission::LoansUpdate);
        $loan = Loan::factory()->create();

        $this->patchJson("/api/v1/loans/{$loan->loan_no}", ['principal' => '60000', 'interest_rate' => '3', 'notes' => 'Revised'])
            ->assertOk()
            ->assertJsonPath('data.principal', '60000.00')
            ->assertJsonPath('data.outstanding_principal', '60000.00')
            ->assertJsonPath('data.interest_rate', '3.0000')
            ->assertJsonPath('data.notes', 'Revised');

        $event = $loan->events()->where('event_type', LoanEventType::Updated)->sole();
        $this->assertEquals(['principal' => '50000.00', 'interest_rate' => '2.5000', 'notes' => null], $event->payload['before']);
        $this->assertEquals(['principal' => '60000.00', 'interest_rate' => '3.0000', 'notes' => 'Revised'], $event->payload['after']);
    }

    #[DataProvider('lockedStatuses')]
    public function test_terms_are_locked_after_activation_but_notes_stay_editable(LoanStatus $status): void
    {
        $this->actingWith(Permission::LoansUpdate);
        $loan = Loan::factory()->status($status)->create();

        $this->patchJson("/api/v1/loans/{$loan->loan_no}", ['principal' => '1.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal' => "cannot be changed once the loan is {$status->value}"]);

        $this->patchJson("/api/v1/loans/{$loan->loan_no}", ['start_date' => '2026-01-01', 'interest_rate_type' => 'yearly'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);

        // Re-sending the unchanged terms (e.g. a full form) is accepted.
        $this->patchJson("/api/v1/loans/{$loan->loan_no}", ['principal' => '50000', 'interest_rate' => '2.5', 'notes' => 'Customer called'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Customer called')
            ->assertJsonPath('data.principal', '50000.00');

        $this->assertSame('50000.00', $loan->fresh()->principal);
    }

    public static function lockedStatuses(): array
    {
        return ['active' => [LoanStatus::Active], 'overdue' => [LoanStatus::Overdue], 'closed' => [LoanStatus::Closed], 'cancelled' => [LoanStatus::Cancelled]];
    }

    public function test_customer_and_status_cannot_be_changed_through_update(): void
    {
        $this->actingWith(Permission::LoansUpdate);
        $loan = Loan::factory()->create();

        $this->patchJson("/api/v1/loans/{$loan->loan_no}", ['customer' => Customer::factory()->create()->customer_no])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer');

        $this->patchJson("/api/v1/loans/{$loan->loan_no}", ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'draft');
    }

    // ── authorization ───────────────────────────────────────────────────────────────────────

    public function test_each_endpoint_requires_its_permission(): void
    {
        $customer = Customer::factory()->create();
        $draft = Loan::factory()->for($customer)->create();
        $active = Loan::factory()->for($customer)->active()->create();

        $this->actingWith(Permission::LoansView);
        $this->getJson('/api/v1/loans')->assertOk();
        $this->getJson("/api/v1/loans/{$draft->loan_no}")->assertOk();
        $this->postJson('/api/v1/loans', $this->valid($customer))->assertForbidden();
        $this->patchJson("/api/v1/loans/{$draft->loan_no}", ['notes' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/loans/{$draft->loan_no}/activate")->assertForbidden();
        $this->postJson("/api/v1/loans/{$active->loan_no}/close")->assertForbidden();
        $this->postJson("/api/v1/loans/{$draft->loan_no}/cancel", ['reason' => 'Mistake'])->assertForbidden();

        // loans.update does not grant the sensitive close/cancel actions.
        $this->actingWith(Permission::LoansUpdate);
        $this->postJson("/api/v1/loans/{$active->loan_no}/close")->assertForbidden();
        $this->postJson("/api/v1/loans/{$draft->loan_no}/cancel", ['reason' => 'Mistake'])->assertForbidden();

        $this->actingWith(Permission::LoansCreate);
        $this->getJson('/api/v1/loans')->assertForbidden();
        $this->getJson("/api/v1/loans/{$draft->loan_no}")->assertForbidden();

        $this->assertSame(LoanStatus::Draft, $draft->fresh()->status);
        $this->assertSame(LoanStatus::Active, $active->fresh()->status);
    }

    public function test_guests_are_unauthenticated_and_loans_are_addressed_by_number(): void
    {
        $loan = Loan::factory()->create();
        $this->getJson('/api/v1/loans')->assertUnauthorized();

        $this->actingWith(Permission::LoansView);
        $this->getJson("/api/v1/loans/{$loan->id}")->assertNotFound();
    }

    public function test_action_hints_follow_status_and_permissions(): void
    {
        $this->actingWith(Permission::LoansView, Permission::LoansClose);
        $loan = Loan::factory()->active()->create();

        $this->getJson("/api/v1/loans/{$loan->loan_no}")
            ->assertJsonPath('data.actions', ['update' => false, 'edit_terms' => false, 'activate' => false, 'close' => true, 'cancel' => false]);
    }

    // ── list / search / filter / pagination ─────────────────────────────────────────────────

    public function test_list_is_paginated_newest_first_with_customer(): void
    {
        $this->actingWith(Permission::LoansView);
        Loan::factory()->count(3)->sequence(['start_date' => '2026-01-01'], ['start_date' => '2026-03-01'], ['start_date' => '2026-02-01'])->create();

        $this->getJson('/api/v1/loans?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.start_date', '2026-03-01')
            ->assertJsonPath('data.1.start_date', '2026-02-01')
            ->assertJsonStructure(['data' => [['customer' => ['customer_no', 'name', 'mobile']]], 'links', 'meta']);
    }

    public function test_search_and_filters(): void
    {
        $this->actingWith(Permission::LoansView);
        $karim = Customer::factory()->create(['name' => 'Karim Uddin', 'mobile' => '01811111111']);
        $first = Loan::factory()->for($karim)->active()->create(['interest_rate' => '2.0000', 'start_date' => '2026-01-15', 'next_due_date' => '2026-10-15']);
        $second = Loan::factory()->status(LoanStatus::Closed)->create(['interest_rate' => '3.5000', 'start_date' => '2026-06-01']);
        $third = Loan::factory()->status(LoanStatus::Overdue)->create(['interest_rate' => '5.0000', 'start_date' => '2026-08-01', 'next_due_date' => '2026-12-01']);

        $numbers = fn (string $query) => collect($this->getJson("/api/v1/loans?{$query}")->assertOk()->json('data'))->pluck('loan_no')->sort()->values()->all();
        $sorted = fn (Loan ...$loans) => collect($loans)->pluck('loan_no')->sort()->values()->all();

        $this->assertSame($sorted($first), $numbers('q=karim'));
        $this->assertSame($sorted($first), $numbers('q=0181111'));
        $this->assertSame($sorted($second), $numbers('q='.strtolower($second->loan_no)));
        $this->assertSame($sorted($first), $numbers("customer={$karim->customer_no}"));
        $this->assertSame($sorted($second), $numbers('status=closed'));
        $this->assertSame($sorted($first, $third), $numbers('status=open'));
        $this->assertSame($sorted($second, $third), $numbers('started_from=2026-05-01'));
        $this->assertSame($sorted($first), $numbers('started_to=2026-05-01'));
        $this->assertSame($sorted($first), $numbers('due_by=2026-11-01'));
        $this->assertSame($sorted($second, $third), $numbers('rate_min=3'));
        $this->assertSame($sorted($first, $second), $numbers('rate_max=3.5'));
    }

    public function test_overdue_filter_uses_missed_interest_periods_on_open_loans(): void
    {
        Carbon::setTestNow('2026-12-15');
        $this->actingWith(Permission::LoansView);
        $missed = Loan::factory()->active()->create();
        $paid = Loan::factory()->active()->create();
        $closed = Loan::factory()->status(LoanStatus::Closed)->create();

        foreach ([[$missed, '0.00'], [$paid, '200.00'], [$closed, '0.00']] as [$loan, $paidInterest]) {
            DB::table('interest_periods')->insert([
                'loan_id' => $loan->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'due_date' => '2026-11-30',
                'expected_interest' => '200.00', 'paid_interest' => $paidInterest, 'status' => 'due',
            ]);
        }

        $this->getJson('/api/v1/loans?overdue=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.loan_no', $missed->loan_no);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingWith(Permission::LoansView);

        $this->getJson('/api/v1/loans?status=pending&rate_min=5&rate_max=1&started_from=2026-10-10&started_to=2026-10-01&per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'rate_max', 'started_to', 'per_page']);
    }

    public function test_customer_loans_endpoint_lists_only_that_customers_loans(): void
    {
        $this->actingWith(Permission::LoansView, Permission::CustomersView);
        $customer = Customer::factory()->create();
        Loan::factory()->for($customer)->count(2)->create();
        Loan::factory()->create();

        $this->getJson("/api/v1/customers/{$customer->customer_no}/loans")->assertOk()->assertJsonPath('meta.total', 2);

        $this->actingWith(Permission::LoansView);
        $this->getJson("/api/v1/customers/{$customer->customer_no}/loans")->assertForbidden();
    }
}
