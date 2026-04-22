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

        if (strlen($digits) !== 11 || ! preg_match('/^01[3-9][0-9]{8}$/', $digits)) {
            throw new InvalidArgumentException(
                'Please use a valid Bangladeshi phone number in 11-digit format, for example 01919012186.',
            );
        }

        return $digits;
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
