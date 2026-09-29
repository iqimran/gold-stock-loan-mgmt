<?php

namespace App\Enums;

/**
 * Roles that ship with the application and cannot be renamed or deleted.
 */
enum SystemRole: string
{
    /** Full access to every ability (granted via Gate::before). */
    case Admin = 'Admin';

    /** Receives only explicitly granted operational permissions. */
    case GeneralUser = 'General User';

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }
}
