<?php

namespace App\Models;

use App\Models\Concerns\HasUserstamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Gold/diamond item held against a loan (table: collateral_items). Authorized by App\Policies\CollateralItemPolicy.
 *
 * Attributes, casts and relationships are added by docs/tasks/008-collateral-backend.md.
 */
class CollateralItem extends Model
{
    use HasUserstamps;
}
