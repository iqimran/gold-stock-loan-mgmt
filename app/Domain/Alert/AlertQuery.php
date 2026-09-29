<?php

namespace App\Domain\Alert;

use App\Enums\AlertStatus;
use App\Models\Alert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Missed-interest alert lists and the dashboard figures ("Missed Payment Alerts", docs/01, docs/04).
 */
class AlertQuery
{
    public const RELATIONS = ['loan:id,loan_no,status,customer_id', 'customer:id,customer_no,name,mobile', 'interestPeriod:id,period_start,period_end,due_date'];

    /**
     * @param  array{status?: ?string, customer?: ?string, loan?: ?string, triggered_from?: ?string, triggered_to?: ?string}  $filters
     *                                                                                                                                  status defaults to open; 'all' for every alert
     * @return Builder<Alert>
     */
    public function query(array $filters): Builder
    {
        $status = $filters['status'] ?? AlertStatus::Open->value;

        return Alert::query()
            ->with(self::RELATIONS)
            ->when($status !== 'all', fn (Builder $q) => $q->where('alerts.status', $status))
            ->when($filters['loan'] ?? null, fn (Builder $q, string $loanNo) => $q->whereHas('loan', fn (Builder $l) => $l->where('loan_no', $loanNo)))
            ->when($filters['customer'] ?? null, fn (Builder $q, string $customerNo) => $q->whereHas('customer', fn (Builder $c) => $c->where('customer_no', $customerNo)))
            ->when($filters['triggered_from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('alerts.triggered_at', '>=', $from))
            ->when($filters['triggered_to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('alerts.triggered_at', '<=', $to))
            ->orderByDesc('alerts.triggered_at')
            ->orderByDesc('alerts.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Alert>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * Dashboard card: open alerts and the number of customers they concern.
     *
     * @return array{open: int, customers: int}
     */
    public function summary(): array
    {
        $open = Alert::query()->where('status', AlertStatus::Open);

        return [
            'open' => (clone $open)->count(),
            'customers' => (clone $open)->distinct()->count('customer_id'),
        ];
    }
}
