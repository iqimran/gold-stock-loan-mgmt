<?php

namespace App\Domain\Reporting\Export;

/**
 * One column of an export. The type drives both renderers: PDF alignment/grouping and Excel number
 * and date formats.
 */
final readonly class ExportColumn
{
    public const TEXT = 'text';

    public const MONEY = 'money';     // 2 decimals

    public const WEIGHT = 'weight';   // grams, 3 decimals

    public const RATE = 'rate';       // percentage, trailing zeros dropped

    public const KARAT = 'karat';     // 2 decimals

    public const INTEGER = 'integer';

    public const DATE = 'date';       // Y-m-d

    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TEXT,
    ) {}

    public function isNumeric(): bool
    {
        return in_array($this->type, [self::MONEY, self::WEIGHT, self::RATE, self::KARAT, self::INTEGER], true);
    }

    public function decimals(): int
    {
        return match ($this->type) {
            self::MONEY, self::KARAT => 2,
            self::WEIGHT => 3,
            self::RATE => 4,
            default => 0,
        };
    }

    /**
     * Human-readable value for the PDF (digits grouped on the decimal string: no float conversion).
     */
    public function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return $this->isNumeric() ? '' : '—';
        }

        if (! $this->isNumeric()) {
            return (string) $value;
        }

        $value = (string) $value;
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);
        $decimals = $this->decimals();

        if ($this->type === self::RATE) {
            $fraction = rtrim($fraction, '0');

            return ($negative ? '-' : '').$grouped.($fraction !== '' ? '.'.$fraction : '');
        }

        return ($negative ? '-' : '').$grouped.($decimals > 0 ? '.'.str_pad(substr($fraction, 0, $decimals), $decimals, '0') : '');
    }
}
