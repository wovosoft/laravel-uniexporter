<?php

namespace Wovosoft\LaravelUniexporter\Components;

use Closure;
use Wovosoft\LaravelUniexporter\Style\Border;
use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * A data table. Rows come from a data path (read once, so lazy collections and cursors keep
 * memory flat) or from a literal iterable.
 *
 * Table::make('employees')->columns([Column::make('name'), Column::make('salary')->money()->total()])
 */
class Table extends Component
{
    /**
     * @var list<Column>
     */
    protected array $columns = [];

    /**
     * @var list<list<HeaderCell|string>>
     */
    protected array $headerRows = [];

    protected bool $showHeader = true;

    protected bool $repeatHeader = true;

    protected ?string $stripeColor = null;

    protected ?Border $cellBorder;

    protected Style $headerStyle;

    protected Style $totalStyle;

    protected string $totalLabel = 'Total';

    protected int $totalLabelColspan = 1;

    protected bool $totalFormulas = false;

    protected string $emptyText = '';

    protected ?Closure $rowStyler = null;

    protected bool $autoWidths = false;

    protected string|Closure|null $subRows = null;

    protected bool $freezeHeader = false;

    /**
     * @param string|iterable<mixed>|null $rows a data path or the rows themselves
     */
    public function __construct(protected string|iterable|null $rows = null)
    {
        $this->cellBorder = new Border(0.5, 'A6A6A6');
        $this->headerStyle = Style::make()->bold()->background('E7E6E6')->verticalAlign('middle');
        $this->totalStyle = Style::make()->bold()->background('F2F2F2');
    }

