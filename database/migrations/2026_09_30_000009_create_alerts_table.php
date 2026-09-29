<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Alerts (e.g. consecutive missed interest periods reaching the configured threshold).
         * An alert never changes loan or collateral state by itself (docs/00, docs/08 Interest 7).
         * interest_period_id + the unique key prevent duplicate alerts for the same
         * loan + interest period + threshold condition (docs/08 Alerts).
         */
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('interest_period_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('type', 50);
            $table->unsignedInteger('threshold');
            $table->string('message', 500);
            $table->string('status', 20);
            $table->dateTime('triggered_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'interest_period_id', 'type', 'threshold']);
            $table->index(['status', 'triggered_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
