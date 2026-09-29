<?php

namespace App\Models;

use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Payment received against a loan (table: payments). Authorized by App\Policies\PaymentPolicy.
 *
 * Attributes, casts and relationships are added by docs/tasks/010-payment-backend.md.
 */
class Payment extends Model
{
    use HasUserstamps;
}
