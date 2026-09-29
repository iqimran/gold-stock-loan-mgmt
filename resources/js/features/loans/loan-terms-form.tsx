import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type Loan } from '@/features/loans/types';
import { cn } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle, Lock } from 'lucide-react';
import { FormEventHandler } from 'react';

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-3 text-sm disabled:opacity-60';
const textareaClass =
    'border-input placeholder:text-muted-foreground focus-visible:ring-ring/50 focus-visible:border-ring flex w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm';

const RATE_TYPE_LABELS: Record<string, string> = { monthly: '% per month', yearly: '% per year' };
const PERIOD_UNIT_LABELS: Record<string, string> = { month: 'Monthly' };

// A type alias (not an interface) so it satisfies Inertia's FormDataType index signature.
type LoanTermsData = {
    customer?: string;
    principal: string;
    interest_rate: string;
    interest_rate_type: string;
    interest_period_unit: string;
    start_date: string;
    notes: string;
};

interface LoanTermsFormProps {
    /** Editing this loan; creating a new draft when omitted. */
    loan?: Loan;
    /** New loan: the chosen customer's number. */
    customerNo?: string;
    rateTypes: string[];
    periodUnits: string[];
    today: string;
}

/**
 * Loan terms. Validation and every rule (e.g. terms locked after activation) live on the server; its
 * messages appear under each field. Nothing is calculated here.
 */
export function LoanTermsForm({ loan, customerNo, rateTypes, periodUnits, today }: LoanTermsFormProps) {
    // Terms are editable on a new loan or a draft; once active only the notes can change.
    const termsLocked = loan !== undefined && !loan.actions.edit_terms;

    const { data, setData, post, patch, processing, errors, transform } = useForm<LoanTermsData>({
        ...(loan ? {} : { customer: customerNo ?? '' }),
        principal: loan?.principal ?? '',
        interest_rate: loan?.interest_rate ?? '',
        interest_rate_type: loan?.interest_rate_type ?? rateTypes[0] ?? 'monthly',
        interest_period_unit: loan?.interest_period_unit ?? periodUnits[0] ?? 'month',
        start_date: loan?.start_date ?? today,
        notes: loan?.notes ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (loan) {
            // A locked loan only sends its notes, so unchanged terms are never re-submitted.
            transform((values) => (termsLocked ? { notes: values.notes } : values));
            patch(route('loans.update', loan.loan_no), { preserveScroll: true });
        } else {
            post(route('loans.store'), { preserveScroll: true });
        }
    };

    const allErrors = errors as Record<string, string>;

    return (
        <form onSubmit={submit} className="space-y-6" noValidate>
            {termsLocked && (
                <p className="text-muted-foreground flex items-center gap-2 text-sm">
                    <Lock className="size-4" aria-hidden /> This loan is {loan?.status}: its terms are locked. Only the notes can be changed.
                </p>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="principal">Principal</Label>
                    <Input
                        id="principal"
                        inputMode="decimal"
                        placeholder="0.00"
                        value={data.principal}
                        onChange={(e) => setData('principal', e.target.value)}
                        disabled={termsLocked}
                        aria-invalid={!!errors.principal}
                        required
                    />
                    <InputError message={errors.principal} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="start_date">Start date</Label>
                    <Input
                        id="start_date"
                        type="date"
                        value={data.start_date}
                        onChange={(e) => setData('start_date', e.target.value)}
                        disabled={termsLocked}
                        aria-invalid={!!errors.start_date}
                    />
                    <p className="text-muted-foreground text-xs">Interest periods run monthly from this date.</p>
                    <InputError message={errors.start_date} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="interest_rate">Interest rate</Label>
                    <div className="flex gap-2">
                        <Input
                            id="interest_rate"
                            inputMode="decimal"
                            placeholder="0.00"
                            value={data.interest_rate}
                            onChange={(e) => setData('interest_rate', e.target.value)}
                            disabled={termsLocked}
                            aria-invalid={!!errors.interest_rate}
                            className="min-w-24 flex-1"
                        />
                        <select
                            aria-label="Rate type"
                            // cn() lets w-auto replace selectClass's w-full; a full-width select would squeeze the rate input to nothing.
                            className={cn(selectClass, 'w-auto shrink-0')}
                            value={data.interest_rate_type}
                            onChange={(e) => setData('interest_rate_type', e.target.value)}
                            disabled={termsLocked}
                        >
                            {rateTypes.map((type) => (
                                <option key={type} value={type}>
                                    {RATE_TYPE_LABELS[type] ?? type}
                                </option>
                            ))}
                        </select>
                    </div>
                    <InputError message={errors.interest_rate ?? errors.interest_rate_type} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="interest_period_unit">Interest period</Label>
                    <select
                        id="interest_period_unit"
                        className={selectClass}
                        value={data.interest_period_unit}
                        onChange={(e) => setData('interest_period_unit', e.target.value)}
                        disabled={termsLocked}
                    >
                        {periodUnits.map((unit) => (
                            <option key={unit} value={unit}>
                                {PERIOD_UNIT_LABELS[unit] ?? unit}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.interest_period_unit} />
                </div>

                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="notes">
                        Notes <span className="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <textarea
                        id="notes"
                        rows={3}
                        maxLength={2000}
                        value={data.notes}
                        onChange={(e) => setData('notes', e.target.value)}
                        className={textareaClass}
                    />
                    <InputError message={errors.notes} />
                </div>
            </div>

            {allErrors.customer && <InputError message={allErrors.customer} />}

            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <Button type="submit" disabled={processing || (!loan && !customerNo)}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    {loan ? 'Save changes' : 'Create draft loan'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href={loan ? route('loans.show', loan.loan_no) : route('loans.index')}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
