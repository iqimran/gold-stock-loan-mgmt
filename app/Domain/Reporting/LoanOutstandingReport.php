<?php

namespace App\Domain\Reporting;

use App\Domain\Alert\DueInterestQuery;
use App\Domain\Loan\LoanSearch;
use App\Enums\LoanStatus;
use App\Models\Loan;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Loan Outstanding Report (docs/10): per loan the principal, outstanding principal, interest due to date
 * and total exposure (outstanding + due interest), as of today. Loans are filtered with the loan search
 * (open loans by default); "due interest" is DueInterestQuery's rule, per loan.
 */
class LoanOutstandingReport
{
    public const SORTS = ['loan_no' => 'loans.loan_no', 'outstanding' => 'loans.outstanding_principal', 'due_interest' => 'due_interest', 'next_due' => 'loans.next_due_date', 'exposure' => 'exposure'];

    public function __construct(
        private readonly LoanSearch $loans,
        private readonly DueInterestQuery $due,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  LoanSearch filters (status defaults to "open") + sort/direction
     * @return Builder<Loan>
     */
    public function query(array $filters): Builder
    {
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $dueInterest = $this->due->dueToDateForLoan('loans.id');

        $status = $filters['status'] ?? 'open';

        $query = $this->loans->query([...$filters, 'status' => $status === 'all' ? null : $status])
            ->select('loans.*')
            ->selectSub($dueInterest, 'due_interest')
            ->selectRaw('(loans.outstanding_principal + ('.$dueInterest->toSql().')) as exposure', $dueInterest->getBindings())
            ->reorder();

        return $query
            ->orderBy(self::SORTS[$filters['sort'] ?? 'loan_no'] ?? self::SORTS['loan_no'], $direction)
            ->orderBy('loans.id', $direction);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{loans: int, principal: string, outstanding_principal: string, due_interest: string, exposure: string}
     */
    public function totals(array $filters): array
    {
        $row = DB::query()
            ->fromSub($this->query($filters)->reorder()->toBase(), 't')
            ->selectRaw('count(*) as loans, coalesce(sum(t.principal), 0) as principal, coalesce(sum(t.outstanding_principal), 0) as outstanding, coalesce(sum(t.due_interest), 0) as due_interest')
            ->first();

        $outstanding = Money::of((string) $row->outstanding);
        $dueInterest = Money::of((string) $row->due_interest);

        return [
            'loans' => (int) $row->loans,
            'principal' => Money::of((string) $row->principal),
            'outstanding_principal' => $outstanding,
            'due_interest' => $dueInterest,
            'exposure' => Money::add($outstanding, $dueInterest),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Loan $loan): array
    {
        $dueInterest = Money::of((string) $loan->getAttribute('due_interest'));

        return [
            'loan_no' => $loan->loan_no,
            'customer' => ['customer_no' => $loan->customer->customer_no, 'name' => $loan->customer->name, 'mobile' => $loan->customer->mobile],
            'principal' => $loan->principal,
            'outstanding_principal' => $loan->outstanding_principal,
            'due_interest' => $dueInterest,
            'exposure' => Money::add($loan->outstanding_principal, $dueInterest),
            'next_due_date' => $loan->next_due_date?->toDateString(),
            'status' => $loan->status->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [...LoanStatus::values(), 'open', 'all'];
    }
}
