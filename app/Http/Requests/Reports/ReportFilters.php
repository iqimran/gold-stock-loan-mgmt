<?php

namespace App\Http\Requests\Reports;

use App\Domain\Alert\DueInterestQuery;
use App\Domain\Reporting\CollateralReport;
use App\Domain\Reporting\CollectionReport;
use App\Domain\Reporting\DueReport;
use App\Domain\Reporting\LoanOutstandingReport;
use App\Domain\Settings\LoanSettings;
use App\Enums\CollateralStatus;
use App\Enums\PaymentType;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The filters of each report, in ONE place: the report endpoints (Api\V1\ReportController) and the
 * exports (ExportController) validate and normalise them identically, so an export always contains
 * exactly what the report shows for the same query string.
 */
final class ReportFilters
{
    public const REPORTS = ['collections', 'due', 'customer-ledger', 'customer-interest', 'loan-outstanding', 'collateral'];

    /**
     * @return array<string, mixed>
     */
    public static function validate(string $report, Request $request): array
    {
        return match ($report) {
            'collections' => self::common($request, [
                'paid_from' => ['nullable', 'date_format:Y-m-d'],
                'paid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:paid_from'],
                'method' => ['nullable', Rule::in(app(LoanSettings::class)->paymentMethods())],
                'type' => ['nullable', Rule::in(PaymentType::values())],
                'customer' => ['nullable', 'string', 'max:30'],
                'loan' => ['nullable', 'string', 'max:30'],
                'staff' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
            ], array_keys(CollectionReport::SORTS)),
            'due' => self::castMissed(self::common($request, [
                'status' => ['nullable', Rule::in(DueInterestQuery::STATUSES)],
                'due_from' => ['nullable', 'date_format:Y-m-d'],
                'due_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:due_from'],
                'customer' => ['nullable', 'string', 'max:30'],
                'loan' => ['nullable', 'string', 'max:30'],
                'min_missed' => ['nullable', 'integer', 'min:1', 'max:1000'],
            ], array_keys(DueReport::SORTS))),
            'loan-outstanding' => [...self::common($request, [
                'status' => ['nullable', Rule::in(LoanOutstandingReport::statuses())],
                'q' => ['nullable', 'string', 'max:191'],
                'customer' => ['nullable', 'string', 'max:30'],
                'due_by' => ['nullable', 'date_format:Y-m-d'],
                'overdue' => ['nullable', 'boolean'],
            ], array_keys(LoanOutstandingReport::SORTS)), 'overdue' => $request->boolean('overdue')],
            'collateral' => self::common($request, [
                'q' => ['nullable', 'string', 'max:191'],
                'loan' => ['nullable', 'string', 'max:30'],
                'customer' => ['nullable', 'string', 'max:30'],
                'type' => ['nullable', Rule::in(app(LoanSettings::class)->collateralTypes())],
                'status' => ['nullable', Rule::in(CollateralStatus::values())],
                'received_from' => ['nullable', 'date_format:Y-m-d'],
                'received_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:received_from'],
                'released_from' => ['nullable', 'date_format:Y-m-d'],
                'released_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:released_from'],
            ], array_keys(CollateralReport::SORTS)),
            'customer-ledger' => self::ledger($request),
        };
    }

    /**
     * Customer ledger: the customer and (optionally) loan are resolved here; a route may supply either.
     *
     * @return array{customer: Customer, loan: ?Loan, loan_id: ?int, from: ?string, to: ?string}
     */
    public static function ledger(Request $request, ?Customer $customer = null, ?Loan $loan = null): array
    {
        $input = $request->validate([
            'customer' => [$customer || $loan ? 'prohibited' : 'required', 'string', 'max:30'],
            'loan' => [$loan ? 'prohibited' : 'nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $customer ??= $loan?->customer ?? Customer::where('customer_no', $input['customer'])->firstOrFail();
        $loan ??= isset($input['loan']) ? Loan::where('loan_no', $input['loan'])->where('customer_id', $customer->id)->firstOrFail() : null;

        return ['customer' => $customer, 'loan' => $loan, 'loan_id' => $loan?->id, 'from' => $input['from'] ?? null, 'to' => $input['to'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $sorts
     * @return array<string, mixed>
     */
    private static function common(Request $request, array $rules, array $sorts): array
    {
        $validated = $request->validate([
            ...$rules,
            'sort' => ['nullable', Rule::in($sorts)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        unset($validated['per_page']);

        return array_filter($validated, fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function castMissed(array $filters): array
    {
        if (isset($filters['min_missed'])) {
            $filters['min_missed'] = (int) $filters['min_missed'];
        }

        return $filters;
    }
}
