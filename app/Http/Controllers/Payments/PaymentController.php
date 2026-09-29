<?php

namespace App\Http\Controllers\Payments;

use App\Domain\Customer\CustomerSearch;
use App\Domain\Loan\LoanSummary;
use App\Domain\Payment\PaymentReceipt;
use App\Domain\Payment\PaymentReversalService;
use App\Domain\Payment\PaymentSearch;
use App\Domain\Payment\PaymentService;
use App\Enums\LoanStatus;
use App\Enums\PaymentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\PaymentRequest;
use App\Http\Requests\Payments\PaymentSearchRequest;
use App\Http\Requests\Payments\ReversePaymentRequest;
use App\Http\Resources\LoanResource;
use App\Http\Resources\PaymentResource;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment screens: list, form, detail, receipt and reversal. Same requests, services and resource as
 * the API; every amount shown is computed by the server.
 */
class PaymentController extends Controller
{
    public function index(PaymentSearchRequest $request, PaymentSearch $search): Response
    {
        $filters = $request->filters();

        return Inertia::render('payments/index', [
            'payments' => PaymentResource::collection($search->paginate($filters, $request->integer('per_page', 20))),
            'filters' => array_map(fn ($value) => $value ?? '', $filters),
            'types' => PaymentType::values(),
            'methods' => config('loans.payment_methods'),
        ]);
    }

    /**
     * The form. Lookups are server-side (Inertia partial reloads): `customer_q` searches customers,
     * `customer` lists that customer's open loans, `loan` loads the loan's authoritative balances.
     */
    public function create(Request $request, CustomerSearch $customers, LoanSummary $summary): Response
    {
        Gate::authorize('create', Payment::class);

        $request->validate([
            'customer_q' => ['nullable', 'string', 'max:191'],
            'customer' => ['nullable', 'string', 'max:30'],
            'loan' => ['nullable', 'string', 'max:30'],
        ]);

        $loan = $request->filled('loan') ? Loan::with('customer')->where('loan_no', $request->string('loan'))->first() : null;
        $loan = $loan && $request->user()->can('view', $loan) ? $loan : null;
        $customer = $loan?->customer ?? ($request->filled('customer') ? Customer::where('customer_no', $request->string('customer'))->first() : null);

        return Inertia::render('payments/create', [
            'types' => array_values(array_diff(PaymentType::values(), [PaymentType::Adjustment->value])),
            'methods' => config('loans.payment_methods'),
            'today' => today()->toDateString(),
            // One key per form: a double submit or a retry after a network error posts the payment once.
            'idempotencyKey' => (string) Str::uuid(),
            'customerResults' => fn () => $request->filled('customer_q')
                ? $customers->query(['q' => (string) $request->string('customer_q'), 'status' => 'all'])->limit(8)->get()
                    ->map(fn (Customer $c) => ['customer_no' => $c->customer_no, 'name' => $c->name, 'mobile' => $c->mobile, 'active_loans' => $c->active_loans_count])
                    ->all()
                : [],
            'customer' => $customer ? ['customer_no' => $customer->customer_no, 'name' => $customer->name, 'mobile' => $customer->mobile] : null,
            'loans' => fn () => $customer
                ? $customer->loans()->whereIn('status', LoanStatus::open())->orderBy('start_date')->get()
                    ->map(fn (Loan $l) => ['loan_no' => $l->loan_no, 'status' => $l->status->value, 'outstanding_principal' => $l->outstanding_principal])
                    ->all()
                : [],
            'loanInfo' => fn () => $loan ? $this->loanInfo($request, $loan, $summary) : null,
        ]);
    }

    public function store(PaymentRequest $request, PaymentService $payments): RedirectResponse
    {
        $loan = $request->loan();
        Gate::authorize('view', $loan);

        $payment = $payments->post($loan, $request->payment(), $request->user(), $request->validated('idempotency_key'));

        return to_route('payments.receipt', ['payment' => $payment, 'new' => 1])
            ->with('success', "Payment {$payment->receipt_no} recorded.");
    }

    public function show(Request $request, Payment $payment, PaymentReceipt $receipt): Response
    {
        Gate::authorize('view', $payment);
        $payment->load(PaymentSearch::RELATIONS);

        return Inertia::render('payments/show', [
            'payment' => (new PaymentResource($payment))->resolve($request),
            'balances' => $receipt->balances($payment),
        ]);
    }

    public function receipt(Request $request, Payment $payment, PaymentReceipt $receipt): Response
    {
        Gate::authorize('view', $payment);
        $payment->load([...PaymentSearch::RELATIONS, 'creator:id,name']);

        return Inertia::render('payments/receipt', [
            'payment' => (new PaymentResource($payment))->resolve($request),
            'balances' => $receipt->balances($payment),
            'shop' => $receipt->shop(),
            'cashier' => $payment->creator?->name,
            'autoPrint' => $request->boolean('print'),
            'justCompleted' => $request->boolean('new'),
        ]);
    }

    public function reverse(ReversePaymentRequest $request, Payment $payment, PaymentReversalService $reversals): RedirectResponse
    {
        $reversals->reverse($payment, $request->user(), $request->validated('reason'));

        return back()->with('success', "Payment {$payment->receipt_no} reversed.");
    }

    /**
     * Authoritative figures for the form's side panel.
     *
     * @return array<string, mixed>
     */
    private function loanInfo(Request $request, Loan $loan, LoanSummary $summary): array
    {
        $figures = $summary->for($loan);
        $payable = '0.00';

        foreach ($figures['periods'] as $period) {
            if ($period['status'] !== 'waived') {
                $payable = Money::add($payable, Money::max(Money::sub($period['expected_interest'], $period['paid_interest']), '0.00'));
            }
        }

        return [
            'loan' => (new LoanResource($loan))->resolve($request),
            'accepts_payments' => $loan->status->isOpen(),
            'outstanding_principal' => $figures['outstanding_principal'],
            'interest_due' => $figures['interest_due'],
            'interest_payable' => $payable,
            'overdue_periods' => $figures['overdue_periods'],
            'next_due_date' => $figures['next_due_date'],
            'last_payment' => $figures['last_payment'],
        ];
    }
}
