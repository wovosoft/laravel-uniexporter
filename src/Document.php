<?php

namespace Wovosoft\LaravelUniexporter;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Wovosoft\LaravelUniexporter\Components\Component;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Support\Data;

/**
 * A format-independent document: a schema of components plus settings and named styles.
 *
 * Extend it and override schema(), settings() and styles(), or build one inline with
 * components(), configure() and withStyles(). The schema never fetches data: pass the data
 * with data() and export with exportToPdf(), exportToDocx(), exportToExcel() or export($format).
 */
class Document
{
    /**
     * @var list<Component>
     */
    protected array $components = [];

    /**
     * @var list<Closure(Settings): (Settings|null)>
     */
    protected array $configurators = [];

    /**
     * @var array<string, Style>
     */
    protected array $namedStyles = [];

    /**
     * @var array<string, list<Closure>>
     */
    protected array $beforeRender = [];

    /**
     * @var array<string, list<Closure>>
     */
    protected array $afterRender = [];

    public static function make(): static
    {
        return new static();
    }

    /**
     * The document's components. Override in subclasses; inline documents use components().
     *
     * @return list<Component>
     */
    public function schema(): array
    {
        return $this->components;
    }

    /**
     * Page setup, defaults and metadata. Override in subclasses; inline documents use configure().
     */
    public function settings(Settings $settings): Settings
    {
        return $settings;
    }

    /**
     * Named styles that components reference with ->style('name'). Override in subclasses.
     *
     * @return array<string, Style>
     */
    public function styles(): array
    {
        return $this->namedStyles;
    }

    /**
     * @param list<Component> $components
     */
    public function components(array $components): static
    {
        $this->components = array_values($components);

        return $this;
    }

    /**
     * @param Closure(Settings): (Settings|null) $configurator
     */
    public function configure(Closure $configurator): static
    {
        $this->configurators[] = $configurator;

        return $this;
    }

    /**
     * @param array<string, Style> $styles
     */
    public function withStyles(array $styles): static
    {
        $this->namedStyles = array_replace($this->namedStyles, $styles);

        return $this;
    }

    /**
     * Runs before any content is rendered, with the driver's native document object
     * (pdf: Mpdf, docx: PhpWord, xlsx: OpenSpout Writer) and the render context.
     */
    public function beforeRender(string $format, Closure $callback): static
    {
        $this->beforeRender[$format][] = $callback;

        return $this;
    }

    /**
     * Runs after all content is rendered, before the file is written.
     */
    public function afterRender(string $format, Closure $callback): static
    {
        $this->afterRender[$format][] = $callback;

        return $this;
    }

    /**
     * @return list<Closure>
     */
    public function getBeforeRender(string $format): array
    {
        return $this->beforeRender[$format] ?? [];
    }

    /**
     * @return list<Closure>
     */
    public function getAfterRender(string $format): array
    {
        return $this->afterRender[$format] ?? [];
    }

    /**
     * The document's settings after configure() callbacks, with the adjustments for `$format` when given.
     */
    public function resolveSettings(?string $format = null): Settings
    {
        $settings = $this->settings(new Settings());

        foreach ($this->configurators as $configurator) {
            $settings = $configurator($settings) ?? $settings;
        }

        return $format === null ? $settings : $settings->forFormat($format);
    }

    /**
     * @param array<string, mixed>|Arrayable<string, mixed>|Data $data
     */
    public function data(array|Arrayable|Data $data): PendingExport
    {
        return new PendingExport($this, Data::from($data));
    }

    public function exportToPdf(): ExportedFile
    {
        return $this->data([])->exportToPdf();
    }

    public function exportToDocx(): ExportedFile
    {
        return $this->data([])->exportToDocx();
    }

    public function exportToExcel(): ExportedFile
    {
        return $this->data([])->exportToExcel();
    }

    public function export(string $format): ExportedFile
    {
        return $this->data([])->export($format);
    }
}
