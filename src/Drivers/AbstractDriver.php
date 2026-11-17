<?php

namespace Wovosoft\LaravelUniexporter\Drivers;

use Closure;
use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\Component;
use Wovosoft\LaravelUniexporter\Components\HeaderCell;
use Wovosoft\LaravelUniexporter\Components\ListBlock;
use Wovosoft\LaravelUniexporter\Components\Run;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Style\Align;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Exceptions\MissingDependencyException;
use Wovosoft\LaravelUniexporter\Exceptions\UnsupportedComponentException;

/**
 * Base driver: sends each component to a `render<ComponentName>()` method (falling back to
 * parent component classes), runs custom renderers, and skips what it cannot render.
 */
abstract class AbstractDriver implements Driver
{
    /**
     * Custom renderers registered at boot time: driver class => component class => renderer.
     *
     * @var array<class-string, array<class-string, Closure>>
     */
    private static array $customRenderers = [];

    /**
     * Teaches this driver to render a component class (e.g. your own components). The renderer
     * receives the component, the render context, the driver and the driver's extra arguments.
     *
     * @param class-string<Component> $component
     */
    public static function renderUsing(string $component, Closure $renderer): void
    {
        self::$customRenderers[static::class][$component] = $renderer;
    }

    public static function flushCustomRenderers(): void
    {
        unset(self::$customRenderers[static::class]);
    }

    protected function renderComponent(Component $component, RenderContext $context, mixed ...$arguments): void
    {
        $component = $component->forFormat($context->format);

        if (! $component->isVisibleFor($context->format)) {
            return;
        }

        for ($class = $component::class; $class !== false && $class !== Component::class; $class = get_parent_class($class)) {
            if (isset(self::$customRenderers[static::class][$class])) {
                self::$customRenderers[static::class][$class]($component, $context, $this, ...$arguments);

                return;
            }

            $method = 'render'.substr(strrchr('\\'.$class, '\\'), 1);

            if (method_exists($this, $method)) {
                $this->{$method}($component, $context, ...$arguments);

                return;
            }
        }

        if ($context->strict) {
            throw UnsupportedComponentException::for($component, $context->format);
        }
    }

    /**
     * @param list<Closure> $callbacks
     */
    protected function runCallbacks(array $callbacks, mixed ...$arguments): void
    {
        foreach ($callbacks as $callback) {
            $callback(...$arguments);
        }
    }

    /**
     * Style of a header cell: the table header style, centered, then the cell's named and inline styles.
     */
    protected function headerCellStyle(HeaderCell $cell, Style $headerStyle, RenderContext $context): Style
    {
        return $headerStyle
            ->merge(Style::make()->align(Align::Center))
            ->merge($cell->getStyleName() !== null ? $context->namedStyle($cell->getStyleName()) : null)
            ->merge($cell->getInlineStyle());
    }

    /**
     * Style of a column's header cell: aligned like the column unless the table's header style sets an
     * alignment (e.g. every label centered), then the column's own header style.
     */
    protected function columnHeaderStyle(Style $tableStyle, Table $table, Column $column, ?Style $base = null): Style
    {
        return $tableStyle
            ->merge(Style::make()->align($column->getInlineStyle()?->align ?? Align::Left))
            ->merge($base)
            ->merge($table->getHeaderStyle())
            ->merge($column->getHeaderStyle());
    }

    /**
     * Style of a totals row cell: aligned like its column, except the label when the table's total
     * style sets an alignment.
     */
    protected function totalCellStyle(Style $totalStyle, Table $table, Column $column, bool $isLabel): Style
    {
        $align = $isLabel && $table->getTotalStyle()->align !== null
            ? $table->getTotalStyle()->align
            : ($column->getInlineStyle()?->align ?? Align::Left);

        return $totalStyle->merge(Style::make()->align($align));
    }

    /**
     * Style of a run relative to its paragraph: only its own named and inline props.
     */
    protected function runStyle(Run $run, RenderContext $context): Style
    {
        $named = $run->getStyleName() !== null ? $context->namedStyle($run->getStyleName()) : null;

        return ($named ?? Style::make())->merge($run->getInlineStyle());
    }

    /**
     * Static items followed by items read from the data source.
     *
     * @return list<string|array{text: string, items?: list<mixed>}>
     */
    protected function listItems(ListBlock $list, RenderContext $context): array
    {
        $fromData = [];

        foreach ($context->data->iterable($list->getSource()) as $item) {
            $fromData[] = is_array($item) ? $item : (string) $item;
        }

        return [...$list->getItems(), ...$fromData];
    }

    /**
     * Fills a missing dimension from the image's aspect ratio (defaults to its size at 96 dpi).
     *
     * @return array{0: float, 1: float} width and height in millimetres
     */
    protected function imageSize(string $path, ?float $width, ?float $height): array
    {
        [$pixelWidth, $pixelHeight] = getimagesize($path) ?: [100, 100];
        $ratio = $pixelHeight > 0 ? $pixelWidth / $pixelHeight : 1;

        return match (true) {
            $width !== null && $height !== null => [$width, $height],
            $width !== null                     => [$width, $width / $ratio],
            $height !== null                    => [$height * $ratio, $height],
            default                             => [$pixelWidth * 25.4 / 96, $pixelHeight * 25.4 / 96],
        };
    }

    protected function ensureInstalled(string $class, string $package): void
    {
        if (! class_exists($class)) {
            throw MissingDependencyException::package($package, $this->extension());
        }
    }
}
