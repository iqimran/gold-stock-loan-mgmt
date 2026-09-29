<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\CollateralReport;
use App\Domain\Reporting\CollectionReport;
use App\Domain\Reporting\CustomerLedgerReport;
use App\Domain\Reporting\DueReport;
use App\Domain\Reporting\LoanOutstandingReport;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilters;
use App\Http\Resources\CollateralResource;
use App\Http\Resources\InterestPeriodResource;
use App\Http\Resources\PaymentResource;
use App\Models\Customer;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Reports (docs/10), all behind reports.view. Each response: the page of rows (data/links/meta), the
 * totals of the WHOLE filtered set (not just the page), and the filters applied. Every figure comes
 * from the report classes in App\Domain\Reporting; filters are validated by ReportFilters (shared with
 * the exports).
 */
class ReportController extends Controller
{
    public function collections(Request $request, CollectionReport $report): JsonResponse
    {
        $filters = $this->filters('collections', $request);

        return PaymentResource::collection($report->query($filters)->paginate($this->perPage($request))->withQueryString())
            ->additional(['totals' => $report->totals($filters), 'filters' => $filters])
            ->response();
    }

    public function due(Request $request, DueReport $report): JsonResponse
    {
        $filters = $this->filters('due', $request);

        return InterestPeriodResource::collection($report->query($filters)->paginate($this->perPage($request))->withQueryString())
            ->additional(['totals' => $report->totals($filters), 'filters' => $filters])
            ->response();
    }

    /**
     * Customer ledger: GET /reports/customer-ledger?customer=…, GET /customers/{customer}/ledger and
     * GET /loans/{loan}/ledger (docs/04).
     */
    public function customerLedger(Request $request, CustomerLedgerReport $report, ?Customer $customer = null, ?Loan $loan = null): JsonResponse
    {
        Gate::authorize(Permission::ReportsView->value);

        $ledger = ReportFilters::ledger($request, $customer, $loan);
        $filters = ['loan_id' => $ledger['loan_id'], 'from' => $ledger['from'], 'to' => $ledger['to']];

        return JsonResource::collection($report->paginate($ledger['customer'], $filters, $request->integer('per_page', 50)))
            ->additional([
                'customer' => ['customer_no' => $ledger['customer']->customer_no, 'name' => $ledger['customer']->name, 'mobile' => $ledger['customer']->mobile],
                'loan' => $ledger['loan']?->loan_no,
                'totals' => $report->totals($ledger['customer'], $filters),
                'filters' => ['from' => $filters['from'], 'to' => $filters['to']],
            ])
            ->response();
    }

    /**
     * GET /loans/{loan}/ledger (docs/04): the customer ledger limited to one loan.
     */
    public function loanLedger(Request $request, Loan $loan, CustomerLedgerReport $report): JsonResponse
    {
        return $this->customerLedger($request, $report, null, $loan);
    }

    public function loanOutstanding(Request $request, LoanOutstandingReport $report): JsonResponse
    {
        $filters = $this->filters('loan-outstanding', $request);
        $page = $report->query($filters)->paginate($this->perPage($request))->withQueryString();

        return JsonResource::collection($page->through(fn (Loan $loan) => LoanOutstandingReport::row($loan)))
            ->additional(['totals' => $report->totals($filters), 'filters' => $filters])
            ->response();
    }

    public function collateral(Request $request, CollateralReport $report): JsonResponse
    {
        $filters = $this->filters('collateral', $request);

        return CollateralResource::collection($report->query($filters)->paginate($this->perPage($request))->withQueryString())
            ->additional(['totals' => $report->totals($filters), 'filters' => $filters])
            ->response();
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(string $report, Request $request): array
    {
        Gate::authorize(Permission::ReportsView->value);

        return ReportFilters::validate($report, $request);
    }

    private function perPage(Request $request): int
    {
        return $request->integer('per_page', 50);
    }
}
