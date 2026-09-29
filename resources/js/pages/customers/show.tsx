import { DataTable, type DataTableColumn } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { CustomerArchiveAction } from '@/features/customers/archive-action';
import { CustomerStatusBadge } from '@/features/customers/status-badge';
import { type Customer, type CustomerLoan } from '@/features/customers/types';
import { useCan } from '@/hooks/use-can';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { BookOpen, Pencil, ReceiptText, UserRound } from 'lucide-react';
import { type ReactNode } from 'react';

interface ShowCustomerProps {
    customer: Customer;
    /** Null when the user may not view loans (the server decides). */
    activeLoans: CustomerLoan[] | null;
}

function Stat({ label, value, tone }: { label: string; value: ReactNode; tone?: 'danger' }) {
    return (
        <div className="rounded-lg border p-4">
            <p className="text-muted-foreground text-sm">{label}</p>
            <p className={cn('mt-1 text-xl font-semibold tabular-nums', tone === 'danger' && 'text-red-600 dark:text-red-400')}>{value}</p>
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

const loanColumns: DataTableColumn<CustomerLoan>[] = [
    {
        key: 'loan_no',
        header: 'Loan no.',
        cell: (loan) => (
            <Link href={route('loans.show', loan.loan_no)} className="font-medium whitespace-nowrap hover:underline">
                {loan.loan_no}
            </Link>
        ),
    },
    { key: 'principal', header: 'Principal', cell: (loan) => formatMoney(loan.principal), className: 'text-right tabular-nums' },
    { key: 'outstanding', header: 'Outstanding', cell: (loan) => formatMoney(loan.outstanding_principal), className: 'text-right tabular-nums' },
    {
        key: 'rate',
        header: 'Rate',
        cell: (loan) => `${loan.interest_rate} (${loan.interest_rate_type} / ${loan.interest_period_unit})`,
        className: 'hidden md:table-cell whitespace-nowrap',
    },
    {
        key: 'status',
        header: 'Status',
        cell: (loan) => <Badge variant={loan.status === 'overdue' ? 'destructive' : 'secondary'}>{loan.status}</Badge>,
    },
    { key: 'next_due', header: 'Next due', cell: (loan) => formatDate(loan.next_due_date), className: 'whitespace-nowrap' },
];

export default function ShowCustomer({ customer, activeLoans }: ShowCustomerProps) {
    const can = useCan();
    const summary = customer.summary;
    const missed = summary?.consecutive_missed ?? 0;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Customers', href: '/customers' },
        { title: customer.name, href: `/customers/${customer.customer_no}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={customer.name} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex items-center gap-4">
                        <div className="bg-muted flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full border">
                            {customer.image_url ? (
                                <img src={customer.image_url} alt="" className="size-full object-cover" />
                            ) : (
                                <UserRound className="text-muted-foreground size-7" aria-hidden />
                            )}
                        </div>
                        <div>
                            <h2 className="text-xl font-semibold tracking-tight">{customer.name}</h2>
                            <div className="text-muted-foreground flex items-center gap-2 text-sm">
                                {customer.customer_no} <CustomerStatusBadge status={customer.status} />
                            </div>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can('customers.update') && (
                            <Button variant="outline" asChild>
                                <Link href={route('customers.edit', customer.customer_no)}>
                                    <Pencil className="size-4" /> Edit
                                </Link>
                            </Button>
                        )}
                        <CustomerArchiveAction customer={customer} size="default" />
                    </div>
                </div>

                <section aria-label="Loan summary" className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <Stat label="Active loans" value={summary?.active_loans ?? 0} />
                    <Stat
                        label="Interest due"
                        value={formatMoney(summary?.total_interest_due)}
                        tone={summary && summary.total_interest_due !== '0.00' ? 'danger' : undefined}
                    />
                    <Stat label="Consecutive missed" value={missed} tone={missed > 0 ? 'danger' : undefined} />
                    <Stat
                        label="Last payment"
                        value={
                            summary?.last_payment ? (
                                <>
                                    {formatMoney(summary.last_payment.amount)}
                                    <span className="text-muted-foreground block text-xs font-normal">{formatDate(summary.last_payment.date)}</span>
                                </>
                            ) : (
                                '—'
                            )
                        }
                    />
                    <Stat label="Next due" value={formatDate(summary?.next_due_date)} />
                </section>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-1">
                        <CardHeader>
                            <CardTitle>Customer information</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-4">
                                <Detail label="Mobile">{customer.mobile}</Detail>
                                <Detail label="NID">{customer.nid}</Detail>
                                <Detail label="Address">{customer.address && <span className="whitespace-pre-line">{customer.address}</span>}</Detail>
                                <Detail label="Registered">{formatDateTime(customer.created_at)}</Detail>
                            </dl>
                        </CardContent>
                    </Card>

                    <div className="space-y-6 lg:col-span-2">
                        {activeLoans !== null && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Active loans</CardTitle>
                                    <CardDescription>Open loans (active or overdue). Amounts are calculated by the server.</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <DataTable columns={loanColumns} rows={activeLoans} rowKey={(loan) => loan.loan_no} empty="No active loans." />
                                </CardContent>
                            </Card>
                        )}

                        {(can('payments.view') || can('reports.view')) && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Payments &amp; ledger</CardTitle>
                                    <CardDescription>
                                        Payment history and the customer ledger open from here once the Payments and Reports modules are available.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-2">
                                    {can('payments.view') && (
                                        <Button variant="outline" disabled>
                                            <ReceiptText className="size-4" /> Payment history
                                        </Button>
                                    )}
                                    {can('reports.view') && (
                                        <Button variant="outline" disabled>
                                            <BookOpen className="size-4" /> Customer ledger
                                        </Button>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
