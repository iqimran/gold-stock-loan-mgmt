<?php

namespace App\Models;

use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Loan customer (table: customers). Authorized by App\Policies\CustomerPolicy.
 *
 * Attributes, casts and relationships are added by docs/tasks/004-customers-backend.md.
 */
class Customer extends Model
{
    use HasUserstamps;
}
