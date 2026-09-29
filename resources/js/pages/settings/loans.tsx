import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import { Info, LoaderCircle } from 'lucide-react';
import { FormEventHandler, type ReactNode } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Loan settings', href: '/settings/loans' }];

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm';

const LABELS: Record<string, string> = {
    outstanding: 'Outstanding principal (reducing)',
    principal: 'Original principal (flat)',
    period_end: 'At the end of each period',
    period_start: 'At the start of each period',
    twelfths: 'Yearly rate ÷ 12',
    actual_days: 'Yearly rate × days ÷ 365',
    monthly: '% per month',
    yearly: '% per year',
};

type Section = 'shop' | 'currency' | 'interest' | 'defaults' | 'collection' | 'numbering' | 'lists';

type SettingsValues = Record<string, string | number | string[] | null>;

// Flat, keyed by setting key (e.g. "shop.name"); lists are edited as comma-separated text.
type FormValues = Record<string, string>;

const LIST_KEYS = ['lists.payment_methods', 'lists.collateral_types', 'lists.karat_options'];

interface LoanSettingsProps {
    settings: SettingsValues;
    effects: Record<Section, string>;
    options: { interest_base: string[]; interest_due: string[]; yearly_conversion: string[]; rate_types: string[] };
}

function toForm(settings: SettingsValues): FormValues {
    return Object.fromEntries(
        Object.entries(settings).map(([key, value]) => [key, Array.isArray(value) ? value.join(', ') : value === null ? '' : String(value)]),
    );
}

function toPayload(values: FormValues): Record<string, Record<string, unknown>> {
    const payload: Record<string, Record<string, unknown>> = {};

    for (const [key, value] of Object.entries(values)) {
        const [section, name] = key.split('.');
        const parsed = LIST_KEYS.includes(key)
            ? value
                  .split(',')
                  .map((item) => item.trim())
                  .filter((item) => item !== '')
            : value === ''
              ? null
              : value;
        payload[section] = { ...payload[section], [name]: parsed };
    }

    return payload;
}

/**
 * Settings → Loan settings. The server validates and stores every value (and audits the change);
 * each section states what a change affects, so historical figures are never silently changed.
 */
export default function LoanSettings({ settings, effects, options }: LoanSettingsProps) {
    const { data, setData, put, processing, errors, transform, recentlySuccessful } = useForm<FormValues>(toForm(settings));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((values) => toPayload(values));
        put(route('settings.loans.update'), { preserveScroll: true });
    };

    const allErrors = errors as Record<string, string>;
    const errorFor = (key: string) => allErrors[key] ?? Object.entries(allErrors).find(([k]) => k.startsWith(`${key}.`))?.[1];

    const text = (key: string, label: string, props: React.ComponentProps<typeof Input> = {}) => (
        <div className="grid gap-2">
            <Label htmlFor={key}>{label}</Label>
            <Input id={key} name={key} value={data[key] ?? ''} onChange={(e) => setData(key, e.target.value)} {...props} />
            <InputError message={errorFor(key)} />
        </div>
    );

    const select = (key: string, label: string, values: string[]) => (
        <div className="grid gap-2">
            <Label htmlFor={key}>{label}</Label>
            <select id={key} name={key} className={selectClass} value={data[key] ?? ''} onChange={(e) => setData(key, e.target.value)}>
                {values.map((value) => (
                    <option key={value} value={value}>
                        {LABELS[value] ?? value}
                    </option>
                ))}
            </select>
            <InputError message={errorFor(key)} />
        </div>
    );

    const section = (id: Section, title: string, children: ReactNode) => (
        <fieldset className="space-y-4 rounded-lg border p-4">
            <legend className="px-1 text-sm font-semibold">{title}</legend>
            <p className="text-muted-foreground flex gap-2 text-xs">
                <Info className="mt-0.5 size-3.5 shrink-0" />
                <span>
                    <span className="font-medium">Applies to:</span> {effects[id]}
                </span>
            </p>
            {children}
        </fieldset>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Loan settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Loan settings"
                        description="Shop details, interest, collection policy, numbering and lists. Every change is audited."
                    />

                    <form onSubmit={submit} className="space-y-6">
                        {section(
                            'shop',
                            'Shop & receipt',
                            <>
                                {text('shop.name', 'Shop name')}
                                {text('shop.address', 'Address')}
                                {text('shop.phone', 'Phone')}
                                {text('shop.receipt_footer', 'Receipt footer')}
                            </>,
                        )}

                        {section(
                            'currency',
                            'Currency',
                            <div className="grid gap-4 sm:grid-cols-2">
                                {text('currency.code', 'Code (ISO)', { maxLength: 3, className: 'uppercase' })}
                                {text('currency.symbol', 'Symbol', { maxLength: 5 })}
                            </div>,
                        )}

                        {section(
                            'interest',
                            'Interest calculation method',
                            <>
                                {select('interest.base', 'Interest base', options.interest_base)}
                                {select('interest.due', 'Interest due', options.interest_due)}
                                {select('interest.yearly_conversion', 'Yearly rates', options.yearly_conversion)}
                            </>,
                        )}

                        {section(
                            'defaults',
                            'New loan defaults',
                            <div className="grid gap-4 sm:grid-cols-2">
                                {text('loans.default_interest_rate', 'Default interest rate (optional)', {
                                    inputMode: 'decimal',
                                    placeholder: 'e.g. 2.5',
                                })}
                                {select('loans.default_interest_rate_type', 'Rate type', options.rate_types)}
                            </div>,
                        )}

                        {section(
                            'collection',
                            'Collection policy',
                            <div className="grid gap-4 sm:grid-cols-2">
                                {text('collection.grace_days', 'Grace period (days after the due date)', { type: 'number', min: 0, max: 90 })}
                                {text('collection.alert_threshold', 'Missed-interest alert after (periods)', { type: 'number', min: 1, max: 24 })}
                            </div>,
                        )}

                        {section(
                            'numbering',
                            'Number formats',
                            <>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {text('numbering.customer_prefix', 'Customer prefix', { className: 'uppercase' })}
                                    {text('numbering.loan_prefix', 'Loan prefix', { className: 'uppercase' })}
                                    {text('numbering.collateral_prefix', 'Collateral prefix', { className: 'uppercase' })}
                                    {text('numbering.receipt_prefix', 'Receipt prefix', { className: 'uppercase' })}
                                    {select('numbering.reset', 'Sequence restarts', ['monthly', 'yearly'])}
                                    {text('numbering.digits', 'Sequence digits', { type: 'number', min: 4, max: 8 })}
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    Example: {data['numbering.loan_prefix'] || 'LN'}-{data['numbering.reset'] === 'yearly' ? '2026' : '202609'}-
                                    {'1'.padStart(Math.min(Math.max(Number(data['numbering.digits']) || 6, 4), 8), '0')}
                                </p>
                            </>,
                        )}

                        {section(
                            'lists',
                            'Lists',
                            <>
                                {text('lists.payment_methods', 'Payment methods (comma-separated)')}
                                {text('lists.collateral_types', 'Collateral types (comma-separated)')}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {text('lists.karat_options', 'Karat / purity options (comma-separated)')}
                                    {text('lists.max_karat', 'Maximum karat', { inputMode: 'decimal' })}
                                </div>
                            </>,
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>
                                {processing && <LoaderCircle className="size-4 animate-spin" />}
                                Save settings
                            </Button>
                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
