import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { type Customer } from '@/features/customers/types';
import { useCan } from '@/hooks/use-can';
import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Archive / restore button with confirmation. Hidden without customers.archive (the server also checks).
 */
export function CustomerArchiveAction({ customer, size = 'sm' }: { customer: Customer; size?: 'sm' | 'default' }) {
    const can = useCan();
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    if (!can('customers.archive')) {
        return null;
    }

    const archived = customer.status === 'archived';

    const confirm = () => {
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
        };

        if (archived) {
            router.post(route('customers.restore', customer.customer_no), {}, options);
        } else {
            router.delete(route('customers.destroy', customer.customer_no), options);
        }
    };

    return (
        <>
            <Button variant={archived ? 'secondary' : 'destructive'} size={size} onClick={() => setOpen(true)}>
                {archived ? 'Restore' : 'Archive'}
            </Button>
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                title={archived ? `Restore ${customer.name}?` : `Archive ${customer.name}?`}
                description={
                    archived
                        ? 'The customer will appear in the active customer list again.'
                        : 'The customer is hidden from the active list. Nothing is deleted: loans, collateral, payments and ledger history are kept.'
                }
                confirmLabel={archived ? 'Restore' : 'Archive'}
                destructive={!archived}
                processing={processing}
                onConfirm={confirm}
            />
        </>
    );
}
