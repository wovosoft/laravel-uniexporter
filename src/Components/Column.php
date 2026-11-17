<?php

namespace Wovosoft\LaravelUniexporter\Components;

use Closure;
use Illuminate\Support\Str;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Support\Data;

/**
 * A table column. The key is a dot path into each row (array, object or model).
 * Inline style props apply to the body cells of the column.
 */
class Column extends Component
{
    protected ?string $label = null;

    protected float $width = 1;

    protected ValueFormat $format;

    protected ?Closure $formatter = null;

    protected bool $total = false;

    protected ?Closure $totalFormatter = null;

    protected ?string $formula = null;

    protected bool $rowNumber = false;

    protected string $emptyValue = '';

    protected ?Style $headerStyle = null;

    protected ?float $characters = null;

    protected bool $fromSubRow = false;

    protected ?Closure $styler = null;

    public function __construct(protected string $key = '')
    {
        $this->format = ValueFormat::text();
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Relative width: a column with width 2 is twice as wide as one with width 1.
     */
    public function width(float $weight): static
    {
        $this->width = max($weight, 0.1);

        return $this;
    }

    /**
     * A fixed spreadsheet column width in characters, instead of a share of the page width.
     * Other formats keep using the weight.
     */
    public function characters(float $characters): static
    {
        $this->characters = $characters;

        return $this;
    }

    /**
     * Reads the value from the sub-row item instead of the row (see Table::subRows()).
     */
    public function fromSubRow(bool $fromSubRow = true): static
    {
        $this->fromSubRow = $fromSubRow;

        return $this;
    }

    /**
     * Conditional style per cell, e.g. a long tenure in red. Receives the value (after formatUsing())
     * and the row (the sub-row item for fromSubRow() columns); returns a style or null.
     *
     * @param Closure(mixed $value, mixed $row): ?Style $styler
     */
    public function styleUsing(Closure $styler): static
    {
        $this->styler = $styler;

        return $this;
    }

    public function cellStyleFor(mixed $value, mixed $row): ?Style
    {
        return $this->styler ? ($this->styler)($value, $row) : null;
    }

    public function isFromSubRow(): bool
    {
        return $this->fromSubRow;
    }

    public function getCharacters(): ?float
    {
        return $this->characters;
    }

    public function text(): static
    {
        $this->format = ValueFormat::text();

        return $this;
    }

    public function number(int $decimals = 0, bool $thousands = true): static
    {
        $this->format = ValueFormat::number($decimals, $thousands);

        return $this->right();
    }

    public function money(int $decimals = 2, string $prefix = ''): static
    {
        $this->format = ValueFormat::number($decimals, true, $prefix);

        return $this->right();
    }

    /**
     * @param string $format PHP date() format; converted to an Excel format for spreadsheets
     */
    public function date(string $format = 'd/m/Y'): static
    {
        $this->format = ValueFormat::date($format);

        return $this;
    }

    /**
     * Transforms the raw value before formatting. Keep it pure: it receives the value, the row
     * and the export data, and must not fetch anything.
     *
     * @param Closure(mixed $value, mixed $row, Data $data): mixed $formatter
     */
    public function formatUsing(Closure $formatter): static
    {
        $this->formatter = $formatter;

        return $this;
    }

    /**
     * Adds the column's sum to the table's totals row. A closure receives the sum and the data and
     * returns what the totals cell shows: a number (formatted like the column) or a text.
     *
     * @param bool|Closure(int|float $sum, Data $data): (int|float|string|null) $total
     */
    public function total(bool|Closure $total = true): static
    {
        $this->total = $total !== false;
        $this->totalFormatter = $total instanceof Closure ? $total : null;

        return $this;
    }

    /**
     * A formula for the column's cells in spreadsheets, e.g. '={days}*{rate}-{deduction}'. {key}
     * is the cell of the column with that key in the same row, {row} the row number. Other
     * formats show the column's value as usual.
     */
    public function formula(?string $formula): static
    {
        $this->formula = $formula;

        return $this;
    }

    /**
     * Shows the 1-based row number instead of a value from the row.
     */
    public function rowNumber(bool $rowNumber = true): static
    {
        $this->rowNumber = $rowNumber;
        $this->format = ValueFormat::number();

        return $this->center();
    }

    /**
     * Text shown when the value is null or empty.
     */
    public function emptyValue(string $value): static
    {
        $this->emptyValue = $value;

        return $this;
    }

    public function headerStyle(Style $style): static
    {
        $this->headerStyle = $style;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label ?? ($this->rowNumber && $this->key === '' ? '#' : Str::headline(str_replace('.', ' ', $this->key)));
    }

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getFormat(): ValueFormat
    {
        return $this->format;
    }

    public function hasTotal(): bool
    {
        return $this->total;
    }

    /**
     * What the totals cell shows for a sum: the total() closure's result, or the sum.
     */
    public function totalValue(int|float $sum, Data $data): int|float|string|null
    {
        return $this->totalFormatter ? ($this->totalFormatter)($sum, $data) : $sum;
    }

    public function getFormula(): ?string
    {
        return $this->formula;
    }

    public function isRowNumber(): bool
    {
        return $this->rowNumber;
    }

    public function getEmptyValue(): string
    {
        return $this->emptyValue;
    }

    public function getHeaderStyle(): ?Style
    {
        return $this->headerStyle;
    }

    /**
     * The value for a row after the formatter, before display formatting.
     */
    public function valueFor(mixed $row, int $index, Data $data): mixed
    {
        $value = $this->rowNumber ? $index + 1 : data_get($row, $this->key);

        return $this->formatter ? ($this->formatter)($value, $row, $data) : $value;
    }
}
