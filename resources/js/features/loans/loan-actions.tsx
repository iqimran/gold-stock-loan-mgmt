import { ConfirmDialog } from '@/components/confirm-dialog';
import { ReasonDialog } from '@/components/reason-dialog';
import { Button } from '@/components/ui/button';
import { type Loan } from '@/features/loans/types';
import { router } from '@inertiajs/react';
import { useState } from 'react';

type Action = 'activate' | 'close' | 'cancel';

/**
 * Loan status actions. Buttons follow the server's action hints (status + permission); the server
 * re-checks everything, including the settlement rules for closing and cancelling, and its message
 * is shown in the dialog when it refuses.
 */
export function LoanActions({ loan }: { loan: Loan }) {
    const [open, setOpen] = useState<Action | null>(null);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);

    const submit = (action: Action, data: Record<string, string> = {}) => {
        router.post(route(`loans.${action}`, loan.loan_no), data, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                setProcessing(true);
                setErrors([]);
            },
            onSuccess: () => setOpen(null),
            onError: (serverErrors) => setErrors(Object.values(serverErrors)),
            onFinish: () => setProcessing(false),
        });
    };

    const show = (action: Action) => {
        setErrors([]);
        setOpen(action);
    };

    const close = (value: boolean) => !value && setOpen(null);

    if (!loan.actions.activate && !loan.actions.close && !loan.actions.cancel) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-2">
            {loan.actions.activate && <Button onClick={() => show('activate')}>Activate</Button>}
            {loan.actions.close && (
                <Button variant="secondary" onClick={() => show('close')}>
                    Close loan
                </Button>
            )}
            {loan.actions.cancel && (
                <Button variant="destructive" onClick={() => show('cancel')}>
                    Cancel loan
                </Button>
            )}

            <ConfirmDialog
                open={open === 'activate'}
                onOpenChange={close}
                title={`Activate ${loan.loan_no}?`}
                description={
                    errors.length > 0
                        ? errors.join(' ')
                        : 'The loan starts running: its principal, rate and start date are locked, and interest periods are generated.'
                }
                confirmLabel="Activate"
                processing={processing}
                onConfirm={() => submit('activate')}
            />
            <ReasonDialog
                open={open === 'close'}
                onOpenChange={close}
                title={`Close ${loan.loan_no}?`}
                description="The server checks that the principal is fully repaid and no interest due to date is unpaid. Afterwards the collateral can be released."
                fieldLabel="Note"
                required={false}
                confirmLabel="Close loan"
                processing={processing}
                errors={errors}
                onConfirm={(note) => submit('close', note ? { note } : {})}
            />
            <ReasonDialog
                open={open === 'cancel'}
                onOpenChange={close}
                title={`Cancel ${loan.loan_no}?`}
                description="Only possible while no payment has been posted on the loan. The loan and its history are kept."
                confirmLabel="Cancel loan"
                destructive
                processing={processing}
                errors={errors}
                onConfirm={(reason) => submit('cancel', { reason })}
            />
        </div>
    );
}
