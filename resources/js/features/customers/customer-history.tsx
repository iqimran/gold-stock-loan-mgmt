import { DataTable, type DataTableColumn } from '@/components/data-table';
import { ExportButtons } from '@/components/export-buttons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PeriodStatusBadge } from '@/features/loans/status-badge';
import { type InterestPeriodStatus } from '@/features/loans/types';
import { methodLabel, paymentTypeLabel, type Payment } from '@/features/payments/types';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';
import { BookOpen, CalendarRange, LoaderCircle, ReceiptText } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

export interface HistoryFilters {
    from: string;
    to: string;
    loan: string;
}

/** App\Domain\Reporting\CustomerLedgerReport row. */
export interface LedgerRow {
    date: string;
    reference: string | null;
    loan_no: string | null;
    entry_type: string;
    description: string | null;
    debit: string;
    credit: string;
    balance: string;
}

export interface LedgerData {
    entries: Paginated<LedgerRow>;
    totals: { opening_balance: string; debit: string; credit: string; closing_balance: string; entries: number };
}

/** App\Domain\Reporting\CustomerInterestReport row: one interest month of one loan. */
export interface InterestMonth {
    loan_no: string;
    month: string;
    period_start: string;
    period_end: string;
    due_date: string;
    principal: string;
    rate: string;
    expected_interest: string;
    paid_interest: string;
    waived_interest: string;
    unpaid_interest: string;
    status: InterestPeriodStatus;
    paid_on: string;
    receipts: string;
}

export interface InterestData {
    months: Paginated<InterestMonth>;
    totals: { months: number; charged: string; paid: string; waived: string; unpaid: string; overdue_months: number };
}

type Tab = 'payments' | 'ledger' | 'interest';

const monthLabel = (month: string) => {
    const [year, m] = month.split('-').map(Number);

    return new Date(year, m - 1, 1).toLocaleDateString(undefined, { year: 'numeric', month: 'short' });
};

const interestColumns: DataTableColumn<InterestMonth>[] = [
    {
        key: 'month',
        header: 'Month',
        cell: (row) => (
            <div className="whitespace-nowrap">
                {monthLabel(row.month)}
                <div className="text-muted-foreground text-xs">{row.loan_no}</div>
            </div>
        ),
    },
    { key: 'due', header: 'Due', cell: (row) => formatDate(row.due_date), className: 'hidden whitespace-nowrap md:table-cell' },
    {
        key: 'principal',
        header: 'Principal',
        cell: (row) => (
            <div className="whitespace-nowrap">
                {formatMoney(row.principal)}
                <div className="text-muted-foreground text-xs">{row.rate}</div>
            </div>
        ),
        className: 'hidden text-right tabular-nums lg:table-cell',
    },
    { key: 'interest', header: 'Interest', cell: (row) => formatMoney(row.expected_interest), className: 'text-right tabular-nums' },
    {
        key: 'paid',
        header: 'Paid',
        cell: (row) => (
            <div className="whitespace-nowrap">
                {formatMoney(row.paid_interest)}
                {row.paid_on && <div className="text-muted-foreground text-xs">{row.paid_on}</div>}
            </div>
        ),
        className: 'text-right tabular-nums',
    },
    {
        key: 'unpaid',
        header: 'Unpaid',
        cell: (row) => (
            <span className={cn(row.status === 'overdue' && 'font-medium text-red-600 dark:text-red-400')}>
                {row.waived_interest !== '0.00' ? `Waived ${formatMoney(row.waived_interest)}` : formatMoney(row.unpaid_interest)}
            </span>
        ),
        className: 'text-right tabular-nums whitespace-nowrap',
    },
    { key: 'status', header: 'Status', cell: (row) => <PeriodStatusBadge status={row.status} /> },
];

const ENTRY_LABELS: Record<string, string> = {
    loan_disbursed: 'Loan disbursed',
    interest_charged: 'Interest charged',
    fee_charged: 'Fee charged',
    payment_received: 'Payment received',
    loan_cancelled: 'Loan cancelled',
    payment_reversed: 'Payment reversed',
    fee_reversed: 'Fee reversed',
};

interface CustomerHistoryProps {
    customerNo: string;
    filters: HistoryFilters;
    loans: string[];
    firstYear: number;
    /** Null when the user may not view payments / the ledger (the server decides). */
    payments: Paginated<Payment> | null;
    ledger: LedgerData | null;
    interest: InterestData | null;
}

