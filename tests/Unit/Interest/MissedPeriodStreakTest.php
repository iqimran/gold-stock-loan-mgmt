<?php

namespace Tests\Unit\Interest;

use App\Domain\Interest\MissedPeriodStreak;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MissedPeriodStreakTest extends TestCase
{
    /**
     * @param  list<string>  $statuses  one per month, due on the 28th of Jan, Feb, Mar, …
     * @return list<array{due_date: string, status: string}>
     */
    private function periods(array $statuses): array
    {
        return array_map(fn (string $status, int $month) => ['due_date' => sprintf('2026-%02d-28', $month + 1), 'status' => $status], $statuses, array_keys($statuses));
    }

    /**
     * Today is 2026-05-15: Jan–Apr are past due, May is not.
     *
     * @return array<string, array{0: list<string>, 1: int}>
     */
    public static function streaks(): array
    {
        return [
            'nothing missed' => [['paid', 'paid', 'paid', 'paid', 'upcoming'], 0],
            'one missed' => [['paid', 'paid', 'paid', 'overdue', 'upcoming'], 1],
            'two consecutive' => [['paid', 'paid', 'overdue', 'overdue', 'upcoming'], 2],
            'all four' => [['overdue', 'overdue', 'overdue', 'overdue', 'upcoming'], 4],
            'a paid period resets the count' => [['overdue', 'overdue', 'paid', 'overdue', 'upcoming'], 1],
            'a waived period resets the count' => [['overdue', 'overdue', 'waived', 'overdue', 'upcoming'], 1],
            'latest period paid: count 0' => [['overdue', 'overdue', 'overdue', 'paid', 'upcoming'], 0],
            'current period does not count' => [['paid', 'paid', 'paid', 'paid', 'partially_paid'], 0],
            'no periods' => [[], 0],
        ];
    }

    #[DataProvider('streaks')]
    public function test_consecutive_missed_count(array $statuses, int $expected): void
    {
        $this->assertCount($expected, MissedPeriodStreak::of($this->periods($statuses), '2026-05-15'));
    }

    public function test_the_streak_is_returned_oldest_first(): void
    {
        $streak = MissedPeriodStreak::of($this->periods(['paid', 'overdue', 'overdue', 'overdue', 'upcoming']), '2026-05-15');

        $this->assertSame(['2026-02-28', '2026-03-28', '2026-04-28'], array_column($streak, 'due_date'));
    }

    public function test_a_period_due_today_is_not_yet_missed(): void
    {
        $this->assertCount(1, MissedPeriodStreak::of($this->periods(['overdue', 'due']), '2026-02-28'));
    }
}
