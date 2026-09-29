<?php

namespace App\Domain\Reporting\Export;

use App\Domain\Customer\CustomerSearch;
use App\Domain\Interest\InterestPeriodStatusResolver;
use App\Domain\Loan\LoanSearch;
use App\Domain\Payment\PaymentSearch;
use App\Domain\Reporting\CollateralReport;
use App\Domain\Reporting\CollectionReport;
use App\Domain\Reporting\CustomerLedgerReport;
use App\Domain\Reporting\DueReport;
use App\Domain\Reporting\LoanOutstandingReport;
use App\Domain\Settings\LoanSettings;
use App\Enums\PaymentStatus;
use App\Models\CollateralItem;
use App\Models\Customer;
use App\Models\InterestPeriod;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds each exportable dataset from the SAME report/search query the screens and report endpoints use
 * (so an export always matches what the user sees for the same filters), with the report's own totals.
 * Filters arrive already validated (ReportFilters, or the list screens' search requests).
 */
class ExportDatasets
{
    private const C = ExportColumn::class;

    public function __construct(
        private readonly CollectionReport $collections,
        private readonly DueReport $due,
        private readonly CustomerLedgerReport $ledger,
        private readonly LoanOutstandingReport $outstanding,
        private readonly CollateralReport $collateral,
        private readonly LoanSearch $loans,
        private readonly PaymentSearch $payments,
        private readonly CustomerSearch $customers,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function collections(array $filters): ExportDataset
    {
        $query = $this->collections->query($filters);
        $this->guard($query);
        $totals = $this->collections->totals($filters);

        $summary = [['label' => 'Gross collected', 'values' => ['amount' => $totals['gross']]]];
        foreach ($totals['by_type'] as $type => $group) {
            $summary[] = ['label' => 'Type: '.$this->label($type)." ({$group['payments']})", 'values' => ['amount' => $group['amount']]];
        }
        foreach ($totals['by_method'] as $method => $group) {
            $summary[] = ['label' => 'Method: '.$this->label($method)." ({$group['payments']})", 'values' => ['amount' => $group['amount']]];
        }
        foreach (['interest' => 'Interest', 'principal' => 'Principal', 'fees' => 'Fees', 'revenue' => 'Revenue (interest + fees)'] as $key => $label) {
            $summary[] = ['label' => $label, 'values' => ['amount' => $totals[$key]]];
        }

        return new ExportDataset(
            'Collection Report',
            $this->describe($filters, [
                'paid_from' => 'Paid from', 'paid_to' => 'Paid to', 'method' => 'Method', 'type' => 'Type',
                'customer' => 'Customer', 'loan' => 'Loan', 'staff' => 'Staff',
            ]) + ['Payments' => "{$totals['payments']} posted (reversed payments excluded)"],
            [
                new ExportColumn('payment_date', 'Date', self::C::DATE),
                new ExportColumn('receipt_no', 'Receipt'),
                new ExportColumn('customer', 'Customer'),
                new ExportColumn('loan_no', 'Loan'),
                new ExportColumn('type', 'Payment type'),
                new ExportColumn('method', 'Method'),
                new ExportColumn('amount', 'Amount', self::C::MONEY),
                new ExportColumn('staff', 'Staff'),
            ],
            $query->get()->map(fn (Payment $p) => [
                'payment_date' => $p->payment_date->toDateString(),
                'receipt_no' => $p->receipt_no,
                'customer' => $p->customer?->name,
                'loan_no' => $p->loan?->loan_no,
                'type' => $this->label($p->type->value),
                'method' => $this->label($p->method),
                'amount' => $p->amount,
                'staff' => $p->creator?->name,
            ])->all(),
            $summary,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function due(array $filters): ExportDataset
    {
        $query = $this->due->query($filters);
        $this->guard($query);
        $totals = $this->due->totals($filters);

        return new ExportDataset(
            'Due Report',
            $this->describe($filters, ['status' => 'Status', 'due_from' => 'Due from', 'due_to' => 'Due to', 'customer' => 'Customer', 'loan' => 'Loan', 'min_missed' => 'Missed at least'])
                + ['As of' => today()->toDateString()],
            [
                new ExportColumn('customer', 'Customer'),
                new ExportColumn('mobile', 'Mobile'),
                new ExportColumn('loan_no', 'Loan'),
                new ExportColumn('expected', 'Expected interest', self::C::MONEY),
                new ExportColumn('paid', 'Paid interest', self::C::MONEY),
                new ExportColumn('balance', 'Balance due', self::C::MONEY),
                new ExportColumn('due_date', 'Due date', self::C::DATE),
                new ExportColumn('missed', 'Missed count', self::C::INTEGER),
                new ExportColumn('status', 'Status'),
            ],
            $query->get()->map(fn (InterestPeriod $p) => [
                'customer' => $p->loan->customer->name,
                'mobile' => $p->loan->customer->mobile,
                'loan_no' => $p->loan->loan_no,
                'expected' => $p->expected_interest,
                'paid' => $p->paid_interest,
                'balance' => Money::sub($p->expected_interest, $p->paid_interest),
                'due_date' => $p->due_date->toDateString(),
                'missed' => (int) $p->getAttribute('consecutive_missed'),
                'status' => $this->label(InterestPeriodStatusResolver::resolve(
                    $p->expected_interest, $p->paid_interest, $p->waived_at !== null, $p->due_date->toDateString(), today()->toDateString(), app(LoanSettings::class)->missedCutoff(),
                )->value),
            ])->all(),
            [['label' => "Totals ({$totals['periods']} periods, {$totals['loans']} loans)", 'values' => [
                'expected' => $totals['expected_interest'], 'paid' => $totals['paid_interest'], 'balance' => $totals['balance_due'],
            ]]],
        );
    }

    /**
     * @param  array{customer: Customer, loan: ?Loan, loan_id: ?int, from: ?string, to: ?string}  $ledger
     */
    public function customerLedger(array $ledger): ExportDataset
    {
        $customer = $ledger['customer'];
        $filters = ['loan_id' => $ledger['loan_id'], 'from' => $ledger['from'], 'to' => $ledger['to']];
        $totals = $this->ledger->totals($customer, $filters);
        $this->guardCount($totals['entries']);

        return new ExportDataset(
            "Customer Ledger - {$customer->name}",
            array_filter([
                'Customer' => "{$customer->name} ({$customer->customer_no}) · {$customer->mobile}",
                'Loan' => $ledger['loan']?->loan_no ?? 'All loans',
                'From' => $ledger['from'],
                'To' => $ledger['to'],
            ]),
            [
                new ExportColumn('date', 'Date', self::C::DATE),
                new ExportColumn('reference', 'Reference'),
                new ExportColumn('loan_no', 'Loan'),
                new ExportColumn('description', 'Description'),
                new ExportColumn('debit', 'Debit', self::C::MONEY),
                new ExportColumn('credit', 'Credit', self::C::MONEY),
                new ExportColumn('balance', 'Balance', self::C::MONEY),
            ],
            $this->ledger->rows($customer, $filters),
            [
                ['label' => 'Opening balance', 'values' => ['balance' => $totals['opening_balance']]],
                ['label' => "Totals ({$totals['entries']} entries)", 'values' => ['debit' => $totals['debit'], 'credit' => $totals['credit']]],
                ['label' => 'Closing balance', 'values' => ['balance' => $totals['closing_balance']]],
            ],
            'portrait',
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function loanOutstanding(array $filters): ExportDataset
    {
        $query = $this->outstanding->query($filters);
        $this->guard($query);
        $totals = $this->outstanding->totals($filters);

        return new ExportDataset(
            'Loan Outstanding Report',
            $this->describe(['status' => $filters['status'] ?? 'open'] + $filters, ['status' => 'Status', 'q' => 'Search', 'customer' => 'Customer', 'due_by' => 'Due by'])
                + array_filter(['Missed interest only' => ($filters['overdue'] ?? false) ? 'Yes' : null, 'As of' => today()->toDateString()]),
            [
                new ExportColumn('loan_no', 'Loan'),
                new ExportColumn('customer', 'Customer'),
                new ExportColumn('principal', 'Principal', self::C::MONEY),
                new ExportColumn('outstanding_principal', 'Outstanding principal', self::C::MONEY),
                new ExportColumn('due_interest', 'Due interest', self::C::MONEY),
                new ExportColumn('exposure', 'Total exposure', self::C::MONEY),
                new ExportColumn('next_due_date', 'Next due', self::C::DATE),
                new ExportColumn('status', 'Status'),
            ],
            $query->get()->map(fn (Loan $loan) => ['customer' => $loan->customer->name, 'status' => $this->label($loan->status->value)] + LoanOutstandingReport::row($loan))->all(),
            [['label' => "Totals ({$totals['loans']} loans)", 'values' => [
                'principal' => $totals['principal'], 'outstanding_principal' => $totals['outstanding_principal'],
                'due_interest' => $totals['due_interest'], 'exposure' => $totals['exposure'],
            ]]],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function collateral(array $filters): ExportDataset
    {
        $query = $this->collateral->query($filters);
        $this->guard($query);
        $totals = $this->collateral->totals($filters);

        return new ExportDataset(
            'Collateral Report',
            $this->describe($filters, [
                'q' => 'Search', 'loan' => 'Loan', 'customer' => 'Customer', 'type' => 'Type', 'status' => 'Status',
                'received_from' => 'Received from', 'received_to' => 'Received to', 'released_from' => 'Released from', 'released_to' => 'Released to',
            ]),
            [
                new ExportColumn('collateral_no', 'Collateral no'),
                new ExportColumn('loan_no', 'Loan'),
                new ExportColumn('customer', 'Customer'),
                new ExportColumn('type', 'Type'),
                new ExportColumn('weight_grams', 'Weight (g)', self::C::WEIGHT),
                new ExportColumn('karat', 'Karat', self::C::KARAT),
                new ExportColumn('estimated_value', 'Estimated value', self::C::MONEY),
                new ExportColumn('status', 'Status'),
                new ExportColumn('received', 'Received', self::C::DATE),
                new ExportColumn('released', 'Released', self::C::DATE),
            ],
            $query->get()->map(fn (CollateralItem $item) => [
                'collateral_no' => $item->collateral_no,
                'loan_no' => $item->loan->loan_no,
                'customer' => $item->loan->customer?->name,
                'type' => $this->label($item->type),
                'weight_grams' => $item->weight_grams,
                'karat' => $item->karat,
                'estimated_value' => $item->estimated_value,
                'status' => $this->label($item->status->value),
                'received' => $item->received_at->toDateString(),
                'released' => $item->released_at?->toDateString(),
            ])->all(),
            [
                ['label' => "Held ({$totals['held']['items']} items)", 'values' => ['weight_grams' => $totals['held']['weight_grams'], 'estimated_value' => $totals['held']['estimated_value']]],
                ['label' => "Released ({$totals['released']['items']} items)", 'values' => ['weight_grams' => $totals['released']['weight_grams'], 'estimated_value' => $totals['released']['estimated_value']]],
                ['label' => "Total ({$totals['items']} items)", 'values' => ['weight_grams' => $totals['weight_grams'], 'estimated_value' => $totals['estimated_value']]],
            ],
        );
    }

    /**
     * The Loans list (LoanSearchRequest filters).
     *
     * @param  array<string, mixed>  $filters
     */
    public function loans(array $filters): ExportDataset
    {
        $query = $this->loans->query($filters);
        $this->guard($query);
        $sums = $this->sums($query, ['principal' => 'loans.principal', 'outstanding_principal' => 'loans.outstanding_principal']);

        return new ExportDataset(
            'Loans',
            $this->describe($filters, ['q' => 'Search', 'customer' => 'Customer', 'status' => 'Status', 'started_from' => 'Started from', 'started_to' => 'Started to', 'due_by' => 'Due by', 'rate_min' => 'Rate from', 'rate_max' => 'Rate to'])
                + array_filter(['Missed interest only' => ($filters['overdue'] ?? false) ? 'Yes' : null]),
            [
                new ExportColumn('loan_no', 'Loan no'),
                new ExportColumn('customer', 'Customer'),
                new ExportColumn('customer_no', 'Customer no'),
                new ExportColumn('principal', 'Principal', self::C::MONEY),
                new ExportColumn('outstanding_principal', 'Outstanding', self::C::MONEY),
                new ExportColumn('interest_rate', 'Rate %', self::C::RATE),
                new ExportColumn('rate_type', 'Rate type'),
                new ExportColumn('status', 'Status'),
                new ExportColumn('start_date', 'Start', self::C::DATE),
                new ExportColumn('next_due_date', 'Next due', self::C::DATE),
            ],
            $query->get()->map(fn (Loan $loan) => [
                'loan_no' => $loan->loan_no,
                'customer' => $loan->customer->name,
                'customer_no' => $loan->customer->customer_no,
                'principal' => $loan->principal,
                'outstanding_principal' => $loan->outstanding_principal,
                'interest_rate' => $loan->interest_rate,
                'rate_type' => $this->label($loan->interest_rate_type->value),
                'status' => $this->label($loan->status->value),
                'start_date' => $loan->start_date->toDateString(),
                'next_due_date' => $loan->next_due_date?->toDateString(),
            ])->all(),
            [['label' => "Totals ({$sums['count']} loans)", 'values' => ['principal' => $sums['principal'], 'outstanding_principal' => $sums['outstanding_principal']]]],
        );
    }

    /**
     * The Payments list (PaymentSearchRequest filters; reversed payments are listed and totalled apart).
     *
     * @param  array<string, mixed>  $filters
     */
    public function payments(array $filters): ExportDataset
    {
        $query = $this->payments->query($filters);
        $this->guard($query);
        $posted = $this->sums((clone $query)->where('payments.status', PaymentStatus::Posted), ['amount' => 'payments.amount']);
        $reversed = $this->sums((clone $query)->where('payments.status', PaymentStatus::Reversed), ['amount' => 'payments.amount']);

        return new ExportDataset(
            'Payments',
            $this->describe($filters, ['q' => 'Search', 'type' => 'Type', 'method' => 'Method', 'status' => 'Status', 'paid_from' => 'Paid from', 'paid_to' => 'Paid to', 'loan' => 'Loan', 'customer' => 'Customer']),
            [
                new ExportColumn('receipt_no', 'Receipt'),
                new ExportColumn('payment_date', 'Date', self::C::DATE),
                new ExportColumn('customer', 'Customer'),
                new ExportColumn('loan_no', 'Loan'),
                new ExportColumn('type', 'Type'),
                new ExportColumn('method', 'Method'),
                new ExportColumn('amount', 'Amount', self::C::MONEY),
                new ExportColumn('status', 'Status'),
                new ExportColumn('reference', 'Reference'),
            ],
            $query->get()->map(fn (Payment $p) => [
                'receipt_no' => $p->receipt_no,
                'payment_date' => $p->payment_date->toDateString(),
                'customer' => $p->customer?->name,
                'loan_no' => $p->loan?->loan_no,
                'type' => $this->label($p->type->value),
                'method' => $this->label($p->method),
                'amount' => $p->amount,
                'status' => $this->label($p->status->value),
                'reference' => $p->reference,
            ])->all(),
            [
                ['label' => "Posted ({$posted['count']})", 'values' => ['amount' => $posted['amount']]],
                ['label' => "Reversed ({$reversed['count']}, not collected)", 'values' => ['amount' => $reversed['amount']]],
            ],
        );
    }

    /**
     * The Customers list (CustomerSearchRequest filters), with the server-derived summary figures.
     *
     * @param  array<string, mixed>  $filters
     */
    public function customers(array $filters): ExportDataset
    {
        $query = $this->customers->query($filters);
        $this->guard($query);
        $rows = $query->get();

        return new ExportDataset(
            'Customers',
            $this->describe($filters, ['q' => 'Search', 'status' => 'Status', 'registered_from' => 'Registered from', 'registered_to' => 'Registered to', 'min_missed' => 'Missed at least'])
                + array_filter(['Overdue only' => ($filters['overdue'] ?? false) ? 'Yes' : null, 'As of' => today()->toDateString()]),
            [
                new ExportColumn('customer_no', 'Customer no'),
                new ExportColumn('name', 'Name'),
                new ExportColumn('mobile', 'Mobile'),
                new ExportColumn('nid', 'NID'),
                new ExportColumn('status', 'Status'),
                new ExportColumn('active_loans', 'Active loans', self::C::INTEGER),
                new ExportColumn('interest_due', 'Interest due', self::C::MONEY),
                new ExportColumn('missed', 'Consecutive missed', self::C::INTEGER),
                new ExportColumn('last_payment', 'Last payment', self::C::DATE),
                new ExportColumn('next_due', 'Next due', self::C::DATE),
            ],
            $rows->map(fn (Customer $c) => [
                'customer_no' => $c->customer_no,
                'name' => $c->name,
                'mobile' => $c->mobile,
                'nid' => $c->nid,
                'status' => $this->label($c->status->value),
                'active_loans' => $c->active_loans_count,
                'interest_due' => $c->total_interest_due,
                'missed' => $c->consecutive_missed ?? 0,
                'last_payment' => $c->last_payment_date?->toDateString(),
                'next_due' => $c->next_due_date?->toDateString(),
            ])->all(),
            [['label' => "Totals ({$rows->count()} customers)", 'values' => [
                'active_loans' => $rows->sum('active_loans_count'),
                'interest_due' => $rows->reduce(fn (string $sum, Customer $c) => Money::add($sum, $c->total_interest_due), '0.00'),
            ]]],
        );
    }

    /**
     * Exports run within the request: refuse before loading anything above the configured row limit.
     */
    private function guard(Builder $query): void
    {
        $this->guardCount((clone $query)->reorder()->count());
    }

    private function guardCount(int $rows): void
    {
        $max = (int) config('loans.exports.max_rows', 5000);

        if ($rows > $max) {
            throw ValidationException::withMessages([
                'export' => "This export would contain {$rows} rows; the limit is {$max}. Narrow the filters (e.g. a shorter date range) and try again.",
            ]);
        }
    }

    /**
     * @param  array<string, string>  $columns  key => SQL column
     * @return array<string, mixed>
     */
    private function sums(Builder $query, array $columns): array
    {
        $aggregate = DB::query()->fromSub((clone $query)->reorder()->toBase(), 't')->selectRaw('count(*) as count');

        foreach ($columns as $key => $column) {
            $aggregate->selectRaw('coalesce(sum(t.'.str_replace(['loans.', 'payments.'], '', $column).'), 0) as '.$key);
        }

        $row = $aggregate->first();
        $sums = ['count' => (int) $row->count];

        foreach (array_keys($columns) as $key) {
            $sums[$key] = Money::of((string) $row->{$key});
        }

        return $sums;
    }

    /**
     * Human-readable filter lines: only the filters actually applied, with names instead of ids.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $labels  filter key => label
     * @return array<string, string>
     */
    private function describe(array $filters, array $labels): array
    {
        $lines = [];

        foreach ($labels as $key => $label) {
            $value = $filters[$key] ?? null;

            if ($value === null || $value === '' || $value === false) {
                continue;
            }

            $lines[$label] = match ($key) {
                'staff' => User::query()->whereKey($value)->value('name') ?? (string) $value,
                'customer' => ($name = Customer::where('customer_no', $value)->value('name')) ? "{$name} ({$value})" : (string) $value,
                'status', 'type', 'method' => $this->label((string) $value),
                default => (string) $value,
            };
        }

        return $lines ?: ['Filters' => 'None (all records)'];
    }

    private function label(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
