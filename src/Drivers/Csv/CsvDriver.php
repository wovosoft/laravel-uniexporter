<?php

namespace Wovosoft\LaravelUniexporter\Drivers\Csv;

use DateTimeInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;
use Wovosoft\LaravelUniexporter\Components\Component;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Drivers\AbstractDriver;
use Wovosoft\LaravelUniexporter\Drivers\RenderContext;
use Wovosoft\LaravelUniexporter\Support\TableRows;

/**
 * CSV through OpenSpout: the data of the document's tables, one after another. Each table writes
 * its column labels, its rows and its totals; everything else (headings, text, page setup) is left
 * out, as CSV holds data only. Numbers stay numbers, dates and other values are written as their
 * display text, and text that a spreadsheet would read as a formula is prefixed with an apostrophe.
 *
 * Options (config drivers.csv or Settings::native('csv', [...])): 'options' are named arguments for
 * OpenSpout\Writer\CSV\Options (e.g. 'FIELD_DELIMITER' => ';').
 *
 * Hooks: beforeRender/afterRender('csv', fn (Writer $writer, RenderContext $context)).
 */
class CsvDriver extends AbstractDriver
{
    private Writer $writer;

    private bool $hasRows = false;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private readonly array $options = []) {}

    public function extension(): string
    {
        return 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv';
    }

    public function render(RenderContext $context, string $path): void
    {
        $this->ensureInstalled(Writer::class, 'openspout/openspout');

        $this->writer = new Writer(new Options(...(array) ($this->options['options'] ?? [])));
        $this->writer->openToFile($path);

        try {
            $this->runCallbacks($context->document->getBeforeRender('csv'), $this->writer, $context);

            foreach ($context->document->schema() as $component) {
                $this->renderComponent($component, $context);
            }

            $this->runCallbacks($context->document->getAfterRender('csv'), $this->writer, $context);
        } finally {
            $this->writer->close();
        }
    }

    /**
     * Containers (groups, columns) contribute the tables inside them.
     */
    protected function renderComponent(Component $component, RenderContext $context, mixed ...$arguments): void
    {
        $component = $component->forFormat($context->format);

        if (! $component->isVisibleFor($context->format)) {
            return;
        }

        if ($component instanceof Table) {
            $this->renderTable($component, $context);

            return;
        }

        foreach ($component->getChildren() as $child) {
            $this->renderComponent($child, $context);
        }
    }

    protected function renderTable(Table $table, RenderContext $context): void
    {
        $columns = $table->getColumns();

        // A blank line separates tables.
        if ($this->hasRows) {
            $this->writer->addRow(Row::fromValues([]));
        }
        $this->hasRows = true;

        if ($table->shouldShowHeader()) {
            $this->writer->addRow(Row::fromValues(array_map(fn ($column): string => $context->text($column->getLabel()), $columns)));
        }

        $rows = new TableRows($table, $context->data);

        foreach ($rows->rows() as $row) {
            $values = [];
            foreach ($columns as $position => $column) {
                $values[] = $this->value($row->values[$position], $row->texts[$position], $column->getFormat()->toSpreadsheetValue($row->values[$position]));
            }
            $this->writer->addRow(Row::fromValues($values));
        }

        if ($table->hasTotals()) {
            $values = [];
            foreach ($rows->totalCells() as $cell) {
                $values[] = match (true) {
                    $cell === null  => '',
                    $cell['label']  => $context->text($table->getTotalLabel()),
                    default         => $this->value($cell['value'], $cell['text'], $cell['value']),
                };
            }
            $this->writer->addRow(Row::fromValues($values));
        }
    }

    private function value(mixed $value, string $text, mixed $typed): int|float|string
    {
        if (is_int($typed) || is_float($typed)) {
            return $typed;
        }

        $text = $typed instanceof DateTimeInterface || $typed === null ? $text : (string) $typed;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }
}
