<?php

namespace App\Domain\Collateral;

use App\Domain\Loan\LoanHistory;
use App\Domain\Settings\LoanSettings;
use App\Enums\CollateralStatus;
use App\Enums\LoanEventType;
use App\Enums\LoanStatus;
use App\Models\CollateralItem;
use App\Models\Loan;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Collateral lifecycle (docs/07 "CollateralService"): intake, correction and release. Business-decided rules:
 *
 *  - items are added to draft, active or overdue loans (never closed/cancelled ones);
 *  - a held item may be corrected at any time; once the loan is past draft a reason is required;
 *  - an item is released only when its loan is closed or cancelled; release records actor and time,
 *    is final, and the item stays in history (re-pledging the same piece is a new intake record);
 *  - an item never moves to another loan and is never deleted.
 *
 * Each change runs in a transaction with the loan row locked (so its status cannot change meanwhile)
 * and is recorded in the loan's history with before/after values.
 */
class CollateralService
{
    /** Fields that describe the item; the only ones that can be corrected. */
    public const DETAIL_FIELDS = ['type', 'weight_grams', 'karat', 'estimated_value', 'description', 'received_at'];

    /** Loan statuses that accept new collateral. */
    private const ACCEPTING = [LoanStatus::Draft, LoanStatus::Active, LoanStatus::Overdue];

    /** Loan statuses after which collateral may be released. */
    private const RELEASABLE = [LoanStatus::Closed, LoanStatus::Cancelled];

    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly LoanHistory $history,
        private readonly LoanSettings $settings,
    ) {}

    /**
     * @param  array{type: string, weight_grams: string, karat?: ?string, estimated_value: string, description?: ?string, received_at?: ?string}  $details
     */
    public function add(Loan $loan, array $details, ?User $actor): CollateralItem
    {
        return DB::transaction(function () use ($loan, $details, $actor): CollateralItem {
            $locked = $this->lockLoan($loan);

            if (! in_array($locked->status, self::ACCEPTING, true)) {
                throw ValidationException::withMessages([
                    'loan' => "Collateral cannot be added to {$this->describe($locked)}.",
                ]);
            }

            $item = $locked->collateralItems()->create([
                'collateral_no' => $this->numbers->nextIn($this->settings->numbering('collateral')),
                ...$this->normalise($details),
                'received_at' => $details['received_at'] ?? now(),
                'status' => CollateralStatus::Held,
            ]);

            $this->history->record($locked, LoanEventType::CollateralAdded, $actor, [
                'collateral_no' => $item->collateral_no,
                'details' => $this->snapshot($item),
            ]);

            return $item;
        });
    }

    /**
     * Corrects a held item's details. A reason is required once the loan is no longer a draft.
     *
     * @param  array<string, mixed>  $changes  validated subset of DETAIL_FIELDS
     */
    public function update(CollateralItem $item, array $changes, ?User $actor, ?string $reason = null): CollateralItem
    {
        return DB::transaction(function () use ($item, $changes, $actor, $reason): CollateralItem {
            $loan = $this->lockLoan($item->loan()->firstOrFail());
            $locked = $this->lockItem($item);

            if ($locked->status === CollateralStatus::Released) {
                throw ValidationException::withMessages(['status' => 'A released collateral item cannot be changed.']);
            }

            $before = $this->snapshot($locked);
            $locked->fill($this->normalise(array_intersect_key($changes, array_flip(self::DETAIL_FIELDS))));
            $after = $this->snapshot($locked);
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

            if ($changed === []) {
                return $item->setRawAttributes($locked->getAttributes(), true);
            }

            if ($loan->status !== LoanStatus::Draft && blank($reason)) {
                throw ValidationException::withMessages([
                    'reason' => "A reason is required to change collateral of {$this->describe($loan)}.",
                ]);
            }

            $locked->save();

            $this->history->record($loan, LoanEventType::CollateralUpdated, $actor, array_filter([
                'collateral_no' => $locked->collateral_no,
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
                'reason' => $reason,
            ], fn ($value) => $value !== null));

            return $item->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Returns a held item to the customer. Only after the loan is closed or cancelled; final.
     */
    public function release(CollateralItem $item, User $actor, string $reason): CollateralItem
    {
        return DB::transaction(function () use ($item, $actor, $reason): CollateralItem {
            $loan = $this->lockLoan($item->loan()->firstOrFail());
            $locked = $this->lockItem($item);

            if ($locked->status === CollateralStatus::Released) {
                throw ValidationException::withMessages(['status' => 'This collateral item has already been released.']);
            }

            if (! in_array($loan->status, self::RELEASABLE, true)) {
                throw ValidationException::withMessages([
                    'status' => "Collateral can be released only after the loan is closed or cancelled (loan is {$loan->status->value}).",
                ]);
            }

            $locked->forceFill([
                'status' => CollateralStatus::Released,
                'released_at' => now(),
                'released_by' => $actor->id,
            ])->save();

            $this->history->record($loan, LoanEventType::CollateralReleased, $actor, [
                'collateral_no' => $locked->collateral_no,
                'reason' => $reason,
            ]);

            return $item->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * "an active loan", "a draft loan", …
     */
    private function describe(Loan $loan): string
    {
        $status = $loan->status->value;

        return (in_array($status[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a')." {$status} loan";
    }

    private function lockLoan(Loan $loan): Loan
    {
        return Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockItem(CollateralItem $item): CollateralItem
    {
        return CollateralItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Decimal-safe normalisation: weight to 3 decimals, karat to 2, value to 2 (validation already
     * limits the decimals, so nothing is rounded).
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function normalise(array $details): array
    {
        $normalised = array_intersect_key($details, array_flip(self::DETAIL_FIELDS));

        if (array_key_exists('weight_grams', $normalised)) {
            $normalised['weight_grams'] = bcadd((string) $normalised['weight_grams'], '0', 3);
        }

        if (array_key_exists('karat', $normalised)) {
            $normalised['karat'] = $normalised['karat'] === null || $normalised['karat'] === '' ? null : bcadd((string) $normalised['karat'], '0', 2);
        }

        if (array_key_exists('estimated_value', $normalised)) {
            $normalised['estimated_value'] = Money::of((string) $normalised['estimated_value']);
        }

        if (($normalised['received_at'] ?? false) === null) {
            unset($normalised['received_at']);
        }

        return $normalised;
    }

    /**
     * @return array<string, ?string>
     */
    private function snapshot(CollateralItem $item): array
    {
        return [
            'type' => $item->type,
            'weight_grams' => $item->weight_grams,
            'karat' => $item->karat,
            'estimated_value' => $item->estimated_value,
            'description' => $item->description,
            'received_at' => $item->received_at?->toIso8601String(),
        ];
    }
}
