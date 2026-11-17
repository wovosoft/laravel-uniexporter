<?php

namespace Wovosoft\LaravelUniexporter;

use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Traits\Conditionable;
use Wovosoft\LaravelUniexporter\Components\Component;
use Wovosoft\LaravelUniexporter\Components\Concerns\BuildsContent;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Support\Data;

/**
 * A format-independent document: a schema of components plus settings and named styles.
 *
 * Extend it and override schema(), settings() and styles(), or build one inline, either with
 * components(), configure() and withStyles(), or with the chainable API: page settings
 * (->paper('A4')->landscape()), content (->heading()->table()->text(), see {@see BuildsContent})
 * and data (->withData()) in one chain. Export with exportToPdf(), exportToDocx(), exportToExcel(),
 * exportToCsv() or export($format), after data() or directly.
 *
 * Page settings called on the document are forwarded to {@see Settings}:
 *
 * @method $this paper(\Wovosoft\LaravelUniexporter\Style\Paper|string $paper)
 * @method $this orientation(\Wovosoft\LaravelUniexporter\Style\Orientation|string $orientation)
 * @method $this portrait()
 * @method $this landscape()
 * @method $this margins(\Wovosoft\LaravelUniexporter\Style\Margins|float ...$margins)
 * @method $this font(string $family, ?float $size = null)
 * @method $this fontSize(float $points)
 * @method $this color(string $color)
 * @method $this title(string $title)
 * @method $this author(string $author)
 * @method $this subject(string $subject)
 * @method $this keywords(string $keywords)
 * @method $this header(string $left = '', string $center = '', string $right = '', ?Style $style = null)
 * @method $this footer(string $left = '', string $center = '', string $right = '', ?Style $style = null)
 * @method $this firstPageHeader(string $left = '', string $center = '', string $right = '', ?Style $style = null)
 * @method $this firstPageFooter(string $left = '', string $center = '', string $right = '', ?Style $style = null)
 * @method $this headerContent(array|Closure $components)
 * @method $this footerContent(array|Closure $components)
 * @method $this watermark(string $imagePath, float $alpha = 0.2, ?float $width = null, ?float $height = null)
 * @method $this fitToWidth(bool $fit = true)
 * @method $this withoutHeader()
 * @method $this withoutFooter()
 * @method $this filename(string $filename)
 * @method $this native(string $format, array $options)
 * @method $this on(string|array $formats, Closure $adjust)
 */
class Document
{
    use BuildsContent;
    use Conditionable;

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

    /**
     * Data attached with withData()/with(), merged under the data given to data().
     *
     * @var array<string, mixed>
     */
    protected array $attachedData = [];

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
     * Appends components to the schema (used by the chainable content API).
     */
    public function add(Component ...$components): static
    {
        array_push($this->components, ...$components);

        return $this;
    }

    /**
     * Attaches data to the document, so it exports without data(); later calls merge over earlier ones.
     *
     * @param array<string, mixed>|Arrayable<string, mixed>|Data $data
     */
    public function withData(array|Arrayable|Data $data): static
    {
        $this->attachedData = array_replace($this->attachedData, Data::from($data)->all());

        return $this;
    }

    /**
     * Attaches one value to the document's data: ->with('office', $office->name).
     */
    public function with(string $key, mixed $value): static
    {
        $this->attachedData[$key] = $value;

        return $this;
    }

    /**
     * Page settings called on the document (paper(), landscape(), font(), footer()...) are forwarded
     * to {@see Settings} through configure().
     *
     * @param array<int|string, mixed> $arguments
     */
    public function __call(string $method, array $arguments): static
    {
        if (! self::isSettingsMethod($method)) {
            throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }

        return $this->configure(fn (Settings $settings) => $settings->{$method}(...$arguments));
    }

    private static function isSettingsMethod(string $method): bool
    {
        return method_exists(Settings::class, $method)
            && ! str_starts_with($method, 'get')
            && ! str_starts_with($method, 'should')
            && ! in_array($method, ['__construct', 'forFormat', 'isLandscape', 'contentWidth'], true);
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
     * Pairs the document with its data (merged over data attached with withData()/with()).
     *
     * @param array<string, mixed>|Arrayable<string, mixed>|Data $data
     */
    public function data(array|Arrayable|Data $data = []): PendingExport
    {
        return new PendingExport($this, new Data(array_replace($this->attachedData, Data::from($data)->all())));
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

    public function exportToCsv(): ExportedFile
    {
        return $this->data([])->exportToCsv();
    }

    public function export(string $format): ExportedFile
    {
        return $this->data([])->export($format);
    }
}
