/** App\Http\Resources\CollateralResource */
export interface CollateralItem {
    collateral_no: string;
    loan?: { loan_no: string; status: string; customer: { customer_no: string; name: string } | null };
    type: string;
    weight_grams: string;
    karat: string | null;
    estimated_value: string;
    description: string | null;
    status: 'held' | 'released';
    received_at: string;
    released_at: string | null;
    released_by?: string | null;
    /** UI hints from the server; every action is re-checked there. */
    actions: { update: boolean; release: boolean };
}

/** Collateral of one loan with server-computed totals of what is still held. */
export interface LoanCollateral {
    items: CollateralItem[];
    held_count: number;
    held_weight_grams: string;
    held_estimated_value: string;
}
