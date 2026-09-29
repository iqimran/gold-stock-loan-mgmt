<?php

namespace Tests\Feature\Loans;

use App\Enums\CollateralStatus;
use App\Enums\LoanStatus;
use App\Enums\Permission;
use App\Models\CollateralItem;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Loan detail screen (Inertia). Business rules are covered by the API and domain tests; these check
 * what the screen receives and that its actions go through the same rules.
 */
class LoanPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-15 10:00:00');
    }

    private function userWith(Permission ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (Permission $permission) => $permission->value, $permissions));

        return $user;
    }

    /**
     * An active loan with three periods (paid, partly paid overdue, upcoming) and two payments (one reversed).
     */
    private function loanWithHistory(): Loan
    {
        $loan = Loan::factory()->active()->create([
            'principal' => '10000.00', 'outstanding_principal' => '8000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-10-01', 'next_due_date' => '2026-12-31',
        ]);

        foreach ([['2026-10-01', '2026-10-31', '200.00'], ['2026-11-01', '2026-11-30', '50.00'], ['2026-12-01', '2026-12-31', '0.00']] as [$start, $end, $paid]) {
            DB::table('interest_periods')->insert([
                'loan_id' => $loan->id, 'period_start' => $start, 'period_end' => $end, 'due_date' => $end,
                'expected_interest' => '200.00', 'paid_interest' => $paid, 'status' => 'upcoming',
            ]);
        }

        $actor = User::factory()->create();
        foreach ([['R-1', '2026-10-31', '2200.00', false], ['R-2', '2026-11-20', '500.00', true]] as [$receipt, $date, $amount, $reversed]) {
            $id = DB::table('payments')->insertGetId([
                'receipt_no' => $receipt, 'customer_id' => $loan->customer_id, 'loan_id' => $loan->id, 'type' => 'interest',
                'amount' => $amount, 'method' => 'cash', 'payment_date' => $date, 'status' => $reversed ? 'reversed' : 'posted',
                'reversed_at' => $reversed ? now() : null, 'reversed_by' => $reversed ? $actor->id : null, 'reversal_reason' => $reversed ? 'Duplicate' : null,
            ]);
            DB::table('payment_allocations')->insert([
                'payment_id' => $id, 'principal_amount' => $reversed ? '500.00' : '2000.00', 'interest_amount' => $reversed ? '0.00' : '200.00', 'total_amount' => $amount,
            ]);
        }

        CollateralItem::factory()->for($loan)->create(['weight_grams' => '10.500', 'estimated_value' => '80000.00']);
        CollateralItem::factory()->for($loan)->create(['weight_grams' => '2.250', 'estimated_value' => '15000.50']);

        return $loan;
    }

    public function test_detail_page_receives_server_calculated_figures(): void
    {
        $loan = $this->loanWithHistory();

        $this->actingAs($this->userWith(Permission::LoansView, Permission::CustomersView, Permission::CollateralView, Permission::PaymentsView))
            ->get("/loans/{$loan->loan_no}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loans/show')
                ->where('loan.loan_no', $loan->loan_no)
                ->where('loan.status', 'active')
                ->where('customer.customer_no', $loan->customer->customer_no)
                ->where('summary.principal', '10000.00')
                ->where('summary.outstanding_principal', '8000.00')
                ->where('summary.principal_repaid', '2000.00')      // reversed payment ignored
                ->where('summary.interest_charged', '400.00')       // Oct + Nov (Dec not due yet)
                ->where('summary.interest_paid', '250.00')
                ->where('summary.interest_due', '150.00')
                ->where('summary.payments_total', '2200.00')
                ->where('summary.payments_count', 1)
                ->where('summary.overdue_periods', 1)
                ->where('summary.consecutive_missed', 1)
                ->where('summary.last_payment', ['date' => '2026-10-31', 'amount' => '2200.00'])
                ->where('summary.periods.0.status', 'paid')
                ->where('summary.periods.1.status', 'overdue')      // resolved live, not the stale stored status
                ->where('summary.periods.2.status', 'upcoming')
                ->where('collateral.held_count', 2)
                ->where('collateral.held_weight_grams', '12.750')
                ->where('collateral.held_estimated_value', '95000.50')
                ->has('collateral.items', 2)
                ->has('payments', 2)
                ->where('payments.0.receipt_no', 'R-2')             // newest first, reversed ones stay listed
                ->where('payments.0.reversed', true)
                ->where('payments.1.receipt_no', 'R-1')
                ->where('collateralTypes', ['gold', 'diamond', 'mixed', 'other']));
    }

    public function test_tab_data_is_sent_only_to_users_who_may_see_it(): void
    {
        $loan = $this->loanWithHistory();

        $this->actingAs($this->userWith(Permission::LoansView))
            ->get("/loans/{$loan->loan_no}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('customer', null)
                ->where('collateral', null)
                ->where('payments', null)
                ->where('loan.customer.name', $loan->customer->name)
                ->where('loan.actions', ['update' => false, 'edit_terms' => false, 'activate' => false, 'close' => false, 'cancel' => false]));

        $this->actingAs($this->userWith(Permission::CollateralView, Permission::PaymentsView))->get("/loans/{$loan->loan_no}")->assertForbidden();
    }

    public function test_loan_actions_redirect_back_with_a_message_or_the_rule_that_blocked_them(): void
    {
        $user = $this->userWith(Permission::LoansView, Permission::LoansUpdate, Permission::LoansClose, Permission::LoansCancel);
        $draft = Loan::factory()->create(['start_date' => '2026-12-01']);

        $this->actingAs($user)->from("/loans/{$draft->loan_no}")->post("/loans/{$draft->loan_no}/activate")
            ->assertRedirect("/loans/{$draft->loan_no}")
            ->assertSessionHas('success', "Loan {$draft->loan_no} activated.");
        $this->assertSame(LoanStatus::Active, $draft->fresh()->status);

        // Closing with principal outstanding is refused by the settlement rule.
        $this->actingAs($user)->from("/loans/{$draft->loan_no}")->post("/loans/{$draft->loan_no}/close")
            ->assertRedirect("/loans/{$draft->loan_no}")
            ->assertSessionHasErrors('status');

        $this->actingAs($user)->from("/loans/{$draft->loan_no}")->post("/loans/{$draft->loan_no}/cancel", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($user)->from("/loans/{$draft->loan_no}")->post("/loans/{$draft->loan_no}/cancel", ['reason' => 'Entered by mistake'])
            ->assertSessionHas('success');
        $this->assertSame(LoanStatus::Cancelled, $draft->fresh()->status);
    }

    public function test_collateral_actions_from_the_loan_screen(): void
    {
        $user = $this->userWith(Permission::LoansView, Permission::CollateralCreate, Permission::CollateralUpdate, Permission::CollateralRelease);
        $loan = Loan::factory()->active()->create();
        $page = "/loans/{$loan->loan_no}";

        $this->actingAs($user)->from($page)->post("/loans/{$loan->loan_no}/collateral", ['type' => 'gold', 'weight_grams' => '0', 'estimated_value' => '100'])
            ->assertRedirect($page)
            ->assertSessionHasErrors('weight_grams');

        $this->actingAs($user)->from($page)->post("/loans/{$loan->loan_no}/collateral", ['type' => 'gold', 'weight_grams' => '5.5', 'karat' => '', 'estimated_value' => '40000'])
            ->assertRedirect($page)
            ->assertSessionHas('success');
        $item = CollateralItem::sole();
        $this->assertNull($item->karat);

        // Active loan: a correction needs a reason.
        $this->actingAs($user)->from($page)->patch("/collateral/{$item->collateral_no}", ['type' => 'gold', 'weight_grams' => '5.6', 'estimated_value' => '40000', 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($user)->from($page)->patch("/collateral/{$item->collateral_no}", ['type' => 'gold', 'weight_grams' => '5.6', 'estimated_value' => '40000', 'reason' => 'Re-weighed'])
            ->assertSessionHas('success');
        $this->assertSame('5.600', $item->fresh()->weight_grams);

        // Release only after the loan is closed or cancelled.
        $this->actingAs($user)->from($page)->post("/collateral/{$item->collateral_no}/release", ['reason' => 'Returned'])
            ->assertSessionHasErrors('status');
        $loan->update(['status' => LoanStatus::Closed]);
        $this->actingAs($user)->from($page)->post("/collateral/{$item->collateral_no}/release", ['reason' => 'Returned'])
            ->assertSessionHas('success');
        $this->assertSame(CollateralStatus::Released, $item->fresh()->status);
    }

    public function test_actions_require_their_permissions(): void
    {
        $viewer = $this->userWith(Permission::LoansView, Permission::CollateralView);
        $loan = Loan::factory()->create();
        $item = CollateralItem::factory()->for($loan)->create();

        $this->actingAs($viewer)->post("/loans/{$loan->loan_no}/activate")->assertForbidden();
        $this->actingAs($viewer)->post("/loans/{$loan->loan_no}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->post("/loans/{$loan->loan_no}/collateral", ['type' => 'gold'])->assertForbidden();
        $this->actingAs($viewer)->patch("/collateral/{$item->collateral_no}", ['weight_grams' => '1'])->assertForbidden();
        $this->actingAs($viewer)->post("/collateral/{$item->collateral_no}/release", ['reason' => 'x'])->assertForbidden();
    }

    public function test_customer_page_links_its_loans_to_the_loan_screen(): void
    {
        $loan = Loan::factory()->active()->create();

        $this->actingAs($this->userWith(Permission::CustomersView, Permission::LoansView))
            ->get("/customers/{$loan->customer->customer_no}")
            ->assertInertia(fn (Assert $page) => $page->where('activeLoans.0.loan_no', $loan->loan_no));

        $this->actingAs($this->userWith(Permission::LoansView))->get(route('loans.show', $loan))->assertOk();
    }
}
