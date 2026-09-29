import { DataTable, type DataTableColumn } from '@/components/data-table';
import { Tabs, type TabItem } from '@/components/tabs';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { CollateralFormDialog } from '@/features/collateral/collateral-form-dialog';
import { CollateralReleaseAction } from '@/features/collateral/release-action';
import { type CollateralItem, type LoanCollateral } from '@/features/collateral/types';
import { type Customer } from '@/features/customers/types';
import { LoanActions } from '@/features/loans/loan-actions';
import { LoanStatusBadge, PeriodStatusBadge } from '@/features/loans/status-badge';
import { type InterestPeriodRow, type Loan, type LoanPayment, type LoanSummary } from '@/features/loans/types';
import { useCan } from '@/hooks/use-can';
import { useVisitState } from '@/hooks/use-visit-state';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { BookOpen, CircleAlert, LoaderCircle, Pencil, Plus, ReceiptText } from 'lucide-react';
import { useState, type ReactNode } from 'react';

interface ShowLoanProps {
    loan: Loan;
    /** Null when the user may not view the customer record. */
    customer: Customer | null;
    summary: LoanSummary;
    /** Null when the user may not view collateral. */
    collateral: LoanCollateral | null;
    /** Null when the user may not view payments. */
    payments: LoanPayment[] | null;
    collateralTypes: string[];
    maxKarat: string;
    karatOptions: string[];
}

type Tab = 'overview' | 'collateral' | 'payments' | 'summary';

const RATE_TYPE: Record<Loan['interest_rate_type'], string> = { monthly: 'per month', yearly: 'per year' };

function label(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1).replaceAll('_', ' ');
}

function Figure({ label, value, hint, tone }: { label: string; value: ReactNode; hint?: ReactNode; tone?: 'danger' }) {
    return (
        <div className="rounded-lg border p-4">
            <p className="text-muted-foreground text-sm">{label}</p>
            <p className={cn('mt-1 text-lg font-semibold tabular-nums', tone === 'danger' && 'text-red-600 dark:text-red-400')}>{value}</p>
            {hint && <p className="text-muted-foreground mt-0.5 text-xs">{hint}</p>}
        </div>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-muted-foreground text-sm">{label}</dt>
            <dd className="mt-0.5 text-sm break-words">{children ?? '—'}</dd>
        </div>
    );
}

/** A row of a summary table: label left, server amount right. */
function Line({ label, value, strong }: { label: string; value: ReactNode; strong?: boolean }) {
    return (
        <div className={cn('flex items-baseline justify-between gap-4 py-2', strong && 'font-semibold')}>
            <dt className={cn(!strong && 'text-muted-foreground')}>{label}</dt>
            <dd className="tabular-nums">{value}</dd>
        </div>
    );
}

