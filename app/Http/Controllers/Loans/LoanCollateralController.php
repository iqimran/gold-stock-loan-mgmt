<?php

namespace App\Http\Controllers\Loans;

use App\Domain\Collateral\CollateralService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collateral\CollateralRequest;
use App\Http\Requests\Collateral\ReleaseCollateralRequest;
use App\Models\CollateralItem;
use App\Models\Loan;
use Illuminate\Http\RedirectResponse;

/**
 * Collateral actions from the loan screen (same requests and CollateralService as the API).
 */
class LoanCollateralController extends Controller
{
    public function store(CollateralRequest $request, Loan $loan, CollateralService $collateral): RedirectResponse
    {
        $item = $collateral->add($loan, $request->details(), $request->user());

        return back()->with('success', "Collateral {$item->collateral_no} added.");
    }

    public function update(CollateralRequest $request, CollateralItem $collateralItem, CollateralService $collateral): RedirectResponse
    {
        $collateral->update($collateralItem, $request->details(), $request->user(), $request->validated('reason'));

        return back()->with('success', "Collateral {$collateralItem->collateral_no} updated.");
    }

    public function release(ReleaseCollateralRequest $request, CollateralItem $collateralItem, CollateralService $collateral): RedirectResponse
    {
        $collateral->release($collateralItem, $request->user(), $request->validated('reason'));

        return back()->with('success', "Collateral {$collateralItem->collateral_no} released.");
    }
}
