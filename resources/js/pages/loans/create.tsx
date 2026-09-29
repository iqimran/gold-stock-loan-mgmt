import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LoanTermsForm } from '@/features/loans/loan-terms-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { LoaderCircle, Search } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Loans', href: '/loans' },
    { title: 'New loan', href: '/loans/create' },
];

interface CustomerOption {
    customer_no: string;
    name: string;
    mobile: string;
    active_loans?: number;
}

interface CreateLoanProps {
    customer: CustomerOption | null;
    customerResults: CustomerOption[];
    rateTypes: string[];
    periodUnits: string[];
    today: string;
}

/**
 * New loan, step 1 of the docs/11 flow: customer and terms → saved as a draft. Collateral is added and
 * the loan activated on the loan screen that follows.
 */
export default function CreateLoan({ customer, customerResults, rateTypes, periodUnits, today }: CreateLoanProps) {
    const [query, setQuery] = useState('');
    const [looking, setLooking] = useState(false);

    // Server lookups (partial reloads); only active customers can take a new loan.
    const lookup = (params: Record<string, string>, only: string[]) =>
        router.get(route('loans.create'), params, {
            only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLooking(true),
            onFinish: () => setLooking(false),
        });

    const search: FormEventHandler = (e) => {
        e.preventDefault();
        if (query.trim() !== '') lookup({ customer_q: query.trim() }, ['customerResults']);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New loan" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading title="New loan" description="Saved as a draft. On the next screen add the collateral, review, then activate the loan." />

                <ol className="text-muted-foreground flex flex-wrap gap-x-6 gap-y-1 text-sm" aria-label="Steps">
                    <li className="text-foreground font-medium">1. Customer &amp; terms</li>
                    <li>2. Add collateral</li>
                    <li>3. Review &amp; activate</li>
                </ol>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Customer</CardTitle>
                            {customer && (
                                <CardDescription>
                                    <span className="text-foreground font-medium">{customer.name}</span> · {customer.customer_no} · {customer.mobile}
                                </CardDescription>
                            )}
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <form onSubmit={search} className="flex gap-2" role="search">
                                <Input
                                    type="search"
                                    placeholder="Name, mobile, NID or customer no."
                                    aria-label="Find customer"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                />
                                <Button type="submit" variant="secondary" disabled={looking}>
                                    {looking ? <LoaderCircle className="size-4 animate-spin" /> : <Search className="size-4" />}
                                    <span className="sr-only">Find</span>
                                </Button>
                            </form>
                            {customerResults.length > 0 && (
                                <ul className="divide-y rounded-md border" aria-label="Matching customers">
                                    {customerResults.map((option) => (
                                        <li key={option.customer_no}>
                                            <button
                                                type="button"
                                                onClick={() => lookup({ customer: option.customer_no }, ['customer'])}
                                                aria-pressed={customer?.customer_no === option.customer_no}
                                                className="hover:bg-muted/50 aria-pressed:bg-muted flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm"
                                            >
                                                <span>
                                                    <span className="font-medium">{option.name}</span>
                                                    <span className="text-muted-foreground block text-xs">
                                                        {option.customer_no} · {option.mobile}
                                                    </span>
                                                </span>
                                                <span className="text-muted-foreground text-xs whitespace-nowrap">
                                                    {option.active_loans ?? 0} active
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="text-muted-foreground text-xs">
                                Customer not registered yet?{' '}
                                <Link href={route('customers.create')} className="text-foreground underline">
                                    Add the customer first
                                </Link>
                                .
                            </p>
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Loan terms</CardTitle>
                            {!customer && <CardDescription>Choose the customer first.</CardDescription>}
                        </CardHeader>
                        <CardContent>
                            <LoanTermsForm
                                key={customer?.customer_no ?? 'none'}
                                customerNo={customer?.customer_no}
                                rateTypes={rateTypes}
                                periodUnits={periodUnits}
                                today={today}
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
