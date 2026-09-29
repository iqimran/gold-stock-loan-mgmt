<?php

namespace Tests\Feature\Reports;

use App\Domain\Loan\LoanService;
use App\Domain\Payment\PaymentService;
use App\Domain\Reporting\Export\ExportDatasets;
use App\Enums\LoanStatus;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * PDF / Excel exports. Dataset ("today" 2026-12-15): customer X, loan A 10,000.00 @ 2%/month from
 * 2026-10-01; staff1 takes 150.00 interest (cash), staff2 takes 1,000.00 principal (bank).
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    private User $staff1;

    private User $staff2;

    private User $exporter;

    private Loan $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-12-15 10:00:00');
        config(['shop.name' => 'Rupali Jewellers', 'shop.address' => '12 Tanti Bazar, Dhaka', 'shop.phone' => '01700-000000']);
        $this->staff1 = User::factory()->create(['name' => 'Staff One']);
        $this->staff2 = User::factory()->create(['name' => 'Staff Two']);

        $customer = Customer::factory()->create(['name' => 'Customer X']);
        $this->a = app(LoanService::class)->activate(
            Loan::factory()->for($customer)->create(['principal' => '10000.00', 'outstanding_principal' => '10000.00', 'interest_rate' => '2.0000', 'start_date' => '2026-10-01']),
            $this->staff1,
        );
        foreach ([[$this->staff1, 'interest', '150', 'cash'], [$this->staff2, 'principal', '1000', 'bank']] as [$staff, $type, $amount, $method]) {
            $this->actingAs($staff);
            app(PaymentService::class)->post($this->a->fresh(), ['type' => $type, 'amount' => $amount, 'method' => $method, 'payment_date' => '2026-12-15'], $staff);
        }

        $this->exporter = User::factory()->create(['name' => 'Report Clerk']);
        $this->exporter->givePermissionTo([Permission::ReportsView->value, Permission::ReportsExport->value]);
        $this->actingAs($this->exporter);
    }

    private function sheet(TestResponse $response): Worksheet
    {
        $response->assertOk()->assertDownload();

        return IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
    }

    // ── Excel ───────────────────────────────────────────────────────────────────────────────

    public function test_collection_report_excel_is_structured_typed_and_totalled(): void
    {
        $sheet = $this->sheet($this->get('/reports/collections/export?format=xlsx&paid_from=2026-12-01&paid_to=2026-12-31'));

        // Title block: title, filters, generated line (merged across the columns).
        $this->assertSame('Collection Report', $sheet->getCell('A1')->getValue());
        $this->assertSame('Paid from: 2026-12-01', $sheet->getCell('A2')->getValue());
        $this->assertSame('Paid to: 2026-12-31', $sheet->getCell('A3')->getValue());
        $this->assertSame('Payments: 2 posted (reversed payments excluded)', $sheet->getCell('A4')->getValue());
        $this->assertStringStartsWith('Generated: 2026-12-15 10:00 (UTC) by Report Clerk', $sheet->getCell('A5')->getValue());
        $this->assertArrayHasKey('A1:H1', $sheet->getMergeCells());

        // Header row 6: structured columns, auto-filter over header + data, frozen below the header.
        $this->assertSame(['Date', 'Receipt', 'Customer', 'Loan', 'Payment type', 'Method', 'Amount', 'Staff'], $sheet->rangeToArray('A6:H6')[0]);
        $this->assertSame('A6:H8', $sheet->getAutoFilter()->getRange());
        $this->assertSame('A7', $sheet->getFreezePane());

        // Data rows (newest first): numbers are numbers, dates are real dates, with formats.
        $this->assertSame(['Customer X', 'Principal', 'Bank', 'Staff Two'], [$sheet->getCell('C7')->getValue(), $sheet->getCell('E7')->getValue(), $sheet->getCell('F7')->getValue(), $sheet->getCell('H7')->getValue()]);
        $this->assertEqualsWithDelta(1000.0, $sheet->getCell('G7')->getValue(), 0.0001);
        $this->assertSame('#,##0.00', $sheet->getStyle('G7')->getNumberFormat()->getFormatCode());
        $this->assertSame('2026-12-15', ExcelDate::excelToDateTimeObject($sheet->getCell('A7')->getValue())->format('Y-m-d'));
        $this->assertSame('yyyy-mm-dd', $sheet->getStyle('A7')->getNumberFormat()->getFormatCode());
        $this->assertEqualsWithDelta(150.0, $sheet->getCell('G8')->getValue(), 0.0001);

        // Totals: the report's server totals, in bold.
        $this->assertSame('Gross collected', $sheet->getCell('A9')->getValue());
        $this->assertEqualsWithDelta(1150.0, $sheet->getCell('G9')->getValue(), 0.0001);
        $this->assertTrue($sheet->getStyle('A9')->getFont()->getBold());
        $this->assertSame('#,##0.00', $sheet->getStyle('G9')->getNumberFormat()->getFormatCode());
        $this->assertSame(
            ['Gross collected', 'Type: Interest (1)', 'Type: Principal (1)', 'Method: Bank (1)', 'Method: Cash (1)', 'Interest', 'Principal', 'Fees', 'Revenue (interest + fees)'],
            array_column($sheet->rangeToArray('A9:A17'), 0),
        );
        $this->assertEqualsWithDelta(150.0, $sheet->getCell('G17')->getValue(), 0.0001); // revenue = interest 150 + fees 0
        $this->assertNull($sheet->getCell('A18')->getValue());
    }

    public function test_excel_respects_filters(): void
    {
        $sheet = $this->sheet($this->get("/reports/collections/export?format=xlsx&staff={$this->staff1->id}"));

        // Title, staff filter, payments line, generated line, header (row 5), one data row, totals.
        $this->assertSame('Staff: Staff One', $sheet->getCell('A2')->getValue());
        $this->assertSame('Staff One', $sheet->getCell('H6')->getValue()); // the only row
        $this->assertEqualsWithDelta(150.0, $sheet->getCell('G6')->getValue(), 0.0001);
        $this->assertSame('Gross collected', $sheet->getCell('A7')->getValue());
        $this->assertEqualsWithDelta(150.0, $sheet->getCell('G7')->getValue(), 0.0001);
    }

    public function test_loans_list_excel_uses_the_list_filters(): void
    {
        Loan::factory()->status(LoanStatus::Closed)->create(['principal' => '3000.00', 'outstanding_principal' => '0.00']);
        $this->exporter->givePermissionTo(Permission::LoansView->value);

        $sheet = $this->sheet($this->get('/loans/export?format=xlsx&status=closed'));

        $this->assertSame('Loans', $sheet->getCell('A1')->getValue());
        $this->assertSame('Status: Closed', $sheet->getCell('A2')->getValue());
        $this->assertSame('Closed', $sheet->getCell('H5')->getValue());
        $this->assertSame('Totals (1 loans)', $sheet->getCell('A6')->getValue());
        $this->assertEqualsWithDelta(3000.0, $sheet->getCell('D6')->getValue(), 0.0001);
        $this->assertSame('General', $sheet->getStyle('F5')->getNumberFormat()->getFormatCode());
    }

    // ── PDF ─────────────────────────────────────────────────────────────────────────────────

    public function test_due_report_pdf_is_a_numbered_pdf_with_header_filters_rows_and_totals(): void
    {
        $response = $this->get('/reports/due/export?format=pdf&status=overdue')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('attachment; filename="due-report-20261215-100000.pdf"', $response->headers->get('Content-Disposition'));
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('Page 1 of 1', $this->pdfText($pdf)); // page numbering on the page

        // The page itself: shop header, title, filter summary, generated line, rows and totals.
        $html = view('exports.dataset', [
            'dataset' => app(ExportDatasets::class)->due(['status' => 'overdue']),
            'shop' => ['name' => 'Rupali Jewellers', 'address' => '12 Tanti Bazar, Dhaka', 'phone' => '01700-000000', 'receipt_footer' => null],
            'generatedAt' => '2026-12-15 10:00 (UTC)',
            'generatedBy' => 'Report Clerk',
            'currency' => ['code' => 'BDT', 'symbol' => '৳'],
        ])->render();

        foreach (['Rupali Jewellers', '12 Tanti Bazar, Dhaka', 'Due Report', 'Status', 'Overdue', 'As of', '2026-12-15', 'Report Clerk',
            $this->a->loan_no, 'Customer X', '2026-10-31', '2026-11-30', 'Totals (2 periods, 1 loans)', '400.00', '150.00', '250.00', 'Amounts in BDT'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_customer_ledger_pdf_downloads(): void
    {
        $this->get("/reports/customer-ledger/export?format=pdf&customer={$this->a->customer->customer_no}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // ── security and limits ─────────────────────────────────────────────────────────────────

    public function test_exports_need_the_export_permission_and_access_to_the_data(): void
    {
        $viewer = tap(User::factory()->create())->givePermissionTo([Permission::ReportsView->value, Permission::LoansView->value, Permission::PaymentsView->value, Permission::CustomersView->value]);
        foreach (['/reports/collections/export', '/loans/export', '/payments/export', '/customers/export'] as $url) {
            $this->actingAs($viewer)->get($url)->assertForbidden(); // can see, cannot export
        }

        $exportOnly = tap(User::factory()->create())->givePermissionTo(Permission::ReportsExport->value);
        foreach (['/reports/due/export', '/loans/export', '/payments/export', '/customers/export'] as $url) {
            $this->actingAs($exportOnly)->get($url)->assertForbidden(); // can export, cannot see
        }

        // reports.export + reports.view does not open customer data held in the Customers list.
        $this->actingAs($this->exporter)->get('/customers/export')->assertForbidden();
        $this->exporter->givePermissionTo(Permission::CustomersView->value);
        $this->actingAs($this->exporter)->get('/customers/export')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/reports/collections/export')->assertUnauthorized();
    }

    public function test_api_exports_and_unknown_reports(): void
    {
        Sanctum::actingAs($this->exporter);

        $this->get('/api/v1/reports/loan-outstanding/export?format=xlsx')->assertOk()->assertDownload();
        $this->getJson('/api/v1/reports/expenses/export')->assertNotFound();
        $this->getJson('/api/v1/reports/collections/export?format=csv')->assertUnprocessable()->assertJsonValidationErrors('format');
    }

    public function test_large_exports_are_refused_above_the_row_limit(): void
    {
        config(['loans.exports.max_rows' => 1]);
        Sanctum::actingAs($this->exporter);

        $this->getJson('/api/v1/reports/collections/export?format=pdf')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['export' => 'This export would contain 2 rows; the limit is 1.']);
    }

    /**
     * Text of the PDF's content streams (dompdf compresses them). The page number is drawn in a base-14
     * font, so it is readable; table text uses an embedded subset font and is checked in the HTML above.
     */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        return implode("\n", array_map(fn (string $stream) => @gzuncompress($stream) ?: $stream, $streams[1]));
    }
}
