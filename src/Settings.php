<?php

namespace Wovosoft\LaravelUniexporter;

use Closure;
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
     * Removes the page header, including the first-page one.
     */
    public function withoutHeader(): self
    {
        $this->header = null;
        $this->firstPageHeader = null;

        return $this;
    }

    /**
     * Removes the page footer, including the first-page one.
     */
    public function withoutFooter(): self
    {
        $this->footer = null;
        $this->firstPageFooter = null;

        return $this;
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
