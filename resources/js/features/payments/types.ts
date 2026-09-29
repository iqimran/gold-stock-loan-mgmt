import { type Loan } from '@/features/loans/types';

/** App\Http\Resources\PaymentResource — the allocation is the server's. */
export interface Payment {
    receipt_no: string;
    loan?: { loan_no: string; status: string };
    customer?: { customer_no: string; name: string; mobile: string };
    type: string;
    amount: string;
    method: string;
    payment_date: string;
    reference: string | null;
    notes: string | null;
    status: 'posted' | 'reversed';
    allocation?: {
        interest: string;
        principal: string;
        fee: string;
        periods: { period_start: string; period_end: string; due_date: string; interest: string }[];
    };
    reversed_at: string | null;
    reversed_by?: string | null;
    reversal_reason: string | null;
    can_reverse: boolean;
    created_at: string | null;
}

/** App\Domain\Payment\PaymentReceipt::balances() — as of the payment. */
export interface PaymentBalances {
    principal_after: string;
    customer_balance_after: string | null;
}

/** Authoritative loan figures for the payment form (PaymentController::loanInfo). */
export interface PaymentLoanInfo {
    loan: Loan;
    accepts_payments: boolean;
    outstanding_principal: string;
    interest_due: string;
    interest_payable: string;
    overdue_periods: number;
    next_due_date: string | null;
    last_payment: { date: string; amount: string } | null;
}

export const PAYMENT_TYPE_LABELS: Record<string, string> = {
    interest: 'Interest',
    principal: 'Principal',
    principal_and_interest: 'Principal + interest',
    other_fee: 'Other fee',
    adjustment: 'Adjustment',
};

export const PAYMENT_TYPE_HINTS: Record<string, string> = {
    interest: 'Applied to the oldest unpaid interest period first.',
    principal: 'Reduces the outstanding principal only.',
    principal_and_interest: 'Clears unpaid interest first (oldest first); the rest reduces principal.',
    other_fee: 'A fee collected at the counter; interest and principal are not affected.',
};

export function paymentTypeLabel(type: string): string {
    return PAYMENT_TYPE_LABELS[type] ?? type;
}

export function methodLabel(method: string): string {
    return method.charAt(0).toUpperCase() + method.slice(1).replaceAll('_', ' ');
}
