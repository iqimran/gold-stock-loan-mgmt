<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\ChangeCustomerStatus;
use App\Actions\Customers\SaveCustomer;
use App\Domain\Customer\CustomerSearch;
use App\Domain\Customer\CustomerSummary;
use App\Domain\Payment\PaymentSearch;
use App\Domain\Reporting\CustomerInterestReport;
use App\Domain\Reporting\CustomerLedgerReport;
use App\Enums\CustomerStatus;
use App\Enums\LoanStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerRequest;
use App\Http\Requests\Customers\CustomerSearchRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\PaymentResource;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer screens. Same domain classes, requests and resource as the API (Api\V1\CustomerController).
 */
class CustomerController extends Controller
{
    public function index(CustomerSearchRequest $request, CustomerSearch $search): Response
    {
        $filters = $request->filters();

        return Inertia::render('customers/index', [
            'customers' => CustomerResource::collection($search->paginate($filters, $request->integer('per_page', 20))),
            'filters' => [
                'q' => $filters['q'],
                'status' => $filters['status'],
                'registered_from' => $filters['registered_from'] ?? '',
                'registered_to' => $filters['registered_to'] ?? '',
                'overdue' => $filters['overdue'],
                'min_missed' => $filters['min_missed'] !== null ? (string) $filters['min_missed'] : '',
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('customers/create');
    }

    public function store(CustomerRequest $request, SaveCustomer $saveCustomer): RedirectResponse
    {
        $customer = $saveCustomer->handle(null, $request->details(), $request->file('image'));

        return to_route('customers.show', $customer)->with('success', "Customer {$customer->customer_no} created.");
    }

    /**
     * The customer's profile, open loans, and full history: every payment (reversed ones included) and
     * the ledger statement (opening balance brought forward, running balance, closing balance carried
     * forward), and the month-by-month interest statement. The history filters (from / to / loan) apply to both and to their exports; each part is
     * shown only with its own permission (payments.view, reports.view).
     */
    public function show(Request $request, Customer $customer, CustomerSummary $summary, PaymentSearch $payments, CustomerLedgerReport $ledger, CustomerInterestReport $interest): Response
    {
        Gate::authorize('view', $customer);

        $user = $request->user();
        $canViewLoans = $user->can('viewAny', Loan::class);
        $canViewPayments = $user->can('viewAny', Payment::class);
        $canViewLedger = $user->can(Permission::ReportsView->value);

        $history = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'loan' => ['nullable', 'string', 'max:30', Rule::exists('loans', 'loan_no')->where('customer_id', $customer->id)],
        ]);
        $history = ['from' => $history['from'] ?? null, 'to' => $history['to'] ?? null, 'loan' => $history['loan'] ?? null];
        $loanId = $history['loan'] ? $customer->loans()->where('loan_no', $history['loan'])->value('id') : null;

        return Inertia::render('customers/show', [
            'customer' => (new CustomerResource($summary->apply(Customer::query()->whereKey($customer->getKey()))->firstOrFail()))->resolve($request),
            // Loan details only for users who may see loans; the summary counts are always shown.
            'activeLoans' => $canViewLoans ? $this->activeLoans($customer) : null,
            'history' => array_map(fn ($value) => $value ?? '', $history),
            // Every loan of the customer (any status) for the history filter; the first start year for the year picker.
            'historyLoans' => $canViewPayments || $canViewLedger ? $customer->loans()->orderBy('start_date')->pluck('loan_no')->all() : [],
            'firstYear' => (int) substr((string) ($customer->loans()->min('start_date') ?? today()->toDateString()), 0, 4),
            'payments' => $canViewPayments
                ? fn () => PaymentResource::collection(
                    $payments->query(['customer' => $customer->customer_no, 'loan' => $history['loan'], 'paid_from' => $history['from'], 'paid_to' => $history['to']])
                        ->paginate(20, ['*'], 'payments_page')
                        ->withQueryString(),
                )
                : null,
            'ledger' => $canViewLedger
                ? function () use ($ledger, $customer, $history, $loanId) {
                    $filters = ['loan_id' => $loanId, 'from' => $history['from'], 'to' => $history['to']];

                    return [
                        'entries' => JsonResource::collection($ledger->paginate($customer, $filters, 25, 'ledger_page')),
                        'totals' => $ledger->totals($customer, $filters),
                    ];
                }
                : null,
            // Month-by-month interest (charged / paid / unpaid); the due-date range uses the same from / to.
            'interest' => $canViewLedger
                ? function () use ($interest, $customer, $history, $loanId) {
                    $filters = ['loan_id' => $loanId, 'from' => $history['from'], 'to' => $history['to']];

                    return [
                        'months' => JsonResource::collection($interest->paginate($customer, $filters, 24, 'interest_page')),
                        'totals' => $interest->totals($customer, $filters),
                    ];
                }
                : null,
        ]);
    }

    public function edit(Request $request, Customer $customer): Response
    {
        Gate::authorize('update', $customer);

        return Inertia::render('customers/edit', [
            'customer' => (new CustomerResource($customer))->resolve($request),
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer, SaveCustomer $saveCustomer): RedirectResponse
    {
        $saveCustomer->handle($customer, $request->details(), $request->file('image'), $request->boolean('remove_image'));

        return to_route('customers.show', $customer)->with('success', "Customer {$customer->customer_no} updated.");
    }

    /**
     * Archives (never deletes) the customer.
     */
    public function destroy(Customer $customer, ChangeCustomerStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('archive', $customer);

        $changeStatus->handle($customer, CustomerStatus::Archived);

        return back()->with('success', "Customer {$customer->customer_no} archived.");
    }

    public function restore(Customer $customer, ChangeCustomerStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('restore', $customer);

        $changeStatus->handle($customer, CustomerStatus::Active);

        return back()->with('success', "Customer {$customer->customer_no} restored.");
    }

    /**
     * Read-only view of the customer's open loans (the loan module owns loan logic and screens).
     *
     * @return list<array<string, mixed>>
     */
    private function activeLoans(Customer $customer): array
    {
        return $customer->loans()
            ->whereIn('status', LoanStatus::open())
            ->orderBy('start_date')
            ->withCasts(['principal' => 'decimal:2', 'outstanding_principal' => 'decimal:2', 'interest_rate' => 'decimal:4', 'start_date' => 'date', 'next_due_date' => 'date'])
            ->get(['loan_no', 'principal', 'outstanding_principal', 'interest_rate', 'interest_rate_type', 'interest_period_unit', 'status', 'start_date', 'next_due_date'])
            ->map(fn (Loan $loan) => [
                'loan_no' => $loan->loan_no,
                'principal' => $loan->principal,
                'outstanding_principal' => $loan->outstanding_principal,
                'interest_rate' => $loan->interest_rate,
                'interest_rate_type' => $loan->interest_rate_type,
                'interest_period_unit' => $loan->interest_period_unit,
                'status' => $loan->status,
                'start_date' => $loan->start_date?->toDateString(),
                'next_due_date' => $loan->next_due_date?->toDateString(),
            ])
            ->all();
    }
}
