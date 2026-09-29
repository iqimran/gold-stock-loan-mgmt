<?php

namespace App\Domain\Payment;

use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payment list/search. Reversed payments stay listed (filter by status). A search term matches the
 * receipt, loan or customer number, the customer name or the reference, case-insensitively.
 */
class PaymentSearch
{
    public const RELATIONS = ['loan:id,loan_no,status', 'customer:id,customer_no,name,mobile', 'allocations.interestPeriod:id,period_start,period_end,due_date', 'reverser:id,name'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Payment>
     */
    public function query(array $filters): Builder
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return Payment::query()
            ->with(self::RELATIONS)
            ->when($term !== '', function (Builder $query) use ($term) {
                $contains = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

                $query->where(fn (Builder $match) => $match
                    ->whereRaw("LOWER(payments.receipt_no) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereRaw("LOWER(payments.reference) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereHas('loan', fn (Builder $loan) => $loan->whereRaw("LOWER(loans.loan_no) LIKE ? ESCAPE '!'", [$contains]))
                    ->orWhereHas('customer', fn (Builder $customer) => $customer
                        ->whereRaw("LOWER(customers.customer_no) LIKE ? ESCAPE '!'", [$contains])
                        ->orWhereRaw("LOWER(customers.name) LIKE ? ESCAPE '!'", [$contains])));
            })
            ->when($filters['loan'] ?? null, fn (Builder $q, string $loanNo) => $q->whereHas('loan', fn (Builder $l) => $l->where('loan_no', $loanNo)))
            ->when($filters['customer'] ?? null, fn (Builder $q, string $customerNo) => $q->whereHas('customer', fn (Builder $c) => $c->where('customer_no', $customerNo)))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('payments.type', $type))
            ->when($filters['method'] ?? null, fn (Builder $q, string $method) => $q->where('payments.method', $method))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('payments.status', $status))
            ->when($filters['paid_from'] ?? null, fn (Builder $q, string $from) => $q->where('payments.payment_date', '>=', $from))
            ->when($filters['paid_to'] ?? null, fn (Builder $q, string $to) => $q->where('payments.payment_date', '<=', $to))
            ->orderByDesc('payments.payment_date')
            ->orderByDesc('payments.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Payment>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }
}
