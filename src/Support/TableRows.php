<?php

namespace Wovosoft\LaravelUniexporter\Support;

use Generator;
use Wovosoft\LaravelUniexporter\Components\Table;

/**
 * Iterates a table's rows once (lazy sources stay lazy) and accumulates column totals on the way,
 * so drivers can write the totals row after the last data row.
 */
final class TableRows
{
    /**
     * @var array<int, float|int> column index => running sum
     */
    private array $totals = [];

    private int $count = 0;

    public function __construct(private readonly Table $table, private readonly Data $data) {}

    /**
     * The table's rows; with Table::subRows() each row yields one TableRow per sub-row.
     *
     * @return Generator<int, TableRow>
     */
    public function rows(): Generator
    {
        $columns = $this->table->getColumns();
        $styler = $this->table->getRowStyler();
        $subRowSource = $this->table->getSubRows();
        $index = 0;

        foreach ($this->data->iterable($this->table->getRows()) as $source) {
            $style = $styler ? $styler($source, $index) : null;
            $subItems = $subRowSource === null ? [null] : $this->subItems($subRowSource, $source, $index);
            $span = count($subItems);

            foreach ($subItems as $subRow => $subItem) {
                $values = [];
                $texts = [];
                $spans = [];
                $cellStyles = [];

                foreach ($columns as $position => $column) {
                    $fromSubRow = $subRowSource !== null && $column->isFromSubRow();

                    if (! $fromSubRow && $subRow > 0) {
                        $values[] = null;
                        $texts[] = '';
                        $spans[$position] = 0;

                        continue;
                    }

                    $value = $fromSubRow
                        ? ($subItem === null ? null : $column->valueFor($subItem, $index, $this->data))
                        : $column->valueFor($source, $index, $this->data);
                    $text = $column->getFormat()->toText($value);

                    $values[] = $value;
                    $texts[] = $text === '' ? $column->getEmptyValue() : $text;

                    if (($cellStyle = $column->cellStyleFor($value, $fromSubRow ? $subItem : $source)) !== null) {
                        $cellStyles[$position] = $cellStyle;
                    }

                    if (! $fromSubRow && $span > 1) {
                        $spans[$position] = $span;
                    }

                    if ($column->hasTotal() && is_numeric($value)) {
                        $this->totals[$position] = ($this->totals[$position] ?? 0) + $value;
                    }
                }

                yield new TableRow($index, $source, $values, $texts, $style, $spans, $subRow, $cellStyles);
            }

            $index++;
        }

        $this->count = $index;
    }

    /**
     * @return list<mixed> at least one item (null when the row has none)
     */
    private function subItems(string|\Closure $source, mixed $row, int $index): array
    {
        $items = is_string($source) ? data_get($row, $source) : $source($row, $index);
        $items = is_iterable($items) ? [...$items] : [];

        return $items === [] ? [null] : array_values($items);
    }

    /**
     * Totals per column index; columns without a total are null. Valid after rows() is exhausted.
     *
     * @return list<int|float|null>
     */
    public function totals(): array
    {
        return array_map(
            fn (int $position): int|float|null => $this->table->getColumns()[$position]->hasTotal() ? ($this->totals[$position] ?? 0) : null,
            array_keys($this->table->getColumns()),
        );
    }

    /**
     * The totals row, one entry per column position; null under the label's colspan. A cell's value
     * is the total (number), a text from Column::total(), or null; its text is ready to display.
     * Valid after rows() is exhausted.
     *
     * @return list<array{value: int|float|string|null, text: string, colspan: int, label: bool, sum: bool}|null>
     */
    public function totalCells(): array
    {
        $columns = $this->table->getColumns();
        $labelColumn = $this->totalLabelColumn();
        $labelSpan = 1;

        if ($labelColumn !== null) {
            while ($labelSpan < $this->table->getTotalLabelColspan()
                && isset($columns[$labelColumn + $labelSpan])
                && ! $columns[$labelColumn + $labelSpan]->hasTotal()) {
                $labelSpan++;
            }
        }

        $cells = [];
        foreach ($columns as $position => $column) {
            if ($labelColumn !== null && $position > $labelColumn && $position < $labelColumn + $labelSpan) {
                $cells[] = null;

                continue;
            }

            if ($column->hasTotal()) {
                $value = $column->totalValue($this->totals[$position] ?? 0, $this->data);
                $cells[] = ['value' => $value, 'text' => is_string($value) ? $value : $column->getFormat()->toText($value), 'colspan' => 1, 'label' => false, 'sum' => is_int($value) || is_float($value)];
            } elseif ($position === $labelColumn) {
                $cells[] = ['value' => null, 'text' => '', 'colspan' => $labelSpan, 'label' => true, 'sum' => false];
            } else {
                $cells[] = ['value' => null, 'text' => '', 'colspan' => 1, 'label' => false, 'sum' => false];
            }
        }

        return $cells;
    }

    /**
     * Index of the column that shows the totals label: the first one before the first total column.
     */
    public function totalLabelColumn(): ?int
    {
        foreach ($this->table->getColumns() as $position => $column) {
            if ($column->hasTotal()) {
                return $position > 0 ? 0 : null;
            }
        }

        return null;
    }

    public function count(): int
    {
        return $this->count;
    }
}
