<?php

namespace Wovosoft\LaravelUniexporter\Style;

/**
 * Format-independent style. Every property is optional: `null` means "inherit".
 *
 * Styles are immutable; each modifier returns a new instance. Resolution layers them as
 * document defaults < inherited (group) < component default < named style < inline style,
 * see {@see Style::merge()}.
 *
 * Units: font size in points, padding and spacing in millimetres, colors as hex.
 */
final class Style
{
    public function __construct(
        public readonly ?string $fontFamily = null,
        public readonly ?float $fontSize = null,
        public readonly ?bool $bold = null,
        public readonly ?bool $italic = null,
        public readonly ?bool $underline = null,
        public readonly ?bool $strike = null,
        public readonly ?string $color = null,
        public readonly ?string $background = null,
        public readonly ?Align $align = null,
        public readonly ?VerticalAlign $verticalAlign = null,
        public readonly ?Border $border = null,
        public readonly ?float $padding = null,
        public readonly ?float $spaceBefore = null,
        public readonly ?float $spaceAfter = null,
        public readonly ?bool $wrap = null,
    ) {}

    public static function make(): self
    {
        return new self();
    }

    public function font(string $family, ?float $size = null): self
    {
        return $this->with(fontFamily: $family, fontSize: $size ?? $this->fontSize);
    }

    public function size(float $points): self
    {
        return $this->with(fontSize: $points);
    }

    public function bold(bool $bold = true): self
    {
        return $this->with(bold: $bold);
    }

    public function italic(bool $italic = true): self
    {
        return $this->with(italic: $italic);
    }

    public function underline(bool $underline = true): self
    {
        return $this->with(underline: $underline);
    }

    public function strike(bool $strike = true): self
    {
        return $this->with(strike: $strike);
    }

    public function color(string $color): self
    {
        return $this->with(color: Color::normalize($color));
    }

    public function background(string $color): self
    {
        return $this->with(background: Color::normalize($color));
    }

    public function align(Align|string $align): self
    {
        return $this->with(align: is_string($align) ? Align::from($align) : $align);
    }

    public function verticalAlign(VerticalAlign|string $align): self
    {
        return $this->with(verticalAlign: is_string($align) ? VerticalAlign::from($align) : $align);
    }

    public function border(Border|float $border = 0.5, string $color = '000000', BorderLine $line = BorderLine::Solid): self
    {
        return $this->with(border: $border instanceof Border ? $border : new Border($border, $color, $line));
    }

    public function padding(float $millimetres): self
    {
        return $this->with(padding: $millimetres);
    }

    public function spaceBefore(float $millimetres): self
    {
        return $this->with(spaceBefore: $millimetres);
    }

    public function spaceAfter(float $millimetres): self
    {
        return $this->with(spaceAfter: $millimetres);
    }

    public function wrap(bool $wrap = true): self
    {
        return $this->with(wrap: $wrap);
    }

    /**
     * Returns a style where every property set on `$over` replaces this one's.
     */
    public function merge(?self $over): self
    {
        if ($over === null) {
            return $this;
        }

        return new self(
            $over->fontFamily ?? $this->fontFamily,
            $over->fontSize ?? $this->fontSize,
            $over->bold ?? $this->bold,
            $over->italic ?? $this->italic,
            $over->underline ?? $this->underline,
            $over->strike ?? $this->strike,
            $over->color ?? $this->color,
            $over->background ?? $this->background,
            $over->align ?? $this->align,
            $over->verticalAlign ?? $this->verticalAlign,
            $over->border ?? $this->border,
            $over->padding ?? $this->padding,
            $over->spaceBefore ?? $this->spaceBefore,
            $over->spaceAfter ?? $this->spaceAfter,
            $over->wrap ?? $this->wrap,
        );
    }

    /**
     * The properties a child element inherits from its container (font and color, not box properties).
     */
    public function inheritable(): self
    {
        return new self(
            fontFamily: $this->fontFamily,
            fontSize: $this->fontSize,
            bold: $this->bold,
            italic: $this->italic,
            underline: $this->underline,
            strike: $this->strike,
            color: $this->color,
            align: $this->align,
        );
    }

    /**
     * A stable identity for caching driver-native styles (OpenSpout registers styles per object).
     */
    public function key(): string
    {
        return md5(serialize(get_object_vars($this)));
    }

    private function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
