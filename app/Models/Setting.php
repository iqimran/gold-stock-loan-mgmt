<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A key/value application setting. Read and written only through App\Domain\Settings\LoanSettings.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value', 'updated_by'];
}
