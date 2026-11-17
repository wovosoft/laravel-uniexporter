<?php

namespace Wovosoft\LaravelUniexporter;

use Closure;
use Wovosoft\LaravelUniexporter\Components\Component;
use Wovosoft\LaravelUniexporter\Components\Group;
use Wovosoft\LaravelUniexporter\Style\Margins;
use Wovosoft\LaravelUniexporter\Style\Orientation;
use Wovosoft\LaravelUniexporter\Style\Paper;
use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * Document-wide settings: page setup, default text style, metadata, headers/footers and
 * per-format native options.
 */
final class Settings
{
    private Paper $paper = Paper::A4;

    private Orientation $orientation = Orientation::Portrait;

    private Margins $margins;

    private Style $defaultStyle;

    private string $title = '';

    private string $author = '';

    private string $subject = '';

    private string $keywords = '';

    private ?HeaderFooter $header = null;

    private ?HeaderFooter $footer = null;

    private ?HeaderFooter $firstPageHeader = null;

    private ?HeaderFooter $firstPageFooter = null;

    private string $filename = 'document';

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $native = [];

    /**
     * @var array<string, list<Closure(self): (self|null)>> format => adjustments applied when exporting to it
     */
    private array $formatAdjustments = [];

    /**
     * @var list<Component> components rendered as the page header, on every page
     */
    private array $headerContent = [];

    /**
     * @var list<Component> components rendered as the page footer, on every page
     */
    private array $footerContent = [];

    /**
     * @var array{path: string, alpha: float, width: float|null, height: float|null}|null
     */
    private ?array $watermark = null;

    private bool $fitToWidth = false;

    public function __construct()
    {
        $this->margins = new Margins();
        $this->defaultStyle = Style::make()->size(10)->color('000000');
    }

    public function paper(Paper|string $paper): self
    {
        $this->paper = is_string($paper) ? Paper::from($paper) : $paper;

        return $this;
    }

    public function orientation(Orientation|string $orientation): self
    {
        $this->orientation = is_string($orientation) ? Orientation::from($orientation) : $orientation;

        return $this;
    }

    public function portrait(): self
    {
        return $this->orientation(Orientation::Portrait);
    }

    public function landscape(): self
    {
        return $this->orientation(Orientation::Landscape);
    }

    /**
     * A Margins object, or 1/2/4 values in millimetres (CSS shorthand order).
     */
    public function margins(Margins|float ...$margins): self
    {
        $this->margins = $margins[0] instanceof Margins ? $margins[0] : Margins::of(...$margins);

        return $this;
    }

    /**
     * Default font for the whole document. Use the family name as installed (e.g. 'Nikosh');
     * the PDF driver maps it to its lowercase font key.
     */
    public function font(string $family, ?float $size = null): self
    {
        $this->defaultStyle = $this->defaultStyle->font($family, $size);

        return $this;
    }

    public function fontSize(float $points): self
    {
        $this->defaultStyle = $this->defaultStyle->size($points);

        return $this;
    }

