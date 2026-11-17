<?php

namespace Wovosoft\LaravelUniexporter\Components;

/**
 * A cell in an extra header row above the column labels: a group label spanning columns, or a label
 * spanning rows down into the column-label row (which then leaves out the labels it covers).
 */
class HeaderCell extends Component
{
    protected int $colspan = 1;

    protected int $rowspan = 1;

    public function __construct(protected string $text = '') {}

    public function text(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function colspan(int $columns): static
    {
        $this->colspan = max(1, $columns);

        return $this;
    }

    /**
     * Spans header rows downwards. Reaching the column-label row replaces those columns' labels.
     */
    public function rowspan(int $rows): static
    {
        $this->rowspan = max(1, $rows);

        return $this;
    }

    public function getRowspan(): int
    {
        return $this->rowspan;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getColspan(): int
    {
        return $this->colspan;
    }
}
