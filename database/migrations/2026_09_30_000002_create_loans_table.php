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
         * outstanding_principal is written only by the loan/payment services inside the payment
         * transaction and is reconstructable from payment_allocations.principal_amount.
         */
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_no', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->decimal('principal', 18, 2);
            $table->decimal('outstanding_principal', 18, 2);
            $table->decimal('interest_rate', 8, 4);
            $table->string('interest_rate_type', 20);
            $table->string('interest_period_unit', 20);
            $table->string('status', 20)->index();
            $table->date('start_date')->index();
            $table->date('next_due_date')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->userstamps();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            // PostgreSQL does not index foreign keys automatically.
            $table->index('created_by');
            $table->index('updated_by');
        });

        CheckConstraints::add('loans', [
            'loans_principal_positive' => 'principal > 0',
            'loans_outstanding_principal_non_negative' => 'outstanding_principal >= 0',
            'loans_interest_rate_non_negative' => 'interest_rate >= 0',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
