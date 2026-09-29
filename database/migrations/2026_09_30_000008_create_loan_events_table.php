<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Loan history (created, payment posted/reversed, collateral released, closed, …).
         * actor_id is nullable for events raised by scheduled jobs rather than a user.
         */
        Schema::create('loan_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 50)->index();
            $table->dateTime('event_date');
            $table->jsonb('payload');
            $table->foreignId('actor_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['loan_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_events');
    }
};
