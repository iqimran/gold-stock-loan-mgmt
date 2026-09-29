import { DataTable, type DataTableColumn } from '@/components/data-table';
import { ExportButtons } from '@/components/export-buttons';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CustomerArchiveAction } from '@/features/customers/archive-action';
import { CustomerStatusBadge, MissedBadge } from '@/features/customers/status-badge';
import { type Customer, type CustomerFilters } from '@/features/customers/types';
import { useCan } from '@/hooks/use-can';
import { useVisitState } from '@/hooks/use-visit-state';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/format';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Customers', href: '/customers' }];

const selectClass = 'border-input bg-background h-9 rounded-md border px-3 text-sm';

interface CustomersIndexProps {
    customers: Paginated<Customer>;
    filters: CustomerFilters;
}

export default function CustomersIndex({ customers, filters }: CustomersIndexProps) {
    const can = useCan();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [form, setForm] = useState<CustomerFilters>(filters);
    const { loading, error, retry } = useVisitState('/customers');

    const apply: FormEventHandler = (e) => {
        e.preventDefault();

        const query = Object.fromEntries(
            Object.entries({ ...form, overdue: form.overdue ? '1' : '' }).filter(
                ([key, value]) => value !== '' && !(key === 'status' && value === 'active'),
            ),
        );

        router.get(route('customers.index'), query, { preserveState: true, replace: true });
    };

    const reset = () => router.get(route('customers.index'), {}, { replace: true });

    // Filters currently applied by the server (not unsaved edits in the form).
    const hasFilters =
        filters.q !== '' ||
        filters.status !== 'active' ||
        filters.registered_from !== '' ||
        filters.registered_to !== '' ||
        filters.overdue ||
        filters.min_missed !== '';

    const columns: DataTableColumn<Customer>[] = [
        {
            key: 'customer',
            header: 'Customer',
            cell: (customer) => (
                <div className="min-w-40">
                    <Link href={route('customers.show', customer.customer_no)} className="font-medium hover:underline">
                        {customer.name}
                    </Link>
                    <div className="text-muted-foreground flex items-center gap-2 text-xs">
                        {customer.customer_no}
                        {customer.status === 'archived' && <CustomerStatusBadge status={customer.status} />}
                    </div>
                </div>
            ),
        },
        { key: 'mobile', header: 'Mobile', cell: (customer) => customer.mobile, className: 'whitespace-nowrap' },
        { key: 'active_loans', header: 'Active loans', cell: (customer) => customer.summary?.active_loans ?? 0, className: 'text-right' },
        {
            key: 'interest_due',
            header: 'Interest due',
            cell: (customer) => formatMoney(customer.summary?.total_interest_due),
            className: 'text-right tabular-nums whitespace-nowrap',
        },
        {
            key: 'missed',
            header: 'Consecutive missed',
            cell: (customer) => <MissedBadge count={customer.summary?.consecutive_missed ?? 0} />,
            className: 'text-right',
        },
        {
            key: 'last_payment',
            header: 'Last payment',
            cell: (customer) =>
                customer.summary?.last_payment ? (
                    <div className="whitespace-nowrap">
                        {formatDate(customer.summary.last_payment.date)}
                        <div className="text-muted-foreground text-xs tabular-nums">{formatMoney(customer.summary.last_payment.amount)}</div>
                    </div>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
            className: 'hidden lg:table-cell',
        },
        {
            key: 'next_due',
            header: 'Next due',
            cell: (customer) => formatDate(customer.summary?.next_due_date),
            className: 'hidden md:table-cell whitespace-nowrap',
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            cell: (customer) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('customers.show', customer.customer_no)}>View</Link>
                    </Button>
                    {can('customers.update') && (
                        <Button variant="outline" size="sm" asChild className="hidden sm:inline-flex">
                            <Link href={route('customers.edit', customer.customer_no)}>Edit</Link>
                        </Button>
                    )}
                    <CustomerArchiveAction customer={customer} />
                </div>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Customers" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <Heading title="Customers" description="Loan customers with their interest due and missed periods." />
                    <div className="flex flex-wrap items-center gap-2">
                        <ExportButtons routeName="customers.export" filters={filters} />
                        {can('customers.create') && (
                            <Button asChild>
                                <Link href={route('customers.create')}>
                                    <Plus className="size-4" /> New customer
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <form onSubmit={apply} className="space-y-2" aria-label="Filter customers">
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <Input
                            type="search"
                            placeholder="Name, mobile, NID or customer no."
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
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                            <option value="all">All statuses</option>
                        </select>
                        <Input
                            type="number"
                            min={1}
                            placeholder="Missed ≥"
                            aria-label="Minimum consecutive missed periods"
                            value={form.min_missed}
                            onChange={(e) => setForm({ ...form, min_missed: e.target.value })}
                            className="sm:w-28"
                        />
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={form.overdue} onCheckedChange={(checked) => setForm({ ...form, overdue: checked === true })} />
                            Overdue only
                        </label>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <Label htmlFor="registered_from" className="text-muted-foreground text-sm font-normal">
                            Registered
                        </Label>
                        <Input
                            id="registered_from"
                            type="date"
                            aria-label="Registered from"
                            value={form.registered_from}
                            onChange={(e) => setForm({ ...form, registered_from: e.target.value })}
                            className="sm:w-40"
                        />
                        <span className="text-muted-foreground hidden text-sm sm:inline">to</span>
                        <Input
                            type="date"
                            aria-label="Registered to"
                            value={form.registered_to}
                            onChange={(e) => setForm({ ...form, registered_to: e.target.value })}
                            className="sm:w-40"
                        />
                        <Button type="submit" variant="secondary" disabled={loading}>
                            Filter
                        </Button>
                        {hasFilters && (
                            <Button type="button" variant="ghost" onClick={reset} disabled={loading}>
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
                    rows={customers.data}
                    rowKey={(customer) => customer.customer_no}
                    meta={customers.meta}
                    loading={loading}
                    error={error}
                    onRetry={retry}
                    empty={
                        hasFilters ? (
                            'No customers match these filters.'
                        ) : (
                            <div className="space-y-2">
                                <p>No customers yet.</p>
                                {can('customers.create') && (
                                    <Button size="sm" asChild>
                                        <Link href={route('customers.create')}>
                                            <Plus className="size-4" /> Add the first customer
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        )
                    }
                />
            </div>
        </AppLayout>
    );
}