    public function color(string $color): self
    {
        $this->defaultStyle = $this->defaultStyle->color($color);

        return $this;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function author(string $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function keywords(string $keywords): self
    {
        $this->keywords = $keywords;

        return $this;
    }

    public function header(string $left = '', string $center = '', string $right = '', ?Style $style = null): self
    {
        $this->header = new HeaderFooter($left, $center, $right, $style);

        return $this;
    }

    public function footer(string $left = '', string $center = '', string $right = '', ?Style $style = null): self
    {
        $this->footer = new HeaderFooter($left, $center, $right, $style);

        return $this;
    }

    /**
     * Header for the first page only; call without arguments to leave the first page blank.
     */
    public function firstPageHeader(string $left = '', string $center = '', string $right = '', ?Style $style = null): self
    {
        $this->firstPageHeader = new HeaderFooter($left, $center, $right, $style);

        return $this;
    }

    public function firstPageFooter(string $left = '', string $center = '', string $right = '', ?Style $style = null): self
    {
        $this->firstPageFooter = new HeaderFooter($left, $center, $right, $style);

        return $this;
    }

    /**
     * Default download name, without extension (the driver adds it).
     */
    public function filename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    /**
     * Options passed straight to the driver for `$format`, merged over its config
     * (e.g. mPDF constructor config for 'pdf', OpenSpout Options arguments for 'xlsx').
     *
     * @param array<string, mixed> $options
     */
    public function native(string $format, array $options): self
    {
        $this->native[$format] = array_replace($this->native[$format] ?? [], $options);

        return $this;
    }

    /**
     * Adjusts the settings for some formats only, e.g. no page header in spreadsheets:
     * ->on('xlsx', fn (Settings $settings) => $settings->withoutHeader()).
     *
     * @param string|list<string> $formats
     * @param Closure(self): (self|null) $adjust
     */
    public function on(string|array $formats, Closure $adjust): self
    {
        foreach ((array) $formats as $format) {
            $this->formatAdjustments[$format][] = $adjust;
        }

        return $this;
    }

    /**
     * These settings with the adjustments for `$format` applied, leaving this instance as it is.
     */
    public function forFormat(string $format): self
    {
        $settings = clone $this;
        $settings->formatAdjustments = [];

        foreach ($this->formatAdjustments[$format] ?? [] as $adjust) {
            $settings = $adjust($settings) ?? $settings;
        }

        return $settings;
    }

    /**
     * Components (a letterhead, a title line...) repeated at the top of every page, above any text
     * header. PDF and DOCX repeat them per page; HTML shows them once at the top; spreadsheets skip them.
     * The PDF top margin grows to fit them. A closure builds them with the content API:
     * ->headerContent(fn (Group $header) => $header->text('Bank name')->text('{office}')).
     *
     * @param list<Component>|Closure(Group): mixed $components
     */
    public function headerContent(array|Closure $components): self
    {
        $this->headerContent = self::contents($components);

        return $this;
    }

    /**
     * Components repeated at the bottom of every page (PDF, DOCX), shown once at the end in HTML.
     *
     * @param list<Component>|Closure(Group): mixed $components
     */
    public function footerContent(array|Closure $components): self
    {
        $this->footerContent = self::contents($components);

        return $this;
    }

    /**
     * @param list<Component>|Closure(Group): mixed $components
     * @return list<Component>
     */
    private static function contents(array|Closure $components): array
    {
        if (is_array($components)) {
            return array_values($components);
        }

        $group = new Group();
        $result = $components($group);

        return ($result instanceof Group ? $result : $group)->getChildren();
    }

    /**
     * An image behind the content of every page, centered (PDF and DOCX).
     *
     * @param float $alpha opacity from 0 (invisible) to 1
     * @param float|null $width millimetres; null keeps the image's size
     */
    public function watermark(string $imagePath, float $alpha = 0.2, ?float $width = null, ?float $height = null): self
    {
        $this->watermark = ['path' => $imagePath, 'alpha' => $alpha, 'width' => $width, 'height' => $height];

        return $this;
    }

    /**
     * Prints spreadsheets scaled to one page wide (as many pages tall as needed).
     */
    public function fitToWidth(bool $fit = true): self
    {
        $this->fitToWidth = $fit;

        return $this;
    }

    /**
     * Removes the page header, including the first-page one.
     */
    public function withoutHeader(): self
    {
        $this->header = null;
        $this->firstPageHeader = null;
        $this->headerContent = [];

        return $this;
    }

    /**
     * Removes the page footer, including the first-page one.
     */
    public function withoutFooter(): self
    {
        $this->footer = null;
        $this->firstPageFooter = null;
        $this->footerContent = [];

        return $this;
    }

    /**
     * @return list<Component>
     */
    public function getHeaderContent(): array
    {
        return $this->headerContent;
    }

    /**
     * @return list<Component>
     */
    public function getFooterContent(): array
    {
        return $this->footerContent;
    }

    /**
     * @return array{path: string, alpha: float, width: float|null, height: float|null}|null
     */
    public function getWatermark(): ?array
    {
        return $this->watermark;
    }

    public function shouldFitToWidth(): bool
    {
        return $this->fitToWidth;
    }

    public function getPaper(): Paper
    {
        return $this->paper;
    }

    public function getOrientation(): Orientation
    {
        return $this->orientation;
    }

    public function isLandscape(): bool
    {
        return $this->orientation === Orientation::Landscape;
    }

    public function getMargins(): Margins
    {
        return $this->margins;
    }

    public function getDefaultStyle(): Style
    {
        return $this->defaultStyle;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getKeywords(): string
    {
        return $this->keywords;
    }

    public function getHeader(): ?HeaderFooter
    {
        return $this->header;
    }

    public function getFooter(): ?HeaderFooter
    {
        return $this->footer;
    }

    public function getFirstPageHeader(): ?HeaderFooter
    {
        return $this->firstPageHeader;
    }

    public function getFirstPageFooter(): ?HeaderFooter
    {
        return $this->firstPageFooter;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * @return array<string, mixed>
     */
    public function getNative(string $format): array
    {
        return $this->native[$format] ?? [];
    }

    /**
     * Printable width (paper width minus left and right margins) in millimetres.
     */
    public function contentWidth(): float
    {
        [$width] = $this->paper->dimensionsFor($this->orientation);

        return $width - $this->margins->left - $this->margins->right;
    }
}
