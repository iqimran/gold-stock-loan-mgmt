<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Application settings editable from the UI (key/value, as in the Inventory POS). Values are JSON;
         * a missing key falls back to its configured default (App\Domain\Settings\LoanSettings).
         */
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        /*
         * The interest method is part of each loan's terms (business-decided): it is fixed when the loan is
         * created, so changing the setting affects new loans only. Existing loans keep the method they have
         * been running under — the configured defaults at the time of this migration.
         */
        Schema::table('loans', function (Blueprint $table) {
            $table->string('interest_base', 20)->nullable()->after('interest_period_unit');
            $table->string('interest_due_timing', 20)->nullable()->after('interest_base');
            $table->string('yearly_rate_conversion', 20)->nullable()->after('interest_due_timing');
        });

        DB::table('loans')->update([
            'interest_base' => config('loans.interest.base', 'outstanding'),
            'interest_due_timing' => config('loans.interest.due', 'period_end'),
            'yearly_rate_conversion' => config('loans.interest.yearly_conversion', 'twelfths'),
        ]);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['interest_base', 'interest_due_timing', 'yearly_rate_conversion']);
        });

        Schema::dropIfExists('settings');
    }
};
