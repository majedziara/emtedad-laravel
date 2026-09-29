<?php

namespace App\Helpers;

use InvalidArgumentException;

final class Money
{
    // These helpers are for the supported two-decimal currencies only.
    public static function decimal(int $minor): string
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('Negative amount.');
        }

        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function minor(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/\A(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?\z/', $value, $matches)) {
            throw new InvalidArgumentException('Invalid two-decimal amount.');
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
