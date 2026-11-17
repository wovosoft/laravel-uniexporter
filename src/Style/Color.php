<?php

namespace Wovosoft\LaravelUniexporter\Style;

use InvalidArgumentException;

final class Color
{
    /**
     * Normalizes `#abc`, `abc`, `#AABBCC` and `aabbcc` to `AABBCC`.
     */
    public static function normalize(string $color): string
    {
        $hex = strtoupper(ltrim(trim($color), '#'));

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9A-F]{6}$/', $hex)) {
            throw new InvalidArgumentException("Invalid color [{$color}]: use a 3 or 6 digit hex value.");
        }

        return $hex;
    }
}
