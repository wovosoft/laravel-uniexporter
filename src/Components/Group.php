<?php

namespace Wovosoft\LaravelUniexporter\Components;

use Illuminate\Support\Traits\Conditionable;
use Wovosoft\LaravelUniexporter\Components\Concerns\BuildsContent;

/**
 * A container whose font and color styles are inherited by its children. Its contents are given as a
 * list or added with the chainable content API ({@see BuildsContent}).
 */
class Group extends Component
{
    use BuildsContent;
    use Conditionable;

    protected bool $keepTogether = false;

    /**
     * @param list<Component> $children
     */
    public function __construct(protected array $children = []) {}

    /**
     * @param list<Component> $children
     */
    public function children(array $children): static
    {
        $this->children = $children;

        return $this;
    }

    public function add(Component ...$components): static
    {
        array_push($this->children, ...$components);

        return $this;
    }

    /**
     * Avoids splitting the group across pages where the format allows it.
     */
    public function keepTogether(bool $keepTogether = true): static
    {
        $this->keepTogether = $keepTogether;

        return $this;
    }

    public function shouldKeepTogether(): bool
    {
        return $this->keepTogether;
    }

    public function getChildren(): array
    {
        return $this->children;
    }
}
