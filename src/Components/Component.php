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
