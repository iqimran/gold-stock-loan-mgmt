<?php

namespace Tests\Feature\Loans;

use App\Domain\Loan\LoanService;
use App\Enums\LoanEventType;
use App\Enums\LoanStatus;
use App\Enums\Permission;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Business-decided loan lifecycle (see App\Domain\Loan\LoanStatusTransitions and LoanSettlement).
 */
class LoanStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-15 10:00:00');
        $this->user = User::factory()->create();
        $this->user->givePermissionTo([Permission::LoansUpdate->value, Permission::LoansClose->value, Permission::LoansCancel->value]);
        Sanctum::actingAs($this->user);
    }

    private function period(Loan $loan, string $dueDate, string $paid = '0.00', bool $waived = false): void
    {
        DB::table('interest_periods')->insert([
            'loan_id' => $loan->id, 'period_start' => Carbon::parse($dueDate)->startOfMonth()->toDateString(), 'period_end' => $dueDate,
            'due_date' => $dueDate, 'expected_interest' => '200.00', 'paid_interest' => $paid, 'status' => 'due',
            'waived_at' => $waived ? now() : null, 'waived_by' => $waived ? $this->user->id : null, 'waiver_reason' => $waived ? 'Goodwill' : null,
        ]);
    }

    private function payment(Loan $loan, bool $reversed = false): void
    {
        DB::table('payments')->insert([
            'receipt_no' => 'R-'.uniqid(), 'customer_id' => $loan->customer_id, 'loan_id' => $loan->id, 'type' => 'interest', 'amount' => '200.00',
            'method' => 'cash', 'payment_date' => '2026-11-30', 'status' => $reversed ? 'reversed' : 'posted',
            'reversed_at' => $reversed ? now() : null, 'reversed_by' => $reversed ? $this->user->id : null, 'reversal_reason' => $reversed ? 'Wrong loan' : null,
        ]);
    }

    private function settled(LoanStatus $status = LoanStatus::Active): Loan
    {
        return Loan::factory()->status($status)->create(['outstanding_principal' => '0.00']);
    }

    // ── activate ────────────────────────────────────────────────────────────────────────────

    public function test_draft_is_activated_and_outstanding_reset_to_principal(): void
    {
        // Starts this month, so no period is overdue yet (backdated activation: InterestScheduleTest).
        $loan = Loan::factory()->create(['principal' => '75000.00', 'outstanding_principal' => '75000.00', 'start_date' => '2026-12-01']);

        $this->postJson("/api/v1/loans/{$loan->loan_no}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.outstanding_principal', '75000.00')
            ->assertJsonPath('data.actions.edit_terms', false);

        $event = $loan->events()->sole();
        $this->assertSame(LoanEventType::Activated, $event->event_type);
        $this->assertEquals(['from' => 'draft', 'to' => 'active', 'principal' => '75000.00'], $event->payload);
        $this->assertSame($this->user->id, $event->actor_id);
    }

    #[DataProvider('nonDraftStatuses')]
    public function test_only_drafts_can_be_activated(LoanStatus $status): void
    {
        $loan = Loan::factory()->status($status)->create();

        $this->postJson("/api/v1/loans/{$loan->loan_no}/activate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => "A {$status->value} loan cannot be changed to active."]);

        $this->assertSame($status, $loan->fresh()->status);
        $this->assertSame(0, $loan->events()->count());
    }

    public static function nonDraftStatuses(): array
    {
        return ['active' => [LoanStatus::Active], 'overdue' => [LoanStatus::Overdue], 'closed' => [LoanStatus::Closed], 'cancelled' => [LoanStatus::Cancelled]];
    }

    // ── close ───────────────────────────────────────────────────────────────────────────────

    #[DataProvider('openStatuses')]
    public function test_settled_open_loan_is_closed(LoanStatus $status): void
    {
        $loan = $this->settled($status);
        $this->period($loan, '2026-10-31', '200.00');   // paid
        $this->period($loan, '2026-11-30', waived: true); // waived periods don't block
        $this->period($loan, '2026-12-31');             // not yet due: doesn't block

        $this->postJson("/api/v1/loans/{$loan->loan_no}/close", ['note' => 'Fully settled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_at', now()->toIso8601String());

        $this->assertEquals(['from' => $status->value, 'to' => 'closed', 'note' => 'Fully settled'], $loan->events()->sole()->payload);
    }

    public static function openStatuses(): array
    {
        return ['active' => [LoanStatus::Active], 'overdue' => [LoanStatus::Overdue]];
    }

    public function test_close_requires_zero_outstanding_principal(): void
    {
        $loan = Loan::factory()->active()->create(['outstanding_principal' => '0.01']);

        $this->postJson("/api/v1/loans/{$loan->loan_no}/close")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Outstanding principal is 0.01']);

        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    public function test_close_requires_no_unpaid_interest_due_to_date(): void
    {
        $loan = $this->settled();
        $this->period($loan, '2026-11-30', '150.00'); // partly paid
        $this->period($loan, '2026-12-15');           // due today

        $this->postJson("/api/v1/loans/{$loan->loan_no}/close")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => '2 interest period(s) due to date are unpaid or partly paid.']);

        $this->assertNull($loan->fresh()->closed_at);
    }

    #[DataProvider('notClosable')]
    public function test_drafts_and_finished_loans_cannot_be_closed(LoanStatus $status): void
    {
        $loan = $this->settled($status);

        $this->postJson("/api/v1/loans/{$loan->loan_no}/close")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => "A {$status->value} loan cannot be changed to closed."]);
    }

    public static function notClosable(): array
    {
        return ['draft' => [LoanStatus::Draft], 'closed' => [LoanStatus::Closed], 'cancelled' => [LoanStatus::Cancelled]];
    }

    // ── cancel ──────────────────────────────────────────────────────────────────────────────

    #[DataProvider('cancellable')]
    public function test_draft_or_open_loan_without_payments_is_cancelled_with_a_reason(LoanStatus $status): void
    {
        $loan = Loan::factory()->status($status)->create();
        $this->payment($loan, reversed: true); // reversed payments don't count

        $this->postJson("/api/v1/loans/{$loan->loan_no}/cancel", ['reason' => 'Customer changed their mind'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.actions.cancel', false);

        $this->assertEquals(
            ['from' => $status->value, 'to' => 'cancelled', 'reason' => 'Customer changed their mind'],
            $loan->events()->sole()->payload,
        );
    }

    public static function cancellable(): array
    {
        return ['draft' => [LoanStatus::Draft], 'active' => [LoanStatus::Active], 'overdue' => [LoanStatus::Overdue]];
    }

    public function test_open_loan_with_posted_payments_cannot_be_cancelled(): void
    {
        $loan = Loan::factory()->active()->create();
        $this->payment($loan);

        $this->postJson("/api/v1/loans/{$loan->loan_no}/cancel", ['reason' => 'Mistake'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'settle and close it instead']);

        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }

    public function test_cancel_requires_a_reason(): void
    {
        $loan = Loan::factory()->create();

        $this->postJson("/api/v1/loans/{$loan->loan_no}/cancel", ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame(LoanStatus::Draft, $loan->fresh()->status);
    }

    #[DataProvider('terminal')]
    public function test_closed_and_cancelled_loans_cannot_change_status(LoanStatus $status): void
    {
        $loan = $this->settled($status);

        foreach (['activate' => [], 'close' => [], 'cancel' => ['reason' => 'Again']] as $action => $body) {
            $this->postJson("/api/v1/loans/{$loan->loan_no}/{$action}", $body)->assertUnprocessable()->assertJsonValidationErrors('status');
        }

        $this->assertSame($status, $loan->fresh()->status);
    }

    public static function terminal(): array
    {
        return ['closed' => [LoanStatus::Closed], 'cancelled' => [LoanStatus::Cancelled]];
    }

    // ── system transitions (scheduler) ──────────────────────────────────────────────────────

    public function test_overdue_is_a_system_transition_between_active_and_overdue_only(): void
    {
        $service = app(LoanService::class);
        $loan = Loan::factory()->active()->create();

        $service->markOverdue($loan);
        $this->assertSame(LoanStatus::Overdue, $loan->status);

        $service->clearOverdue($loan);
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);

        $this->assertSame(
            [LoanEventType::MarkedOverdue, LoanEventType::OverdueCleared],
            $loan->events()->orderBy('id')->pluck('event_type')->all(),
        );
        $this->assertNull($loan->events()->first()->actor_id);

        $this->expectException(ValidationException::class);
        $service->markOverdue(Loan::factory()->create()); // a draft cannot become overdue
    }
}
