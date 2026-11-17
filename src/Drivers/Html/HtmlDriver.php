<?php

namespace Wovosoft\LaravelUniexporter\Drivers\Html;

use RuntimeException;
use Wovosoft\LaravelUniexporter\Components\Columns;
use Wovosoft\LaravelUniexporter\Components\Component;
use Wovosoft\LaravelUniexporter\Components\Divider;
use Wovosoft\LaravelUniexporter\Components\Group;
use Wovosoft\LaravelUniexporter\Components\Heading;
use Wovosoft\LaravelUniexporter\Components\Image;
use Wovosoft\LaravelUniexporter\Components\KeyValue;
use Wovosoft\LaravelUniexporter\Components\ListBlock;
use Wovosoft\LaravelUniexporter\Components\Native;
use Wovosoft\LaravelUniexporter\Components\PageBreak;
use Wovosoft\LaravelUniexporter\Components\Run;
use Wovosoft\LaravelUniexporter\Components\Sheet;
use Wovosoft\LaravelUniexporter\Components\Spacer;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Components\Text;
use Wovosoft\LaravelUniexporter\Drivers\AbstractDriver;
use Wovosoft\LaravelUniexporter\Drivers\RenderContext;
use Wovosoft\LaravelUniexporter\Style\Align;
use Wovosoft\LaravelUniexporter\Style\Border;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Style\VerticalAlign;
use Wovosoft\LaravelUniexporter\Support\TableRows;

/**
 * A standalone HTML page, for viewing in the browser or printing from it. Also the markup
 * base of the PDF driver, which writes the same HTML to mPDF instead of a file.
 *
 * The page is written one top-level component at a time, so large tables don't build up in
 * memory. Images are embedded as data URIs. Page headers and footers are not rendered,
 * because a browser page has no page numbers; page breaks only apply when printing.
 *
 * Native callbacks: a component's ->native('html', fn (string $html, RenderContext $context): ?string)
 * may return replacement HTML. Native components get (RenderContext $context) and return HTML.
 *
 * Driver options: 'html_listener' (a callable receiving each HTML chunk, for debugging and tests).
 */
class HtmlDriver extends AbstractDriver
{
    protected string $buffer = '';

    /**
     * Whether anything was written since the last page break; a sheet only starts a new page when it was.
     */
    protected bool $pageHasContent = false;

    /**
     * @var resource|null
     */
    private $file = null;

    /**
     * Table cell styles turned into classes: CSS => class name.
     *
     * @var array<string, string>
     */
    private array $cellClasses = [];

    /**
     * Class rules not yet written; they go out just before the HTML that first uses them.
     */
    private string $pendingCss = '';

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(protected readonly array $options = []) {}

    public function extension(): string
    {
        return 'html';
    }

    public function mimeType(): string
    {
        return 'text/html; charset=UTF-8';
    }

    public function render(RenderContext $context, string $path): void
    {
        $this->file = fopen($path, 'wb') ?: throw new RuntimeException("Could not open [{$path}] for writing.");
        $this->cellClasses = [];
        $this->pendingCss = '';

        try {
            $settings = $context->settings;
            $this->runCallbacks($context->document->getBeforeRender('html'), $context);

            $this->write('<!doctype html><html><head><meta charset="utf-8">'
                .'<meta name="viewport" content="width=device-width, initial-scale=1">'
                .'<title>'.$this->escape($context->text($settings->getTitle())).'</title>'
                .'<style>'.$this->pageCss($context).'</style></head><body><div class="ux-page">');

            foreach ($context->document->schema() as $component) {
                $this->renderComponent($component, $context, null, false);
                $this->flush();
            }

            $this->write('</div></body></html>');
            $this->runCallbacks($context->document->getAfterRender('html'), $context);
        } finally {
            fclose($this->file);
            $this->file = null;
        }
    }

