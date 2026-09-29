<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive changes for the payment engine (docs/tasks/010):
     *  - payments.type fits the documented "principal_and_interest" (22 characters);
     *  - payments.idempotency_key: a retried request never posts a payment twice (docs/04);
     *  - ledger_entries.interest_period_id: an interest period is charged to the ledger exactly once,
     *    however often the daily interest run repeats (unique period + entry type).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('type', 30)->change();
            $table->string('idempotency_key', 100)->nullable()->unique()->after('receipt_no');
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreignId('interest_period_id')->nullable()->after('payment_id')->constrained()->restrictOnDelete();
            $table->unique(['interest_period_id', 'entry_type']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['interest_period_id', 'entry_type']);
            $table->dropConstrainedForeignId('interest_period_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
            $table->string('type', 20)->change();
        });
    }
};
