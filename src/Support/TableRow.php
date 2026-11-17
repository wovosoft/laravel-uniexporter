<?php

namespace Wovosoft\LaravelUniexporter\Support;

use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * One resolved body row of a table.
 */
final readonly class TableRow
{
    /**
     * @param int $index the row's position among the table's rows (sub-rows share their row's index)
     * @param list<mixed> $values per column, after the formatter (raw, typed)
     * @param list<string> $texts per column, formatted for display
     * @param array<int, int> $spans column index => rows the cell spans from here (> 1), or 0 when a cell
     *                               above spans over it (skip it); columns without an entry are plain cells
     * @param int $subRow position within the row's sub-rows (0 for the first or only one)
     * @param array<int, Style> $cellStyles column index => conditional style from Column::styleUsing()
     */
    public function __construct(
        public int $index,
        public mixed $source,
        public array $values,
        public array $texts,
        public ?Style $style,
        public array $spans = [],
        public int $subRow = 0,
        public array $cellStyles = [],
    ) {}

    /**
     * The row style with the cell's conditional style on top.
     */
    public function styleFor(int $position): ?Style
    {
        return isset($this->cellStyles[$position]) ? ($this->style ?? Style::make())->merge($this->cellStyles[$position]) : $this->style;
    }

    /**
     * Rows the cell at `$position` spans: 1 for a plain cell, more for a spanning cell, 0 when covered.
     */
    public function span(int $position): int
    {
        return $this->spans[$position] ?? 1;
    }
}
