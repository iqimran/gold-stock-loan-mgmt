<?php

namespace App\Http\Controllers;

use App\Domain\Reporting\DashboardMetricsService;
use App\Models\Loan;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Loan management dashboard (as in the Inventory POS: every signed-in user lands here). Each block is
 * built only for users who may see it: loan figures with loans.view, collections with payments.view.
 * Figures come from App\Domain\Reporting\DashboardMetricsService; see it for the date semantics.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetricsService $metrics): Response
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.today()->format('Y-m')],
        ]);

        $user = $request->user();
        $canLoans = $user->can('viewAny', Loan::class);
        $canPayments = $user->can('viewAny', Payment::class);
        $month = isset($filters['month']) ? CarbonImmutable::createFromFormat('!Y-m', $filters['month']) : CarbonImmutable::parse(today()->toDateString());

        return Inertia::render('dashboard', [
            'asOf' => today()->toDateString(),
            'timezone' => config('app.timezone'),
            'month' => $month->format('Y-m'),
            'loanSummary' => $canLoans ? $metrics->loanSummary() : null,
            'alerts' => $canLoans ? $metrics->alerts() : null,
            'overdueAccounts' => $canLoans ? $metrics->overdueAccounts() : null,
            'recentLoans' => $canLoans ? $metrics->recentLoans() : null,
            'collections' => $canPayments ? $metrics->collections($month) : null,
            'recentPayments' => $canPayments ? $metrics->recentPayments() : null,
        ]);
    }
}
