<?php

namespace Wovosoft\LaravelUniexporter\Style;

/**
 * Paper sizes every default driver supports (mPDF, PhpWord and OpenSpout all know these).
 */
enum Paper: string
{
    case A3 = 'A3';
    case A4 = 'A4';
    case A5 = 'A5';
    case Letter = 'Letter';
    case Legal = 'Legal';

    /**
     * Portrait width and height in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public function dimensions(): array
    {
        return match ($this) {
            self::A3     => [297.0, 420.0],
            self::A4     => [210.0, 297.0],
            self::A5     => [148.0, 210.0],
            self::Letter => [215.9, 279.4],
            self::Legal  => [215.9, 355.6],
        };
    }

    /**
     * Width and height in millimetres for the given orientation.
     *
     * @return array{0: float, 1: float}
     */
    public function dimensionsFor(Orientation $orientation): array
    {
        [$width, $height] = $this->dimensions();

        return $orientation === Orientation::Landscape ? [$height, $width] : [$width, $height];
    }
}
