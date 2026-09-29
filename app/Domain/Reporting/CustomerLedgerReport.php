<?php

namespace App\Domain\Reporting;

use App\Models\Customer;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customer Ledger (docs/10): the customer's statement in chronological order (entry date, then posting
 * order), from the append-only ledger (App\Domain\Ledger\CustomerLedgerService).
 *
 * The running balance is computed for the selection itself — opening balance (everything dated before
 * the range, for the same loan filter) plus debits minus credits in date order — so it is correct for a
 * date range or a single loan. (The stored balance_after follows posting order, which can differ from
 * date order when the interest run catches up.) Unfiltered, the closing balance equals the ledger balance.
 */
class CustomerLedgerReport
{
    /**
     * @param  array{loan_id?: ?int, from?: ?string, to?: ?string}  $filters
     */
    public function paginate(Customer $customer, array $filters, int $perPage = 50, string $pageName = 'page'): LengthAwarePaginator
    {
        $opening = $this->opening($customer, $filters);

        $rows = DB::query()
            ->fromSub($this->entries($customer, $filters), 't')
            ->orderBy('t.entry_date')
            ->orderBy('t.id')
            ->paginate($perPage, ['*'], $pageName)
            ->withQueryString();

        return $rows->through(fn (object $row) => $this->row($row, $opening));
    }

    /**
     * Every entry of the selection (exports), same order and running balance as the pages.
     *
     * @param  array{loan_id?: ?int, from?: ?string, to?: ?string}  $filters
     * @return list<array<string, string|null>>
     */
    public function rows(Customer $customer, array $filters): array
    {
        $opening = $this->opening($customer, $filters);

        return DB::query()
            ->fromSub($this->entries($customer, $filters), 't')
            ->orderBy('t.entry_date')
            ->orderBy('t.id')
            ->get()
            ->map(fn (object $row) => $this->row($row, $opening))
            ->all();
    }

    /**
     * @return array<string, string|null>
     */
    private function row(object $row, string $opening): array
    {
        return [
            'date' => substr((string) $row->entry_date, 0, 10),
            'reference' => $row->reference,
            'loan_no' => $row->loan_no,
            'entry_type' => $row->entry_type,
            'description' => $row->description,
            'debit' => Money::of((string) $row->debit),
            'credit' => Money::of((string) $row->credit),
            'balance' => Money::add($opening, Money::of((string) $row->movement)),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{opening_balance: string, debit: string, credit: string, closing_balance: string, entries: int}
     */
    public function totals(Customer $customer, array $filters): array
    {
        $opening = $this->opening($customer, $filters);
        $sums = $this->base($customer, $filters)
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('e.entry_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('e.entry_date', '<=', $to))
            ->selectRaw('count(*) as entries, coalesce(sum(e.debit), 0) as debit, coalesce(sum(e.credit), 0) as credit')
            ->first();

        $debit = Money::of((string) $sums->debit);
        $credit = Money::of((string) $sums->credit);

        return [
            'opening_balance' => $opening,
            'debit' => $debit,
            'credit' => $credit,
            'closing_balance' => Money::sub(Money::add($opening, $debit), $credit),
            'entries' => (int) $sums->entries,
        ];
    }

    /**
     * Entries in range with the running movement (debit − credit) in date order.
     *
     * @param  array<string, mixed>  $filters
     */
    private function entries(Customer $customer, array $filters): Builder
    {
        return $this->base($customer, $filters)
            ->leftJoin('loans as l', 'l.id', '=', 'e.loan_id')
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('e.entry_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('e.entry_date', '<=', $to))
            ->select('e.id', 'e.entry_date', 'e.reference', 'e.entry_type', 'e.description', 'e.debit', 'e.credit', 'l.loan_no')
            ->selectRaw('sum(e.debit - e.credit) over (order by e.entry_date, e.id rows between unbounded preceding and current row) as movement');
    }

    /**
     * Balance brought forward: everything dated before the range (same loan filter).
     *
     * @param  array<string, mixed>  $filters
     */
    private function opening(Customer $customer, array $filters): string
    {
        if (empty($filters['from'])) {
            return '0.00';
        }

        $sum = $this->base($customer, $filters)
            ->whereDate('e.entry_date', '<', $filters['from'])
            ->selectRaw('coalesce(sum(e.debit - e.credit), 0) as balance')
            ->value('balance');

        return Money::of((string) $sum);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function base(Customer $customer, array $filters): Builder
    {
        return DB::table('ledger_entries as e')
            ->where('e.customer_id', $customer->id)
            ->when($filters['loan_id'] ?? null, fn (Builder $q, int $loanId) => $q->where('e.loan_id', $loanId));
    }
}
