<?php

namespace Wovosoft\LaravelUniexporter\Components;

/**
 * An image from a file path or binary contents. The source is a template, so `{logo}`
 * takes a path or the binary contents from the export data. Sizes are in millimetres;
 * when only one is given the other follows the image's aspect ratio.
 */
class Image extends Component
{
    protected ?float $width = null;

    protected ?float $height = null;

    public function __construct(protected string $source = '') {}

    public function source(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function width(float $millimetres): static
    {
        $this->width = $millimetres;

        return $this;
    }

    public function height(float $millimetres): static
    {
        $this->height = $millimetres;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getWidth(): ?float
    {
        return $this->width;
    }

    public function getHeight(): ?float
    {
        return $this->height;
    }
}
