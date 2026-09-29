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
         * A posted payment's amount is never edited in place (docs/08 Payments 4). Reversal keeps the
         * row and records who, when and why; balances are corrected by compensating ledger entries.
         */
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->string('type', 20)->index();
            $table->decimal('amount', 18, 2);
            $table->string('method', 20);
            $table->date('payment_date');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->index();
            $table->userstamps();
            $table->foreignId('reversed_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'payment_date']);
            $table->index(['loan_id', 'payment_date']);
            $table->index('payment_date');
            // PostgreSQL does not index foreign keys automatically.
            $table->index('created_by');
            $table->index('updated_by');
        });

        CheckConstraints::add('payments', [
            'payments_amount_positive' => 'amount > 0',
            'payments_reversal_recorded' => '(reversed_at IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL) OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL)',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