const paymentColumns: DataTableColumn<Payment>[] = [
    {
        key: 'receipt',
        header: 'Receipt',
        cell: (payment) => (
            <Link href={route('payments.show', payment.receipt_no)} className="font-medium whitespace-nowrap hover:underline">
                {payment.receipt_no}
            </Link>
        ),
    },
    { key: 'date', header: 'Date', cell: (payment) => formatDate(payment.payment_date), className: 'whitespace-nowrap' },
    { key: 'loan', header: 'Loan', cell: (payment) => payment.loan?.loan_no, className: 'whitespace-nowrap' },
    { key: 'type', header: 'Type', cell: (payment) => paymentTypeLabel(payment.type), className: 'hidden md:table-cell' },
    { key: 'method', header: 'Method', cell: (payment) => methodLabel(payment.method), className: 'hidden lg:table-cell' },
    {
        key: 'interest',
        header: 'Interest',
        cell: (payment) => formatMoney(payment.allocation?.interest),
        className: 'hidden text-right tabular-nums lg:table-cell',
    },
    {
        key: 'principal',
        header: 'Principal',
        cell: (payment) => formatMoney(payment.allocation?.principal),
        className: 'hidden text-right tabular-nums lg:table-cell',
    },
    {
        key: 'amount',
        header: 'Amount',
        cell: (payment) => (
            <span className={cn(payment.status === 'reversed' && 'text-muted-foreground line-through')}>{formatMoney(payment.amount)}</span>
        ),
        className: 'text-right tabular-nums whitespace-nowrap',
    },
    {
        key: 'status',
        header: 'Status',
        cell: (payment) =>
            payment.status === 'reversed' ? <Badge variant="destructive">Reversed</Badge> : <Badge variant="secondary">Posted</Badge>,
    },
];

const ledgerColumns: DataTableColumn<LedgerRow>[] = [
    { key: 'date', header: 'Date', cell: (row) => formatDate(row.date), className: 'whitespace-nowrap' },
    {
        key: 'entry',
        header: 'Entry',
        cell: (row) => (
            <div className="min-w-40">
                {ENTRY_LABELS[row.entry_type] ?? row.entry_type}
                {row.description && <div className="text-muted-foreground text-xs">{row.description}</div>}
            </div>
        ),
    },
    {
        key: 'ref',
        header: 'Loan / ref.',
        cell: (row) => (
            <div className="whitespace-nowrap">
                {row.loan_no}
                {row.reference && <div className="text-muted-foreground text-xs">{row.reference}</div>}
            </div>
        ),
        className: 'hidden md:table-cell',
    },
    { key: 'debit', header: 'Debit', cell: (row) => (row.debit !== '0.00' ? formatMoney(row.debit) : ''), className: 'text-right tabular-nums' },
    { key: 'credit', header: 'Credit', cell: (row) => (row.credit !== '0.00' ? formatMoney(row.credit) : ''), className: 'text-right tabular-nums' },
    { key: 'balance', header: 'Balance', cell: (row) => formatMoney(row.balance), className: 'text-right font-medium tabular-nums' },
];

/**
 * The customer's full history: every payment (reversed ones stay listed), the month-by-month interest
 * statement (charged / paid / unpaid), and the ledger statement with the balance brought forward from
 * before the range and carried forward at its end. One set of filters applies to all tabs and to their Excel / PDF exports. Every figure comes from the server.
 */
