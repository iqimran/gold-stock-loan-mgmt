<?php

namespace App\Console\Commands;

use App\Domain\Interest\InterestScheduleService;
use App\Domain\Loan\LoanService;
use App\Enums\LoanStatus;
use App\Models\Loan;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily interest run: generates due interest periods, refreshes period statuses and next due dates,
 * and applies overdue detection to every open loan. Idempotent — safe to run any number of times.
 */
class ProcessInterest extends Command
{
    protected $signature = 'loans:process-interest {--date= : Business date to process (Y-m-d); defaults to today in the application time zone}';

    protected $description = 'Generate interest periods, refresh their statuses and detect overdue loans';

    public function handle(InterestScheduleService $schedule, LoanService $loans): int
    {
        $today = $this->option('date')
            ? Carbon::createFromFormat('!Y-m-d', (string) $this->option('date'))
            : today();

        if ($today === false || ($this->option('date') && $today->toDateString() !== $this->option('date'))) {
            $this->components->error('The --date option must be a valid date in Y-m-d format.');

            return self::FAILURE;
        }

        $created = $changed = $failed = 0;
        $overdueBefore = Loan::where('status', LoanStatus::Overdue)->count();

        Loan::query()->whereIn('status', LoanStatus::open())->chunkById(200, function ($chunk) use ($schedule, $loans, $today, &$created, &$changed, &$failed) {
            foreach ($chunk as $loan) {
                try {
                    // One transaction per loan: a failing loan never blocks the others.
                    DB::transaction(function () use ($schedule, $loans, $loan, $today, &$created, &$changed) {
                        $result = $schedule->sync($loan, $today);
                        $loans->syncOverdueStatus($loan);
                        $created += $result->periodsCreated;
                        $changed += $result->statusesChanged;
                    });
                } catch (Throwable $e) {
                    $failed++;
                    Log::error("Interest processing failed for loan {$loan->loan_no}.", ['exception' => $e]);
                }
            }
        });

        $overdueAfter = Loan::where('status', LoanStatus::Overdue)->count();

        $this->components->info(sprintf(
            'Interest processed for %s: %d period(s) created, %d status change(s), overdue loans %d → %d.',
            $today->toDateString(), $created, $changed, $overdueBefore, $overdueAfter,
        ));

        if ($failed > 0) {
            $this->components->error("{$failed} loan(s) failed; see the application log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
