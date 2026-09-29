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
         * Every item belongs to exactly one loan and is traceable from intake (received_at) to
         * release (released_at + released_by). Weight and valuation are historical facts.
         * karat is nullable: diamond collateral may have no meaningful karat (docs/08 Collateral 5).
         */
        Schema::create('collateral_items', function (Blueprint $table) {
            $table->id();
            $table->string('collateral_no', 30)->unique();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->string('type', 30)->index();
            $table->decimal('weight_grams', 12, 3);
            $table->decimal('karat', 5, 2)->nullable();
            $table->decimal('estimated_value', 18, 2);
            $table->text('description')->nullable();
            $table->string('status', 20)->index();
            $table->dateTime('received_at');
            $table->dateTime('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->userstamps();
            $table->timestamps();

            $table->index(['loan_id', 'status']);
            // PostgreSQL does not index foreign keys automatically.
            $table->index('created_by');
            $table->index('updated_by');
        });

        CheckConstraints::add('collateral_items', [
            'collateral_items_weight_positive' => 'weight_grams > 0',
            'collateral_items_karat_positive' => 'karat IS NULL OR karat > 0',
            'collateral_items_estimated_value_non_negative' => 'estimated_value >= 0',
            'collateral_items_release_recorded' => '(released_at IS NULL AND released_by IS NULL) OR (released_at IS NOT NULL AND released_by IS NOT NULL)',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('collateral_items');
    }
};
