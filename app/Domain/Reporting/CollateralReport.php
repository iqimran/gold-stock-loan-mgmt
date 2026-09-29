<?php

namespace App\Domain\Reporting;

use App\Domain\Collateral\CollateralSearch;
use App\Enums\CollateralStatus;
use App\Models\CollateralItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Collateral Report (docs/10 "Collateral Report", docs/01 "Collateral Inventory Report"): items with the
 * collateral search's filters (loan, customer, type, status, received dates) plus release dates, with
 * whole-set totals of weight and estimated value, split into held and released.
 */
class CollateralReport
{
    public const SORTS = ['received' => 'collateral_items.received_at', 'released' => 'collateral_items.released_at', 'weight' => 'collateral_items.weight_grams', 'value' => 'collateral_items.estimated_value', 'collateral_no' => 'collateral_items.collateral_no'];

    public function __construct(private readonly CollateralSearch $collateral) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CollateralItem>
     */
    public function query(array $filters): Builder
    {
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $this->collateral->query($filters)
            ->when($filters['released_from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('collateral_items.released_at', '>=', $from))
            ->when($filters['released_to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('collateral_items.released_at', '<=', $to))
            ->reorder()
            ->orderBy(self::SORTS[$filters['sort'] ?? 'received'] ?? self::SORTS['received'], $direction)
            ->orderBy('collateral_items.id', $direction);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: int, weight_grams: string, estimated_value: string, held: array{items: int, weight_grams: string, estimated_value: string}, released: array{items: int, weight_grams: string, estimated_value: string}}
     */
    public function totals(array $filters): array
    {
        $rows = DB::query()
            ->fromSub($this->query($filters)->reorder()->toBase()->select('collateral_items.status', 'collateral_items.weight_grams', 'collateral_items.estimated_value'), 't')
            ->groupBy('t.status')
            ->selectRaw('t.status, count(*) as items, coalesce(sum(t.weight_grams), 0) as weight, coalesce(sum(t.estimated_value), 0) as value')
            ->get()
            ->keyBy('status');

        $bucket = fn (?object $row) => [
            'items' => (int) ($row->items ?? 0),
            'weight_grams' => bcadd((string) ($row->weight ?? '0'), '0', 3),
            'estimated_value' => bcadd((string) ($row->value ?? '0'), '0', 2),
        ];

        $held = $bucket($rows->get(CollateralStatus::Held->value));
        $released = $bucket($rows->get(CollateralStatus::Released->value));

        return [
            'items' => $held['items'] + $released['items'],
            'weight_grams' => bcadd($held['weight_grams'], $released['weight_grams'], 3),
            'estimated_value' => bcadd($held['estimated_value'], $released['estimated_value'], 2),
            'held' => $held,
            'released' => $released,
        ];
    }
}
