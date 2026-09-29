<?php

namespace App\Domain\Customer;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer list/search used by the API (and the customer screens).
 *
 * A search term matches customer number, mobile or NID (exact matches ranked first) or any part of
 * the name, mobile, NID or customer number, case-insensitively on every database.
 */
class CustomerSearch
{
    public function __construct(private readonly CustomerSummary $summary) {}

    /**
     * @param  array{q?: ?string, status?: ?string, registered_from?: ?string, registered_to?: ?string, overdue?: ?bool, min_missed?: ?int}  $filters
     *                                                                                                                                                 status: 'active' | 'archived' | 'all'
     * @return Builder<Customer>
     */
    public function query(array $filters): Builder
    {
        $term = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? CustomerStatus::Active->value;

        $query = $this->summary->apply(Customer::query());

        if ($term !== '') {
            $contains = '%'.$this->escapeLike(mb_strtolower($term)).'%';
            $exact = mb_strtolower($term);

            $query->where(function (Builder $match) use ($contains) {
                foreach (['customer_no', 'name', 'mobile', 'nid'] as $column) {
                    $match->orWhereRaw("LOWER(customers.{$column}) LIKE ? ESCAPE '!'", [$contains]);
                }
            })->orderByRaw(
                'CASE WHEN LOWER(customers.customer_no) = ? OR customers.mobile = ? OR LOWER(customers.nid) = ? THEN 0 ELSE 1 END',
                [$exact, $term, $exact],
            );
        }

        $query
            ->when($status !== 'all', fn (Builder $q) => $q->where('customers.status', $status))
            ->when($filters['registered_from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('customers.created_at', '>=', $from))
            ->when($filters['registered_to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('customers.created_at', '<=', $to))
            ->when($filters['overdue'] ?? false, fn (Builder $q) => $this->summary->whereOverdue($q))
            ->when($filters['min_missed'] ?? null, fn (Builder $q, int $threshold) => $this->summary->whereConsecutiveMissedAtLeast($q, $threshold));

        return $query->orderBy('customers.name')->orderBy('customers.id');
    }

    /**
     * @param  array{q?: ?string, status?: ?string, registered_from?: ?string, registered_to?: ?string, overdue?: ?bool, min_missed?: ?int}  $filters
     * @return LengthAwarePaginator<int, Customer>
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
