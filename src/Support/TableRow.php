<?php

namespace Wovosoft\LaravelUniexporter\Support;

use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * One resolved body row of a table.
 */
final readonly class TableRow
{
    /**
     * @param list<mixed> $values per column, after the formatter (raw, typed)
     * @param list<string> $texts per column, formatted for display
     */
    public function __construct(
        public int $index,
        public mixed $source,
        public array $values,
        public array $texts,
        public ?Style $style,
    ) {}
}
