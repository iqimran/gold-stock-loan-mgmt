import { ConfirmDialog } from '@/components/confirm-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoanStatusBadge } from '@/features/loans/status-badge';
import { type LoanStatus } from '@/features/loans/types';
import { methodLabel, PAYMENT_TYPE_HINTS, type PaymentLoanInfo, paymentTypeLabel } from '@/features/payments/types';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatMoney } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CircleAlert, LoaderCircle, Search } from 'lucide-react';
import { FormEventHandler, type ReactNode, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Payments', href: '/payments' },
    { title: 'Record payment', href: '/payments/create' },
];

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const textareaClass =
    'border-input placeholder:text-muted-foreground focus-visible:ring-ring/50 focus-visible:border-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm';

interface CustomerOption {
    customer_no: string;
    name: string;
    mobile: string;
    active_loans?: number;
}

interface CreatePaymentProps {
    types: string[];
    methods: string[];
    today: string;
    idempotencyKey: string;
    customerResults: CustomerOption[];
    customer: CustomerOption | null;
    loans: { loan_no: string; status: LoanStatus; outstanding_principal: string }[];
    loanInfo: PaymentLoanInfo | null;
}

// A type alias (not an interface) so it satisfies Inertia's FormDataType index signature.
type PaymentFormData = {
    loan: string;
    customer: string;
    type: string;
    amount: string;
    method: string;
    payment_date: string;
    reference: string;
    notes: string;
    idempotency_key: string;
};

function Figure({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium tabular-nums">{children}</dd>
        </div>
    );
}

