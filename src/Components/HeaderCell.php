<?php

namespace Wovosoft\LaravelUniexporter\Components;

/**
 * A cell in an extra header row above the column labels, e.g. a group label spanning columns.
 */
class HeaderCell extends Component
{
    protected int $colspan = 1;

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

    public function getText(): string
    {
        return $this->text;
    }

    public function getColspan(): int
    {
        return $this->colspan;
    }
}
