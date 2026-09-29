<?php

namespace App\Enums;

/**
 * An alert is open while its condition holds and resolved once it no longer does (e.g. the missed
 * periods were paid). Resolved alerts are kept as history; a returning condition reopens the same alert.
 */
enum AlertStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
