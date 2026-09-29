import { DataTable, type DataTableColumn } from '@/components/data-table';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoanStatusBadge } from '@/features/loans/status-badge';
import { type LoanStatus } from '@/features/loans/types';
import { paymentTypeLabel } from '@/features/payments/types';
import { useVisitState } from '@/hooks/use-visit-state';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { BellRing, CircleAlert, Coins, HandCoins, Landmark, LoaderCircle, type LucideIcon, TriangleAlert } from 'lucide-react';
import { type ReactNode } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

/** App\Domain\Reporting\DashboardMetricsService — every figure is the server's. */
interface LoanSummary {
    active_loans: number;
    overdue_loans: number;
    draft_loans: number;
    outstanding_principal: string;
    due_interest: string;
    due_periods: number;
    overdue_interest: string;
    overdue_accounts: number;
    overdue_customers: number;
}

interface Collections {
    month: string;
    from: string;
    to: string;
    total: string;
    payments: number;
    interest: string;
    principal: string;
    fees: string;
    revenue: string;
}

interface OverdueAccount {
    loan_no: string;
    status: LoanStatus;
    customer: { customer_no: string; name: string; mobile: string };
    overdue_interest: string;
    overdue_periods: number;
    oldest_due_date: string;
    consecutive_missed: number;
}

interface RecentPayment {
    receipt_no: string;
    payment_date: string;
    type: string;
    amount: string;
    status: 'posted' | 'reversed';
    customer: string | null;
    loan_no: string | null;
}

interface RecentLoan {
    loan_no: string;
    status: LoanStatus;
    principal: string;
    start_date: string;
    customer: string;
}

interface DashboardProps {
    asOf: string;
    timezone: string;
    month: string;
    /** Null when the user may not view loans. */
    loanSummary: LoanSummary | null;
    alerts: { open: number; customers: number; threshold: number } | null;
    overdueAccounts: OverdueAccount[] | null;
    recentLoans: RecentLoan[] | null;
    /** Null when the user may not view payments. */
    collections: Collections | null;
    recentPayments: RecentPayment[] | null;
}

