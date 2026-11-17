<?php

namespace Wovosoft\LaravelUniexporter\Components;

use InvalidArgumentException;
use Wovosoft\LaravelUniexporter\Style\Style;

class Heading extends Component
{
    /**
     * Default font sizes (points) per heading level.
     */
    public const array SIZES = [1 => 16, 2 => 14, 3 => 12.5, 4 => 11.5, 5 => 11, 6 => 10.5];

    public function __construct(protected string $text = '', protected int $level = 1)
    {
        $this->level($level);
    }

    public function text(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function level(int $level): static
    {
        if ($level < 1 || $level > 6) {
            throw new InvalidArgumentException('Heading level must be between 1 and 6.');
        }

        $this->level = $level;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function defaultStyle(): Style
    {
        return Style::make()->bold()->size(self::SIZES[$this->level])->spaceAfter(2);
    }
}
