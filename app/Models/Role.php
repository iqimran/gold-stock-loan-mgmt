<?php

namespace App\Models;

use App\Enums\SystemRole;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public function isAdmin(): bool
    {
        return $this->name === SystemRole::Admin->value;
    }

    public function isSystem(): bool
    {
        return in_array($this->name, SystemRole::names(), true);
    }
}