    /**
     * @param string|iterable<mixed> $rows a data path or the rows themselves
     */
    public function rows(string|iterable $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    /**
     * @param list<Column> $columns
     */
    public function columns(array $columns): static
    {
        $this->columns = array_values($columns);

        return $this;
    }

    /**
     * Appends a column: ->column('salary', 'Salary', fn (Column $column) => $column->money()->total()).
     * Without a label the header is the headline of the key; an empty key makes a computed column.
     */
    public function column(string $key = '', ?string $label = null, ?Closure $tap = null): static
    {
        $column = Column::make($key);

        if ($label !== null) {
            $column->label($label);
        }

        if ($tap !== null) {
            $result = $tap($column);
            $column = $result instanceof Column ? $result : $column;
        }

        $this->columns[] = $column;

        return $this;
    }

    /**
     * Appends a header row above the column labels (see headerRows()).
     *
     * @param list<HeaderCell|string> $cells
     */
    public function headerRow(array $cells): static
    {
        $this->headerRows[] = $cells;

        return $this;
    }

    /**
     * Extra header rows above the column labels; cells can span columns.
     *
     * @param list<list<HeaderCell|string>> $rows
     */
    public function headerRows(array $rows): static
    {
        $this->headerRows = $rows;

        return $this;
    }

    public function showHeader(bool $show = true): static
    {
        $this->showHeader = $show;

        return $this;
    }

    /**
     * Repeats the header rows on every page (paged formats) or as print titles (spreadsheets).
     */
    public function repeatHeader(bool $repeat = true): static
    {
        $this->repeatHeader = $repeat;

        return $this;
    }

    public function striped(bool $striped = true, string $color = 'F7F7F7'): static
    {
        $this->stripeColor = $striped ? $color : null;

        return $this;
    }

    /**
     * Cell borders; pass false for a borderless table.
     */
    public function borders(Border|bool $border = true): static
    {
        $this->cellBorder = match (true) {
            $border instanceof Border => $border,
            $border                   => new Border(0.5, 'A6A6A6'),
            default                   => null,
        };

        return $this;
    }

    public function headerStyle(Style|Closure $style): static
    {
        $this->headerStyle = $style instanceof Closure ? $style($this->headerStyle) : $this->headerStyle->merge($style);

        return $this;
    }

    public function totalStyle(Style|Closure $style): static
    {
        $this->totalStyle = $style instanceof Closure ? $style($this->totalStyle) : $this->totalStyle->merge($style);

        return $this;
    }

    /**
     * The totals row label, in the first column; $colspan widens it over the next columns (up to the
     * first total).
     */
    public function totalLabel(string $label, int $colspan = 1): static
    {
        $this->totalLabel = $label;
        $this->totalLabelColspan = max(1, $colspan);

        return $this;
    }

    /**
     * Spreadsheets write numeric totals as SUM formulas over the body rows instead of values.
     */
    public function totalFormulas(bool $formulas = true): static
    {
        $this->totalFormulas = $formulas;

        return $this;
    }

    /**
     * Text shown in one full-width row when there are no rows.
     */
    public function emptyText(string $text): static
    {
        $this->emptyText = $text;

        return $this;
    }

    /**
     * Conditional row styling. Keep it pure: it receives the row and its 0-based index.
     *
     * @param Closure(mixed $row, int $index): ?Style $styler
     */
    public function rowStyleUsing(Closure $styler): static
    {
        $this->rowStyler = $styler;

        return $this;
    }

    /**
     * Applies the table's and its columns' adjustments for `$format`, and drops columns hidden in it.
     * Header rows (headerRows()) are not adjusted, so their colspans should account for hidden columns.
     */
    public function forFormat(string $format): static
    {
        $table = parent::forFormat($format);
        $columns = [];

        foreach ($table->columns as $column) {
            $column = $column->forFormat($format);

            if ($column->isVisibleFor($format)) {
                $columns[] = $column;
            }
        }

        if ($columns === $table->columns) {
            return $table;
        }

        $table = $table === $this ? clone $table : $table;
        $table->columns = $columns;

        return $table;
    }

    /**
     * Splits each row into sub-rows, one per item of `$source` (a dot path into the row, or a closure
     * receiving the row and its index). Columns marked fromSubRow() read the sub-row item; the other
     * columns are written once and span the row's sub-rows. A row without items gets one sub-row.
     *
     * @param string|Closure(mixed $row, int $index): iterable<mixed> $source
     */
    public function subRows(string|Closure $source): static
    {
        $this->subRows = $source;

        return $this;
    }

    public function getSubRows(): string|Closure|null
    {
        return $this->subRows;
    }

    /**
     * Lets PDF and HTML size the columns from their content instead of the column weights.
     * Spreadsheets and DOCX keep using the weights (or Column::characters()).
     */
    public function autoWidths(bool $auto = true): static
    {
        $this->autoWidths = $auto;

        return $this;
    }

    /**
     * Keeps the header rows visible while scrolling a spreadsheet (the first frozen table on a sheet wins).
     */
    public function freezeHeader(bool $freeze = true): static
    {
        $this->freezeHeader = $freeze;

        return $this;
    }

    public function hasAutoWidths(): bool
    {
        return $this->autoWidths;
    }

    public function shouldFreezeHeader(): bool
    {
        return $this->freezeHeader;
    }

    public function getRows(): string|iterable|null
    {
        return $this->rows;
    }

    /**
     * @return list<Column>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<list<HeaderCell>>
     */
    public function getHeaderRows(): array
    {
        return array_map(
            fn (array $row): array => array_map(fn (HeaderCell|string $cell): HeaderCell => is_string($cell) ? new HeaderCell($cell) : $cell, $row),
            $this->headerRows,
        );
    }

    public function shouldShowHeader(): bool
    {
        return $this->showHeader;
    }

    public function shouldRepeatHeader(): bool
    {
        return $this->repeatHeader;
    }

    public function getStripeColor(): ?string
    {
        return $this->stripeColor;
    }

    public function getCellBorder(): ?Border
    {
        return $this->cellBorder;
    }

    public function getHeaderStyle(): Style
    {
        return $this->headerStyle;
    }

    public function getTotalStyle(): Style
    {
        return $this->totalStyle;
    }

    public function getTotalLabel(): string
    {
        return $this->totalLabel;
    }

    public function getTotalLabelColspan(): int
    {
        return $this->totalLabelColspan;
    }

    public function hasTotalFormulas(): bool
    {
        return $this->totalFormulas;
    }

    public function getEmptyText(): string
    {
        return $this->emptyText;
    }

    public function getRowStyler(): ?Closure
    {
        return $this->rowStyler;
    }

    public function hasTotals(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->hasTotal()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Column widths as percentages that add up to 100.
     *
     * @return list<float>
     */
    public function getWidthPercentages(): array
    {
        $total = array_sum(array_map(fn (Column $column): float => $column->getWidth(), $this->columns)) ?: 1;

        return array_map(fn (Column $column): float => $column->getWidth() / $total * 100, $this->columns);
    }

    public function defaultStyle(): Style
    {
        return Style::make()->padding(1)->spaceAfter(3);
    }
}
