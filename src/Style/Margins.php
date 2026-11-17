<?php

namespace Wovosoft\LaravelUniexporter\Style;

/**
 * Page margins in millimetres. `header` and `footer` are the distances from the page edge
 * to the header and footer content.
 */
final readonly class Margins
{
    public function __construct(
        public float $top = 15,
        public float $right = 15,
        public float $bottom = 15,
        public float $left = 15,
        public float $header = 5,
        public float $footer = 5,
    ) {}

    /**
     * CSS-style shorthand: one value for all sides, two for vertical/horizontal, or four for top/right/bottom/left.
     */
    public static function of(float ...$values): self
    {
        return match (count($values)) {
            1       => new self($values[0], $values[0], $values[0], $values[0]),
            2       => new self($values[0], $values[1], $values[0], $values[1]),
            4       => new self($values[0], $values[1], $values[2], $values[3]),
            default => throw new \InvalidArgumentException('Margins take 1, 2 or 4 values.'),
        };
    }

    public function withHeaderFooter(float $header, float $footer): self
    {
        return new self($this->top, $this->right, $this->bottom, $this->left, $header, $footer);
    }
}
