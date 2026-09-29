<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Fills created_by / updated_by from the authenticated user.
 * Pair with the `userstamps()` migration macro.
 */
trait HasUserstamps
{
    public static function bootHasUserstamps(): void
    {
        static::creating(function (Model $model): void {
            if ($userId = Auth::id()) {
                $model->created_by ??= $userId;
                $model->updated_by ??= $userId;
            }
        });

        static::updating(function (Model $model): void {
            if (($userId = Auth::id()) && ! $model->isDirty('updated_by')) {
                $model->updated_by = $userId;
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
