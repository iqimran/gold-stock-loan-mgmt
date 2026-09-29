import { Button } from '@/components/ui/button';
import { type Payment, type PaymentBalances, methodLabel, paymentTypeLabel } from '@/features/payments/types';
import { Divider, DocumentHeader, type PrintShop } from '@/features/printing/document-header';
import { PrintPage } from '@/features/printing/print-page';
import { usePaperWidth } from '@/features/printing/use-paper-width';
import { useCan } from '@/hooks/use-can';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface ReceiptProps {
    payment: Payment;
    balances: PaymentBalances;
    shop: PrintShop;
    cashier: string | null;
    autoPrint: boolean;
    justCompleted: boolean;
}

function Row({ label, children, strong }: { label: string; children: ReactNode; strong?: boolean }) {
    return (
        <div className={cn('flex justify-between gap-2', strong && 'font-bold')}>
            <dt>{label}</dt>
            <dd className="text-right tabular-nums">{children}</dd>
        </div>
    );
}

/**
 * Customer payment slip for 80 mm or 58 mm thermal paper (readable on any printer or screen).
 * Every figure is the server's; the template only lays them out.
 */
export default function PaymentReceipt({ payment, balances, shop, cashier, autoPrint, justCompleted }: ReceiptProps) {
    const can = useCan();
    const paper = usePaperWidth();
    const allocation = payment.allocation;
    const reversed = payment.status === 'reversed';

    return (
        <PrintPage
            title={`Receipt ${payment.receipt_no}`}
            paper={{ roll: paper.width }}
            autoPrint={autoPrint}
            className={paper.maxWidthClass}
            actions={
                <>
                    <Button variant="outline" asChild>
                        <Link href={route('payments.show', payment.receipt_no)}>Details</Link>
                    </Button>
                    {payment.loan && (
                        <Button variant="outline" asChild>
                            <Link href={route('loans.show', payment.loan.loan_no)}>Loan</Link>
                        </Button>
                    )}
                    {can('payments.create') && (
                        <Button variant="secondary" asChild>
                            <Link href={route('payments.create', payment.loan ? { loan: payment.loan.loan_no } : {})}>New payment</Link>
                        </Button>
                    )}
                    {paper.toggle}
                </>
            }
            hint={justCompleted ? `Payment recorded. Receipt ${payment.receipt_no} issued by the server.` : undefined}
        >
            <article
                className={cn(
                    'relative mx-auto w-full bg-white p-4 font-mono leading-snug text-black shadow print:px-[3mm] print:py-[2mm] print:shadow-none',
                    paper.maxWidthClass,
                    paper.textClass,
                )}
            >
                <DocumentHeader shop={shop} title="PAYMENT RECEIPT" />

                {reversed && <p className="my-2 border-2 border-black py-1 text-center font-bold tracking-widest">REVERSED</p>}

                <Divider />

                <dl className="space-y-0.5">
                    <Row label="Receipt">
                        <span className="font-bold">{payment.receipt_no}</span>
                    </Row>
                    <Row label="Date">{formatDate(payment.payment_date)}</Row>
                    <Row label="Customer">{payment.customer ? `${payment.customer.name}` : '—'}</Row>
                    {payment.customer && <Row label="Customer no">{payment.customer.customer_no}</Row>}
                    <Row label="Loan">{payment.loan?.loan_no ?? '—'}</Row>
                    <Row label="Type">{paymentTypeLabel(payment.type)}</Row>
                    <Row label="Method">{methodLabel(payment.method)}</Row>
                    {payment.reference && <Row label="Reference">{payment.reference}</Row>}
                    {cashier && <Row label="Cashier">{cashier}</Row>}
                </dl>

                <Divider />

                <dl className="space-y-0.5">
                    {allocation?.periods.map((period) => (
                        <Row key={period.period_start} label={`Interest ${formatDate(period.period_start)}`}>
                            {formatMoney(period.interest)}
                        </Row>
                    ))}
                    {allocation && allocation.principal !== '0.00' && <Row label="Principal">{formatMoney(allocation.principal)}</Row>}
                    {allocation && allocation.fee !== '0.00' && <Row label="Fee">{formatMoney(allocation.fee)}</Row>}
                    <div className="my-1 border-t border-black" />
                    <Row label="TOTAL PAID" strong>
                        {formatMoney(payment.amount)}
                    </Row>
                </dl>

                <Divider />

                <dl className="space-y-0.5">
                    <Row label="Principal outstanding">{formatMoney(balances.principal_after)}</Row>
                    {balances.customer_balance_after !== null && <Row label="Balance owed">{formatMoney(balances.customer_balance_after)}</Row>}
                </dl>
                <p className="mt-1 text-[0.85em]">Balances as of this payment.</p>

                {reversed && (
                    <>
                        <Divider />
                        <p>
                            Reversed {formatDate(payment.reversed_at?.slice(0, 10))}: {payment.reversal_reason}
                        </p>
                    </>
                )}

                {shop.receipt_footer && (
                    <>
                        <Divider />
                        <p className="text-center">{shop.receipt_footer}</p>
                    </>
                )}
            </article>
        </PrintPage>
    );
}
