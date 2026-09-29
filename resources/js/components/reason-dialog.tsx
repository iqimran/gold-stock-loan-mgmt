import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useId, useState } from 'react';

interface ReasonDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmLabel: string;
    /** Label of the text field, e.g. "Reason" or "Note". */
    fieldLabel?: string;
    required?: boolean;
    destructive?: boolean;
    processing?: boolean;
    /** Server validation messages for the field and for the action itself. */
    errors?: string[];
    onConfirm: (text: string) => void;
}

/**
 * Confirmation that captures a reason (docs/05: sensitive actions require confirmation and a reason).
 * The server validates the reason; its messages are shown in the dialog.
 */
export function ReasonDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    fieldLabel = 'Reason',
    required = true,
    destructive,
    processing,
    errors = [],
    onConfirm,
}: ReasonDialogProps) {
    const id = useId();
    const [text, setText] = useState('');

    useEffect(() => {
        if (!open) {
            setText('');
        }
    }, [open]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        onConfirm(text);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                    <div className="grid gap-2">
                        <Label htmlFor={id}>
                            {fieldLabel} {!required && <span className="text-muted-foreground font-normal">(optional)</span>}
                        </Label>
                        <textarea
                            id={id}
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            rows={3}
                            maxLength={500}
                            required={required}
                            aria-invalid={errors.length > 0}
                            className="border-input placeholder:text-muted-foreground focus-visible:ring-ring/50 focus-visible:border-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm"
                        />
                        {errors.map((error) => (
                            <InputError key={error} message={error} />
                        ))}
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" variant={destructive ? 'destructive' : 'default'} disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            {confirmLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
