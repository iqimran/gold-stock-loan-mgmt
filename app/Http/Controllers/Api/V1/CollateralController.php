<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Collateral\CollateralSearch;
use App\Domain\Collateral\CollateralService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collateral\CollateralRequest;
use App\Http\Requests\Collateral\CollateralSearchRequest;
use App\Http\Requests\Collateral\ReleaseCollateralRequest;
use App\Http\Resources\CollateralResource;
use App\Models\CollateralItem;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Thin HTTP layer: validation in form requests, every rule in CollateralService.
 * There is no delete: collateral history is permanent.
 */
class CollateralController extends Controller
{
    public function index(CollateralSearchRequest $request, CollateralSearch $search): AnonymousResourceCollection
    {
        return CollateralResource::collection($search->paginate($request->filters(), $request->integer('per_page', 20)));
    }

    /**
     * GET /loans/{loan}/collateral (docs/04): every item of the loan, released ones included.
     */
    public function forLoan(CollateralSearchRequest $request, Loan $loan, CollateralSearch $search): AnonymousResourceCollection
    {
        Gate::authorize('view', $loan);

        return CollateralResource::collection(
            $search->paginate(['loan' => $loan->loan_no, 'status' => $request->validated('status')], $request->integer('per_page', 50))
        );
    }

    public function store(CollateralRequest $request, Loan $loan, CollateralService $collateral): JsonResponse
    {
        $item = $collateral->add($loan, $request->details(), $request->user());

        return (new CollateralResource($item->load(CollateralSearch::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(CollateralItem $collateralItem): CollateralResource
    {
        Gate::authorize('view', $collateralItem);

        return new CollateralResource($collateralItem->load(CollateralSearch::RELATIONS));
    }

    public function update(CollateralRequest $request, CollateralItem $collateralItem, CollateralService $collateral): CollateralResource
    {
        $item = $collateral->update($collateralItem, $request->details(), $request->user(), $request->validated('reason'));

        return new CollateralResource($item->load(CollateralSearch::RELATIONS));
    }

    public function release(ReleaseCollateralRequest $request, CollateralItem $collateralItem, CollateralService $collateral): CollateralResource
    {
        $item = $collateral->release($collateralItem, $request->user(), $request->validated('reason'));

        return new CollateralResource($item->load(CollateralSearch::RELATIONS));
    }
}
