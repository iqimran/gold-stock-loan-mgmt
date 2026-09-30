<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditTrail;
use App\Domain\Reporting\Export\ExcelExporter;
use App\Domain\Reporting\Export\ExportDataset;
use App\Domain\Reporting\Export\ExportDatasets;
use App\Domain\Reporting\Export\PdfExporter;
use App\Enums\Permission;
use App\Http\Requests\Customers\CustomerSearchRequest;
use App\Http\Requests\Loans\LoanSearchRequest;
use App\Http\Requests\Payments\PaymentSearchRequest;
use App\Http\Requests\Reports\ReportFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF / Excel exports of the reports and of the Loans, Payments and Customers lists (web and API).
 *
 * Every export needs reports.export (docs/05: exports of customer data are sensitive) AND the permission
 * to see the data itself (reports.view for reports; the list's own view permission for lists, enforced
 * by its search request). Filters are validated exactly as for the screen/report, so the file contains
 * what the user sees for the same query string. Each export is audited (who, what, filters, rows).
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly ExportDatasets $datasets,
        private readonly PdfExporter $pdf,
        private readonly ExcelExporter $excel,
        private readonly AuditTrail $audit,
    ) {}

    public function report(Request $request, string $report): Response
    {
        Gate::authorize(Permission::ReportsExport->value);
        Gate::authorize(Permission::ReportsView->value);
        abort_unless(in_array($report, ReportFilters::REPORTS, true), 404);

        $dataset = match ($report) {
            'collections' => $this->datasets->collections(ReportFilters::validate($report, $request)),
            'due' => $this->datasets->due(ReportFilters::validate($report, $request)),
            'customer-ledger' => $this->datasets->customerLedger(ReportFilters::ledger($request)),
            'customer-interest' => $this->datasets->customerInterest(ReportFilters::ledger($request)),
            'loan-outstanding' => $this->datasets->loanOutstanding(ReportFilters::validate($report, $request)),
            'collateral' => $this->datasets->collateral(ReportFilters::validate($report, $request)),
        };

        return $this->render($request, $dataset, "report:{$report}");
    }

    public function loans(LoanSearchRequest $request): Response
    {
        Gate::authorize(Permission::ReportsExport->value);

        return $this->render($request, $this->datasets->loans($request->filters()), 'list:loans');
    }

    public function payments(PaymentSearchRequest $request): Response
    {
        Gate::authorize(Permission::ReportsExport->value);

        return $this->render($request, $this->datasets->payments($request->filters()), 'list:payments');
    }

    public function customers(CustomerSearchRequest $request): Response
    {
        Gate::authorize(Permission::ReportsExport->value);

        return $this->render($request, $this->datasets->customers($request->filters()), 'list:customers');
    }

    private function render(Request $request, ExportDataset $dataset, string $what): Response
    {
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';

        // Exports of customer data are sensitive (docs/05): recorded in the audit log with the filters;
        // the application log keeps no personal data.
        $this->audit->record('export.downloaded', null, [], [
            'export' => $what, 'format' => $format, 'rows' => count($dataset->rows), 'filters' => $dataset->filters,
        ], "{$dataset->title} ({$format}, ".count($dataset->rows).' rows)');
        Log::info('Export downloaded', ['user_id' => $request->user()->id, 'export' => $what, 'format' => $format, 'rows' => count($dataset->rows)]);

        return $format === 'pdf'
            ? $this->pdf->download($dataset, $request->user())
            : $this->excel->download($dataset, $request->user());
    }
}
