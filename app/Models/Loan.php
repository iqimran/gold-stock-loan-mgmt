<?php

namespace App\Models;

use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Collateralised loan (table: loans). Authorized by App\Policies\LoanPolicy.
 *
 * Attributes, casts and relationships are added by docs/tasks/006-loan-backend.md.
 */
class Loan extends Model
{
    use HasUserstamps;
}
