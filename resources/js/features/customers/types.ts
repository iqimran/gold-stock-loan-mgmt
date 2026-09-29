export type CustomerStatus = 'active' | 'archived';

/** Derived figures computed by the server (App\Domain\Customer\CustomerSummary). */
export interface CustomerSummary {
    active_loans: number;
    total_interest_due: string;
    consecutive_missed: number;
    next_due_date: string | null;
    last_payment: { date: string; amount: string } | null;
}

export interface Customer {
    customer_no: string;
    name: string;
    mobile: string;
    nid: string | null;
    address: string | null;
    status: CustomerStatus;
    image_url: string | null;
    summary?: CustomerSummary;
    created_at: string | null;
    updated_at: string | null;
}

/** Read-only view of an open loan on the customer detail page. */
export interface CustomerLoan {
    loan_no: string;
    principal: string;
    outstanding_principal: string;
    interest_rate: string;
    interest_rate_type: string;
    interest_period_unit: string;
    status: string;
    start_date: string | null;
    next_due_date: string | null;
}

export interface CustomerFilters {
    q: string;
    status: string;
    registered_from: string;
    registered_to: string;
    overdue: boolean;
    min_missed: string;
}
