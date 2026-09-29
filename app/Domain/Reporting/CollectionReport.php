<?php

namespace App\Domain\Reporting;

use App\Domain\Payment\PaymentSearch;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Collection Report (docs/10): money collected within a period. Posted payments only — a reversed payment
 * was never collected. Filters follow the payment search (date range, method, type, customer, loan) plus
 * the staff member who recorded the payment. Totals cover the whole filtered set (not just one page) and
 * come from the payments and their allocations. The dashboard's collection figures use these totals too.
 */
class CollectionReport
{
    public const SORTS = ['date' => 'payments.payment_date', 'amount' => 'payments.amount', 'receipt' => 'payments.receipt_no'];

    public function __construct(private readonly PaymentSearch $payments) {}

    /**
     * @param  array{paid_from?: ?string, paid_to?: ?string, method?: ?string, type?: ?string, customer?: ?string, loan?: ?string, staff?: ?int, sort?: ?string, direction?: ?string}  $filters
     * @return Builder<Payment>
     */
    public function query(array $filters): Builder
    {
        $query = $this->payments->query([...$filters, 'status' => PaymentStatus::Posted->value, 'q' => ''])
            ->with('creator:id,name')
            ->when($filters['staff'] ?? null, fn (Builder $q, int $staff) => $q->where('payments.created_by', $staff));

        return $query->reorder()
            ->orderBy(self::SORTS[$filters['sort'] ?? 'date'] ?? self::SORTS['date'], ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc')
            ->orderBy('payments.id', ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{gross: string, payments: int, interest: string, principal: string, fees: string, revenue: string, by_type: array<string, array{payments: int, amount: string}>, by_method: array<string, array{payments: int, amount: string}>}
     */
    public function totals(array $filters): array
    {
        $ids = $this->query($filters)->reorder()->toBase()->select('payments.id');

        $overall = DB::table('payments')->whereIn('id', $ids)->selectRaw('count(*) as payments, coalesce(sum(amount), 0) as gross')->first();
        $split = DB::table('payment_allocations')->whereIn('payment_id', $ids)
            ->selectRaw('coalesce(sum(interest_amount), 0) as interest, coalesce(sum(principal_amount), 0) as principal, coalesce(sum(fee_amount), 0) as fees')
            ->first();

        $group = fn (string $column) => DB::table('payments')->whereIn('id', $ids)
            ->groupBy($column)->orderBy($column)
            ->selectRaw("{$column} as grp, count(*) as payments, coalesce(sum(amount), 0) as amount")
            ->get()
            ->mapWithKeys(fn (object $row) => [$row->grp => ['payments' => (int) $row->payments, 'amount' => Money::of((string) $row->amount)]])
            ->all();

        $interest = Money::of((string) $split->interest);
        $fees = Money::of((string) $split->fees);

        return [
            'gross' => Money::of((string) $overall->gross),
            'payments' => (int) $overall->payments,
            'interest' => $interest,
            'principal' => Money::of((string) $split->principal),
            'fees' => $fees,
            'revenue' => Money::add($interest, $fees),
            'by_type' => $group('type'),
            'by_method' => $group('method'),
        ];
    }
}
