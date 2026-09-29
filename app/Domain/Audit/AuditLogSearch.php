<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\CollateralItem;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Audit log search (audit.view): newest first, filtered by action, area, entity, user, text and date.
 */
class AuditLogSearch
{
    /** Filterable entity areas → auditable type. "settings" entries have no entity. */
    public const ENTITIES = [
        'loan' => Loan::class,
        'payment' => Payment::class,
        'collateral' => CollateralItem::class,
        'customer' => Customer::class,
        'user' => User::class,
        'role' => Role::class,
    ];

    /**
     * @param  array{q?: ?string, event?: ?string, area?: ?string, entity?: ?string, reference?: ?string, user?: ?int, system?: ?bool, from?: ?string, to?: ?string}  $filters
     * @return Builder<AuditLog>
     */
    public function query(array $filters): Builder
    {
        $term = trim((string) ($filters['q'] ?? ''));

        return AuditLog::query()
            ->with(['user:id,name,email', 'auditable'])
            ->when($term !== '', function (Builder $query) use ($term) {
                $contains = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                $query->where(fn (Builder $q) => $q
                    ->whereRaw("LOWER(description) LIKE ? ESCAPE '!'", [$contains])
                    ->orWhereRaw("LOWER(event) LIKE ? ESCAPE '!'", [$contains]));
            })
            ->when($filters['event'] ?? null, fn (Builder $q, string $event) => $q->where('event', $event))
            // Area = the event's prefix, e.g. "payment" → payment.created, payment.reversed.
            ->when($filters['area'] ?? null, fn (Builder $q, string $area) => $q->where('event', 'like', "{$area}.%"))
            ->when($filters['entity'] ?? null, fn (Builder $q, string $entity) => $q->where('auditable_type', self::ENTITIES[$entity] ?? '-'))
            ->when($filters['reference'] ?? null, fn (Builder $q, string $reference) => $this->whereReference($q, $reference))
            ->when($filters['user'] ?? null, fn (Builder $q, int $user) => $q->where('user_id', $user))
            ->when($filters['system'] ?? false, fn (Builder $q) => $q->whereNull('user_id'))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * Everything recorded about one business record, by its public number (loan, receipt, collateral,
     * customer no.) — including the entries of the loan's payments and collateral for a loan number.
     *
     * @param  Builder<AuditLog>  $query
     */
    private function whereReference(Builder $query, string $reference): void
    {
        $loan = Loan::query()->where('loan_no', $reference)->first(['id']);

        $query->where(function (Builder $q) use ($reference, $loan) {
            $q->whereHasMorph('auditable', [Payment::class], fn (Builder $p) => $p->where('receipt_no', $reference))
                ->orWhereHasMorph('auditable', [CollateralItem::class], fn (Builder $c) => $c->where('collateral_no', $reference))
                ->orWhereHasMorph('auditable', [Customer::class], fn (Builder $c) => $c->where('customer_no', $reference));

            if ($loan) {
                $q->orWhere(fn (Builder $l) => $l->where('auditable_type', Loan::class)->where('auditable_id', $loan->id))
                    ->orWhereHasMorph('auditable', [Payment::class, CollateralItem::class], fn (Builder $r) => $r->where('loan_id', $loan->id));
            }
        });
    }
}
