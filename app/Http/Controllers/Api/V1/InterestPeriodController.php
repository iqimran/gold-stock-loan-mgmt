<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alert\DueInterestQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\InterestPeriodResource;
use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Due / overdue interest views (read-only; periods are maintained by the interest engine).
 */
class InterestPeriodController extends Controller
{
    /**
     * GET /interest-periods: unsettled periods of open loans, filterable for due/overdue views.
     */
    public function index(Request $request, DueInterestQuery $due): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Loan::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(DueInterestQuery::STATUSES)],
            'due_from' => ['nullable', 'date_format:Y-m-d'],
            'due_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:due_from'],
            'customer' => ['nullable', 'string', 'max:30'],
            'loan' => ['nullable', 'string', 'max:30'],
            'min_missed' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (isset($filters['min_missed'])) {
            $filters['min_missed'] = (int) $filters['min_missed'];
        }

        return InterestPeriodResource::collection($due->paginate($filters, $request->integer('per_page', 20)));
    }

    /**
     * GET /loans/{loan}/interest-periods (docs/04): every period of the loan, settled ones included.
     */
    public function forLoan(Loan $loan): AnonymousResourceCollection
    {
        Gate::authorize('view', $loan);

        return InterestPeriodResource::collection($loan->interestPeriods()->get());
    }
}
