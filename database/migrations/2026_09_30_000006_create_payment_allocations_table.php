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
         * How a payment is split into principal / interest / fee components, optionally against a
         * specific interest period. Rows are immutable; a reversal is handled on the payment.
         */
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('interest_period_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->decimal('principal_amount', 18, 2)->default(0);
            $table->decimal('interest_amount', 18, 2)->default(0);
            $table->decimal('fee_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2);
            $table->timestamps();
        });

        CheckConstraints::add('payment_allocations', [
            'payment_allocations_components_non_negative' => 'principal_amount >= 0 AND interest_amount >= 0 AND fee_amount >= 0',
            'payment_allocations_total_positive' => 'total_amount > 0',
            'payment_allocations_total_matches_components' => 'total_amount = principal_amount + interest_amount + fee_amount',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
