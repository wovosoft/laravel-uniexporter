<?php

namespace Wovosoft\LaravelUniexporter\Components;

/**
 * A container whose font and color styles are inherited by its children.
 */
class Group extends Component
{
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
