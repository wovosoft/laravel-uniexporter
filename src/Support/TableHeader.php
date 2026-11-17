<?php

namespace Wovosoft\LaravelUniexporter\Support;

use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\HeaderCell;
use Wovosoft\LaravelUniexporter\Components\Table;

/**
 * A table's header as a grid: the extra header rows (with col- and rowspans) followed by the column
 * labels, minus the labels that a header cell spans down over.
 */
final readonly class TableHeader
{
    /**
     * @param list<list<array{cell: HeaderCell|Column, position: int, colspan: int, rowspan: int}>> $rows
     * @param list<list<int>> $covered per row, the column positions a cell from an earlier row spans over
     */
    private function __construct(public array $rows, public array $covered) {}

    public static function of(Table $table): self
    {
        $columnCount = count($table->getColumns());
        $headerRows = $table->getHeaderRows();
        $rowCount = count($headerRows) + 1;
        $occupied = [];
        $rows = [];

        foreach ($headerRows as $r => $headerRow) {
            $position = 0;
            $entries = [];

            foreach ($headerRow as $cell) {
                while (isset($occupied[$r][$position])) {
                    $position++;
                }
                if ($position >= $columnCount) {
                    break;
                }

                $colspan = min($cell->getColspan(), $columnCount - $position);
                $rowspan = min($cell->getRowspan(), $rowCount - $r);
                $entries[] = ['cell' => $cell, 'position' => $position, 'colspan' => $colspan, 'rowspan' => $rowspan];

                for ($y = $r; $y < $r + $rowspan; $y++) {
                    for ($x = $position; $x < $position + $colspan; $x++) {
                        $occupied[$y][$x] = $y === $r ? 'own' : 'covered';
                    }
                }

                $position += $colspan;
            }

            $rows[] = $entries;
        }

        $labels = [];
        foreach ($table->getColumns() as $position => $column) {
            if (! isset($occupied[$rowCount - 1][$position])) {
                $labels[] = ['cell' => $column, 'position' => $position, 'colspan' => 1, 'rowspan' => 1];
            }
        }
        $rows[] = $labels;

        $covered = [];
        for ($r = 0; $r < $rowCount; $r++) {
            $covered[$r] = array_keys(array_filter($occupied[$r] ?? [], fn (string $state): bool => $state === 'covered'));
        }

        return new self($rows, $covered);
    }
}
