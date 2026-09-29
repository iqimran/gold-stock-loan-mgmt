<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Append-only audit trail of financial and sensitive changes (ported from the Inventory POS):
         * loans, payments, reversals, collateral, customers and settings — see App\Domain\Audit\AuditTrail
         * for the events. Loans additionally keep their own per-loan history (loan_events).
         */
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60);
            $table->nullableMorphs('auditable');
            $table->string('description', 255)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['event', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
