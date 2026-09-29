<?php

namespace App\Domain\Alert;

use App\Domain\Interest\InterestPeriodStatusResolver;
use App\Domain\Interest\MissedPeriodStreak;
use App\Domain\Loan\LoanHistory;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\LoanEventType;
use App\Models\Alert;
use App\Models\InterestPeriod;
use App\Models\Loan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Missed-interest alerts (docs/00 "Interest reminder rule", docs/08 Interest 5–7 and Alerts).
 *
 * For each open loan, the consecutive-missed streak (App\Domain\Interest\MissedPeriodStreak) is compared
 * with the configured threshold:
 *
 *  - streak ≥ threshold and no open alert → one alert, keyed to the period that made the streak reach the
 *    threshold. A longer streak does not raise another alert for the same streak.
 *  - streak < threshold (payment or waiver reset it, or the threshold was raised) → open alerts resolved.
 *  - loan no longer open (closed/cancelled) → open alerts resolved.
 *
 * Duplicates are impossible: at most one open alert per loan, and the database keys an alert by loan +
 * triggering period + type + threshold; a condition that returns (e.g. after a payment reversal) reopens
 * the same alert. Alerts are information only — they never mark a loan defaulted, close it, seize
 * collateral or penalise the customer (docs/00: that needs an explicit business rule, and none exists).
 *
 * Callers hold the loan row lock (the interest schedule sync), so concurrent runs cannot race.
 */
class MissedPaymentAlertService
{
    public function __construct(
        private readonly AlertSettings $settings,
        private readonly LoanHistory $history,
    ) {}

    public function sync(Loan $loan, CarbonInterface|string $today): ?Alert
    {
        $today = $today instanceof CarbonInterface ? $today->toDateString() : $today;
        $threshold = $this->settings->missedPeriodThreshold;

        if (! $loan->status->isOpen()) {
            $this->resolveOpen($loan, 'the loan is '.$loan->status->value);

            return null;
        }

        $streak = MissedPeriodStreak::of($this->periods($loan, $today), $today);

        if (count($streak) < $threshold) {
            $this->resolveOpen($loan, count($streak) === 0 ? 'no missed periods' : count($streak).' consecutive missed, below the threshold of '.$threshold);

            return null;
        }

        $open = $this->openAlerts($loan)->where('threshold', $threshold)->first();

        if ($open) {
            return $open;
        }

        // An open alert raised under a different (old) threshold no longer applies.
        $this->resolveOpen($loan, 'threshold changed to '.$threshold);

        $trigger = $streak[$threshold - 1];
        $message = sprintf(
            'Loan %s has %d consecutive missed interest period(s), reaching the alert threshold of %d.',
            $loan->loan_no, count($streak), $threshold,
        );

        $alert = Alert::query()->firstOrNew([
            'loan_id' => $loan->id,
            'interest_period_id' => $trigger['id'],
            'type' => AlertType::MissedInterest,
            'threshold' => $threshold,
        ]);
        $reopened = $alert->exists;

        $alert->fill([
            'customer_id' => $loan->customer_id,
            'message' => $message,
            'status' => AlertStatus::Open,
            'triggered_at' => now(),
            'resolved_at' => null,
        ])->save();

        $this->history->record($loan, LoanEventType::AlertRaised, null, [
            'alert' => AlertType::MissedInterest->value,
            'threshold' => $threshold,
            'consecutive_missed' => count($streak),
            'period_start' => $trigger['period_start'],
            'reopened' => $reopened,
        ]);

        return $alert;
    }

    /**
     * @return list<array{id: int, period_start: string, due_date: string, status: string}>
     */
    private function periods(Loan $loan, string $today): array
    {
        return InterestPeriod::query()
            ->where('loan_id', $loan->id)
            ->orderBy('due_date')
            ->orderBy('period_start')
            ->get()
            ->map(fn (InterestPeriod $period) => [
                'id' => $period->id,
                'period_start' => $period->period_start->toDateString(),
                'due_date' => $period->due_date->toDateString(),
                'status' => InterestPeriodStatusResolver::resolve(
                    $period->expected_interest, $period->paid_interest, $period->waived_at !== null, $period->due_date->toDateString(), $today,
                )->value,
            ])
            ->all();
    }

    /**
     * @return Builder<Alert>
     */
    private function openAlerts(Loan $loan)
    {
        return Alert::query()
            ->where('loan_id', $loan->id)
            ->where('type', AlertType::MissedInterest)
            ->where('status', AlertStatus::Open);
    }

    private function resolveOpen(Loan $loan, string $why): void
    {
        foreach ($this->openAlerts($loan)->get() as $alert) {
            $alert->update(['status' => AlertStatus::Resolved, 'resolved_at' => now()]);

            $this->history->record($loan, LoanEventType::AlertResolved, null, [
                'alert' => $alert->type->value,
                'threshold' => $alert->threshold,
                'reason' => $why,
            ]);
        }
    }
}
