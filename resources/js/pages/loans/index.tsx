import { DataTable, type DataTableColumn } from '@/components/data-table';
import { ExportButtons } from '@/components/export-buttons';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoanStatusBadge } from '@/features/loans/status-badge';
import { type Loan } from '@/features/loans/types';
import { useCan } from '@/hooks/use-can';
import { useVisitState } from '@/hooks/use-visit-state';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/format';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, ReceiptText } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Loans', href: '/loans' }];
const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

interface LoanFilters {
    q: string;
    status: string;
    overdue: boolean;
    started_from: string;
    started_to: string;
    due_by: string;
}

const RATE_TYPE: Record<Loan['interest_rate_type'], string> = { monthly: '/ month', yearly: '/ year' };

export default function LoansIndex({ loans, filters }: { loans: Paginated<Loan>; filters: LoanFilters }) {
    const can = useCan();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [form, setForm] = useState<LoanFilters>(filters);
    const { loading, error, retry } = useVisitState('/loans');

    const hasFilters =
        filters.q !== '' ||
        filters.status !== '' ||
        filters.overdue ||
        filters.started_from !== '' ||
        filters.started_to !== '' ||
        filters.due_by !== '';

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        const query = Object.fromEntries(Object.entries({ ...form, overdue: form.overdue ? '1' : '' }).filter(([, value]) => value !== ''));
        router.get(route('loans.index'), query, { preserveState: true, replace: true });
    };

    const columns: DataTableColumn<Loan>[] = [
        {
            key: 'loan_no',
            header: 'Loan no.',
            cell: (loan) => (
                <Link href={route('loans.show', loan.loan_no)} className="font-medium whitespace-nowrap hover:underline">
                    {loan.loan_no}
                </Link>
            ),
        },
        {
            key: 'customer',
            header: 'Customer',
            cell: (loan) =>
                loan.customer && (
                    <div className="min-w-32">
                        {loan.customer.name}
                        <div className="text-muted-foreground text-xs">
                            {loan.customer.customer_no} · {loan.customer.mobile}
                        </div>
                    </div>
                ),
        },
        {
            key: 'principal',
            header: 'Principal',
            cell: (loan) => formatMoney(loan.principal),
            className: 'hidden md:table-cell text-right tabular-nums',
        },
        {
            key: 'outstanding',
            header: 'Outstanding',
            cell: (loan) => formatMoney(loan.outstanding_principal),
            className: 'text-right tabular-nums whitespace-nowrap',
        },
        {
            key: 'rate',
            header: 'Rate',
            cell: (loan) => `${loan.interest_rate}% ${RATE_TYPE[loan.interest_rate_type]}`,
            className: 'hidden lg:table-cell whitespace-nowrap',
        },
        { key: 'status', header: 'Status', cell: (loan) => <LoanStatusBadge status={loan.status} /> },
        { key: 'next_due', header: 'Next due', cell: (loan) => formatDate(loan.next_due_date), className: 'hidden sm:table-cell whitespace-nowrap' },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            cell: (loan) => (
                <div className="flex justify-end gap-2">
                    <Button size="sm" variant="outline" asChild>
                        <Link href={route('loans.show', loan.loan_no)}>View</Link>
                    </Button>
                    {can('payments.create') && ['active', 'overdue'].includes(loan.status) && (
                        <Button size="sm" variant="secondary" asChild className="hidden sm:inline-flex">
                            <Link href={route('payments.create', { loan: loan.loan_no })}>
                                <ReceiptText className="size-4" /> Pay
                            </Link>
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Loans" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Loans" description="All loans with their outstanding principal and next due date." />
                    <div className="flex flex-wrap items-center gap-2">
                        <ExportButtons routeName="loans.export" filters={filters} />
                        {can('loans.create') && (
                            <Button asChild>
                                <Link href={route('loans.create')}>
                                    <Plus className="size-4" /> New loan
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <form onSubmit={apply} className="space-y-2" aria-label="Filter loans">
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <Input
                            type="search"
                            placeholder="Loan no., customer name, no. or mobile"
                            aria-label="Search"
                            value={form.q}
                            onChange={(e) => setForm({ ...form, q: e.target.value })}
                            className="sm:max-w-xs"
                        />
                        <select
                            className={selectClass}
                            value={form.status}
                            onChange={(e) => setForm({ ...form, status: e.target.value })}
                            aria-label="Status"
                        >
                            <option value="">All statuses</option>
                            <option value="open">Open (active + overdue)</option>
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="overdue">Overdue</option>
                            <option value="closed">Closed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={form.overdue} onCheckedChange={(checked) => setForm({ ...form, overdue: checked === true })} />
                            Missed interest only
                        </label>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <Label htmlFor="started_from" className="text-muted-foreground text-sm font-normal">
                            Started
                        </Label>
                        <Input
                            id="started_from"
                            type="date"
                            aria-label="Started from"
                            value={form.started_from}
                            onChange={(e) => setForm({ ...form, started_from: e.target.value })}
                            className="sm:w-40"
                        />
                        <span className="text-muted-foreground hidden text-sm sm:inline">to</span>
                        <Input
                            type="date"
                            aria-label="Started to"
                            value={form.started_to}
                            onChange={(e) => setForm({ ...form, started_to: e.target.value })}
                            className="sm:w-40"
                        />
                        <Label htmlFor="due_by" className="text-muted-foreground text-sm font-normal">
                            Due by
                        </Label>
                        <Input
                            id="due_by"
                            type="date"
                            value={form.due_by}
                            onChange={(e) => setForm({ ...form, due_by: e.target.value })}
                            className="sm:w-40"
                        />
                        <Button type="submit" variant="secondary" disabled={loading}>
                            Filter
                        </Button>
                        {hasFilters && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => router.get(route('loans.index'), {}, { replace: true })}
                                disabled={loading}
                            >
                                Reset
                            </Button>
                        )}
                    </div>
                    {Object.entries(errors).map(([field, message]) => (
                        <InputError key={field} message={message} />
                    ))}
                </form>

                <DataTable
                    columns={columns}
                    rows={loans.data}
                    rowKey={(loan) => loan.loan_no}
                    meta={loans.meta}
                    loading={loading}
                    error={error}
                    onRetry={retry}
                    empty={hasFilters ? 'No loans match these filters.' : 'No loans yet.'}
                />
            </div>
        </AppLayout>
    );
}
