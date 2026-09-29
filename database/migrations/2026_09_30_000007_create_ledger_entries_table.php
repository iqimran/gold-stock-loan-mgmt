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
         * Append-only customer ledger. Corrections are new compensating entries, never edits.
         * balance_after may be negative, so it is not constrained.
         */
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('entry_type', 32)->index();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->decimal('balance_after', 18, 2);
            $table->date('entry_date');
            $table->string('description', 500);
            $table->string('reference', 100);
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'entry_date', 'id']);
            $table->index(['loan_id', 'entry_date']);
        });

        CheckConstraints::add('ledger_entries', [
            'ledger_entries_amounts_non_negative' => 'debit >= 0 AND credit >= 0',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