    protected function pageCss(RenderContext $context): string
    {
        $settings = $context->settings;
        $margins = $settings->getMargins();
        $paper = $settings->getPaper()->value.($settings->isLandscape() ? ' landscape' : ' portrait');

        return '@page { size: '.$paper.'; margin: '.$margins->top.'mm '.$margins->right.'mm '.$margins->bottom.'mm '.$margins->left.'mm; }'
            .' body { margin: 0; background: #fff; color: #000; '.$this->textCss($settings->getDefaultStyle()).' }'
            .' .ux-page { max-width: 1200px; margin: 0 auto; padding: 8mm 4mm; }'
            .' p, h1, h2, h3, h4, h5, h6 { margin: 0; } td, th { vertical-align: top; } thead { display: table-header-group; }'
            .' tr { page-break-inside: avoid; }'
            .' @media print { .ux-page { max-width: none; padding: 0; } }';
    }

    protected function pageBreakHtml(): string
    {
        return '<div style="page-break-after: always;"></div>';
    }

    /**
     * The style of a table cell as a shared class, since thousands of cells repeat a handful of styles.
     */
    protected function cellAttribute(string $css): string
    {
        if (! isset($this->cellClasses[$css])) {
            $this->cellClasses[$css] = 'c'.count($this->cellClasses);
            $this->pendingCss .= '.'.$this->cellClasses[$css].'{'.$css.'}';
        }

        return ' class="'.$this->cellClasses[$css].'"';
    }

    protected function tableAttributes(): string
    {
        return '';
    }

