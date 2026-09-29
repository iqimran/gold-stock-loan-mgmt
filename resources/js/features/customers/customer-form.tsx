import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Customer } from '@/features/customers/types';
import { Link, useForm } from '@inertiajs/react';
import { ImageIcon, LoaderCircle } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

// A type alias (not an interface) so it satisfies Inertia's FormDataType index signature.
type CustomerFormData = {
    name: string;
    mobile: string;
    nid: string;
    address: string;
    image: File | null;
    remove_image: boolean;
    _method?: 'put';
};

/**
 * Create/edit form. Validation happens on the server; its messages are shown under each field.
 */
export function CustomerForm({ customer }: { customer?: Customer }) {
    const { data, setData, post, processing, errors, progress } = useForm<CustomerFormData>({
        name: customer?.name ?? '',
        mobile: customer?.mobile ?? '',
        nid: customer?.nid ?? '',
        address: customer?.address ?? '',
        image: null,
        remove_image: false,
        // Files need a multipart POST; Laravel treats it as the update via method spoofing.
        ...(customer ? { _method: 'put' as const } : {}),
    });

    const [preview, setPreview] = useState<string | null>(null);

    useEffect(() => {
        if (!data.image) {
            setPreview(null);

            return;
        }

        const url = URL.createObjectURL(data.image);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [data.image]);

    const currentImage = preview ?? (data.remove_image ? null : (customer?.image_url ?? null));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(customer ? route('customers.update', customer.customer_no) : route('customers.store'), {
            preserveScroll: true,
            forceFormData: true,
        });
    };

    return (
        <form onSubmit={submit} className="space-y-8" noValidate>
            <div className="grid gap-6 md:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        maxLength={150}
                        autoComplete="off"
                        aria-invalid={!!errors.name}
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="mobile">Mobile</Label>
                    <Input
                        id="mobile"
                        type="tel"
                        inputMode="tel"
                        value={data.mobile}
                        onChange={(e) => setData('mobile', e.target.value)}
                        required
                        autoComplete="off"
                        aria-invalid={!!errors.mobile}
                    />
                    <InputError message={errors.mobile} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="nid">
                        NID <span className="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <Input
                        id="nid"
                        value={data.nid}
                        onChange={(e) => setData('nid', e.target.value)}
                        maxLength={50}
                        autoComplete="off"
                        aria-invalid={!!errors.nid}
                    />
                    <InputError message={errors.nid} />
                </div>

                <div className="grid gap-2 md:row-span-2">
                    <Label htmlFor="address">
                        Address <span className="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <textarea
                        id="address"
                        value={data.address}
                        onChange={(e) => setData('address', e.target.value)}
                        maxLength={500}
                        rows={4}
                        aria-invalid={!!errors.address}
                        className="border-input placeholder:text-muted-foreground focus-visible:ring-ring/50 focus-visible:border-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm"
                    />
                    <InputError message={errors.address} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="image">
                        Photo <span className="text-muted-foreground font-normal">(optional, PNG/JPG/WebP, max 2 MB)</span>
                    </Label>
                    <div className="flex items-center gap-4">
                        <div className="bg-muted flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                            {currentImage ? (
                                <img src={currentImage} alt="Customer photo" className="size-full object-cover" />
                            ) : (
                                <ImageIcon className="text-muted-foreground size-6" aria-hidden />
                            )}
                        </div>
                        <div className="grid flex-1 gap-2">
                            <Input
                                id="image"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(e) => {
                                    setData('image', e.target.files?.[0] ?? null);
                                    setData('remove_image', false);
                                }}
                                aria-invalid={!!errors.image}
                            />
                            {customer?.image_url && !data.image && (
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={data.remove_image} onCheckedChange={(checked) => setData('remove_image', checked === true)} />
                                    Remove current photo
                                </label>
                            )}
                        </div>
                    </div>
                    {progress && <progress value={progress.percentage} max={100} className="h-1 w-full" />}
                    <InputError message={errors.image} />
                </div>
            </div>

            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    {customer ? 'Save changes' : 'Create customer'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href={customer ? route('customers.show', customer.customer_no) : route('customers.index')}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
