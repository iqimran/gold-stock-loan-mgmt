<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Structural guarantees of the loan schema (docs/03-database.md, docs/08-business-rules.md).
 * CHECK constraints and decimal precision are PostgreSQL-only: php artisan test -c phpunit.pgsql.xml
 */
class LoanSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->admin()->id;
    }

    public function test_tables_have_the_documented_columns(): void
    {
        $columns = [
            'customers' => ['customer_no', 'name', 'mobile', 'nid', 'image_path', 'address', 'status', 'created_by', 'updated_by', 'created_at', 'updated_at'],
            'loans' => ['loan_no', 'customer_id', 'principal', 'outstanding_principal', 'interest_rate', 'interest_rate_type', 'interest_period_unit', 'status', 'start_date', 'next_due_date', 'closed_at', 'notes', 'created_by'],
            'collateral_items' => ['collateral_no', 'loan_id', 'type', 'weight_grams', 'karat', 'estimated_value', 'description', 'status', 'received_at', 'released_at', 'released_by', 'created_by'],
            'interest_periods' => ['loan_id', 'period_start', 'period_end', 'due_date', 'expected_interest', 'paid_interest', 'status', 'paid_at', 'waived_at', 'waived_by', 'waiver_reason'],
            'payments' => ['receipt_no', 'customer_id', 'loan_id', 'type', 'amount', 'method', 'payment_date', 'reference', 'notes', 'status', 'created_by', 'reversed_by', 'reversed_at', 'reversal_reason'],
            'payment_allocations' => ['payment_id', 'interest_period_id', 'principal_amount', 'interest_amount', 'fee_amount', 'total_amount'],
            'ledger_entries' => ['customer_id', 'loan_id', 'payment_id', 'entry_type', 'debit', 'credit', 'balance_after', 'entry_date', 'description', 'reference', 'created_by'],
            'loan_events' => ['loan_id', 'event_type', 'event_date', 'payload', 'actor_id'],
            'alerts' => ['customer_id', 'loan_id', 'interest_period_id', 'type', 'threshold', 'message', 'status', 'triggered_at', 'resolved_at'],
        ];

        foreach ($columns as $table => $expected) {
            $this->assertTrue(Schema::hasColumns($table, $expected), "[{$table}] is missing documented columns.");
        }
    }

    #[DataProvider('documentNumbers')]
    public function test_document_numbers_are_unique(string $table, string $column): void
    {
        $row = match ($table) {
            'customers' => fn () => $this->customerRow(),
            'loans' => fn () => $this->loanRow($this->customer()),
            'collateral_items' => fn () => $this->collateralRow($this->loan()),
            'payments' => fn () => $this->paymentRow($this->loan()),
        };

        DB::table($table)->insert([...$row(), $column => 'DUP-1']);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table($table)->insert([...$row(), $column => 'DUP-1']);
    }

    public static function documentNumbers(): array
    {
        return [
            'customer_no' => ['customers', 'customer_no'],
            'loan_no' => ['loans', 'loan_no'],
            'collateral_no' => ['collateral_items', 'collateral_no'],
            'receipt_no' => ['payments', 'receipt_no'],
        ];
    }

    public function test_an_interest_period_exists_once_per_loan(): void
    {
        $loanId = $this->loan();
        DB::table('interest_periods')->insert($this->periodRow($loanId));

        // Another loan may have the same dates.
        DB::table('interest_periods')->insert($this->periodRow($this->loan()));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('interest_periods')->insert($this->periodRow($loanId));
    }

    public function test_duplicate_alerts_for_the_same_loan_period_and_threshold_are_rejected(): void
    {
        $loanId = $this->loan();
        $periodId = DB::table('interest_periods')->insertGetId($this->periodRow($loanId));
        DB::table('alerts')->insert($this->alertRow($loanId, $periodId, 2));

        // A different threshold for the same period is a different condition.
        DB::table('alerts')->insert($this->alertRow($loanId, $periodId, 3));

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('alerts')->insert($this->alertRow($loanId, $periodId, 2));
    }

    public function test_diamond_collateral_may_omit_karat(): void
    {
        $id = DB::table('collateral_items')->insertGetId([...$this->collateralRow($this->loan()), 'type' => 'diamond', 'karat' => null]);

        $this->assertNull(DB::table('collateral_items')->find($id)->karat);
    }

    public function test_a_payment_may_be_allocated_across_principal_interest_and_fee(): void
    {
        $loanId = $this->loan();
        $periodId = DB::table('interest_periods')->insertGetId($this->periodRow($loanId));
        $paymentId = DB::table('payments')->insertGetId([...$this->paymentRow($loanId), 'amount' => '1600.00']);

        DB::table('payment_allocations')->insert([
            ['payment_id' => $paymentId, 'interest_period_id' => $periodId, 'principal_amount' => '0.00', 'interest_amount' => '500.00', 'fee_amount' => '100.00', 'total_amount' => '600.00'],
            ['payment_id' => $paymentId, 'interest_period_id' => null, 'principal_amount' => '1000.00', 'interest_amount' => '0.00', 'fee_amount' => '0.00', 'total_amount' => '1000.00'],
        ]);

        $this->assertSame(2, DB::table('payment_allocations')->where('payment_id', $paymentId)->count());
    }

    /**
     * Financial history cannot be removed by deleting its parent (docs/03, docs/08 "Customer deletion").
     */
    #[DataProvider('protectedParents')]
    public function test_parents_with_financial_history_cannot_be_deleted(string $parent): void
    {
        $loanId = $this->loan();
        $paymentId = DB::table('payments')->insertGetId($this->paymentRow($loanId));
        DB::table('payment_allocations')->insert(['payment_id' => $paymentId, 'principal_amount' => '100.00', 'total_amount' => '100.00']);

        $id = match ($parent) {
            'customers' => DB::table('loans')->find($loanId)->customer_id,
            'loans' => $loanId,
            'payments' => $paymentId,
        };

        $this->expectException(QueryException::class);
        DB::table($parent)->where('id', $id)->delete();
    }

    public static function protectedParents(): array
    {
        return ['customer with loans' => ['customers'], 'loan with payments' => ['loans'], 'payment with allocations' => ['payments']];
    }

    public function test_users_referenced_by_financial_records_cannot_be_deleted(): void
    {
        DB::table('customers')->insert($this->customerRow());

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $this->userId)->delete();
    }

    public function test_decimal_precision_is_preserved(): void
    {
        $this->requirePostgres();

        $loanId = DB::table('loans')->insertGetId([...$this->loanRow($this->customer()), 'principal' => '1234567890123456.78', 'interest_rate' => '2.1250']);
        $itemId = DB::table('collateral_items')->insertGetId([...$this->collateralRow($loanId), 'weight_grams' => '11.664', 'karat' => '21.60']);

        $loan = DB::table('loans')->find($loanId);
        $item = DB::table('collateral_items')->find($itemId);

        $this->assertSame('1234567890123456.78', $loan->principal);
        $this->assertSame('2.1250', $loan->interest_rate);
        $this->assertSame('11.664', $item->weight_grams);
        $this->assertSame('21.60', $item->karat);
    }

    #[DataProvider('checkViolations')]
    public function test_check_constraints_reject_invalid_financial_data(string $table, array $overrides): void
    {
        $this->requirePostgres();

        $loanId = $this->loan();
        $row = match ($table) {
            'loans' => $this->loanRow($this->customer()),
            'collateral_items' => $this->collateralRow($loanId),
            'interest_periods' => $this->periodRow($loanId),
            'payments' => $this->paymentRow($loanId),
            'payment_allocations' => ['payment_id' => DB::table('payments')->insertGetId($this->paymentRow($loanId)), 'principal_amount' => '100.00', 'total_amount' => '100.00'],
            'ledger_entries' => $this->ledgerRow($loanId),
        };

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/check constraint/i');
        DB::table($table)->insert([...$row, ...$overrides]);
    }

    public static function checkViolations(): array
    {
        return [
            'zero principal' => ['loans', ['principal' => '0.00']],
            'negative outstanding principal' => ['loans', ['outstanding_principal' => '-0.01']],
            'negative interest rate' => ['loans', ['interest_rate' => '-1.0000']],
            'zero weight' => ['collateral_items', ['weight_grams' => '0.000']],
            'zero karat' => ['collateral_items', ['karat' => '0.00']],
            'negative estimated value' => ['collateral_items', ['estimated_value' => '-1.00']],
            'release without actor' => ['collateral_items', ['released_at' => '2026-10-01 10:00:00']],
            'period ends before it starts' => ['interest_periods', ['period_end' => '2026-09-30']],
            'negative expected interest' => ['interest_periods', ['expected_interest' => '-1.00']],
            'negative paid interest' => ['interest_periods', ['paid_interest' => '-1.00']],
            'waiver without reason' => ['interest_periods', ['waived_at' => '2026-10-15 10:00:00']],
            'zero payment' => ['payments', ['amount' => '0.00']],
            'negative payment' => ['payments', ['amount' => '-5.00']],
            'reversal without reason' => ['payments', ['reversed_at' => '2026-10-02 10:00:00']],
            'negative allocation component' => ['payment_allocations', ['principal_amount' => '-10.00', 'interest_amount' => '110.00']],
            'allocation total mismatch' => ['payment_allocations', ['interest_amount' => '50.00']],
            'zero allocation' => ['payment_allocations', ['principal_amount' => '0.00', 'total_amount' => '0.00']],
            'negative ledger debit' => ['ledger_entries', ['debit' => '-1.00']],
        ];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only: php artisan test -c phpunit.pgsql.xml');
        }
    }

    private int $sequence = 0;

    private function next(string $prefix): string
    {
        return $prefix.'-'.++$this->sequence;
    }

    private function customerRow(): array
    {
        return ['customer_no' => $this->next('C'), 'name' => 'Customer', 'mobile' => '01700000000', 'status' => 'active', 'created_by' => $this->userId];
    }

    private function customer(): int
    {
        return DB::table('customers')->insertGetId($this->customerRow());
    }

    private function loanRow(int $customerId): array
    {
        return [
            'loan_no' => $this->next('L'), 'customer_id' => $customerId, 'principal' => '10000.00', 'outstanding_principal' => '10000.00',
            'interest_rate' => '2.0000', 'interest_rate_type' => 'percent', 'interest_period_unit' => 'month', 'status' => 'active',
            'start_date' => '2026-10-01', 'created_by' => $this->userId,
        ];
    }

    private function loan(): int
    {
        return DB::table('loans')->insertGetId($this->loanRow($this->customer()));
    }

    private function collateralRow(int $loanId): array
    {
        return [
            'collateral_no' => $this->next('G'), 'loan_id' => $loanId, 'type' => 'gold', 'weight_grams' => '11.664', 'karat' => '22.00',
            'estimated_value' => '90000.00', 'status' => 'held', 'received_at' => '2026-10-01 10:00:00', 'created_by' => $this->userId,
        ];
    }

    private function periodRow(int $loanId): array
    {
        return ['loan_id' => $loanId, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_date' => '2026-10-31', 'expected_interest' => '200.00', 'status' => 'pending'];
    }

    private function paymentRow(int $loanId): array
    {
        return [
            'receipt_no' => $this->next('R'), 'customer_id' => DB::table('loans')->find($loanId)->customer_id, 'loan_id' => $loanId,
            'type' => 'interest', 'amount' => '200.00', 'method' => 'cash', 'payment_date' => '2026-10-31', 'status' => 'posted', 'created_by' => $this->userId,
        ];
    }

    private function ledgerRow(int $loanId): array
    {
        return [
            'customer_id' => DB::table('loans')->find($loanId)->customer_id, 'loan_id' => $loanId, 'entry_type' => 'loan_disbursed',
            'debit' => '10000.00', 'credit' => '0.00', 'balance_after' => '10000.00', 'entry_date' => '2026-10-01',
            'description' => 'Loan disbursed', 'reference' => 'L-1', 'created_by' => $this->userId,
        ];
    }

    private function alertRow(int $loanId, int $periodId, int $threshold): array
    {
        return [
            'customer_id' => DB::table('loans')->find($loanId)->customer_id, 'loan_id' => $loanId, 'interest_period_id' => $periodId,
            'type' => 'missed_interest', 'threshold' => $threshold, 'message' => 'Missed interest periods', 'status' => 'open', 'triggered_at' => '2026-12-01 00:00:00',
        ];
    }
}
