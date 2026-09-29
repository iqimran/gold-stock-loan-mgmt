import { ReasonDialog } from '@/components/reason-dialog';
import { Button } from '@/components/ui/button';
import { type CollateralItem } from '@/features/collateral/types';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Release (return) a collateral item: confirmation + reason; the server records actor and time.
 * Shown only when the server's hint allows it (loan closed/cancelled and collateral.release).
 */
export function CollateralReleaseAction({ item }: { item: CollateralItem }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);

    if (!item.actions.release) {
        return null;
    }

    const release = (reason: string) => {
        router.post(
            route('collateral.release', item.collateral_no),
            { reason },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    setProcessing(true);
                    setErrors([]);
                },
                onSuccess: () => setOpen(false),
                onError: (serverErrors) => setErrors(Object.values(serverErrors)),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Button
                size="sm"
                variant="secondary"
                onClick={() => {
                    setErrors([]);
                    setOpen(true);
                }}
            >
                Release
            </Button>
            <ReasonDialog
                open={open}
                onOpenChange={setOpen}
                title={`Release ${item.collateral_no}?`}
                description="The item is returned to the customer. This is final; the item stays in the loan's history."
                confirmLabel="Release"
                processing={processing}
                errors={errors}
                onConfirm={release}
            />
        </>
    );
}
