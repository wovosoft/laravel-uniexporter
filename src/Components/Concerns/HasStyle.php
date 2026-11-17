<?php

namespace Wovosoft\LaravelUniexporter\Components\Concerns;

use Closure;
use Wovosoft\LaravelUniexporter\Style\Align;
use Wovosoft\LaravelUniexporter\Style\Border;
use Wovosoft\LaravelUniexporter\Style\BorderLine;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Style\VerticalAlign;

/**
 * Fluent style props shared by every component. Inline props are layered over the named style.
 */
trait HasStyle
{
    protected ?Style $inlineStyle = null;

    protected ?string $styleName = null;

    /**
     * Applies a named document style, a Style object, or a closure that receives and returns a Style.
     *
     * @param string|Style|Closure(Style): Style $style
     */
    public function style(string|Style|Closure $style): static
    {
        if (is_string($style)) {
            $this->styleName = $style;

            return $this;
        }

        $current = $this->inlineStyle ?? Style::make();
        $this->inlineStyle = $style instanceof Closure ? $style($current) : $current->merge($style);

        return $this;
    }

    public function font(string $family, ?float $size = null): static
    {
        return $this->style(fn (Style $style): Style => $style->font($family, $size));
    }

    public function size(float $points): static
    {
        return $this->style(fn (Style $style): Style => $style->size($points));
    }

    public function bold(bool $bold = true): static
    {
        return $this->style(fn (Style $style): Style => $style->bold($bold));
    }

    public function italic(bool $italic = true): static
    {
        return $this->style(fn (Style $style): Style => $style->italic($italic));
    }

    public function underline(bool $underline = true): static
    {
        return $this->style(fn (Style $style): Style => $style->underline($underline));
    }

    public function strike(bool $strike = true): static
    {
        return $this->style(fn (Style $style): Style => $style->strike($strike));
    }

    public function color(string $color): static
    {
        return $this->style(fn (Style $style): Style => $style->color($color));
    }

    public function background(string $color): static
    {
        return $this->style(fn (Style $style): Style => $style->background($color));
    }

    public function align(Align|string $align): static
    {
        return $this->style(fn (Style $style): Style => $style->align($align));
    }

    public function left(): static
    {
        return $this->align(Align::Left);
    }

    public function center(): static
    {
        return $this->align(Align::Center);
    }

    public function right(): static
    {
        return $this->align(Align::Right);
    }

    public function justify(): static
    {
        return $this->align(Align::Justify);
    }

    public function verticalAlign(VerticalAlign|string $align): static
    {
        return $this->style(fn (Style $style): Style => $style->verticalAlign($align));
    }

    public function border(Border|float $border = 0.5, string $color = '000000', BorderLine $line = BorderLine::Solid): static
    {
        return $this->style(fn (Style $style): Style => $style->border($border, $color, $line));
    }

    public function padding(float $millimetres): static
    {
        return $this->style(fn (Style $style): Style => $style->padding($millimetres));
    }

    public function spaceBefore(float $millimetres): static
    {
        return $this->style(fn (Style $style): Style => $style->spaceBefore($millimetres));
    }

    public function spaceAfter(float $millimetres): static
    {
        return $this->style(fn (Style $style): Style => $style->spaceAfter($millimetres));
    }

    public function getInlineStyle(): ?Style
    {
        return $this->inlineStyle;
    }

    public function getStyleName(): ?string
    {
        return $this->styleName;
    }
}
