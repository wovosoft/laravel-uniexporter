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
     * @return Generator<int, TableRow>
     */
    public function rows(): Generator
    {
        $columns = $this->table->getColumns();
        $styler = $this->table->getRowStyler();
        $index = 0;

        foreach ($this->data->iterable($this->table->getRows()) as $source) {
            $values = [];
            $texts = [];

            foreach ($columns as $position => $column) {
                $value = $column->valueFor($source, $index, $this->data);
                $text = $column->getFormat()->toText($value);

                $values[] = $value;
                $texts[] = $text === '' ? $column->getEmptyValue() : $text;

                if ($column->hasTotal() && is_numeric($value)) {
                    $this->totals[$position] = ($this->totals[$position] ?? 0) + $value;
                }
            }

            yield new TableRow($index, $source, $values, $texts, $styler ? $styler($source, $index) : null);

            $index++;
        }

        $this->count = $index;
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
