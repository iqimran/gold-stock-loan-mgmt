<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

class BlueprintMacros
{
    public static function register(): void
    {
        /*
         * Adds created_by / updated_by audit columns referencing users.
         * Deletion of a referenced user is restricted so history stays auditable.
         */
        Blueprint::macro('userstamps', function (): void {
            /** @var Blueprint $this */
            $this->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $this->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
        });
    }
}
