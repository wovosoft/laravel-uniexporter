<?php

namespace Wovosoft\LaravelUniexporter\Style;

/**
 * A border applied to all sides. Width is in points.
 */
final readonly class Border
{
    public string $color;

    public function __construct(
        public float $width = 0.5,
        string $color = '000000',
        public BorderLine $line = BorderLine::Solid,
    ) {
        $this->color = Color::normalize($color);
    }

    public static function none(): self
    {
        return new self(0, '000000', BorderLine::None);
    }

    public function isVisible(): bool
    {
        return $this->line !== BorderLine::None && $this->width > 0;
    }
}
