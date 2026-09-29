<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Customers\ChangeCustomerStatus;
use App\Actions\Customers\SaveCustomer;
use App\Domain\Customer\CustomerSearch;
use App\Domain\Customer\CustomerSummary;
use App\Enums\CustomerStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerRequest;
use App\Http\Requests\Customers\CustomerSearchRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    /**
     * Search/filter customers (active only unless `status` is given), with derived summary figures.
     */
    public function index(CustomerSearchRequest $request, CustomerSearch $search): AnonymousResourceCollection
    {
        return CustomerResource::collection(
            $search->paginate($request->filters(), $request->integer('per_page', 20))
        );
    }

    public function store(CustomerRequest $request, SaveCustomer $saveCustomer, CustomerSummary $summary): JsonResponse
    {
        $customer = $saveCustomer->handle(null, $request->details(), $request->file('image'));

        return (new CustomerResource($this->withSummary($customer, $summary)))->response()->setStatusCode(201);
    }

    public function show(Customer $customer, CustomerSummary $summary): CustomerResource
    {
        Gate::authorize('view', $customer);

        return new CustomerResource($this->withSummary($customer, $summary));
    }

    public function update(CustomerRequest $request, Customer $customer, SaveCustomer $saveCustomer, CustomerSummary $summary): CustomerResource
    {
        $saveCustomer->handle($customer, $request->details(), $request->file('image'), $request->boolean('remove_image'));

        return new CustomerResource($this->withSummary($customer, $summary));
    }

    /**
     * DELETE archives: customers are never hard-deleted (docs/04, docs/08 "Customer deletion").
     */
    public function destroy(Customer $customer, ChangeCustomerStatus $changeStatus, CustomerSummary $summary): CustomerResource
    {
        Gate::authorize('archive', $customer);

        $changeStatus->handle($customer, CustomerStatus::Archived);

        return new CustomerResource($this->withSummary($customer, $summary));
    }

    public function restore(Customer $customer, ChangeCustomerStatus $changeStatus, CustomerSummary $summary): CustomerResource
    {
        Gate::authorize('restore', $customer);

        $changeStatus->handle($customer, CustomerStatus::Active);

        return new CustomerResource($this->withSummary($customer, $summary));
    }

    private function withSummary(Customer $customer, CustomerSummary $summary): Customer
    {
        return $summary->apply(Customer::query()->whereKey($customer->getKey()))->firstOrFail();
    }
}
