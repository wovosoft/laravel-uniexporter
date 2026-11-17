<?php

namespace Wovosoft\LaravelUniexporter\Components;

use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * Label/value pairs, e.g. a profile header. Labels and values are templates:
 *
 * KeyValue::make(['Name' => '{employee.name}', 'PF No' => '{employee.pf_number}'])->columns(2)
 */
class KeyValue extends Component
{
    protected int $columns = 1;

    protected Style $labelStyle;

    protected string $separator = ':';

    /**
     * @param array<string, string> $items label => value template
     */
    public function __construct(protected array $items = [])
    {
        $this->labelStyle = Style::make()->bold();
    }

    /**
     * @param array<string, string> $items
     */
    public function items(array $items): static
    {
        $this->items = $items;

        return $this;
    }

    public function item(string $label, string $value): static
    {
        $this->items[$label] = $value;

        return $this;
    }

    /**
     * Number of label/value pairs per line.
     */
    public function columns(int $columns): static
    {
        $this->columns = max(1, $columns);

        return $this;
    }

    public function labelStyle(Style $style): static
    {
        $this->labelStyle = $style;

        return $this;
    }

    /**
     * Text appended to each label (default ':'; '' for none).
     */
    public function separator(string $separator): static
    {
        $this->separator = $separator;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getColumns(): int
    {
        return $this->columns;
    }

    public function getLabelStyle(): Style
    {
        return $this->labelStyle;
    }

    public function getSeparator(): string
    {
        return $this->separator;
    }

    public function defaultStyle(): Style
    {
        return Style::make()->spaceAfter(2);
    }
}
