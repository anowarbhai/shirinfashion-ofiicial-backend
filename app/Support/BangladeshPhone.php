<?php

namespace App\Support;

use InvalidArgumentException;

class BangladeshPhone
{
    public static function normalizeToLocal(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('Phone number is required.');
        }

        if (str_starts_with($digits, '880') && strlen($digits) === 13) {
            $digits = '0'.substr($digits, 3);
        }

        if (! str_starts_with($digits, '01') || ! preg_match('/^01[3-9]/', $digits)) {
            throw new InvalidArgumentException(
                'Number format error. Bangladeshi mobile numbers must start with 013, 014, 015, 016, 017, 018, or 019.',
            );
        }

        if (strlen($digits) !== 11) {
            throw new InvalidArgumentException(
                'Phone number must be exactly 11 digits.',
            );
        }

        return $digits;
    }

    public static function normalizeToInternational(string $phone): string
    {
        $local = self::normalizeToLocal($phone);

        return '880'.substr($local, 1);
    }

    public static function isValidLocal(string $phone): bool
    {
        try {
            self::normalizeToLocal($phone);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
