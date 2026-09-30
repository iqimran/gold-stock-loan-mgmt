<?php

namespace App\Http\Requests\Settings;

use App\Domain\Settings\LoanSettings;
use App\Enums\InterestDueTiming;
use App\Enums\InterestRateType;
use App\Enums\Permission;
use App\Enums\YearlyRateConversion;
use App\Support\Validation\MoneyRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Loan settings (Settings → Loan settings). The payload is nested by section (shop.name,
 * numbering.loan_prefix, …), matching the setting keys of App\Domain\Settings\LoanSettings.
 */
class LoanSettingsRequest extends FormRequest
{
    public const PREFIXES = ['customer', 'loan', 'collateral', 'receipt'];

    public function authorize(): bool
    {
        return Gate::allows(Permission::SettingsManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $slug = 'regex:/^[a-z][a-z0-9_]{0,29}$/';

        return [
            'shop.name' => ['required', 'string', 'max:120'],
            'shop.address' => ['nullable', 'string', 'max:255'],
            'shop.phone' => ['nullable', 'string', 'max:40'],
            'shop.receipt_footer' => ['nullable', 'string', 'max:255'],
            // Raster only: an SVG could carry script, and the logo is served publicly (sign-in page).
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024', 'dimensions:min_width=32,min_height=32,max_width=2000,max_height=2000'],
            'remove_logo' => ['sometimes', 'boolean'],

            'currency.code' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'currency.symbol' => ['required', 'string', 'max:5'],

            // Business rule: a principal payment reduces the balance interest is charged on (reducing
            // balance), so the flat base is not offered.
            'interest.base' => ['required', Rule::in(LoanSettings::INTEREST_BASES)],
            'interest.due' => ['required', Rule::enum(InterestDueTiming::class)],
            'interest.yearly_conversion' => ['required', Rule::enum(YearlyRateConversion::class)],

            'loans.default_interest_rate' => MoneyRules::rate(required: false),
            'loans.default_interest_rate_type' => ['required', Rule::in(InterestRateType::values())],

            'collection.grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'collection.alert_threshold' => ['required', 'integer', 'min:1', 'max:24'],

            ...collect(self::PREFIXES)->mapWithKeys(fn (string $doc) => [
                "numbering.{$doc}_prefix" => ['required', 'string', 'regex:/^[A-Z][A-Z0-9]{0,9}$/'],
            ])->all(),
            'numbering.reset' => ['required', Rule::in(['monthly', 'yearly'])],
            'numbering.digits' => ['required', 'integer', 'min:4', 'max:8'],

            'lists.payment_methods' => ['required', 'array', 'min:1', 'max:20'],
            'lists.payment_methods.*' => ['required', 'string', $slug, 'distinct'],
            'lists.collateral_types' => ['required', 'array', 'min:1', 'max:30'],
            'lists.collateral_types.*' => ['required', 'string', $slug, 'distinct'],
            'lists.max_karat' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99.99'],
            'lists.karat_options' => ['required', 'array', 'min:1', 'max:20'],
            'lists.karat_options.*' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'lte:lists.max_karat', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currency.code.regex' => 'Use a three-letter ISO currency code, e.g. BDT.',
            'numbering.*.regex' => 'Use 1–10 capital letters or digits, starting with a letter.',
            'lists.payment_methods.*.regex' => 'Use lowercase letters, digits and underscores (e.g. bank_transfer).',
            'lists.collateral_types.*.regex' => 'Use lowercase letters, digits and underscores (e.g. necklace).',
            'lists.*.*.distinct' => 'Each value may appear only once.',
            'lists.karat_options.*.lte' => 'A karat option cannot exceed the maximum karat.',
            'logo.mimes' => 'The logo must be a PNG, JPG or WebP image.',
            'logo.mimetypes' => 'The logo must be a PNG, JPG or WebP image.',
            'logo.max' => 'The logo may not be larger than 1 MB.',
            'logo.dimensions' => 'The logo must be between 32×32 and 2000×2000 pixels.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Distinct prefixes keep each document type's numbers (and sequence) separate.
            $prefixes = collect(self::PREFIXES)->mapWithKeys(fn (string $doc) => [$doc => $this->input("numbering.{$doc}_prefix")]);

            foreach ($prefixes as $doc => $prefix) {
                if ($prefix !== null && $prefixes->filter(fn ($p) => $p === $prefix)->count() > 1) {
                    $validator->errors()->add("numbering.{$doc}_prefix", 'Each document type needs its own prefix.');
                }
            }
        }];
    }

    /**
     * The validated values keyed by setting key (LoanSettings), lists re-indexed.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $validated = $this->validated();

        // The logo is a file, handled by the controller (upload / remove / keep).
        return collect(array_keys(app(LoanSettings::class)->defaults()))
            ->reject(fn (string $key) => $key === 'shop.logo_path')
            ->mapWithKeys(function (string $key) use ($validated) {
                $value = data_get($validated, $key);

                return [$key => match (true) {
                    is_array($value) => array_values(array_map('strval', $value)),
                    in_array($key, ['collection.grace_days', 'collection.alert_threshold', 'numbering.digits'], true) => (int) $value,
                    in_array($key, ['loans.default_interest_rate', 'lists.max_karat'], true) => $value === null ? null : (string) $value,
                    is_string($value) => trim($value) === '' ? null : trim($value),
                    default => $value,
                }];
            })
            ->all();
    }

    protected function prepareForValidation(): void
    {
        $upper = fn (?string $v) => $v === null ? null : strtoupper(trim($v));

        $this->merge([
            'currency' => [...(array) $this->input('currency', []), 'code' => $upper($this->input('currency.code'))],
            'numbering' => [
                ...(array) $this->input('numbering', []),
                ...collect(self::PREFIXES)->mapWithKeys(fn (string $doc) => ["{$doc}_prefix" => $upper($this->input("numbering.{$doc}_prefix"))])->all(),
            ],
        ]);
    }
}
