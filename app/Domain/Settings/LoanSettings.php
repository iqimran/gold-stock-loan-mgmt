<?php

namespace App\Domain\Settings;

use App\Domain\Audit\AuditTrail;
use App\Domain\Interest\InterestSettings;
use App\Enums\InterestBase;
use App\Enums\InterestDueTiming;
use App\Enums\YearlyRateConversion;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The module's settings (docs/01 "Settings"), stored in the key/value `settings` table as in the
 * Inventory POS. A saved value wins; until one is saved the default from config/loans.php or
 * config/shop.php applies, so nothing configurable is hard-coded. Cached; the cache is cleared on save.
 *
 * What each setting affects (EFFECTS, shown on the settings screen) — historical financial records are
 * never recalculated:
 *  - interest method (base, due timing, yearly conversion): NEW loans only — copied onto the loan at
 *    creation (business-decided); existing loans keep their own method; generated periods never change
 *  - default rate / rate type: prefill of the New loan form only
 *  - grace period and alert threshold: collection policy, applied to all open loans from the next
 *    evaluation (overdue status, missed counts, alerts); no amount changes
 *  - number formats: numbers issued from now on; existing numbers are never renumbered
 *  - payment methods, collateral types, karat options: choices for new entries; existing records keep
 *    their values
 *  - currency and shop details: presentation only (screens, receipts incl. reprints, exports); no
 *    amount is converted
 */
class LoanSettings
{
    private const CACHE_KEY = 'settings.loans';

    public const EFFECTS = [
        'shop' => 'Printed on receipts and exports from now on, including reprints of old receipts.',
        'currency' => 'Display only (screens, receipts, exports). Amounts are never converted.',
        'interest' => 'New loans only: each loan keeps the method in force when it was created. Existing loans and generated interest periods never change.',
        'defaults' => 'Prefill of the New loan form only.',
        'collection' => 'All open loans, from the next evaluation (overdue status, missed counts, alerts). No amounts change.',
        'numbering' => 'Numbers issued from now on. Existing numbers are never changed.',
        'lists' => 'Choices for new entries. Existing records keep their values.',
    ];

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Every setting with its effective value (saved, else default).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->defaults(), $this->saved());
    }

    /**
     * Saves the given settings (already validated), writing only changed keys, and records one audit
     * entry with the before/after values — in one transaction.
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        $before = $this->all();
        $values = array_intersect_key($values, $this->defaults());

        DB::transaction(function () use ($values, $before): void {
            foreach ($values as $key => $value) {
                if ($before[$key] !== $value) {
                    Setting::updateOrCreate(['key' => $key], ['value' => json_encode($value), 'updated_by' => Auth::id()]);
                }
            }

            Cache::forget(self::CACHE_KEY);
            $this->audit->recordChanges('settings.loans_updated', null, $before, $this->all(), 'Loan settings changed');
        });

        Cache::forget(self::CACHE_KEY);
    }

    // ── typed accessors ─────────────────────────────────────────────────────────────────────

    /**
     * @return array{name: string, address: ?string, phone: ?string, receipt_footer: ?string}
     */
    public function shop(): array
    {
        $all = $this->all();

        return [
            'name' => (string) ($all['shop.name'] ?: config('app.name')),
            'address' => $all['shop.address'],
            'phone' => $all['shop.phone'],
            'receipt_footer' => $all['shop.receipt_footer'],
        ];
    }

    /**
     * @return array{code: string, symbol: string}
     */
    public function currency(): array
    {
        return ['code' => (string) $this->all()['currency.code'], 'symbol' => (string) $this->all()['currency.symbol']];
    }

    /**
     * The interest method given to NEW loans (copied onto the loan when it is created).
     */
    public function interestMethod(): InterestSettings
    {
        $all = $this->all();

        return new InterestSettings(
            InterestBase::from($all['interest.base']),
            InterestDueTiming::from($all['interest.due']),
            YearlyRateConversion::from($all['interest.yearly_conversion']),
        );
    }

    public function defaultInterestRate(): ?string
    {
        return $this->all()['loans.default_interest_rate'];
    }

    public function defaultInterestRateType(): string
    {
        return $this->all()['loans.default_interest_rate_type'];
    }

    public function graceDays(): int
    {
        return (int) $this->all()['collection.grace_days'];
    }

    /**
     * A period due BEFORE this date is missed/overdue: today minus the grace period (business-decided:
     * applies to all open loans). With no grace period this is today.
     */
    public function missedCutoff(?CarbonInterface $today = null): string
    {
        return ($today ?? today())->copy()->subDays($this->graceDays())->toDateString();
    }

    public function alertThreshold(): int
    {
        return (int) $this->all()['collection.alert_threshold'];
    }

    /**
     * @param  'customer'|'loan'|'collateral'|'receipt'  $document
     * @return array{prefix: string, reset: string, digits: int}
     */
    public function numbering(string $document): array
    {
        $all = $this->all();

        return ['prefix' => (string) $all["numbering.{$document}_prefix"], 'reset' => (string) $all['numbering.reset'], 'digits' => (int) $all['numbering.digits']];
    }

    /**
     * @return list<string>
     */
    public function paymentMethods(): array
    {
        return array_values($this->all()['lists.payment_methods']);
    }

    /**
     * @return list<string>
     */
    public function collateralTypes(): array
    {
        return array_values($this->all()['lists.collateral_types']);
    }

    /**
     * @return list<string>
     */
    public function karatOptions(): array
    {
        return array_values($this->all()['lists.karat_options']);
    }

    public function maxKarat(): string
    {
        return (string) $this->all()['lists.max_karat'];
    }

    // ── storage ─────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'shop.name' => config('shop.name'),
            'shop.address' => config('shop.address'),
            'shop.phone' => config('shop.phone'),
            'shop.receipt_footer' => config('shop.receipt_footer'),
            'currency.code' => config('loans.currency.code'),
            'currency.symbol' => config('loans.currency.symbol'),
            'interest.base' => config('loans.interest.base'),
            'interest.due' => config('loans.interest.due'),
            'interest.yearly_conversion' => config('loans.interest.yearly_conversion'),
            'loans.default_interest_rate' => config('loans.defaults.interest_rate'),
            'loans.default_interest_rate_type' => config('loans.defaults.interest_rate_type'),
            'collection.grace_days' => (int) config('loans.defaults.grace_days'),
            'collection.alert_threshold' => (int) config('loans.alerts.missed_period_threshold'),
            'numbering.customer_prefix' => config('loans.numbering.customer'),
            'numbering.loan_prefix' => config('loans.numbering.loan'),
            'numbering.collateral_prefix' => config('loans.numbering.collateral'),
            'numbering.receipt_prefix' => config('loans.numbering.receipt'),
            'numbering.reset' => config('loans.numbering.reset'),
            'numbering.digits' => (int) config('loans.numbering.digits'),
            'lists.payment_methods' => config('loans.payment_methods'),
            'lists.collateral_types' => config('loans.collateral.types'),
            'lists.karat_options' => config('loans.karat_options'),
            'lists.max_karat' => (string) config('loans.collateral.max_karat'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function saved(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()
            ->whereIn('key', array_keys($this->defaults()))
            ->pluck('value', 'key')
            ->map(fn (?string $value) => $value === null ? null : json_decode($value, true))
            ->all());
    }
}
