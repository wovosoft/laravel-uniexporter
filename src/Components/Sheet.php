<?php

namespace Wovosoft\LaravelUniexporter\Components;

/**
 * Starts a new worksheet in spreadsheets and a new page in paged formats.
 * Placed before any content, it only names the first worksheet.
 */
class Sheet extends Component
{
    public function __construct(protected string $name = '') {}

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
