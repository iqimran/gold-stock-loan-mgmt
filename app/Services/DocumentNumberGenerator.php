<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Concurrency-safe human-friendly document numbers, e.g. PUR-202609-000001.
 *
 * Must be called inside the database transaction that creates the document, so the
 * sequence row stays locked until commit and a rolled-back document releases its number.
 */
class DocumentNumberGenerator
{
    /**
     * @param  string  $reset  'monthly' (PREFIX-YYYYMM-…) or 'yearly' (PREFIX-YYYY-…): when the sequence restarts
     * @param  int  $digits  zero-padded sequence length
     */
    public function next(string $prefix, ?DateTimeInterface $date = null, string $reset = 'monthly', int $digits = 6): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Document numbers must be generated inside a database transaction.');
        }

        $period = ($date ?? now())->format($reset === 'yearly' ? 'Y' : 'Ym');
        $next = $this->lockedLastNumber($prefix, $period) + 1;

        DB::table('document_sequences')
            ->where('prefix', $prefix)
            ->where('period', $period)
            ->update(['last_number' => $next, 'updated_at' => now()]);

        return sprintf('%s-%s-%0'.$digits.'d', $prefix, $period, $next);
    }

    /**
     * The next number in a configured format (Settings → Loan settings → numbering).
     *
     * @param  array{prefix: string, reset: string, digits: int}  $format
     */
    public function nextIn(array $format, ?DateTimeInterface $date = null): string
    {
        return $this->next($format['prefix'], $date, $format['reset'], $format['digits']);
    }

    private function lockedLastNumber(string $prefix, string $period): int
    {
        $select = fn () => DB::table('document_sequences')
            ->where('prefix', $prefix)
            ->where('period', $period)
            ->lockForUpdate()
            ->value('last_number');

        // Lock an existing row first; insert only when the period's row is genuinely missing
        // (an INSERT IGNORE on an existing key would take a shared lock and risk deadlocks).
        $last = $select();

        if ($last === null) {
            DB::table('document_sequences')->insertOrIgnore([
                'prefix' => $prefix, 'period' => $period, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $last = $select();
        }

        return (int) $last;
    }
}
