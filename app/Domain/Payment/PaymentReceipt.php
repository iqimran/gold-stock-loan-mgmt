<?php

namespace App\Domain\Payment;

use App\Domain\Settings\LoanSettings;
use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\Loan;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Server-authoritative figures printed on a receipt, as of that payment (a reprint later shows the
 * same numbers):
 *
 *  - outstanding principal of the loan right after this payment: principal minus principal repaid by
 *    this and earlier payments that are still posted;
 *  - what the customer owed in total right after this payment: the ledger balance of its entry.
 */
class PaymentReceipt
{
    /**
     * @return array{principal_after: string, customer_balance_after: ?string}
     */
    public function balances(Payment $payment): array
    {
        $repaid = DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->where('p.loan_id', $payment->loan_id)
            ->where('p.id', '<=', $payment->id)
            ->where(fn ($q) => $q->whereNull('p.reversed_at')->orWhere('p.id', $payment->id))
            ->sum('a.principal_amount');

        $entry = LedgerEntry::query()
            ->where('payment_id', $payment->id)
            ->where('entry_type', LedgerEntryType::PaymentReceived)
            ->first();

        $principal = (string) Loan::query()->whereKey($payment->loan_id)->value('principal');

        return [
            'principal_after' => Money::max(Money::sub(Money::of($principal), Money::of((string) $repaid)), '0.00'),
            'customer_balance_after' => $entry?->balance_after,
        ];
    }

    /**
     * Settings → shop details: the block printed at the top of receipts and exports.
     *
     * @return array{name: string, address: ?string, phone: ?string, receipt_footer: ?string}
     */
    public function shop(): array
    {
        return app(LoanSettings::class)->shop();
    }
}
