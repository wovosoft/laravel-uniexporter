<?php

namespace Wovosoft\LaravelUniexporter\Components;

use Closure;
use Wovosoft\LaravelUniexporter\Components\Concerns\HasStyle;
use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * Base for every schema element. Components only describe structure and presentation;
 * they never fetch data. Text props are templates whose `{path}` placeholders are filled
 * from the export data.
 */
abstract class Component
{
    use HasStyle;

    /**
     * @var list<string>|null formats the component renders in; null = all
     */
    protected ?array $visibleOn = null;

    /**
     * @var list<string>
     */
    protected array $hiddenOn = [];

    /**
     * @var array<string, list<Closure>> format => callbacks receiving the driver's native objects
     */
    protected array $nativeCallbacks = [];

    /**
     * @var array<string, list<Closure(static): (static|null)>> format => adjustments applied when exporting to it
     */
    protected array $formatAdjustments = [];

    public static function make(mixed ...$arguments): static
    {
        return new static(...$arguments);
    }

    /**
     * Renders the component only for the given formats (e.g. 'pdf', 'docx').
     */
    public function visibleOn(string ...$formats): static
    {
        $this->visibleOn = $formats;

        return $this;
    }

    public function hiddenOn(string ...$formats): static
    {
        $this->hiddenOn = [...$this->hiddenOn, ...$formats];

        return $this;
    }

    public function isVisibleFor(string $format): bool
    {
        if (in_array($format, $this->hiddenOn, true)) {
            return false;
        }

        return $this->visibleOn === null || in_array($format, $this->visibleOn, true);
    }

    /**
     * Adjusts the component for some formats only, on a copy, e.g. a table without its header row in
     * spreadsheets: ->on('xlsx', fn (Table $table) => $table->showHeader(false)).
     *
     * @param string|list<string> $formats
     * @param Closure(static): (static|null) $adjust
     */
    public function on(string|array $formats, Closure $adjust): static
    {
        foreach ((array) $formats as $format) {
            $this->formatAdjustments[$format][] = $adjust;
        }

        return $this;
    }

    /**
     * The component as rendered in `$format`: itself, or an adjusted copy when on() targets the format.
     */
    public function forFormat(string $format): static
    {
        if (empty($this->formatAdjustments[$format])) {
            return $this;
        }

        $component = clone $this;
        $component->formatAdjustments = [];

        foreach ($this->formatAdjustments[$format] as $adjust) {
            $component = $adjust($component) ?? $component;
        }

        return $component;
    }

    /**
     * Escape hatch: after rendering this component, the driver for `$format` calls the callback
     * with the native object(s) it produced. See each driver for the arguments it passes.
     */
    public function native(string $format, Closure $callback): static
    {
        $this->nativeCallbacks[$format][] = $callback;

        return $this;
    }

    /**
     * @return list<Closure>
     */
    public function getNativeCallbacks(string $format): array
    {
        return $this->nativeCallbacks[$format] ?? [];
    }

    /**
     * Style applied before the named and inline styles (e.g. heading sizes).
     */
    public function defaultStyle(): ?Style
    {
        return null;
    }

    /**
     * Nested components, for containers.
     *
     * @return list<Component>
     */
    public function getChildren(): array
    {
        return [];
    }
}