function monthLabel(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return new Date(year, number - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

function Kpi({
    title,
    value,
    hint,
    icon: Icon,
    href,
    tone,
}: {
    title: string;
    value: ReactNode;
    hint?: ReactNode;
    icon: LucideIcon;
    href?: string;
    tone?: 'danger';
}) {
    const body = (
        <Card className={cn('h-full gap-2 py-4', href && 'hover:bg-muted/40 transition-colors')}>
            <CardHeader className="flex flex-row items-center justify-between gap-2 px-4">
                <CardTitle className="text-muted-foreground text-sm font-medium">{title}</CardTitle>
                <Icon className={cn('text-muted-foreground size-4', tone === 'danger' && 'text-red-600 dark:text-red-400')} aria-hidden />
            </CardHeader>
            <CardContent className="px-4">
                <p className={cn('text-2xl font-semibold tabular-nums', tone === 'danger' && 'text-red-600 dark:text-red-400')}>{value}</p>
                {hint && <p className="text-muted-foreground mt-1 text-xs">{hint}</p>}
            </CardContent>
        </Card>
    );

    return href ? (
        <Link href={href} className="block rounded-xl focus-visible:ring-[3px] focus-visible:outline-none">
            {body}
        </Link>
    ) : (
        body
    );
}

const overdueColumns: DataTableColumn<OverdueAccount>[] = [
    {
        key: 'loan',
        header: 'Loan',
        cell: (row) => (
            <div className="min-w-32">
                <Link href={route('loans.show', row.loan_no)} className="font-medium hover:underline">
                    {row.loan_no}
                </Link>
                <div className="text-muted-foreground text-xs">
                    {row.customer.name} · {row.customer.mobile}
                </div>
            </div>
        ),
    },
    { key: 'since', header: 'Oldest due', cell: (row) => formatDate(row.oldest_due_date), className: 'hidden sm:table-cell whitespace-nowrap' },
    { key: 'missed', header: 'Missed', cell: (row) => <Badge variant="destructive">{row.consecutive_missed}</Badge>, className: 'text-right' },
    {
        key: 'amount',
        header: 'Overdue interest',
        cell: (row) => formatMoney(row.overdue_interest),
        className: 'text-right tabular-nums whitespace-nowrap',
    },
];

const paymentColumns: DataTableColumn<RecentPayment>[] = [
    {
        key: 'receipt',
        header: 'Receipt',
        cell: (row) => (
            <div>
                <Link href={route('payments.show', row.receipt_no)} className="font-medium whitespace-nowrap hover:underline">
                    {row.receipt_no}
                </Link>
                <div className="text-muted-foreground text-xs">{row.customer}</div>
            </div>
        ),
    },
    { key: 'date', header: 'Date', cell: (row) => formatDate(row.payment_date), className: 'hidden sm:table-cell whitespace-nowrap' },
    { key: 'type', header: 'Type', cell: (row) => paymentTypeLabel(row.type), className: 'hidden md:table-cell' },
    {
        key: 'amount',
        header: 'Amount',
        cell: (row) => <span className={cn(row.status === 'reversed' && 'text-muted-foreground line-through')}>{formatMoney(row.amount)}</span>,
        className: 'text-right tabular-nums whitespace-nowrap',
    },
];

const loanColumns: DataTableColumn<RecentLoan>[] = [
    {
        key: 'loan',
        header: 'Loan',
        cell: (row) => (
            <div>
                <Link href={route('loans.show', row.loan_no)} className="font-medium whitespace-nowrap hover:underline">
                    {row.loan_no}
                </Link>
                <div className="text-muted-foreground text-xs">{row.customer}</div>
            </div>
        ),
    },
    { key: 'start', header: 'Start', cell: (row) => formatDate(row.start_date), className: 'hidden sm:table-cell whitespace-nowrap' },
    { key: 'status', header: 'Status', cell: (row) => <LoanStatusBadge status={row.status} /> },
    { key: 'principal', header: 'Principal', cell: (row) => formatMoney(row.principal), className: 'text-right tabular-nums whitespace-nowrap' },
];

export default function Dashboard({
    asOf,
    timezone,
    month,
    loanSummary,
    alerts,
    overdueAccounts,
    recentLoans,
    collections,
    recentPayments,
}: DashboardProps) {
    const { auth } = usePage<SharedData>().props;
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const { loading, error, retry } = useVisitState('/dashboard');
    const hasMetrics = loanSummary !== null || collections !== null;

    const changeMonth = (value: string) => {
        if (value)
            router.get(
                route('dashboard'),
                { month: value },
                { preserveState: true, preserveScroll: true, replace: true, only: ['month', 'collections'] },
            );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <Heading
                        title={`Welcome, ${auth.user.name}`}
                        description={
                            hasMetrics ? `Figures as of ${formatDate(asOf)} (${timezone}). Collections are for the selected month.` : undefined
                        }
                    />
                    {collections && (
                        <div className="flex items-center gap-2">
                            <Label htmlFor="month" className="text-muted-foreground text-sm font-normal">
                                Month
                            </Label>
                            <Input
                                id="month"
                                type="month"
                                value={month}
                                max={asOf.slice(0, 7)}
                                onChange={(e) => changeMonth(e.target.value)}
                                className="w-44"
                            />
                            {loading && <LoaderCircle className="text-muted-foreground size-4 animate-spin" aria-label="Loading" />}
                        </div>
                    )}
                </div>

                {(error || errors.month) && (
                    <Alert variant="destructive">
                        <CircleAlert className="size-4" />
                        <AlertTitle>Could not load the dashboard</AlertTitle>
                        <AlertDescription>
                            <p>{error ?? errors.month}</p>
                            {error && (
                                <Button variant="outline" size="sm" className="mt-2" onClick={retry}>
                                    Try again
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {!hasMetrics && (
                    <Card className="max-w-md">
                        <CardHeader>
                            <CardTitle className="text-base">Your access</CardTitle>
                            <CardDescription>
                                Role: {auth.user.roles.join(', ') || 'None assigned'} · {auth.user.permissions.length} permission(s). Use the
                                navigation to open the modules available to you.
                            </CardDescription>
                        </CardHeader>
                    </Card>
                )}

                {hasMetrics && (
                    <section
                        aria-label="Key figures"
                        className={cn('grid gap-4 sm:grid-cols-2 xl:grid-cols-3', loading && 'opacity-60 transition-opacity')}
                    >
                        {loanSummary && (
                            <Kpi
                                title="Active loans"
                                value={loanSummary.active_loans}
                                hint={`${loanSummary.overdue_loans} overdue · ${loanSummary.draft_loans} draft`}
                                icon={HandCoins}
                                href={route('loans.index', { status: 'open' })}
                            />
                        )}
                        {collections && (
                            <Kpi
                                title={`Collection · ${monthLabel(collections.month)}`}
                                value={formatMoney(collections.total)}
                                hint={`${collections.payments} payment(s) · revenue ${formatMoney(collections.revenue)}`}
                                icon={Coins}
                                href={route('payments.index', { paid_from: collections.from, paid_to: collections.to, status: 'posted' })}
                            />
                        )}
                        {loanSummary && (
                            <Kpi
                                title="Due interest"
                                value={formatMoney(loanSummary.due_interest)}
                                hint={`${loanSummary.due_periods} period(s) due to date · ${formatMoney(loanSummary.overdue_interest)} overdue`}
                                icon={TriangleAlert}
                                tone={loanSummary.due_interest !== '0.00' ? 'danger' : undefined}
                            />
                        )}
                        {loanSummary && (
                            <Kpi
                                title="Overdue accounts"
                                value={loanSummary.overdue_accounts}
                                hint={`${loanSummary.overdue_customers} customer(s) with missed interest`}
                                icon={CircleAlert}
                                href={route('loans.index', { overdue: 1 })}
                                tone={loanSummary.overdue_accounts > 0 ? 'danger' : undefined}
                            />
                        )}
                        {loanSummary && (
                            <Kpi
                                title="Outstanding loan amount"
                                value={formatMoney(loanSummary.outstanding_principal)}
                                hint="Principal outstanding on active and overdue loans"
                                icon={Landmark}
                            />
                        )}
                        {alerts && (
                            <Kpi
                                title="Missed payment alerts"
                                value={alerts.open}
                                hint={`${alerts.customers} customer(s) · alert at ${alerts.threshold} consecutive missed period(s)`}
                                icon={BellRing}
                                href={route('loans.index', { overdue: 1 })}
                                tone={alerts.open > 0 ? 'danger' : undefined}
                            />
                        )}
                    </section>
                )}

                {collections && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Collections · {monthLabel(collections.month)}</CardTitle>
                            <CardDescription>
                                Payments dated {formatDate(collections.from)} – {formatDate(collections.to)}; reversed payments excluded. Revenue =
                                interest + fees.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-2 gap-4 text-sm md:grid-cols-5">
                                {[
                                    ['Interest', collections.interest],
                                    ['Principal', collections.principal],
                                    ['Fees', collections.fees],
                                    ['Revenue', collections.revenue],
                                    ['Total collected', collections.total],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-muted-foreground">{label}</dt>
                                        <dd className="mt-0.5 font-semibold tabular-nums">{formatMoney(value)}</dd>
                                    </div>
                                ))}
                            </dl>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-6 xl:grid-cols-2">
                    {overdueAccounts && (
                        <Card className="xl:col-span-2">
                            <CardHeader>
                                <CardTitle>Overdue accounts</CardTitle>
                                <CardDescription>Oldest missed interest first.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <DataTable
                                    columns={overdueColumns}
                                    rows={overdueAccounts}
                                    rowKey={(row) => row.loan_no}
                                    empty="No overdue accounts. Every due interest period is paid."
                                />
                            </CardContent>
                        </Card>
                    )}
                    {recentPayments && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Recent payments</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <DataTable
                                    columns={paymentColumns}
                                    rows={recentPayments}
                                    rowKey={(row) => row.receipt_no}
                                    empty="No payments recorded yet."
                                />
                            </CardContent>
                        </Card>
                    )}
                    {recentLoans && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Recently created loans</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <DataTable columns={loanColumns} rows={recentLoans} rowKey={(row) => row.loan_no} empty="No loans yet." />
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
