<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Customers are deactivated/archived through `status`, never hard-deleted once financial
         * history exists: loans, payments and ledger entries restrict deletion (docs/08 "Customer deletion").
         */
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_no', 30)->unique();
            $table->string('name', 150)->index();
            $table->string('mobile', 30)->index();
            $table->string('nid', 50)->nullable()->index();
            $table->string('image_path')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('status', 20)->index();
            $table->userstamps();
            $table->timestamps();

            // PostgreSQL does not index foreign keys automatically.
            $table->index('created_by');
            $table->index('updated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