export function CustomerHistory({ customerNo, filters, loans, firstYear, payments, ledger, interest }: CustomerHistoryProps) {
    const tabs = (
        [
            ['payments', 'Payments', ReceiptText, payments],
            ['interest', 'Interest by month', CalendarRange, interest],
            ['ledger', 'Ledger', BookOpen, ledger],
        ] as const
    ).filter(([, , , data]) => data !== null);
    const [tab, setTab] = useState<Tab>(tabs[0]?.[0] ?? 'payments');
    const [form, setForm] = useState<HistoryFilters>(filters);
    const [loading, setLoading] = useState(false);

    const currentYear = new Date().getFullYear();
    const years = Array.from({ length: Math.max(currentYear - firstYear + 1, 1) }, (_, i) => String(currentYear - i));
    const selectedYear = years.find((year) => form.from === `${year}-01-01` && form.to === `${year}-12-31`) ?? '';

    const load = (next: HistoryFilters) => {
        setForm(next);
        router.get(
            route('customers.show', customerNo),
            { ...next },
            {
                only: ['payments', 'ledger', 'interest', 'history'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    };

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        load(form);
    };

    const pickYear = (year: string) => load(year ? { ...form, from: `${year}-01-01`, to: `${year}-12-31` } : { ...form, from: '', to: '' });

    const ledgerExport = { customer: customerNo, loan: filters.loan, from: filters.from, to: filters.to };
    const paymentsExport = { customer: customerNo, loan: filters.loan, paid_from: filters.from, paid_to: filters.to };

    return (
        <Card>
            <CardHeader className="gap-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <CardTitle>Payments &amp; ledger</CardTitle>
                        <CardDescription>
                            Full history across all of the customer&apos;s loans. Loans continue from year to year until the principal is repaid.
                        </CardDescription>
                    </div>
                    {tab === 'payments' && <ExportButtons routeName="payments.export" filters={paymentsExport} />}
                    {tab === 'ledger' && <ExportButtons routeName="reports.export" params={{ report: 'customer-ledger' }} filters={ledgerExport} />}
                    {tab === 'interest' && (
                        <ExportButtons routeName="reports.export" params={{ report: 'customer-interest' }} filters={ledgerExport} />
                    )}
                </div>

                <form onSubmit={apply} className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end" aria-label="Filter history">
                    <div className="grid gap-1">
                        <Label htmlFor="history-year" className="text-xs">
                            Year
                        </Label>
                        <select id="history-year" className={selectClass} value={selectedYear} onChange={(e) => pickYear(e.target.value)}>
                            <option value="">{form.from || form.to ? 'Custom range' : 'All years'}</option>
                            {years.map((year) => (
                                <option key={year} value={year}>
                                    {year}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="history-from" className="text-xs">
                            From
                        </Label>
                        <Input id="history-from" type="date" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="history-to" className="text-xs">
                            To
                        </Label>
                        <Input id="history-to" type="date" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="history-loan" className="text-xs">
                            Loan
                        </Label>
                        <select
                            id="history-loan"
                            className={selectClass}
                            value={form.loan}
                            onChange={(e) => setForm({ ...form, loan: e.target.value })}
                        >
                            <option value="">All loans</option>
                            {loans.map((loanNo) => (
                                <option key={loanNo} value={loanNo}>
                                    {loanNo}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex gap-2">
                        <Button type="submit" variant="secondary" disabled={loading}>
                            {loading && <LoaderCircle className="size-4 animate-spin" />}
                            Apply
                        </Button>
                        {(filters.from || filters.to || filters.loan) && (
                            <Button type="button" variant="ghost" onClick={() => load({ from: '', to: '', loan: '' })}>
                                Clear
                            </Button>
                        )}
                    </div>
                </form>

                {tabs.length > 1 && (
                    <div className="flex gap-1 overflow-x-auto border-b" role="tablist">
                        {tabs.map(([key, label, Icon]) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={tab === key}
                                onClick={() => setTab(key)}
                                className={cn(
                                    '-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm',
                                    tab === key ? 'border-primary font-medium' : 'text-muted-foreground border-transparent',
                                )}
                            >
                                <Icon className="size-4" /> {label}
                            </button>
                        ))}
                    </div>
                )}
            </CardHeader>

            <CardContent className="space-y-4">
                {tab === 'payments' && payments && (
                    <DataTable
                        columns={paymentColumns}
                        rows={payments.data}
                        rowKey={(payment) => payment.receipt_no}
                        meta={payments.meta}
                        loading={loading}
                        empty="No payments for this selection."
                    />
                )}

                {tab === 'interest' && interest && (
                    <>
                        <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            {(
                                [
                                    ['Interest charged', interest.totals.charged],
                                    ['Paid', interest.totals.paid],
                                    ['Waived', interest.totals.waived],
                                    ['Unpaid (due)', interest.totals.unpaid],
                                ] as const
                            ).map(([label, value]) => (
                                <div key={label} className="rounded-lg border p-3">
                                    <dt className="text-muted-foreground text-xs">{label}</dt>
                                    <dd className="mt-0.5 font-semibold tabular-nums">{formatMoney(value)}</dd>
                                </div>
                            ))}
                        </dl>
                        <p className="text-muted-foreground text-xs">
                            {interest.totals.months} month(s), {interest.totals.overdue_months} overdue. The month still running shows nothing unpaid
                            until it falls due. The date range applies to the due date.
                        </p>
                        <DataTable
                            columns={interestColumns}
                            rows={interest.months.data}
                            rowKey={(row) => `${row.loan_no}-${row.period_start}`}
                            meta={interest.months.meta}
                            loading={loading}
                            empty="No interest months for this selection."
                        />
                    </>
                )}

                {tab === 'ledger' && ledger && (
                    <>
                        <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            {(
                                [
                                    ['Brought forward', ledger.totals.opening_balance],
                                    ['Debits', ledger.totals.debit],
                                    ['Credits', ledger.totals.credit],
                                    [filters.to ? 'Carried forward' : 'Closing balance', ledger.totals.closing_balance],
                                ] as const
                            ).map(([label, value]) => (
                                <div key={label} className="rounded-lg border p-3">
                                    <dt className="text-muted-foreground text-xs">{label}</dt>
                                    <dd className="mt-0.5 font-semibold tabular-nums">{formatMoney(value)}</dd>
                                </div>
                            ))}
                        </dl>
                        <DataTable
                            columns={ledgerColumns}
                            rows={ledger.entries.data.map((row, index) => ({ ...row, index }))}
                            rowKey={(row) => row.index}
                            meta={ledger.entries.meta}
                            loading={loading}
                            empty="No ledger entries for this selection."
                        />
                    </>
                )}
            </CardContent>
        </Card>
    );
}
