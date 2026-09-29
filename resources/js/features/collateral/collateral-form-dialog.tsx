import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type CollateralItem } from '@/features/collateral/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect } from 'react';

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';
const textareaClass =
    'border-input placeholder:text-muted-foreground focus-visible:ring-ring/50 focus-visible:border-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm';

// A type alias (not an interface) so it satisfies Inertia's FormDataType index signature.
type CollateralFormData = {
    type: string;
    weight_grams: string;
    karat: string;
    estimated_value: string;
    description: string;
    reason: string;
};

interface CollateralFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    loanNo: string;
    /** Editing this item; adding a new one when omitted. */
    item?: CollateralItem;
    /** A reason is required for corrections once the loan is past draft (the server enforces it). */
    reasonRequired: boolean;
    types: string[];
    maxKarat: string;
    /** Suggested karat values (Settings); any value up to maxKarat is accepted. */
    karatOptions: string[];
}

/**
 * Add or correct a collateral item. Validation is done by the server; its messages appear under each field.
 */
export function CollateralFormDialog({ open, onOpenChange, loanNo, item, reasonRequired, types, maxKarat, karatOptions }: CollateralFormDialogProps) {
    const { data, setData, post, patch, processing, errors, reset, clearErrors } = useForm<CollateralFormData>({
        type: item?.type ?? types[0] ?? '',
        weight_grams: item?.weight_grams ?? '',
        karat: item?.karat ?? '',
        estimated_value: item?.estimated_value ?? '',
        description: item?.description ?? '',
        reason: '',
    });

    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
        }
        // Reset whenever the dialog opens for a (possibly different) item.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, item?.collateral_no]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const options = { preserveScroll: true, preserveState: true, onSuccess: () => onOpenChange(false) };

        if (item) {
            patch(route('collateral.update', item.collateral_no), options);
        } else {
            post(route('loans.collateral.store', loanNo), options);
        }
    };

    const field = (name: keyof CollateralFormData) => ({
        id: `collateral-${name}`,
        value: data[name],
        'aria-invalid': !!errors[name],
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <DialogTitle>{item ? `Edit ${item.collateral_no}` : 'Add collateral'}</DialogTitle>
                    <DialogDescription>
                        {item
                            ? 'Weight and valuation are historical facts: every change is recorded in the loan history.'
                            : `Held against loan ${loanNo}.`}
                    </DialogDescription>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="collateral-type">Type</Label>
                            <select {...field('type')} onChange={(e) => setData('type', e.target.value)} className={selectClass}>
                                {types.map((type) => (
                                    <option key={type} value={type}>
                                        {type.charAt(0).toUpperCase() + type.slice(1)}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.type} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="collateral-weight_grams">Weight (grams)</Label>
                            <Input
                                {...field('weight_grams')}
                                inputMode="decimal"
                                onChange={(e) => setData('weight_grams', e.target.value)}
                                placeholder="0.000"
                            />
                            <InputError message={errors.weight_grams} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="collateral-karat">
                                Karat <span className="text-muted-foreground font-normal">(optional, max {maxKarat})</span>
                            </Label>
                            <Input
                                {...field('karat')}
                                inputMode="decimal"
                                list="collateral-karat-options"
                                onChange={(e) => setData('karat', e.target.value)}
                            />
                            <datalist id="collateral-karat-options">
                                {karatOptions.map((karat) => (
                                    <option key={karat} value={karat} />
                                ))}
                            </datalist>
                            <InputError message={errors.karat} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="collateral-estimated_value">Estimated value</Label>
                            <Input
                                {...field('estimated_value')}
                                inputMode="decimal"
                                onChange={(e) => setData('estimated_value', e.target.value)}
                                placeholder="0.00"
                            />
                            <InputError message={errors.estimated_value} />
                        </div>
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="collateral-description">
                                Description <span className="text-muted-foreground font-normal">(optional)</span>
                            </Label>
                            <textarea
                                {...field('description')}
                                rows={2}
                                maxLength={2000}
                                onChange={(e) => setData('description', e.target.value)}
                                className={textareaClass}
                            />
                            <InputError message={errors.description} />
                        </div>
                        {item && reasonRequired && (
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="collateral-reason">Reason for the change</Label>
                                <textarea
                                    {...field('reason')}
                                    rows={2}
                                    maxLength={500}
                                    onChange={(e) => setData('reason', e.target.value)}
                                    className={textareaClass}
                                />
                                <InputError message={errors.reason} />
                            </div>
                        )}
                    </div>
                    {(errors as Record<string, string>).loan && <InputError message={(errors as Record<string, string>).loan} />}
                    {(errors as Record<string, string>).status && <InputError message={(errors as Record<string, string>).status} />}

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            {item ? 'Save changes' : 'Add collateral'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