    /**
     * The image as a data URI, so the page needs no access to the server's files.
     */
    protected function imageSource(string $path): string
    {
        $mime = getimagesize($path)['mime'] ?? 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    protected function renderPageBreak(PageBreak $break, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        if (! $inline) {
            $this->emit($break, $context, $this->pageBreakHtml());
            $this->flush();
            $this->pageHasContent = false;
        }
    }

    protected function renderSheet(Sheet $sheet, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        if ($this->pageHasContent && ! $inline) {
            $this->emit($sheet, $context, $this->pageBreakHtml());
            $this->flush();
            $this->pageHasContent = false;
        }
    }

    protected function renderNative(Native $native, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        if (($renderer = $native->getRenderer('html')) !== null) {
            $this->buffer .= (string) $renderer($context);
        }
    }

    /**
     * Appends a component's HTML after giving its native callbacks a chance to replace it.
     */
    protected function emit(Component $component, RenderContext $context, string $html): void
    {
        foreach ($component->getNativeCallbacks('html') as $callback) {
            $html = $callback($html, $context) ?? $html;
        }

        $this->buffer .= $html;
    }

    protected function flush(): void
    {
        if ($this->buffer !== '') {
            if ($this->pendingCss !== '') {
                $this->write('<style>'.$this->pendingCss.'</style>');
                $this->pendingCss = '';
            }

            $this->write($this->buffer);
            $this->buffer = '';
            $this->pageHasContent = true;
        }
    }

    protected function write(string $html): void
    {
        if (is_callable($listener = $this->options['html_listener'] ?? null)) {
            $listener($html);
        }

        fwrite($this->file, $html);
    }

    protected function renderHeading(Heading $heading, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        $style = $context->style($heading, $inherited);
        $text = $this->escape($context->text($heading->getText()));

        $this->emit($heading, $context, $inline
            ? '<span style="'.$this->textCss($style).'">'.$text.'</span><br />'
            : '<h'.$heading->getLevel().' style="'.$this->blockCss($style).'">'.$text.'</h'.$heading->getLevel().'>');
    }

    protected function renderText(Text $text, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        $style = $context->style($text, $inherited);
        $runs = implode('', array_map(fn (Run $run): string => $this->runHtml($run, $context), $text->getRuns()));

        $this->emit($text, $context, $inline
            ? '<span style="'.$this->textCss($style).'">'.$runs.'</span><br />'
            : '<p style="'.$this->blockCss($style).'">'.$runs.'</p>');
    }

    protected function renderTable(Table $table, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        $style = $context->style($table, $inherited);
        $columns = $table->getColumns();
        $widths = $table->getWidthPercentages();
        $border = $table->getCellBorder();
        $padding = $style->padding ?? 1;
        $headerStyle = $style->merge($table->getHeaderStyle());
        $rows = new TableRows($table, $context->data);

        $head = '';

        if ($table->shouldShowHeader()) {
            foreach ($table->getHeaderRows() as $headerRow) {
                $head .= '<tr>';
                foreach ($headerRow as $cell) {
                    $cellStyle = $this->headerCellStyle($cell, $headerStyle, $context);
                    $head .= '<th colspan="'.$cell->getColspan().'"'.$this->cellAttribute($this->cellCss($cellStyle, $border, $padding)).'>'
                        .$this->escape($context->text($cell->getText())).'</th>';
                }
                $head .= '</tr>';
            }

            $head .= '<tr>';
            foreach ($columns as $position => $column) {
                $cellStyle = $headerStyle
                    ->merge(Style::make()->align($column->getInlineStyle()?->align ?? Align::Left))
                    ->merge($column->getHeaderStyle());
                $head .= '<th'.$this->cellAttribute('width: '.round($widths[$position], 3).'%; '.$this->cellCss($cellStyle, $border, $padding)).'>'
                    .$this->escape($context->text($column->getLabel())).'</th>';
            }
            $head .= '</tr>';
        }

        $body = '';
        $columnStyles = array_map(fn ($column): Style => $style->merge($column->getInlineStyle())->merge(Style::make()->padding($padding)), $columns);

        foreach ($rows->rows() as $row) {
            $stripe = $table->getStripeColor() !== null && $row->index % 2 === 1 ? Style::make()->background($table->getStripeColor()) : null;
            $body .= '<tr>';
            foreach ($row->texts as $position => $text) {
                $cellStyle = $columnStyles[$position]->merge($stripe)->merge($row->style);
                $body .= '<td'.$this->cellAttribute($this->cellCss($cellStyle, $border, $padding)).'>'.$this->escape($text).'</td>';
            }
            $body .= '</tr>';
        }

        if ($rows->count() === 0 && $table->getEmptyText() !== '') {
            $body .= '<tr><td colspan="'.count($columns).'"'.$this->cellAttribute($this->cellCss($style->merge(Style::make()->align(Align::Center)), $border, $padding)).'>'
                .$this->escape($context->text($table->getEmptyText())).'</td></tr>';
        }

        if ($table->hasTotals()) {
            $totalStyle = $style->merge($table->getTotalStyle());
            $labelColumn = $rows->totalLabelColumn();
            $body .= '<tr>';
            foreach ($rows->totals() as $position => $total) {
                $text = match (true) {
                    $total !== null           => $columns[$position]->getFormat()->toText($total),
                    $position === $labelColumn => $context->text($table->getTotalLabel()),
                    default                   => '',
                };
                $cellStyle = $totalStyle->merge(Style::make()->align($columns[$position]->getInlineStyle()?->align ?? Align::Left));
                $body .= '<td'.$this->cellAttribute($this->cellCss($cellStyle, $border, $padding)).'>'.$this->escape($text).'</td>';
            }
            $body .= '</tr>';
        }

        $headHtml = $head === '' ? '' : ($table->shouldRepeatHeader() ? '<thead>'.$head.'</thead>' : $head);
        $tableCss = 'width: 100%; border-collapse: collapse; '.$this->spacingCss($style);

        $this->emit($table, $context, '<table'.$this->tableAttributes().' style="'.$tableCss.'">'.$headHtml.'<tbody>'.$body.'</tbody></table>');
    }

    protected function renderKeyValue(KeyValue $keyValue, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        $style = $context->style($keyValue, $inherited);
        $labelStyle = $style->merge($keyValue->getLabelStyle());
        $pairs = array_chunk($keyValue->getItems(), $keyValue->getColumns(), true);
        $cellPadding = $style->padding ?? 0.5;

        $html = '<table style="width: 100%; border-collapse: collapse; '.$this->spacingCss($style).'">';
        foreach ($pairs as $pair) {
            $html .= '<tr>';
            foreach ($pair as $label => $value) {
                $html .= '<td'.$this->cellAttribute($this->cellCss($labelStyle, null, $cellPadding).' white-space: nowrap;').'>'
                    .$this->escape($context->text($label).$keyValue->getSeparator()).'</td>';
                $html .= '<td'.$this->cellAttribute($this->cellCss($style, null, $cellPadding)).'>'.$this->escape($context->text($value)).'</td>';
            }
            $html .= '</tr>';
        }

        $this->emit($keyValue, $context, $html.'</table>');
    }

    protected function renderListBlock(ListBlock $list, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        $style = $context->style($list, $inherited);
        $this->emit($list, $context, $this->listHtml($this->listItems($list, $context), $list->isOrdered(), $style, $context));
    }

    protected function renderImage(Image $image, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        $path = $context->imagePath($image->getSource());

        if ($path === null) {
            return;
        }

        $style = $context->style($image, $inherited);
        $size = ($image->getWidth() !== null ? 'width: '.$image->getWidth().'mm; ' : '')
            .($image->getHeight() !== null ? 'height: '.$image->getHeight().'mm; ' : '');
        $img = '<img src="'.$this->escape($this->imageSource($path)).'" style="'.$size.'" />';

        $this->emit($image, $context, $inline
            ? $img.'<br />'
            : '<div style="text-align: '.($style->align ?? Align::Left)->value.'; '.$this->spacingCss($style).'">'.$img.'</div>');
    }

    protected function renderColumns(Columns $columns, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        $style = $context->style($columns, $inherited);
        $widths = $columns->getWidthPercentages();

        $html = '<table style="width: 100%; border-collapse: collapse; '.$this->spacingCss($style).'"><tr>';
        foreach ($columns->getBlocks() as $position => $block) {
            $blockStyle = $context->style($block, $style);
            $html .= '<td style="width: '.round($widths[$position], 3).'%; vertical-align: '.$this->verticalAlign($blockStyle->verticalAlign ?? VerticalAlign::Top).'; '
                .$this->textCss($blockStyle).' text-align: '.($blockStyle->align ?? Align::Left)->value.';">'
                .$this->capture(fn () => $this->renderChildren($block, $context, $blockStyle, true)).'</td>';
        }

        $this->emit($columns, $context, $html.'</tr></table>');
    }

    protected function renderGroup(Group $group, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        $style = $context->style($group, $inherited);
        $children = $this->capture(fn () => $this->renderChildren($group, $context, $style, $inline));

        $this->emit($group, $context, $inline
            ? $children
            : '<div style="'.$this->blockCss($style).($group->shouldKeepTogether() ? ' page-break-inside: avoid;' : '').'">'.$children.'</div>');
    }

    protected function renderSpacer(Spacer $spacer, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        $this->emit($spacer, $context, $inline
            ? '<br />'
            : '<div style="height: '.$spacer->getHeight().'mm; font-size: 1pt; line-height: 1pt;">&nbsp;</div>');
    }

    protected function renderDivider(Divider $divider, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        $style = $context->style($divider, $inherited);
        $border = $style->border ?? new Border(0.5, '999999');

        $this->emit($divider, $context, '<hr style="color: #'.$border->color.'; height: '.$border->width.'pt; '.$this->spacingCss($style).'" />');
    }

    protected function renderChildren(Component $container, RenderContext $context, Style $style, bool $inline): void
    {
        foreach ($container->getChildren() as $child) {
            $this->renderComponent($child, $context, $style, $inline);
        }
    }

    protected function capture(callable $render): string
    {
        $previous = $this->buffer;
        $this->buffer = '';
        $render();
        $captured = $this->buffer;
        $this->buffer = $previous;

        return $captured;
    }

    /**
     * @param list<string|array{text: string, items?: list<mixed>}> $items
     */
    protected function listHtml(array $items, bool $ordered, Style $style, RenderContext $context): string
    {
        $tag = $ordered ? 'ol' : 'ul';
        $html = '<'.$tag.' style="'.$this->blockCss($style).'">';

        foreach ($items as $item) {
            $text = is_array($item) ? ($item['text'] ?? '') : $item;
            $html .= '<li>'.$this->escape($context->text((string) $text));

            if (is_array($item) && ! empty($item['items'])) {
                $html .= $this->listHtml($item['items'], $ordered, $style->inheritable(), $context);
            }

            $html .= '</li>';
        }

        return $html.'</'.$tag.'>';
    }

    protected function runHtml(Run $run, RenderContext $context): string
    {
        $text = $this->escape($context->text($run->getText()));
        $css = $this->textCss($this->runStyle($run, $context));
        $html = $css === '' ? $text : '<span style="'.$css.'">'.$text.'</span>';

        if ($run->getUrl() !== null) {
            $html = '<a href="'.$this->escape($context->text($run->getUrl())).'">'.$html.'</a>';
        }

        return $html;
    }

    protected function textCss(Style $style): string
    {
        $css = [];

        if ($style->fontFamily !== null) {
            $css[] = 'font-family: '.$this->fontKey($style->fontFamily);
        }
        if ($style->fontSize !== null) {
            $css[] = 'font-size: '.$style->fontSize.'pt';
        }
        if ($style->bold !== null) {
            $css[] = 'font-weight: '.($style->bold ? 'bold' : 'normal');
        }
        if ($style->italic !== null) {
            $css[] = 'font-style: '.($style->italic ? 'italic' : 'normal');
        }

        $decorations = array_keys(array_filter(['underline' => $style->underline, 'line-through' => $style->strike]));
        if ($style->underline !== null || $style->strike !== null) {
            $css[] = 'text-decoration: '.($decorations === [] ? 'none' : implode(' ', $decorations));
        }
        if ($style->color !== null) {
            $css[] = 'color: #'.$style->color;
        }
        if ($style->background !== null) {
            $css[] = 'background-color: #'.$style->background;
        }

        return $css === [] ? '' : implode('; ', $css).';';
    }

    protected function blockCss(Style $style): string
    {
        $css = $this->textCss($style);

        if ($style->align !== null) {
            $css .= ' text-align: '.$style->align->value.';';
        }
        if ($style->padding !== null) {
            $css .= ' padding: '.$style->padding.'mm;';
        }
        if ($style->border?->isVisible()) {
            $css .= ' border: '.$this->borderCss($style->border).';';
        }

        return trim($css.' '.$this->spacingCss($style));
    }

    protected function cellCss(Style $style, ?Border $border, float $padding): string
    {
        $css = $this->textCss($style)
            .' text-align: '.($style->align ?? Align::Left)->value.';'
            .' vertical-align: '.$this->verticalAlign($style->verticalAlign ?? VerticalAlign::Top).';'
            .' padding: '.($style->padding ?? $padding).'mm;';

        $border = $style->border ?? $border;

        return $css.($border?->isVisible() ? ' border: '.$this->borderCss($border).';' : '');
    }

    protected function spacingCss(Style $style): string
    {
        return 'margin-top: '.($style->spaceBefore ?? 0).'mm; margin-bottom: '.($style->spaceAfter ?? 0).'mm;';
    }

    protected function borderCss(Border $border): string
    {
        return $border->width.'pt '.$border->line->value.' #'.$border->color;
    }

    protected function verticalAlign(VerticalAlign $align): string
    {
        return $align === VerticalAlign::Middle ? 'middle' : $align->value;
    }

    /**
     * The CSS font-family value for a family name, single-quoted because it goes into a style attribute.
     */
    protected function fontKey(string $family): string
    {
        return "'".str_replace(["'", '"'], '', $family)."', serif";
    }

    protected function escape(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}
