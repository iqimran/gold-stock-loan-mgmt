<?php

namespace App\Domain\Collateral;

use App\Models\CollateralItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Collateral list/search (also the basis of the collateral inventory report). Released items stay
 * listed (filter by status). A search term matches the collateral or loan number, the customer's
 * number or name, or the description, case-insensitively.
 */
class CollateralSearch
{
    public const RELATIONS = ['loan:id,loan_no,status,customer_id', 'loan.customer:id,customer_no,name', 'releaser:id,name'];

    /**
     * @param  array{q?: ?string, loan?: ?string, customer?: ?string, type?: ?string, status?: ?string, received_from?: ?string, received_to?: ?string}  $filters
     * @return Builder<CollateralItem>
     */
    public function query(array $filters): Builder
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return CollateralItem::query()
            ->with(self::RELATIONS)
            ->when($term !== '', function (Builder $query) use ($term) {
                $contains = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

                $query->where(fn (Builder $match) => $match
                    ->whereRaw("LOWER(collateral_items.collateral_no) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereRaw("LOWER(collateral_items.description) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereHas('loan', fn (Builder $loan) => $loan
                        ->whereRaw("LOWER(loans.loan_no) LIKE ? ESCAPE '!'", [$contains])
                        ->orWhereHas('customer', fn (Builder $customer) => $customer
                            ->whereRaw("LOWER(customers.customer_no) LIKE ? ESCAPE '!'", [$contains])
                            ->orWhereRaw("LOWER(customers.name) LIKE ? ESCAPE '!'", [$contains]))));
            })
            ->when($filters['loan'] ?? null, fn (Builder $q, string $loanNo) => $q->whereHas('loan', fn (Builder $l) => $l->where('loan_no', $loanNo)))
            ->when($filters['customer'] ?? null, fn (Builder $q, string $customerNo) => $q->whereHas('loan.customer', fn (Builder $c) => $c->where('customer_no', $customerNo)))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('collateral_items.type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('collateral_items.status', $status))
            ->when($filters['received_from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('collateral_items.received_at', '>=', $from))
            ->when($filters['received_to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('collateral_items.received_at', '<=', $to))
            ->orderByDesc('collateral_items.received_at')
            ->orderByDesc('collateral_items.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CollateralItem>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }
}
