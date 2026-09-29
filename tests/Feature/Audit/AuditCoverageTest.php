<?php

namespace Tests\Feature\Audit;

use App\Actions\Customers\ChangeCustomerStatus;
use App\Actions\Customers\SaveCustomer;
use App\Domain\Audit\AuditTrail;
use App\Domain\Collateral\CollateralService;
use App\Domain\Loan\LoanService;
use App\Domain\Payment\PaymentReversalService;
use App\Domain\Payment\PaymentService;
use App\Domain\Settings\LoanSettings;
use App\Enums\CustomerStatus;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\CollateralItem;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Task 019: every financial and sensitive change leaves one audit entry with actor, time, action,
 * entity + id, before/after values and the reason where one is required — written in the same
 * transaction as the change (nothing is logged for a refused change) and never holding secrets.
 */
class AuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->staff = User::factory()->create();
    }

    private function audit(string $event): AuditLog
    {
        return AuditLog::query()->where('event', $event)->sole();
    }

    private function assertEntry(AuditLog $log, object $subject, ?User $actor): void
    {
        $this->assertSame($subject::class, $log->auditable_type);
        $this->assertSame($subject->getKey(), $log->auditable_id);
        $this->assertSame($actor?->id, $log->user_id);
        $this->assertNotNull($log->created_at);
    }

    private function activeLoan(): Loan
    {
        $loan = app(LoanService::class)->create(Customer::factory()->create(), [
            'principal' => '10000', 'interest_rate' => '2', 'interest_rate_type' => 'monthly', 'interest_period_unit' => 'month', 'start_date' => '2026-10-01',
        ], $this->staff);
        CollateralItem::factory()->for($loan)->create();

        return app(LoanService::class)->activate($loan, $this->staff);
    }

    // ── loans ───────────────────────────────────────────────────────────────────────────────

    public function test_loan_creation_and_term_changes_are_audited(): void
    {
        $customer = Customer::factory()->create();
        $loan = app(LoanService::class)->create($customer, [
            'principal' => '50000', 'interest_rate' => '2.5', 'interest_rate_type' => 'monthly', 'interest_period_unit' => 'month', 'start_date' => '2026-10-01',
        ], $this->staff);

        $log = $this->audit('loan.created');
        $this->assertEntry($log, $loan, $this->staff);
        $this->assertNull($log->old_values);
        $this->assertSame([
            'loan_no' => $loan->loan_no, 'customer_no' => $customer->customer_no, 'status' => 'draft',
            'principal' => '50000.00', 'interest_rate' => '2.5000', 'interest_rate_type' => 'monthly', 'interest_period_unit' => 'month', 'start_date' => '2026-10-01',
            'interest_base' => 'outstanding', 'interest_due_timing' => 'period_end', 'yearly_rate_conversion' => 'twelfths',
        ], $log->new_values);

        app(LoanService::class)->update($loan, ['principal' => '45000', 'notes' => 'Two bangles'], $this->staff);

        $log = $this->audit('loan.updated');
        $this->assertEntry($log, $loan, $this->staff);
        $this->assertSame(['principal' => '50000.00', 'notes' => null], $log->old_values);
        $this->assertSame(['principal' => '45000.00', 'notes' => 'Two bangles'], $log->new_values);
    }

    public function test_every_loan_status_change_is_audited_with_reason_and_actor(): void
    {
        $loan = $this->activeLoan();

        $activated = AuditLog::query()->where('event', 'loan.status_changed')->sole();
        $this->assertEntry($activated, $loan, $this->staff);
        $this->assertSame(['status' => 'draft'], $activated->old_values);
        $this->assertSame(['status' => 'active', 'action' => 'activated', 'principal' => '10000.00'], $activated->new_values);

        app(LoanService::class)->cancel($loan, $this->staff, 'Customer changed their mind');

        $cancelled = AuditLog::query()->where('event', 'loan.status_changed')->latest('id')->first();
        $this->assertSame(['status' => 'active'], $cancelled->old_values);
        $this->assertSame(['status' => 'cancelled', 'action' => 'cancelled', 'reason' => 'Customer changed their mind'], $cancelled->new_values);
        $this->assertStringContainsString('Customer changed their mind', $cancelled->description);
    }

    public function test_automatic_overdue_changes_are_system_entries_even_inside_a_users_request(): void
    {
        $loan = $this->activeLoan();
        $this->actingAs($this->staff);

        Carbon::setTestNow('2026-11-02 00:05:00'); // October's interest (due 31 Oct) is now missed
        $this->artisan('loans:process-interest')->assertSuccessful();

        $log = AuditLog::query()->where('event', 'loan.status_changed')->latest('id')->first();
        $this->assertEntry($log, $loan, null);
        $this->assertSame(['status' => 'overdue', 'action' => 'marked_overdue'], $log->new_values);
    }

    public function test_a_refused_change_leaves_no_audit_entry(): void
    {
        $loan = $this->activeLoan();
        $before = AuditLog::count();

        try {
            app(LoanService::class)->close($loan, $this->staff); // principal outstanding
            $this->fail('Closing should have been refused.');
        } catch (ValidationException) {
        }

        $this->assertSame($before, AuditLog::count());
    }

    // ── payments ────────────────────────────────────────────────────────────────────────────

    public function test_payment_creation_and_reversal_are_audited(): void
    {
        $loan = $this->activeLoan();
        Carbon::setTestNow('2026-10-20 10:00:00');

        $payment = app(PaymentService::class)->post($loan->fresh(), [
            'type' => 'principal_and_interest', 'amount' => '1200', 'method' => 'cash', 'payment_date' => '2026-10-20', 'reference' => 'Counter 1',
        ], $this->staff);

        $log = $this->audit('payment.created');
        $this->assertEntry($log, $payment, $this->staff);
        $this->assertSame([
            'receipt_no' => $payment->receipt_no, 'loan_no' => $loan->loan_no, 'type' => 'principal_and_interest', 'amount' => '1200.00',
            'method' => 'cash', 'payment_date' => '2026-10-20', 'reference' => 'Counter 1',
            'interest' => '200.00', 'principal' => '1000.00', 'fee' => '0.00', 'outstanding_principal_after' => '9000.00',
        ], $log->new_values);

        $manager = User::factory()->create();
        app(PaymentReversalService::class)->reverse($payment, $manager, 'Wrong amount keyed');

        $log = $this->audit('payment.reversed');
        $this->assertEntry($log, $payment, $manager);
        $this->assertSame(['status' => 'posted'], $log->old_values);
        $this->assertSame('reversed', $log->new_values['status']);
        $this->assertSame('Wrong amount keyed', $log->new_values['reason']);
        $this->assertSame(['1200.00', '200.00', '1000.00'], [$log->new_values['amount'], $log->new_values['interest'], $log->new_values['principal']]);
        $this->assertStringContainsString('Wrong amount keyed', $log->description);
    }

    public function test_reversal_through_the_api_records_the_signed_in_user_and_request(): void
    {
        $loan = $this->activeLoan();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $payment = app(PaymentService::class)->post($loan->fresh(), ['type' => 'interest', 'amount' => '100', 'method' => 'cash', 'payment_date' => '2026-10-20'], $this->staff);

        $manager = User::factory()->create();
        $manager->givePermissionTo([Permission::PaymentsView->value, Permission::PaymentsReverse->value]);

        $this->actingAs($manager)
            ->withHeader('User-Agent', 'AuditTest/1.0')
            ->postJson("/api/v1/payments/{$payment->receipt_no}/reverse", ['reason' => 'Duplicate entry'])
            ->assertOk();

        $log = $this->audit('payment.reversed');
        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame('AuditTest/1.0', $log->user_agent);
        $this->assertNotNull($log->ip_address);
    }

    // ── collateral ──────────────────────────────────────────────────────────────────────────

    public function test_collateral_creation_correction_and_release_are_audited(): void
    {
        $loan = app(LoanService::class)->create(Customer::factory()->create(), [
            'principal' => '10000', 'interest_rate' => '2', 'interest_rate_type' => 'monthly', 'interest_period_unit' => 'month', 'start_date' => '2026-10-01',
        ], $this->staff);
        $collateral = app(CollateralService::class);

        $item = $collateral->add($loan, ['type' => 'gold', 'weight_grams' => '11.664', 'karat' => '22', 'estimated_value' => '95000', 'description' => 'Bangles'], $this->staff);
        $log = $this->audit('collateral.created');
        $this->assertEntry($log, $item, $this->staff);
        $this->assertSame(['collateral_no', 'loan_no', 'status', 'type', 'weight_grams', 'karat', 'estimated_value', 'description', 'received_at'], array_keys($log->new_values));
        $this->assertSame('11.664', $log->new_values['weight_grams']);

        app(LoanService::class)->activate($loan, $this->staff);
        $collateral->update($item, ['weight_grams' => '11.600'], $this->staff, 'Re-weighed on calibrated scale');

        $log = $this->audit('collateral.updated');
        $this->assertEntry($log, $item, $this->staff);
        $this->assertSame(['weight_grams' => '11.664'], $log->old_values);
        $this->assertSame(['weight_grams' => '11.600', 'reason' => 'Re-weighed on calibrated scale'], $log->new_values);

        app(LoanService::class)->cancel($loan->fresh(), $this->staff, 'Not proceeding');
        $collateral->release($item->fresh(), $this->staff, 'Returned to customer in person');

        $log = $this->audit('collateral.released');
        $this->assertEntry($log, $item, $this->staff);
        $this->assertSame(['status' => 'held'], $log->old_values);
        $this->assertSame('released', $log->new_values['status']);
        $this->assertSame('cancelled', $log->new_values['loan_status']);
        $this->assertSame('Returned to customer in person', $log->new_values['reason']);
    }

    // ── customers ───────────────────────────────────────────────────────────────────────────

    public function test_customer_changes_are_audited_with_the_nid_masked(): void
    {
        $this->actingAs($this->staff);
        $save = app(SaveCustomer::class);

        $customer = $save->handle(null, ['name' => 'Salma Begum', 'mobile' => '01711000000', 'nid' => '1990123456789', 'address' => 'Dhaka']);
        $log = $this->audit('customer.created');
        $this->assertEntry($log, $customer, $this->staff);
        $this->assertSame('•••••••••6789', $log->new_values['nid']);
        $this->assertSame('none', $log->new_values['photo']);
        $this->assertStringNotContainsString('1990123456789', json_encode($log->new_values));

        $save->handle($customer, ['name' => 'Salma Begum', 'mobile' => '01811000000', 'nid' => '1990123456789', 'address' => 'Dhaka']);
        $log = $this->audit('customer.updated');
        $this->assertSame(['mobile' => '01711000000'], $log->old_values); // unchanged fields are not logged
        $this->assertSame(['mobile' => '01811000000'], $log->new_values);

        $save->handle($customer, ['name' => 'Salma Begum', 'mobile' => '01811000000', 'nid' => '1990123456789', 'address' => 'Dhaka']);
        $this->assertSame(1, AuditLog::query()->where('event', 'customer.updated')->count()); // no change, no entry

        app(ChangeCustomerStatus::class)->handle($customer, CustomerStatus::Archived);
        app(ChangeCustomerStatus::class)->handle($customer, CustomerStatus::Active);
        $this->assertSame(['status' => 'archived'], $this->audit('customer.archived')->new_values);
        $this->assertSame(['status' => 'archived'], $this->audit('customer.restored')->old_values);
    }

    // ── settings, secrets, immutability ─────────────────────────────────────────────────────

    public function test_settings_changes_are_audited(): void
    {
        $this->actingAs($this->staff);
        app(LoanSettings::class)->update(['collection.grace_days' => 3]);

        $log = $this->audit('settings.loans_updated');
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame(['collection.grace_days' => 0], $log->old_values);
        $this->assertSame(['collection.grace_days' => 3], $log->new_values);
    }

    public function test_secrets_are_never_stored_and_entries_are_immutable(): void
    {
        $log = app(AuditTrail::class)->record('test.event', $this->staff, ['password' => 'old-secret'], ['password' => 'new-secret', 'remember_token' => 'abc']);

        $this->assertSame(['password' => '[changed]'], $log->old_values);
        $this->assertSame(['password' => '[changed]', 'remember_token' => '[changed]'], $log->new_values);
        $this->assertStringNotContainsString('secret', (string) AuditLog::query()->toBase()->where('id', $log->id)->value('new_values'));

        $this->expectException(LogicException::class);
        $log->update(['event' => 'tampered']);
    }

    public function test_every_audit_entry_of_a_full_loan_lifecycle_names_an_actor_or_is_a_system_action(): void
    {
        $loan = $this->activeLoan();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $payment = app(PaymentService::class)->post($loan->fresh(), ['type' => 'principal_and_interest', 'amount' => '10200', 'method' => 'cash', 'payment_date' => '2026-10-20'], $this->staff);
        app(LoanService::class)->close($loan->fresh(), $this->staff, 'Settled in full');
        app(CollateralService::class)->release($loan->collateralItems()->first(), $this->staff, 'Returned');

        $events = AuditLog::query()->orderBy('id')->pluck('event')->all();
        $this->assertSame(['loan.created', 'loan.status_changed', 'payment.created', 'loan.status_changed', 'collateral.released'], $events);
        $this->assertSame(0, AuditLog::query()->whereNull('user_id')->count());
        $this->assertSame(Payment::class, AuditLog::query()->where('event', 'payment.created')->value('auditable_type'));
        $this->assertSame('Settled in full', AuditLog::query()->where('event', 'loan.status_changed')->latest('id')->first()->new_values['note']);
    }
}