export default function CreatePayment({ types, methods, today, idempotencyKey, customerResults, customer, loans, loanInfo }: CreatePaymentProps) {
    const [query, setQuery] = useState('');
    const [looking, setLooking] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const { data, setData, post, processing, errors, clearErrors } = useForm<PaymentFormData>({
        loan: loanInfo?.loan.loan_no ?? '',
        customer: customer?.customer_no ?? '',
        type: types[0] ?? 'interest',
        amount: '',
        method: methods[0] ?? 'cash',
        payment_date: today,
        reference: '',
        notes: '',
        idempotency_key: idempotencyKey,
    });

    // Server lookups (partial reloads): the page never computes balances itself.
    const lookup = (params: Record<string, string>, only: string[]) =>
        router.get(route('payments.create'), params, {
            only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLooking(true),
            onFinish: () => setLooking(false),
        });

    const searchCustomers: FormEventHandler = (e) => {
        e.preventDefault();
        if (query.trim() !== '') lookup({ customer_q: query.trim() }, ['customerResults']);
    };

    const chooseCustomer = (customerNo: string) => {
        setData((current) => ({ ...current, customer: customerNo, loan: '' }));
        lookup({ customer: customerNo }, ['customer', 'loans', 'loanInfo']);
    };

    const chooseLoan = (loanNo: string) => {
        setData('loan', loanNo);
        clearErrors();
        lookup(loanNo ? { customer: data.customer, loan: loanNo } : { customer: data.customer }, ['customer', 'loans', 'loanInfo']);
    };

    const review: FormEventHandler = (e) => {
        e.preventDefault();
        setConfirming(true);
    };

    const submit = () =>
        post(route('payments.store'), {
            preserveScroll: true,
            onError: () => setConfirming(false),
        });

    const blocked = loanInfo !== null && !loanInfo.accepts_payments;
    const generalErrors = (['loan', 'customer', 'idempotency_key'] as const).map((field) => errors[field]).filter(Boolean) as string[];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Record payment" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading title="Record payment" description="The server checks the amount and splits it between interest and principal." />

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        {/* 1. Customer */}
                        <Card>
                            <CardHeader>
                                <CardTitle>1. Customer</CardTitle>
                                {customer && (
                                    <CardDescription>
                                        {customer.name} · {customer.customer_no} · {customer.mobile}
                                    </CardDescription>
                                )}
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <form onSubmit={searchCustomers} className="flex gap-2" role="search">
                                    <Input
                                        type="search"
                                        placeholder="Name, mobile, NID or customer no."
                                        aria-label="Find customer"
                                        value={query}
                                        onChange={(e) => setQuery(e.target.value)}
                                    />
                                    <Button type="submit" variant="secondary" disabled={looking}>
                                        {looking ? <LoaderCircle className="size-4 animate-spin" /> : <Search className="size-4" />}
                                        <span className="sr-only sm:not-sr-only">Find</span>
                                    </Button>
                                </form>
                                {customerResults.length > 0 && (
                                    <ul className="divide-y rounded-md border" aria-label="Matching customers">
                                        {customerResults.map((option) => (
                                            <li key={option.customer_no}>
                                                <button
                                                    type="button"
                                                    onClick={() => chooseCustomer(option.customer_no)}
                                                    aria-pressed={data.customer === option.customer_no}
                                                    className="hover:bg-muted/50 aria-pressed:bg-muted flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm"
                                                >
                                                    <span>
                                                        <span className="font-medium">{option.name}</span>
                                                        <span className="text-muted-foreground block text-xs">
                                                            {option.customer_no} · {option.mobile}
                                                        </span>
                                                    </span>
                                                    <span className="text-muted-foreground text-xs whitespace-nowrap">
                                                        {option.active_loans ?? 0} active loan(s)
                                                    </span>
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        {/* 2. Loan */}
                        {customer && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>2. Loan</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {loans.length === 0 ? (
                                        <p className="text-muted-foreground text-sm">This customer has no active or overdue loans.</p>
                                    ) : (
                                        <>
                                            <Label htmlFor="loan" className="sr-only">
                                                Loan
                                            </Label>
                                            <select
                                                id="loan"
                                                className={selectClass}
                                                value={data.loan}
                                                onChange={(e) => chooseLoan(e.target.value)}
                                                disabled={looking}
                                            >
                                                <option value="">Choose a loan…</option>
                                                {loans.map((loan) => (
                                                    <option key={loan.loan_no} value={loan.loan_no}>
                                                        {loan.loan_no} — {loan.status} — outstanding {formatMoney(loan.outstanding_principal)}
                                                    </option>
                                                ))}
                                            </select>
                                        </>
                                    )}
                                    <InputError message={errors.loan} />
                                </CardContent>
                            </Card>
                        )}

                        {/* 3. Payment */}
                        {loanInfo && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>3. Payment</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form onSubmit={review} className="space-y-6" noValidate>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="type">Payment type</Label>
                                                <select
                                                    id="type"
                                                    className={selectClass}
                                                    value={data.type}
                                                    onChange={(e) => setData('type', e.target.value)}
                                                >
                                                    {types.map((type) => (
                                                        <option key={type} value={type}>
                                                            {paymentTypeLabel(type)}
                                                        </option>
                                                    ))}
                                                </select>
                                                <p className="text-muted-foreground text-xs">{PAYMENT_TYPE_HINTS[data.type]}</p>
                                                <InputError message={errors.type} />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="amount">Amount</Label>
                                                <Input
                                                    id="amount"
                                                    inputMode="decimal"
                                                    placeholder="0.00"
                                                    value={data.amount}
                                                    onChange={(e) => setData('amount', e.target.value)}
                                                    aria-invalid={!!errors.amount}
                                                    required
                                                />
                                                <InputError message={errors.amount} />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="method">Method</Label>
                                                <select
                                                    id="method"
                                                    className={selectClass}
                                                    value={data.method}
                                                    onChange={(e) => setData('method', e.target.value)}
                                                >
                                                    {methods.map((method) => (
                                                        <option key={method} value={method}>
                                                            {methodLabel(method)}
                                                        </option>
                                                    ))}
                                                </select>
                                                <InputError message={errors.method} />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="payment_date">Date</Label>
                                                <Input
                                                    id="payment_date"
                                                    type="date"
                                                    max={today}
                                                    value={data.payment_date}
                                                    onChange={(e) => setData('payment_date', e.target.value)}
                                                    aria-invalid={!!errors.payment_date}
                                                />
                                                <InputError message={errors.payment_date} />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="reference">
                                                    Reference <span className="text-muted-foreground font-normal">(optional)</span>
                                                </Label>
                                                <Input
                                                    id="reference"
                                                    maxLength={100}
                                                    placeholder="e.g. bank or mobile banking transaction ID"
                                                    value={data.reference}
                                                    onChange={(e) => setData('reference', e.target.value)}
                                                />
                                                <InputError message={errors.reference} />
                                            </div>
                                            <div className="grid gap-2 sm:col-span-2">
                                                <Label htmlFor="notes">
                                                    Notes <span className="text-muted-foreground font-normal">(optional)</span>
                                                </Label>
                                                <textarea
                                                    id="notes"
                                                    rows={2}
                                                    maxLength={2000}
                                                    value={data.notes}
                                                    onChange={(e) => setData('notes', e.target.value)}
                                                    className={textareaClass}
                                                />
                                                <InputError message={errors.notes} />
                                            </div>
                                        </div>

                                        {generalErrors.map((message) => (
                                            <InputError key={message} message={message} />
                                        ))}

                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <Button type="submit" disabled={processing || blocked || data.amount.trim() === ''}>
                                                Review payment
                                            </Button>
                                            <Button variant="outline" asChild>
                                                <Link href={route('loans.show', loanInfo.loan.loan_no)}>Cancel</Link>
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Authoritative loan figures */}
                    <div>
                        {loanInfo ? (
                            <Card className="lg:sticky lg:top-4">
                                <CardHeader>
                                    <CardTitle className="flex flex-wrap items-center gap-2">
                                        <Link href={route('loans.show', loanInfo.loan.loan_no)} className="hover:underline">
                                            {loanInfo.loan.loan_no}
                                        </Link>
                                        <LoanStatusBadge status={loanInfo.loan.status} />
                                    </CardTitle>
                                    <CardDescription>Current figures from the server.</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {blocked && (
                                        <Alert variant="destructive" className="mb-4">
                                            <CircleAlert className="size-4" />
                                            <AlertTitle>No payments on this loan</AlertTitle>
                                            <AlertDescription>Payments can only be recorded on active or overdue loans.</AlertDescription>
                                        </Alert>
                                    )}
                                    <dl className="divide-y text-sm">
                                        <Figure label="Outstanding principal">{formatMoney(loanInfo.outstanding_principal)}</Figure>
                                        <Figure label="Interest due to date">
                                            <span className={loanInfo.interest_due !== '0.00' ? 'text-red-600 dark:text-red-400' : undefined}>
                                                {formatMoney(loanInfo.interest_due)}
                                            </span>
                                        </Figure>
                                        <Figure label="Interest payable now">{formatMoney(loanInfo.interest_payable)}</Figure>
                                        <Figure label="Overdue periods">{loanInfo.overdue_periods}</Figure>
                                        <Figure label="Next due">{formatDate(loanInfo.next_due_date)}</Figure>
                                        <Figure label="Last payment">
                                            {loanInfo.last_payment
                                                ? `${formatMoney(loanInfo.last_payment.amount)} · ${formatDate(loanInfo.last_payment.date)}`
                                                : '—'}
                                        </Figure>
                                    </dl>
                                    <p className="text-muted-foreground mt-3 text-xs">
                                        "Interest payable now" includes the current period, which can be paid before its due date.
                                    </p>
                                </CardContent>
                            </Card>
                        ) : (
                            <Card>
                                <CardContent className="text-muted-foreground pt-6 text-sm">
                                    Choose a customer and loan to see its outstanding balances.
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`Record ${data.amount.trim()} ${paymentTypeLabel(data.type).toLowerCase()} payment?`}
                description={`Loan ${data.loan} · ${methodLabel(data.method)} · ${formatDate(data.payment_date)}${data.reference ? ` · ref ${data.reference}` : ''}. The server allocates the amount and issues the receipt.`}
                confirmLabel={processing ? 'Recording…' : 'Record payment'}
                processing={processing}
                onConfirm={submit}
            />
        </AppLayout>
    );
}
