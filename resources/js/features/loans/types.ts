export type LoanStatus = 'draft' | 'active' | 'overdue' | 'closed' | 'cancelled';

/** App\Http\Resources\LoanResource */
export interface Loan {
    loan_no: string;
    customer?: { customer_no: string; name: string; mobile: string };
    principal: string;
    outstanding_principal: string;
    interest_rate: string;
    interest_rate_type: 'monthly' | 'yearly';
    interest_period_unit: 'month';
    status: LoanStatus;
    start_date: string;
    next_due_date: string | null;
    closed_at: string | null;
    notes: string | null;
    /** UI hints from the server; every action is re-checked there. */
    actions: { update: boolean; edit_terms: boolean; activate: boolean; close: boolean; cancel: boolean };
    created_at: string | null;
    updated_at: string | null;
}

export type InterestPeriodStatus = 'upcoming' | 'due' | 'partially_paid' | 'paid' | 'overdue' | 'waived';

export interface InterestPeriodRow {
    period_start: string;
    period_end: string;
    due_date: string;
    expected_interest: string;
    paid_interest: string;
    status: InterestPeriodStatus;
}

/** App\Domain\Loan\LoanSummary — every figure is computed by the server. */
export interface LoanSummary {
    principal: string;
    outstanding_principal: string;
    principal_repaid: string;
    interest_charged: string;
    interest_paid: string;
    interest_waived: string;
    interest_due: string;
    fees_paid: string;
    payments_total: string;
    payments_count: number;
    overdue_periods: number;
    consecutive_missed: number;
    next_due_date: string | null;
    last_payment: { date: string; amount: string } | null;
    periods: InterestPeriodRow[];
}

export interface LoanPayment {
    receipt_no: string;
    payment_date: string;
    type: string;
    method: string;
    amount: string;
    reference: string | null;
    status: string;
    reversed: boolean;
    reversal_reason: string | null;
}
