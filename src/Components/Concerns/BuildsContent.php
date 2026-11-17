<?php

namespace Wovosoft\LaravelUniexporter\Components\Concerns;

use Closure;
use Wovosoft\LaravelUniexporter\Components\Columns;
use Wovosoft\LaravelUniexporter\Components\Component;
use Wovosoft\LaravelUniexporter\Components\Divider;
use Wovosoft\LaravelUniexporter\Components\Group;
use Wovosoft\LaravelUniexporter\Components\Heading;
use Wovosoft\LaravelUniexporter\Components\Image;
use Wovosoft\LaravelUniexporter\Components\KeyValue;
use Wovosoft\LaravelUniexporter\Components\Link;
use Wovosoft\LaravelUniexporter\Components\ListBlock;
use Wovosoft\LaravelUniexporter\Components\Native;
use Wovosoft\LaravelUniexporter\Components\PageBreak;
use Wovosoft\LaravelUniexporter\Components\Sheet;
use Wovosoft\LaravelUniexporter\Components\Spacer;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Components\Text;

/**
 * The chainable content API shared by documents and groups: each method appends a component and
 * returns the container, so a document reads top to bottom:
 *
 *     Document::make()
 *         ->heading('Employees of {office}')
 *         ->table('employees', fn (Table $table) => $table
 *             ->column('job_id', 'PF No.', fn (Column $column) => $column->text())
 *             ->column('name', 'Name'))
 *         ->text('Printed on {date}', fn (Text $text) => $text->right());
 *
 * The optional closure receives the new component to configure it; containers (group(),
 * columns()) receive a builder to add their contents.
 */
trait BuildsContent
{
    /**
     * Appends the component(s) to this container.
     */
    abstract public function add(Component ...$components): static;

    public function heading(string $text, int $level = 1, ?Closure $tap = null): static
    {
        return $this->addTapped(Heading::make($text, $level), $tap);
    }

    /**
     * A paragraph. Pass a closure instead of the text to build it from runs:
     * ->text(fn (Text $text) => $text->run('Note: ', fn (Run $run) => $run->bold())->run('see the policy')).
     */
    public function text(string|Closure $text = '', ?Closure $tap = null): static
    {
        return $text instanceof Closure
            ? $this->addTapped(Text::make(), $text)
            : $this->addTapped(Text::make($text), $tap);
    }

    public function link(string $text, string $url, ?Closure $tap = null): static
    {
        return $this->addTapped(Link::make($text, $url), $tap);
    }

    /**
     * An image from a path, a `{placeholder}` or raw bytes.
     */
    public function image(string $source, ?Closure $tap = null): static
    {
        return $this->addTapped(Image::make($source), $tap);
    }

    /**
     * A bulleted list (->ordered() for numbers); items may also come from data with ->source('path').
     *
     * @param list<string|array{text: string, items?: list<mixed>}> $items
     */
    public function list(array $items = [], ?Closure $tap = null): static
    {
        return $this->addTapped(ListBlock::make($items), $tap);
    }

    /**
     * Label/value pairs, e.g. ['Month' => '{month}'], or added with ->item($label, $value).
     *
     * @param array<string, string> $items
     */
    public function keyValue(array $items = [], ?Closure $tap = null): static
    {
        return $this->addTapped(KeyValue::make($items), $tap);
    }

    /**
     * A table of a data path or the rows themselves; the closure adds its columns:
     * ->table('rows', fn (Table $table) => $table->column('name', 'Name')->column('salary', 'Salary', fn (Column $c) => $c->money()->total())).
     */
    public function table(string|iterable $rows, ?Closure $build = null): static
    {
        return $this->addTapped(Table::make($rows), $build);
    }

    /**
     * Blocks side by side; the closure adds them with ->block():
     * ->columns(fn (Columns $columns) => $columns->block(fn (Group $block) => $block->text('Prepared by'))->block(...)->widths([2, 1])).
     */
    public function columns(Closure $build): static
    {
        return $this->addTapped(Columns::make(), $build);
    }

    /**
     * A container whose text styles its children inherit; the closure fills it with the same API:
     * ->group(fn (Group $group) => $group->heading('Signatures', 2)->text('...')->keepTogether()).
     */
    public function group(Closure $build): static
    {
        return $this->addTapped(Group::make(), $build);
    }

    /**
     * Vertical space in millimetres.
     */
    public function spacer(float $height = 5): static
    {
        return $this->add(Spacer::make($height));
    }

    public function divider(?Closure $tap = null): static
    {
        return $this->addTapped(Divider::make(), $tap);
    }

    public function pageBreak(): static
    {
        return $this->add(PageBreak::make());
    }

    /**
     * A new worksheet in spreadsheets, a new page elsewhere.
     */
    public function sheet(string $name = ''): static
    {
        return $this->add(Sheet::make($name));
    }

    /**
     * Content written with a driver's own library: ->nativeContent(fn (Native $native) => $native->for('pdf', fn (Mpdf $mpdf) => ...)).
     */
    public function nativeContent(Closure $build): static
    {
        return $this->addTapped(Native::make(), $build);
    }

    /**
     * Calls the closure for each item, with this container, the item and its key, e.g. a table per office:
     * ->each($offices, fn (Document $document, Office $office) => $document->heading($office->name)->table($office->employees, ...)).
     *
     * @param iterable<mixed> $items
     */
    public function each(iterable $items, Closure $callback): static
    {
        foreach ($items as $key => $item) {
            $callback($this, $item, $key);
        }

        return $this;
    }

    /**
     * Adds the component after the closure configured it; a closure that returns a component replaces it.
     */
    protected function addTapped(Component $component, ?Closure $tap): static
    {
        if ($tap !== null) {
            $result = $tap($component);

            if ($result instanceof Component) {
                $component = $result;
            }
        }

        return $this->add($component);
    }
}
