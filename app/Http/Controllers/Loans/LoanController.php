<?php

namespace App\Http\Controllers\Loans;

use App\Domain\Collateral\CollateralSearch;
use App\Domain\Loan\LoanService;
use App\Domain\Loan\LoanSummary;
use App\Enums\CollateralStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\LoanStatusRequest;
use App\Http\Resources\CollateralResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\LoanResource;
use App\Models\CollateralItem;
use App\Models\Loan;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Loan detail screen. Same domain classes, requests and resources as the API; every figure comes
 * from the server. Tab data is sent only to users who may see it.
 */
class LoanController extends Controller
{
    public function show(Request $request, Loan $loan, LoanSummary $summary): Response
    {
        Gate::authorize('view', $loan);

        $user = $request->user();
        $loan->load('customer');

        return Inertia::render('loans/show', [
            'loan' => (new LoanResource($loan))->resolve($request),
            'customer' => $user->can('view', $loan->customer) ? (new CustomerResource($loan->customer))->resolve($request) : null,
            'summary' => $summary->for($loan),
            'collateral' => $user->can('viewAny', CollateralItem::class) ? $this->collateral($request, $loan) : null,
            'payments' => $user->can('viewAny', Payment::class) ? $this->payments($loan) : null,
            'collateralTypes' => config('loans.collateral.types'),
            'maxKarat' => config('loans.collateral.max_karat'),
        ]);
    }

    public function activate(LoanStatusRequest $request, Loan $loan, LoanService $loans): RedirectResponse
    {
        $loans->activate($loan, $request->user());

        return back()->with('success', "Loan {$loan->loan_no} activated.");
    }

    public function close(LoanStatusRequest $request, Loan $loan, LoanService $loans): RedirectResponse
    {
        $loans->close($loan, $request->user(), $request->validated('note'));

        return back()->with('success', "Loan {$loan->loan_no} closed. Its collateral can now be released.");
    }

    public function cancel(LoanStatusRequest $request, Loan $loan, LoanService $loans): RedirectResponse
    {
        $loans->cancel($loan, $request->user(), $request->validated('reason'));

        return back()->with('success', "Loan {$loan->loan_no} cancelled.");
    }

    /**
     * Every item of the loan (released ones stay listed) with server-computed totals of what is held.
     *
     * @return array{items: array<int, mixed>, held_count: int, held_weight_grams: string, held_estimated_value: string}
     */
    private function collateral(Request $request, Loan $loan): array
    {
        $items = CollateralItem::query()
            ->with(CollateralSearch::RELATIONS)
            ->where('loan_id', $loan->id)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
        $held = $items->where('status', CollateralStatus::Held);

        return [
            'items' => CollateralResource::collection($items)->resolve($request),
            'held_count' => $held->count(),
            'held_weight_grams' => $held->reduce(fn (string $sum, CollateralItem $item) => bcadd($sum, $item->weight_grams, 3), '0.000'),
            'held_estimated_value' => $held->reduce(fn (string $sum, CollateralItem $item) => Money::add($sum, $item->estimated_value), '0.00'),
        ];
    }

    /**
     * Payment history (read-only; recording and reversing payments is the payment module's job).
     *
     * @return list<array<string, mixed>>
     */
    private function payments(Loan $loan): array
    {
        return DB::table('payments')
            ->where('loan_id', $loan->id)
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get(['receipt_no', 'payment_date', 'type', 'method', 'amount', 'reference', 'status', 'reversed_at', 'reversal_reason'])
            ->map(fn (object $payment) => [
                'receipt_no' => $payment->receipt_no,
                'payment_date' => substr((string) $payment->payment_date, 0, 10),
                'type' => $payment->type,
                'method' => $payment->method,
                'amount' => Money::of((string) $payment->amount),
                'reference' => $payment->reference,
                'status' => $payment->status,
                'reversed' => $payment->reversed_at !== null,
                'reversal_reason' => $payment->reversal_reason,
            ])
            ->all();
    }
}
