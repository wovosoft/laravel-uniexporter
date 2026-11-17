<?php

namespace Wovosoft\LaravelUniexporter;

use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * Page header or footer text in three slots. Templates can use data placeholders and the
 * page tokens `{page}`, `{pages}` and `{date}`, which each driver maps to its own fields.
 */
final readonly class HeaderFooter
{
    public const array PAGE_TOKENS = ['page', 'pages', 'date'];

    public Style $style;

    public function __construct(
        public string $left = '',
        public string $center = '',
        public string $right = '',
        ?Style $style = null,
    ) {
        $this->style = Style::make()->size(8)->color('595959')->merge($style);
    }

    public function isEmpty(): bool
    {
        return $this->left === '' && $this->center === '' && $this->right === '';
    }

    /**
     * @return array{left: string, center: string, right: string}
     */
    public function slots(): array
    {
        return ['left' => $this->left, 'center' => $this->center, 'right' => $this->right];
    }
}
