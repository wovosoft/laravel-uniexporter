<?php

namespace Wovosoft\LaravelUniexporter\Support;

/**
 * Conversions from the schema's canonical units (millimetres, points) to each library's units.
 */
final class Units
{
    public static function mmToTwip(float $millimetres): int
    {
        return (int) round($millimetres * 56.6929);
    }

    public static function mmToPoint(float $millimetres): float
    {
        return $millimetres * 72 / 25.4;
    }

    public static function mmToPixel(float $millimetres, int $dpi = 96): int
    {
        return (int) round($millimetres * $dpi / 25.4);
    }

    public static function mmToInch(float $millimetres): float
    {
        return $millimetres / 25.4;
    }

    public static function pointToTwip(float $points): int
    {
        return (int) round($points * 20);
    }

    /**
     * Approximate Excel column width units (characters of the default font) for a width in millimetres.
     */
    public static function mmToExcelWidth(float $millimetres): float
    {
        return round($millimetres / 1.9, 2);
    }
}
