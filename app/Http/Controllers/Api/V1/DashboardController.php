<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Alert\DueInterestQuery;
use App\Domain\Reporting\DashboardMetricsService;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard endpoints (docs/04 "Dashboard"); the same figures as the dashboard screen.
 * GET /dashboard/missed-payment-alerts is served by AlertController.
 */
class DashboardController extends Controller
{
    public function loanSummary(DashboardMetricsService $metrics): JsonResponse
    {
        Gate::authorize('viewAny', Loan::class);

        return response()->json(['data' => $metrics->loanSummary() + ['as_of' => today()->toDateString()]]);
    }

    public function collections(Request $request, DashboardMetricsService $metrics): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);

        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.today()->format('Y-m')]])['month'] ?? null;

        return response()->json(['data' => $metrics->collections(
            $month ? CarbonImmutable::createFromFormat('!Y-m', $month) : CarbonImmutable::parse(today()->toDateString()),
        )]);
    }

    public function dueInterest(DueInterestQuery $due): JsonResponse
    {
        Gate::authorize('viewAny', Loan::class);

        return response()->json(['data' => [
            'as_of' => today()->toDateString(),
            'due_to_date' => $due->totals(['status' => 'unpaid', 'due_to' => today()->toDateString()]),
            'overdue' => $due->totals(['status' => 'overdue']),
            'due_today' => $due->totals(['status' => 'due']),
            'upcoming_7_days' => $due->totals(['status' => 'unpaid', 'due_from' => today()->addDay()->toDateString(), 'due_to' => today()->addDays(7)->toDateString()]),
        ]]);
    }

    public function overdueAccounts(Request $request, DashboardMetricsService $metrics): JsonResponse
    {
        Gate::authorize('viewAny', Loan::class);

        $limit = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']])['limit'] ?? 20;

        return response()->json(['data' => $metrics->overdueAccounts((int) $limit)]);
    }
}
