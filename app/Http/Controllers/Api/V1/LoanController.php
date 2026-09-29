<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Loan\LoanSearch;
use App\Domain\Loan\LoanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\LoanRequest;
use App\Http\Requests\Loans\LoanSearchRequest;
use App\Http\Requests\Loans\LoanStatusRequest;
use App\Http\Resources\LoanResource;
use App\Models\Customer;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Thin HTTP layer: validation in form requests, every rule and change in LoanService.
 */
class LoanController extends Controller
{
    public function index(LoanSearchRequest $request, LoanSearch $search): AnonymousResourceCollection
    {
        return LoanResource::collection($search->paginate($request->filters(), $request->integer('per_page', 20)));
    }

    /**
     * GET /customers/{customer}/loans (docs/04).
     */
    public function forCustomer(LoanSearchRequest $request, Customer $customer, LoanSearch $search): AnonymousResourceCollection
    {
        Gate::authorize('view', $customer);

        return LoanResource::collection(
            $search->paginate(['customer' => $customer->customer_no, 'status' => $request->validated('status')], $request->integer('per_page', 20))
        );
    }

    public function store(LoanRequest $request, LoanService $loans): JsonResponse
    {
        $loan = $loans->create($request->customer(), $request->terms(), $request->user());

        return (new LoanResource($loan->load('customer')))->response()->setStatusCode(201);
    }

    public function show(Loan $loan): LoanResource
    {
        Gate::authorize('view', $loan);

        return new LoanResource($loan->load('customer'));
    }

    public function update(LoanRequest $request, Loan $loan, LoanService $loans): LoanResource
    {
        return new LoanResource($loans->update($loan, $request->terms(), $request->user())->load('customer'));
    }

    public function activate(LoanStatusRequest $request, Loan $loan, LoanService $loans): LoanResource
    {
        return new LoanResource($loans->activate($loan, $request->user())->load('customer'));
    }

    public function close(LoanStatusRequest $request, Loan $loan, LoanService $loans): LoanResource
    {
        return new LoanResource($loans->close($loan, $request->user(), $request->validated('note'))->load('customer'));
    }

    public function cancel(LoanStatusRequest $request, Loan $loan, LoanService $loans): LoanResource
    {
        return new LoanResource($loans->cancel($loan, $request->user(), $request->validated('reason'))->load('customer'));
    }
}
