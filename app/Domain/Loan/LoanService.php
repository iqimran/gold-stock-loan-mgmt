<?php

namespace App\Domain\Loan;

use App\Enums\LoanEventType;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loan creation, editing and status changes (docs/07 "LoanService"). Every change runs in a
 * transaction with the loan row locked, is checked against LoanStatusTransitions and is recorded
 * as a loan event (actor, time, payload).
 *
 * Money is handled as decimal strings (App\Support\Money); no interest is calculated here.
 */
class LoanService
{
    public const NUMBER_PREFIX = 'LN';

    /** Loan terms that are editable only while the loan is a draft. */
    public const FINANCIAL_FIELDS = ['principal', 'interest_rate', 'interest_rate_type', 'interest_period_unit', 'start_date'];

    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly LoanSettlement $settlement,
    ) {}

    /**
     * Creates a draft loan; outstanding principal equals principal until payments reduce it.
     *
     * @param  array{principal: string, interest_rate: string, interest_rate_type: string, interest_period_unit: string, start_date: string, notes?: ?string}  $terms
     */
    public function create(Customer $customer, array $terms, ?User $actor): Loan
    {
        return DB::transaction(function () use ($customer, $terms, $actor): Loan {
            $principal = Money::of($terms['principal']);

            $loan = Loan::create([
                'loan_no' => $this->numbers->next(self::NUMBER_PREFIX),
                'customer_id' => $customer->id,
                'principal' => $principal,
                'outstanding_principal' => $principal,
                'interest_rate' => $this->rate($terms['interest_rate']),
                'interest_rate_type' => $terms['interest_rate_type'],
                'interest_period_unit' => $terms['interest_period_unit'],
                'status' => LoanStatus::Draft,
                'start_date' => $terms['start_date'],
                'notes' => $terms['notes'] ?? null,
            ]);

            $this->record($loan, LoanEventType::Created, $actor, ['terms' => $this->terms($loan)]);

            return $loan;
        });
    }

    /**
     * Draft: terms and notes are editable. Any other status: notes only.
     *
     * @param  array<string, mixed>  $changes  validated subset of FINANCIAL_FIELDS + notes
     */
    public function update(Loan $loan, array $changes, ?User $actor): Loan
    {
        return DB::transaction(function () use ($loan, $changes, $actor): Loan {
            $locked = $this->lock($loan);
            $before = $this->terms($locked) + ['notes' => $locked->notes];

            if ($locked->status !== LoanStatus::Draft) {
                $lockedChanges = [];

                foreach (array_intersect_key($changes, array_flip(self::FINANCIAL_FIELDS)) as $field => $value) {
                    if ($this->normalise($field, $value) !== $before[$field]) {
                        $lockedChanges[$field] = "The {$this->label($field)} cannot be changed once the loan is {$locked->status->value}.";
                    }
                }

                if ($lockedChanges !== []) {
                    throw ValidationException::withMessages($lockedChanges);
                }
            }

            if ($locked->status === LoanStatus::Draft && array_key_exists('principal', $changes)) {
                $changes['outstanding_principal'] = Money::of($changes['principal']);
            }

            $locked->fill($changes);

            if (array_key_exists('interest_rate', $changes)) {
                $locked->interest_rate = $this->rate($changes['interest_rate']);
            }

            $after = $this->terms($locked) + ['notes' => $locked->notes];
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

            if ($changed !== []) {
                $locked->save();
                $this->record($locked, LoanEventType::Updated, $actor, [
                    'before' => array_intersect_key($before, array_flip($changed)),
                    'after' => array_intersect_key($after, array_flip($changed)),
                ]);
            }

            return $loan->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * draft → active: the loan starts running; its terms are locked from now on.
     */
    public function activate(Loan $loan, ?User $actor): Loan
    {
        return $this->transition($loan, LoanStatus::Active, LoanEventType::Activated, $actor, function (Loan $locked): array {
            // The map also allows overdue → active, but that is the scheduler's clearOverdue(), not activation.
            if ($locked->status !== LoanStatus::Draft) {
                throw ValidationException::withMessages(['status' => "A {$locked->status->value} loan cannot be changed to active."]);
            }

            $locked->outstanding_principal = $locked->principal;

            return ['principal' => $locked->principal];
        });
    }

    /**
     * active/overdue → closed, only when LoanSettlement::closeBlockers() is empty.
     */
    public function close(Loan $loan, ?User $actor, ?string $note = null): Loan
    {
        return $this->transition($loan, LoanStatus::Closed, LoanEventType::Closed, $actor, function (Loan $locked) use ($note): array {
            if ($blockers = $this->settlement->closeBlockers($locked)) {
                throw ValidationException::withMessages(['status' => $blockers]);
            }

            $locked->closed_at = now();

            return ['note' => $note];
        });
    }

    /**
     * draft → cancelled, or active/overdue → cancelled while no payment has been posted.
     */
    public function cancel(Loan $loan, ?User $actor, string $reason): Loan
    {
        return $this->transition($loan, LoanStatus::Cancelled, LoanEventType::Cancelled, $actor, function (Loan $locked) use ($reason): array {
            if ($locked->status->isOpen() && $this->settlement->hasPostedPayments($locked)) {
                throw ValidationException::withMessages([
                    'status' => 'Payments have been posted on this loan; settle and close it instead of cancelling.',
                ]);
            }

            return ['reason' => $reason];
        });
    }

    /**
     * active → overdue. System action for the scheduler (missed-payment detection); no user actor.
     */
    public function markOverdue(Loan $loan): Loan
    {
        return $this->transition($loan, LoanStatus::Overdue, LoanEventType::MarkedOverdue, null);
    }

    /**
     * overdue → active. System action once the loan is no longer overdue.
     */
    public function clearOverdue(Loan $loan): Loan
    {
        return $this->transition($loan, LoanStatus::Active, LoanEventType::OverdueCleared, null);
    }

    /**
     * @param  (callable(Loan): array<string, mixed>)|null  $apply  guards + changes for this transition; returns event payload
     */
    private function transition(Loan $loan, LoanStatus $to, LoanEventType $event, ?User $actor, ?callable $apply = null): Loan
    {
        return DB::transaction(function () use ($loan, $to, $event, $actor, $apply): Loan {
            $locked = $this->lock($loan);
            $from = $locked->status;

            if (! LoanStatusTransitions::allows($from, $to)) {
                throw ValidationException::withMessages([
                    'status' => "A {$from->value} loan cannot be changed to {$to->value}.",
                ]);
            }

            $payload = $apply ? $apply($locked) : [];
            $locked->status = $to;
            $locked->save();

            $this->record($locked, $event, $actor, ['from' => $from->value, 'to' => $to->value, ...array_filter($payload, fn ($value) => $value !== null)]);

            return $loan->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function lock(Loan $loan): Loan
    {
        return Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(Loan $loan, LoanEventType $type, ?User $actor, array $payload): void
    {
        $loan->events()->create([
            'event_type' => $type,
            'event_date' => now(),
            'payload' => $payload,
            'actor_id' => $actor?->id,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function terms(Loan $loan): array
    {
        return [
            'principal' => $loan->principal,
            'interest_rate' => $loan->interest_rate,
            'interest_rate_type' => $loan->interest_rate_type->value,
            'interest_period_unit' => $loan->interest_period_unit->value,
            'start_date' => $loan->start_date->toDateString(),
        ];
    }

    private function normalise(string $field, mixed $value): string
    {
        return match ($field) {
            'principal' => Money::of((string) $value),
            'interest_rate' => $this->rate((string) $value),
            default => (string) $value,
        };
    }

    /**
     * Rates keep 4 decimals (validation allows at most 4, so nothing is rounded here).
     */
    private function rate(string $value): string
    {
        return bcadd($value, '0', 4);
    }

    private function label(string $field): string
    {
        return str_replace('_', ' ', $field);
    }
}
