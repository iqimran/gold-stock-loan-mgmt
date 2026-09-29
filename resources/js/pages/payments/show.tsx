import { DataTable, type DataTableColumn } from '@/components/data-table';
import { ReasonDialog } from '@/components/reason-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { methodLabel, type Payment, type PaymentBalances, paymentTypeLabel } from '@/features/payments/types';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Ban, Printer, ReceiptText } from 'lucide-react';
import { type ReactNode, useState } from 'react';

type PeriodRow = NonNullable<Payment['allocation']>['periods'][number];

function Line({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-2">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right tabular-nums">{children ?? '—'}</dd>
        </div>
    );
}

const periodColumns: DataTableColumn<PeriodRow>[] = [
    {
        key: 'period',
        header: 'Interest period',
        cell: (period) => (
            <span className="whitespace-nowrap">
                {formatDate(period.period_start)} – {formatDate(period.period_end)}
            </span>
        ),
    },
    { key: 'due', header: 'Due', cell: (period) => formatDate(period.due_date), className: 'whitespace-nowrap' },
    { key: 'interest', header: 'Applied', cell: (period) => formatMoney(period.interest), className: 'text-right tabular-nums' },
];

export default function ShowPayment({ payment, balances }: { payment: Payment; balances: PaymentBalances }) {
    const [reversing, setReversing] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);
    const reversed = payment.status === 'reversed';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Payments', href: '/payments' },
        { title: payment.receipt_no, href: `/payments/${payment.receipt_no}` },
    ];

    const reverse = (reason: string) =>
        router.post(
            route('payments.reverse', payment.receipt_no),
            { reason },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors([]);
                },
                onSuccess: () => setReversing(false),
                onError: (serverErrors) => setErrors(Object.values(serverErrors)),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Payment ${payment.receipt_no}`} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-xl font-semibold tracking-tight">{payment.receipt_no}</h2>
                            {reversed ? <Badge variant="destructive">Reversed</Badge> : <Badge variant="secondary">Posted</Badge>}
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {formatMoney(payment.amount)} · {paymentTypeLabel(payment.type)} · {formatDate(payment.payment_date)}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={route('payments.receipt', payment.receipt_no)}>
                                <ReceiptText className="size-4" /> Receipt
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={route('payments.receipt', { payment: payment.receipt_no, print: 1 })} target="_blank" rel="noopener">
                                <Printer className="size-4" /> Print
                            </a>
                        </Button>
                        {payment.can_reverse && (
                            <Button
                                variant="destructive"
                                onClick={() => {
                                    setErrors([]);
                                    setReversing(true);
                                }}
                            >
                                <Ban className="size-4" /> Reverse
                            </Button>
                        )}
                    </div>
                </div>

                {reversed && (
                    <Alert variant="destructive">
                        <Ban className="size-4" />
                        <AlertTitle>Reversed {formatDateTime(payment.reversed_at)}</AlertTitle>
                        <AlertDescription>
                            {payment.reversed_by && <span>By {payment.reversed_by}. </span>}
                            Reason: {payment.reversal_reason}. The payment stays on record; its effect on the loan was undone.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Payment</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="divide-y text-sm">
                                <Line label="Customer">
                                    {payment.customer && (
                                        <span>
                                            {payment.customer.name}
                                            <span className="text-muted-foreground block text-xs">{payment.customer.customer_no}</span>
                                        </span>
                                    )}
                                </Line>
                                <Line label="Loan">
                                    {payment.loan && (
                                        <Link href={route('loans.show', payment.loan.loan_no)} className="hover:underline">
                                            {payment.loan.loan_no}
                                        </Link>
                                    )}
                                </Line>
                                <Line label="Type">{paymentTypeLabel(payment.type)}</Line>
                                <Line label="Method">{methodLabel(payment.method)}</Line>
                                <Line label="Date">{formatDate(payment.payment_date)}</Line>
                                <Line label="Reference">{payment.reference}</Line>
                                <Line label="Recorded">{formatDateTime(payment.created_at)}</Line>
                            </dl>
                            {payment.notes && <p className="text-muted-foreground mt-3 text-sm whitespace-pre-line">{payment.notes}</p>}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Allocation</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="divide-y text-sm">
                                <Line label="Interest">{formatMoney(payment.allocation?.interest)}</Line>
                                <Line label="Principal">{formatMoney(payment.allocation?.principal)}</Line>
                                <Line label="Fee">{formatMoney(payment.allocation?.fee)}</Line>
                                <div className="flex items-baseline justify-between gap-4 py-2 font-semibold">
                                    <dt>Total</dt>
                                    <dd className="tabular-nums">{formatMoney(payment.amount)}</dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>After this payment</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="divide-y text-sm">
                                <Line label="Outstanding principal">{formatMoney(balances.principal_after)}</Line>
                                <Line label="Customer balance">{formatMoney(balances.customer_balance_after)}</Line>
                            </dl>
                            <p className="text-muted-foreground mt-3 text-xs">As recorded when the payment was posted.</p>
                        </CardContent>
                    </Card>
                </div>

                {(payment.allocation?.periods.length ?? 0) > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Interest periods paid</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DataTable columns={periodColumns} rows={payment.allocation?.periods ?? []} rowKey={(period) => period.period_start} />
                        </CardContent>
                    </Card>
                )}
            </div>

            <ReasonDialog
                open={reversing}
                onOpenChange={setReversing}
                title={`Reverse ${payment.receipt_no}?`}
                description="The payment stays on record as reversed. Its interest and principal are put back on the loan and the customer's statement is corrected."
                confirmLabel="Reverse payment"
                destructive
                processing={processing}
                errors={errors}
                onConfirm={reverse}
            />
        </AppLayout>
    );
}
