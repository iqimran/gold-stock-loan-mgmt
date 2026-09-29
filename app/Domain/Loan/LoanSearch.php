<?php

namespace App\Domain\Loan;

use App\Enums\LoanStatus;
use App\Models\Loan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Loan list/search. A search term matches the loan number, or the customer's number, name or mobile,
 * case-insensitively. "Overdue" uses the same missed-period rule as the customer summary: a period due
 * before today, not waived and not fully paid.
 */
class LoanSearch
{
    /**
     * @param  array{q?: ?string, customer?: ?string, status?: ?string, started_from?: ?string, started_to?: ?string, due_by?: ?string, overdue?: bool, rate_min?: ?string, rate_max?: ?string}  $filters
     *                                                                                                                                                                                                     status: a LoanStatus value, or 'open' (active + overdue)
     * @return Builder<Loan>
     */
    public function query(array $filters): Builder
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? null;

        return Loan::query()
            ->with('customer:id,customer_no,name,mobile,status')
            ->when($term !== '', function (Builder $query) use ($term) {
                $contains = '%'.$this->escapeLike(mb_strtolower($term)).'%';

                $query->where(fn (Builder $match) => $match
                    ->whereRaw("LOWER(loans.loan_no) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereHas('customer', fn (Builder $customer) => $customer
                        ->whereRaw("LOWER(customers.customer_no) LIKE ? ESCAPE '!'", [$contains])
                        ->orWhereRaw("LOWER(customers.name) LIKE ? ESCAPE '!'", [$contains])
                        ->orWhereRaw("LOWER(customers.mobile) LIKE ? ESCAPE '!'", [$contains])));
            })
            ->when($filters['customer'] ?? null, fn (Builder $q, string $customerNo) => $q->whereHas('customer', fn (Builder $c) => $c->where('customer_no', $customerNo)))
            ->when($status === 'open', fn (Builder $q) => $q->whereIn('loans.status', LoanStatus::open()))
            ->when($status !== null && $status !== 'open', fn (Builder $q) => $q->where('loans.status', $status))
            ->when($filters['started_from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('loans.start_date', '>=', $from))
            ->when($filters['started_to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('loans.start_date', '<=', $to))
            ->when($filters['due_by'] ?? null, fn (Builder $q, string $date) => $q->whereNotNull('loans.next_due_date')->whereDate('loans.next_due_date', '<=', $date))
            ->when($filters['rate_min'] ?? null, fn (Builder $q, string $rate) => $q->where('loans.interest_rate', '>=', $rate))
            ->when($filters['rate_max'] ?? null, fn (Builder $q, string $rate) => $q->where('loans.interest_rate', '<=', $rate))
            ->when($filters['overdue'] ?? false, fn (Builder $q) => $q
                ->whereIn('loans.status', LoanStatus::open())
                ->whereExists(fn (QueryBuilder $periods) => $periods
                    ->selectRaw('1')
                    ->from('interest_periods as p')
                    ->whereColumn('p.loan_id', 'loans.id')
                    ->where('p.due_date', '<', today()->toDateString())
                    ->whereNull('p.waived_at')
                    ->whereColumn('p.paid_interest', '<', 'p.expected_interest')))
            ->orderByDesc('loans.start_date')
            ->orderByDesc('loans.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Loan>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