export default function ShowLoan({ loan, customer, summary, collateral, payments, collateralTypes, maxKarat, karatOptions }: ShowLoanProps) {
    const can = useCan();
    const { url } = usePage();
    const initialTab = new URLSearchParams(url.split('?')[1] ?? '').get('tab') as Tab | null;
    const [tab, setTab] = useState<Tab>(initialTab ?? 'overview');
    const [editing, setEditing] = useState<CollateralItem | 'new' | null>(null);
    const { loading, error, retry } = useVisitState(`/loans/${loan.loan_no}`);

    const breadcrumbs: BreadcrumbItem[] = [
        ...(loan.customer
            ? [
                  { title: 'Customers', href: '/customers' },
                  { title: loan.customer.name, href: `/customers/${loan.customer.customer_no}` },
              ]
            : []),
        { title: loan.loan_no, href: `/loans/${loan.loan_no}` },
    ];

    const tabs: TabItem[] = [
        { value: 'overview', label: 'Overview' },
        ...(collateral ? [{ value: 'collateral', label: 'Collateral', badge: collateral.held_count }] : []),
        ...(payments ? [{ value: 'payments', label: 'Payments', badge: payments.length }] : []),
        { value: 'summary', label: 'Summary' },
    ];
    const activeTab = tabs.some((item) => item.value === tab) ? tab : 'overview';
    const canAddCollateral = collateral !== null && can('collateral.create') && ['draft', 'active', 'overdue'].includes(loan.status);

    const collateralColumns: DataTableColumn<CollateralItem>[] = [
        {
            key: 'item',
            header: 'Item',
            cell: (item) => (
                <div className="min-w-32">
                    <span className="font-medium whitespace-nowrap">{item.collateral_no}</span>
                    {item.description && <p className="text-muted-foreground text-xs">{item.description}</p>}
                </div>
            ),
        },
        { key: 'type', header: 'Type', cell: (item) => label(item.type) },
        { key: 'weight', header: 'Weight (g)', cell: (item) => item.weight_grams, className: 'text-right tabular-nums' },
        { key: 'karat', header: 'Karat', cell: (item) => item.karat ?? '—', className: 'text-right tabular-nums' },
        {
            key: 'value',
            header: 'Estimated value',
            cell: (item) => formatMoney(item.estimated_value),
            className: 'text-right tabular-nums whitespace-nowrap',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (item) =>
                item.status === 'released' ? (
                    <div>
                        <Badge variant="outline">Released</Badge>
                        <p className="text-muted-foreground mt-1 text-xs whitespace-nowrap">
                            {formatDateTime(item.released_at)}
                            {item.released_by && ` · ${item.released_by}`}
                        </p>
                    </div>
                ) : (
                    <Badge variant="secondary">Held</Badge>
                ),
        },
        {
            key: 'received',
            header: 'Received',
            cell: (item) => formatDateTime(item.received_at),
            className: 'hidden lg:table-cell whitespace-nowrap',
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            cell: (item) => (
                <div className="flex justify-end gap-2">
                    {item.actions.update && (
                        <Button size="sm" variant="outline" onClick={() => setEditing(item)}>
                            Edit
                        </Button>
                    )}
                    <CollateralReleaseAction item={item} />
                </div>
            ),
        },
    ];

    const paymentColumns: DataTableColumn<LoanPayment>[] = [
        {
            key: 'receipt',
            header: 'Receipt',
            cell: (payment) => (
                <span className="inline-flex items-center gap-2 whitespace-nowrap">
                    <Link href={route('payments.show', payment.receipt_no)} className="font-medium hover:underline">
                        {payment.receipt_no}
                    </Link>
                    <Link href={route('payments.receipt', payment.receipt_no)} title="Receipt" aria-label={`Receipt ${payment.receipt_no}`}>
                        <ReceiptText className="text-muted-foreground hover:text-foreground size-4" />
                    </Link>
                </span>
            ),
        },
        { key: 'date', header: 'Date', cell: (payment) => formatDate(payment.payment_date), className: 'whitespace-nowrap' },
        { key: 'type', header: 'Type', cell: (payment) => label(payment.type) },
        { key: 'method', header: 'Method', cell: (payment) => label(payment.method), className: 'hidden md:table-cell' },
        {
            key: 'amount',
            header: 'Amount',
            cell: (payment) => <span className={cn(payment.reversed && 'text-muted-foreground line-through')}>{formatMoney(payment.amount)}</span>,
            className: 'text-right tabular-nums whitespace-nowrap',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (payment) =>
                payment.reversed ? (
                    <span title={payment.reversal_reason ?? undefined}>
                        <Badge variant="destructive">Reversed</Badge>
                    </span>
                ) : (
                    <Badge variant="secondary">{label(payment.status)}</Badge>
                ),
        },
        { key: 'reference', header: 'Reference', cell: (payment) => payment.reference ?? '—', className: 'hidden lg:table-cell' },
    ];

    const periodColumns: DataTableColumn<InterestPeriodRow>[] = [
        {
            key: 'period',
            header: 'Period',
            cell: (period) => (
                <span className="whitespace-nowrap">
                    {formatDate(period.period_start)} – {formatDate(period.period_end)}
                </span>
            ),
        },
        { key: 'due', header: 'Due', cell: (period) => formatDate(period.due_date), className: 'whitespace-nowrap' },
        { key: 'expected', header: 'Interest', cell: (period) => formatMoney(period.expected_interest), className: 'text-right tabular-nums' },
        { key: 'paid', header: 'Paid', cell: (period) => formatMoney(period.paid_interest), className: 'text-right tabular-nums' },
        { key: 'status', header: 'Status', cell: (period) => <PeriodStatusBadge status={period.status} /> },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Loan ${loan.loan_no}`} />
            <div className="space-y-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-xl font-semibold tracking-tight">{loan.loan_no}</h2>
                            <LoanStatusBadge status={loan.status} />
                            {loading && (
                                <span className="text-muted-foreground inline-flex items-center gap-1 text-xs" role="status">
                                    <LoaderCircle className="size-3 animate-spin" /> Updating…
                                </span>
                            )}
                        </div>
                        {loan.customer && (
                            <p className="text-muted-foreground text-sm">
                                {customer ? (
                                    <Link
                                        href={route('customers.show', loan.customer.customer_no)}
                                        className="text-foreground font-medium hover:underline"
                                    >
                                        {loan.customer.name}
                                    </Link>
                                ) : (
                                    <span className="text-foreground font-medium">{loan.customer.name}</span>
                                )}{' '}
                                · {loan.customer.customer_no} · {loan.customer.mobile}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can('payments.create') && ['active', 'overdue'].includes(loan.status) && (
                            <Button asChild>
                                <Link href={route('payments.create', { loan: loan.loan_no })}>
                                    <ReceiptText className="size-4" /> Record payment
                                </Link>
                            </Button>
                        )}
                        {loan.actions.update && (
                            <Button variant="outline" asChild>
                                <Link href={route('loans.edit', loan.loan_no)}>
                                    <Pencil className="size-4" /> Edit
                                </Link>
                            </Button>
                        )}
                        <LoanActions loan={loan} />
                    </div>
                </div>

                {error && (
                    <Alert variant="destructive">
                        <CircleAlert className="size-4" />
                        <AlertTitle>Could not refresh the loan</AlertTitle>
                        <AlertDescription>
                            <p>{error}</p>
                            <Button variant="outline" size="sm" className="mt-2" onClick={retry}>
                                Try again
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                {loan.status === 'draft' && (
                    <Alert>
                        <CircleAlert className="size-4" />
                        <AlertTitle>Draft loan — next steps</AlertTitle>
                        <AlertDescription>
                            <ol className="list-inside list-decimal">
                                <li>
                                    Add the collateral held against this loan
                                    {collateral && ` (${collateral.held_count} item(s) recorded so far)`}.
                                </li>
                                <li>Review the terms and collateral{loan.actions.update ? ' (use Edit to correct the terms)' : ''}.</li>
                                <li>Activate the loan: its terms are then locked and interest starts running.</li>
                            </ol>
                            {collateral && canAddCollateral && (
                                <Button size="sm" className="mt-2" onClick={() => setTab('collateral')}>
                                    <Plus className="size-4" /> Go to collateral
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <section aria-label="Loan figures" className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Figure label="Principal" value={formatMoney(loan.principal)} />
                    <Figure label="Outstanding principal" value={formatMoney(loan.outstanding_principal)} />
                    <Figure
                        label="Interest"
                        value={`${loan.interest_rate}% ${RATE_TYPE[loan.interest_rate_type]}`}
                        hint={
                            summary.interest_due !== '0.00' ? (
                                <span className="text-red-600 dark:text-red-400">{formatMoney(summary.interest_due)} due</span>
                            ) : (
                                'Nothing due'
                            )
                        }
                    />
                    <Figure
                        label="Next due"
                        value={formatDate(summary.next_due_date)}
                        hint={summary.overdue_periods > 0 ? `${summary.overdue_periods} period(s) overdue` : undefined}
                        tone={summary.overdue_periods > 0 ? 'danger' : undefined}
                    />
                </section>

                <Tabs tabs={tabs} value={activeTab} onChange={(value) => setTab(value as Tab)} label="Loan details">
                    {(current) => (
                        <>
                            {current === 'overview' && (
                                <div className="grid gap-6 lg:grid-cols-3">
                                    <Card className="lg:col-span-2">
                                        <CardHeader>
                                            <CardTitle>Loan information</CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <dl className="grid gap-4 sm:grid-cols-2">
                                                <Detail label="Loan number">{loan.loan_no}</Detail>
                                                <Detail label="Status">
                                                    <LoanStatusBadge status={loan.status} />
                                                </Detail>
                                                <Detail label="Start date">{formatDate(loan.start_date)}</Detail>
                                                <Detail label="Interest">
                                                    {loan.interest_rate}% {RATE_TYPE[loan.interest_rate_type]}, charged per{' '}
                                                    {loan.interest_period_unit}
                                                </Detail>
                                                <Detail label="Next due">{formatDate(loan.next_due_date)}</Detail>
                                                {loan.closed_at && <Detail label="Closed">{formatDateTime(loan.closed_at)}</Detail>}
                                                <div className="sm:col-span-2">
                                                    <Detail label="Notes">
                                                        {loan.notes && <span className="whitespace-pre-line">{loan.notes}</span>}
                                                    </Detail>
                                                </div>
                                            </dl>
                                        </CardContent>
                                    </Card>
                                    <div className="space-y-6">
                                        <Card>
                                            <CardHeader>
                                                <CardTitle>Current balances</CardTitle>
                                                <CardDescription>Calculated by the server.</CardDescription>
                                            </CardHeader>
                                            <CardContent>
                                                <dl className="divide-y text-sm">
                                                    <Line label="Outstanding principal" value={formatMoney(summary.outstanding_principal)} />
                                                    <Line label="Interest due" value={formatMoney(summary.interest_due)} />
                                                    <Line label="Consecutive missed" value={summary.consecutive_missed} />
                                                </dl>
                                            </CardContent>
                                        </Card>
                                        <Card>
                                            <CardHeader>
                                                <CardTitle>Customer</CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                {customer ? (
                                                    <dl className="grid gap-3">
                                                        <Detail label="Name">
                                                            <Link
                                                                href={route('customers.show', customer.customer_no)}
                                                                className="font-medium hover:underline"
                                                            >
                                                                {customer.name}
                                                            </Link>
                                                        </Detail>
                                                        <Detail label="Customer number">{customer.customer_no}</Detail>
                                                        <Detail label="Mobile">{customer.mobile}</Detail>
                                                        <Detail label="NID">{customer.nid}</Detail>
                                                        <Detail label="Address">{customer.address}</Detail>
                                                    </dl>
                                                ) : (
                                                    <p className="text-muted-foreground text-sm">
                                                        {loan.customer ? `${loan.customer.name} (${loan.customer.customer_no})` : '—'}
                                                    </p>
                                                )}
                                            </CardContent>
                                        </Card>
                                    </div>
                                </div>
                            )}

                            {current === 'collateral' && collateral && (
                                <div className="space-y-4">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <p className="text-muted-foreground text-sm">
                                            Held: {collateral.held_count} item(s) · {collateral.held_weight_grams} g · estimated{' '}
                                            {formatMoney(collateral.held_estimated_value)}
                                        </p>
                                        {canAddCollateral && (
                                            <Button onClick={() => setEditing('new')}>
                                                <Plus className="size-4" /> Add collateral
                                            </Button>
                                        )}
                                    </div>
                                    {['closed', 'cancelled'].includes(loan.status) && collateral.held_count > 0 && (
                                        <Alert>
                                            <CircleAlert className="size-4" />
                                            <AlertTitle>Collateral can be released</AlertTitle>
                                            <AlertDescription>
                                                The loan is {loan.status}; release each item when it is returned to the customer.
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    <DataTable
                                        columns={collateralColumns}
                                        rows={collateral.items}
                                        rowKey={(item) => item.collateral_no}
                                        loading={loading}
                                        empty={
                                            canAddCollateral
                                                ? 'No collateral recorded yet. Add the items held against this loan.'
                                                : 'No collateral recorded.'
                                        }
                                    />
                                </div>
                            )}

                            {current === 'payments' && payments && (
                                <div className="space-y-4">
                                    <p className="text-muted-foreground text-sm">
                                        {summary.payments_count} payment(s) posted · total {formatMoney(summary.payments_total)}. Reversed payments
                                        stay listed.
                                    </p>
                                    <DataTable
                                        columns={paymentColumns}
                                        rows={payments}
                                        rowKey={(payment) => payment.receipt_no}
                                        loading={loading}
                                        empty="No payments recorded for this loan yet."
                                    />
                                </div>
                            )}

                            {current === 'summary' && (
                                <div className="space-y-6">
                                    <div className="grid gap-6 lg:grid-cols-3">
                                        <Card>
                                            <CardHeader>
                                                <CardTitle>Principal</CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                <dl className="divide-y text-sm">
                                                    <Line label="Principal" value={formatMoney(summary.principal)} />
                                                    <Line label="Repaid" value={formatMoney(summary.principal_repaid)} />
                                                    <Line label="Outstanding" value={formatMoney(summary.outstanding_principal)} strong />
                                                </dl>
                                            </CardContent>
                                        </Card>
                                        <Card>
                                            <CardHeader>
                                                <CardTitle>Interest</CardTitle>
                                                <CardDescription>Periods due to date.</CardDescription>
                                            </CardHeader>
                                            <CardContent>
                                                <dl className="divide-y text-sm">
                                                    <Line label="Charged" value={formatMoney(summary.interest_charged)} />
                                                    <Line label="Paid" value={formatMoney(summary.interest_paid)} />
                                                    <Line label="Waived" value={formatMoney(summary.interest_waived)} />
                                                    <Line label="Due" value={formatMoney(summary.interest_due)} strong />
                                                </dl>
                                            </CardContent>
                                        </Card>
                                        <Card>
                                            <CardHeader>
                                                <CardTitle>Payments</CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                <dl className="divide-y text-sm">
                                                    <Line label="Payments posted" value={summary.payments_count} />
                                                    <Line label="Fees paid" value={formatMoney(summary.fees_paid)} />
                                                    <Line
                                                        label="Last payment"
                                                        value={
                                                            summary.last_payment
                                                                ? `${formatMoney(summary.last_payment.amount)} · ${formatDate(summary.last_payment.date)}`
                                                                : '—'
                                                        }
                                                    />
                                                    <Line label="Total received" value={formatMoney(summary.payments_total)} strong />
                                                </dl>
                                            </CardContent>
                                        </Card>
                                    </div>

                                    <Card>
                                        <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="space-y-1.5">
                                                <CardTitle>Due information</CardTitle>
                                                <CardDescription>
                                                    Next due {formatDate(summary.next_due_date)} · {summary.overdue_periods} overdue period(s) ·{' '}
                                                    {summary.consecutive_missed} consecutive missed
                                                </CardDescription>
                                            </div>
                                            {can('reports.view') && (
                                                // The customer ledger arrives with the reports module.
                                                <Button variant="outline" disabled title="Available once the reports module is enabled">
                                                    <BookOpen className="size-4" /> Ledger
                                                </Button>
                                            )}
                                        </CardHeader>
                                        <CardContent>
                                            <DataTable
                                                columns={periodColumns}
                                                rows={summary.periods}
                                                rowKey={(period) => period.period_start}
                                                loading={loading}
                                                empty={
                                                    loan.status === 'draft'
                                                        ? 'Interest periods are generated when the loan is activated.'
                                                        : 'No interest periods yet.'
                                                }
                                            />
                                        </CardContent>
                                    </Card>
                                </div>
                            )}
                        </>
                    )}
                </Tabs>
            </div>

            {collateral && (
                <CollateralFormDialog
                    key={editing === null || editing === 'new' ? 'new' : editing.collateral_no}
                    open={editing !== null}
                    onOpenChange={(open) => !open && setEditing(null)}
                    loanNo={loan.loan_no}
                    item={editing === null || editing === 'new' ? undefined : editing}
                    reasonRequired={loan.status !== 'draft'}
                    types={collateralTypes}
                    maxKarat={maxKarat}
                    karatOptions={karatOptions}
                />
            )}
        </AppLayout>
    );
}
