import { DataTable, type DataTableColumn } from '@/components/data-table';
import { ExportButtons } from '@/components/export-buttons';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { methodLabel, type Payment, paymentTypeLabel } from '@/features/payments/types';
import { useCan } from '@/hooks/use-can';
import { useVisitState } from '@/hooks/use-visit-state';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, ReceiptText } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Payments', href: '/payments' }];
const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

interface Filters {
    q: string;
    type: string;
    method: string;
    status: string;
    paid_from: string;
    paid_to: string;
    [key: string]: string;
}

interface PaymentsIndexProps {
    payments: Paginated<Payment>;
    filters: Filters;
    types: string[];
    methods: string[];
}

export default function PaymentsIndex({ payments, filters, types, methods }: PaymentsIndexProps) {
    const can = useCan();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [form, setForm] = useState<Filters>(filters);
    const { loading, error, retry } = useVisitState('/payments');
    const hasFilters = ['q', 'type', 'method', 'status', 'paid_from', 'paid_to'].some((key) => filters[key] !== '');

    const apply: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(route('payments.index'), Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '')), {
            preserveState: true,
            replace: true,
        });
    };

    const set = (key: keyof Filters) => (e: { target: { value: string } }) => setForm({ ...form, [key]: e.target.value });

    const columns: DataTableColumn<Payment>[] = [
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
        {
            key: 'customer',
            header: 'Customer',
            cell: (payment) =>
                payment.customer && (
                    <div className="min-w-32">
                        {payment.customer.name}
                        <div className="text-muted-foreground text-xs">{payment.loan?.loan_no}</div>
                    </div>
                ),
        },
        { key: 'type', header: 'Type', cell: (payment) => paymentTypeLabel(payment.type), className: 'hidden md:table-cell' },
        { key: 'method', header: 'Method', cell: (payment) => methodLabel(payment.method), className: 'hidden lg:table-cell' },
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
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            cell: (payment) => (
                <div className="flex justify-end">
                    <Button size="sm" variant="outline" asChild>
                        <Link href={route('payments.receipt', payment.receipt_no)}>
                            <ReceiptText className="size-4" /> <span className="hidden sm:inline">Receipt</span>
                        </Link>
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payments" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Payments" description="Payments received against loans. Reversed payments stay listed." />
                    <div className="flex flex-wrap items-center gap-2">
                        <ExportButtons routeName="payments.export" filters={filters} />
                        {can('payments.create') && (
                            <Button asChild>
                                <Link href={route('payments.create')}>
                                    <Plus className="size-4" /> Record payment
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <form onSubmit={apply} className="space-y-2" aria-label="Filter payments">
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <Input
                            type="search"
                            placeholder="Receipt, loan, customer or reference"
                            aria-label="Search"
                            value={form.q}
                            onChange={set('q')}
                            className="sm:max-w-xs"
                        />
                        <select className={selectClass} value={form.type} onChange={set('type')} aria-label="Type">
                            <option value="">All types</option>
                            {types.map((type) => (
                                <option key={type} value={type}>
                                    {paymentTypeLabel(type)}
                                </option>
                            ))}
                        </select>
                        <select className={selectClass} value={form.method} onChange={set('method')} aria-label="Method">
                            <option value="">All methods</option>
                            {methods.map((method) => (
                                <option key={method} value={method}>
                                    {methodLabel(method)}
                                </option>
                            ))}
                        </select>
                        <select className={selectClass} value={form.status} onChange={set('status')} aria-label="Status">
                            <option value="">Posted and reversed</option>
                            <option value="posted">Posted</option>
                            <option value="reversed">Reversed</option>
                        </select>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <Label htmlFor="paid_from" className="text-muted-foreground text-sm font-normal">
                            Paid
                        </Label>
                        <Input
                            id="paid_from"
                            type="date"
                            aria-label="Paid from"
                            value={form.paid_from}
                            onChange={set('paid_from')}
                            className="sm:w-40"
                        />
                        <span className="text-muted-foreground hidden text-sm sm:inline">to</span>
                        <Input type="date" aria-label="Paid to" value={form.paid_to} onChange={set('paid_to')} className="sm:w-40" />
                        <Button type="submit" variant="secondary" disabled={loading}>
                            Filter
                        </Button>
                        {hasFilters && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => router.get(route('payments.index'), {}, { replace: true })}
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
                    rows={payments.data}
                    rowKey={(payment) => payment.receipt_no}
                    meta={payments.meta}
                    loading={loading}
                    error={error}
                    onRetry={retry}
                    empty={hasFilters ? 'No payments match these filters.' : 'No payments recorded yet.'}
                />
            </div>
        </AppLayout>
    );
}
