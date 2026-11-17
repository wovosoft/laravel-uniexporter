<?php

namespace Wovosoft\LaravelUniexporter\Components;

/**
 * A bulleted or numbered list. Items are text templates; an item can carry nested items:
 *
 * ListBlock::make(['First', ['text' => 'Second', 'items' => ['Nested']]])->ordered()
 *
 * Items can also come from the data with {@see ListBlock::source()} (an iterable of strings).
 */
class ListBlock extends Component
{
    protected bool $ordered = false;

    protected ?string $source = null;

    /**
     * @param list<string|array{text: string, items?: list<mixed>}> $items
     */
    public function __construct(protected array $items = []) {}

    /**
     * @param list<string|array{text: string, items?: list<mixed>}> $items
     */
    public function items(array $items): static
    {
        $this->items = $items;

        return $this;
    }

    /**
     * Reads the items from a data path (an iterable of strings), after any static items.
     */
    public function source(string $path): static
    {
        $this->source = $path;

        return $this;
    }

    public function ordered(bool $ordered = true): static
    {
        $this->ordered = $ordered;

        return $this;
    }

    public function isOrdered(): bool
    {
        return $this->ordered;
    }

    /**
     * @return list<string|array{text: string, items?: list<mixed>}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }
}
