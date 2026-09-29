<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payment\PaymentSearch;
use App\Domain\Payment\PaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\PaymentRequest;
use App\Http\Requests\Payments\PaymentSearchRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Thin HTTP layer: validation in PaymentRequest, every calculation and rule in PaymentService.
 * There is no update or delete: a posted payment is only ever reversed (docs/tasks/011).
 */
class PaymentController extends Controller
{
    public function index(PaymentSearchRequest $request, PaymentSearch $search): AnonymousResourceCollection
    {
        return PaymentResource::collection($search->paginate($request->filters(), $request->integer('per_page', 20)));
    }

    /**
     * GET /loans/{loan}/payments (docs/04).
     */
    public function forLoan(PaymentSearchRequest $request, Loan $loan, PaymentSearch $search): AnonymousResourceCollection
    {
        Gate::authorize('view', $loan);

        return PaymentResource::collection($search->paginate(['loan' => $loan->loan_no] + $request->filters(), $request->integer('per_page', 20)));
    }

    /**
     * GET /customers/{customer}/payments (docs/04).
     */
    public function forCustomer(PaymentSearchRequest $request, Customer $customer, PaymentSearch $search): AnonymousResourceCollection
    {
        Gate::authorize('view', $customer);

        return PaymentResource::collection($search->paginate(['customer' => $customer->customer_no] + $request->filters(), $request->integer('per_page', 20)));
    }

    /**
     * Posts a payment. 201 for a new payment; 200 with the original when the idempotency key was seen before.
     */
    public function store(PaymentRequest $request, PaymentService $payments): JsonResponse
    {
        $loan = $request->loan();
        Gate::authorize('view', $loan);

        $payment = $payments->post($loan, $request->payment(), $request->user(), $request->validated('idempotency_key'));

        return (new PaymentResource($payment->load(PaymentSearch::RELATIONS)))
            ->response()
            ->setStatusCode($payment->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        return new PaymentResource($payment->load(PaymentSearch::RELATIONS));
    }
}
