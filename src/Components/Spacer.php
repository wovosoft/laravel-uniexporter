<?php

namespace Wovosoft\LaravelUniexporter\Components;

/**
 * Vertical blank space. Height in millimetres (spreadsheets add one empty row).
 */
class Spacer extends Component
{
    public function __construct(protected float $height = 5) {}

    public function height(float $millimetres): static
    {
        $this->height = $millimetres;

        return $this;
    }

    public function getHeight(): float
    {
        return $this->height;
    }
}
