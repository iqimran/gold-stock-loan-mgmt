<?php

use App\Support\Database\CheckConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One explicit row per interest period (docs/08 Interest 2). Missed/partially-paid state is
         * derived from due_date + allocations; the unique key keeps schedule generation idempotent.
         */
        Schema::create('interest_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->decimal('expected_interest', 18, 2);
            $table->decimal('paid_interest', 18, 2)->default(0);
            $table->string('status', 20);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->foreignId('waived_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->string('waiver_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'period_start', 'period_end']);
            $table->index(['loan_id', 'due_date']);
            $table->index(['status', 'due_date']);
        });

        CheckConstraints::add('interest_periods', [
            'interest_periods_period_order' => 'period_end >= period_start',
            'interest_periods_expected_interest_non_negative' => 'expected_interest >= 0',
            'interest_periods_paid_interest_non_negative' => 'paid_interest >= 0',
            'interest_periods_waiver_recorded' => '(waived_at IS NULL AND waived_by IS NULL AND waiver_reason IS NULL) OR (waived_at IS NOT NULL AND waived_by IS NOT NULL AND waiver_reason IS NOT NULL)',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_periods');
    }
};
