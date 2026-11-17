<?php

namespace Wovosoft\LaravelUniexporter\Drivers\Mpdf;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
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
use Wovosoft\LaravelUniexporter\HeaderFooter;
use Wovosoft\LaravelUniexporter\Style\Align;
use Wovosoft\LaravelUniexporter\Style\Border;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Style\VerticalAlign;
use Wovosoft\LaravelUniexporter\Support\TableRows;

/**
 * PDF through mPDF. The schema becomes HTML/CSS that is written to mPDF one top-level
 * component at a time, and the PDF is saved with Destination::FILE (never sent to output).
 *
 * Native callbacks: a component's ->native('pdf', fn (string $html, Mpdf $mpdf, RenderContext $context): ?string)
 * may return replacement HTML. Native components get (Mpdf $mpdf, RenderContext $context).
 * Document hooks get (Mpdf $mpdf, RenderContext $context).
 *
 * Driver options: 'config' (mPDF constructor config), 'font_dir', 'font_data', and
 * 'html_listener' (a callable receiving each HTML chunk, for debugging and tests).
 */
class MpdfDriver extends AbstractDriver
{
    private Mpdf $mpdf;

    private string $buffer = '';

    /**
     * Whether anything was written since the last page break; a sheet only starts a new page when it was.
     */
    private bool $pageHasContent = false;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private readonly array $options = []) {}

    public function extension(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function render(RenderContext $context, string $path): void
    {
        $this->ensureInstalled(Mpdf::class, 'mpdf/mpdf');

        $this->mpdf = new Mpdf($this->mpdfConfig($context));
        $settings = $context->settings;

        $this->mpdf->SetTitle($context->text($settings->getTitle()));
        $this->mpdf->SetAuthor($context->text($settings->getAuthor()));
        $this->mpdf->SetSubject($context->text($settings->getSubject()));
        $this->mpdf->SetKeywords($context->text($settings->getKeywords()));

        $this->runCallbacks($context->document->getBeforeRender('pdf'), $this->mpdf, $context);

        $this->write($this->pageCss($context), HTMLParserMode::HEADER_CSS);
        $this->defineHeadersAndFooters($context);

        foreach ($context->document->schema() as $component) {
            $this->renderComponent($component, $context, null, false);
            $this->flush();
        }

        $this->runCallbacks($context->document->getAfterRender('pdf'), $this->mpdf, $context);

        $this->mpdf->Output($path, Destination::FILE);
    }

    public function getMpdf(): Mpdf
    {
        return $this->mpdf;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mpdfConfig(RenderContext $context): array
    {
        $settings = $context->settings;
        $margins = $settings->getMargins();
        $default = $settings->getDefaultStyle();
        $fontDirs = (new ConfigVariables())->getDefaults()['fontDir'];
        $fontData = (new FontVariables())->getDefaults()['fontdata'];

        $config = [
            'mode'                 => 'utf-8',
            'format'               => strtoupper($settings->getPaper()->value),
            'orientation'          => $settings->isLandscape() ? 'L' : 'P',
            'margin_left'          => $margins->left,
            'margin_right'         => $margins->right,
            'margin_top'           => $margins->top,
            'margin_bottom'        => $margins->bottom,
            'margin_header'        => $margins->header,
            'margin_footer'        => $margins->footer,
            'default_font_size'    => $default->fontSize ?? 10,
            'tempDir'              => $context->temporaryDirectory.DIRECTORY_SEPARATOR.'mpdf',
            'fontDir'              => array_merge($fontDirs, (array) ($this->options['font_dir'] ?? [])),
            'fontdata'             => array_replace($fontData, (array) ($this->options['font_data'] ?? [])),
            // Keep the sizes the schema asks for instead of silently shrinking wide tables.
            'shrink_tables_to_fit' => 0,
            'packTableData'        => true,
        ];

        if ($default->fontFamily !== null) {
            $config['default_font'] = $this->fontKey($default->fontFamily);
        }

        return array_replace($config, (array) ($this->options['config'] ?? []));
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
                    $head .= '<th colspan="'.$cell->getColspan().'" style="'.$this->cellCss($cellStyle, $border, $padding).'">'
                        .$this->escape($context->text($cell->getText())).'</th>';
                }
                $head .= '</tr>';
            }

            $head .= '<tr>';
            foreach ($columns as $position => $column) {
                $cellStyle = $headerStyle
                    ->merge(Style::make()->align($column->getInlineStyle()?->align ?? Align::Left))
                    ->merge($column->getHeaderStyle());
                $head .= '<th style="width: '.round($widths[$position], 3).'%; '.$this->cellCss($cellStyle, $border, $padding).'">'
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
                $body .= '<td style="'.$this->cellCss($cellStyle, $border, $padding).'">'.$this->escape($text).'</td>';
            }
            $body .= '</tr>';
        }

        if ($rows->count() === 0 && $table->getEmptyText() !== '') {
            $body .= '<tr><td colspan="'.count($columns).'" style="'.$this->cellCss($style->merge(Style::make()->align(Align::Center)), $border, $padding).'">'
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
                $body .= '<td style="'.$this->cellCss($cellStyle, $border, $padding).'">'.$this->escape($text).'</td>';
            }
            $body .= '</tr>';
        }

        $headHtml = $head === '' ? '' : ($table->shouldRepeatHeader() ? '<thead>'.$head.'</thead>' : $head);
        $tableCss = 'width: 100%; border-collapse: collapse; '.$this->spacingCss($style);

        $this->emit($table, $context, '<table autosize="1" style="'.$tableCss.'">'.$headHtml.'<tbody>'.$body.'</tbody></table>');
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
                $html .= '<td style="'.$this->cellCss($labelStyle, null, $cellPadding).' white-space: nowrap;">'
                    .$this->escape($context->text($label).$keyValue->getSeparator()).'</td>';
                $html .= '<td style="'.$this->cellCss($style, null, $cellPadding).'">'.$this->escape($context->text($value)).'</td>';
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
        $img = '<img src="'.$this->escape($path).'" style="'.$size.'" />';

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

    protected function renderPageBreak(PageBreak $break, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        if (! $inline) {
            $this->emit($break, $context, '<pagebreak />');
            $this->flush();
            $this->pageHasContent = false;
        }
    }

    protected function renderSheet(Sheet $sheet, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        if ($this->pageHasContent && ! $inline) {
            $this->emit($sheet, $context, '<pagebreak />');
            $this->flush();
            $this->pageHasContent = false;
        }
    }

    protected function renderNative(Native $native, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        if (($renderer = $native->getRenderer('pdf')) !== null) {
            $this->flush();
            $renderer($this->mpdf, $context);
            $this->pageHasContent = true;
        }
    }

    protected function renderChildren(Component $container, RenderContext $context, Style $style, bool $inline): void
    {
        foreach ($container->getChildren() as $child) {
            $this->renderComponent($child, $context, $style, $inline);
        }
    }

    /**
     * Appends a component's HTML after giving its native callbacks a chance to replace it.
     */
    protected function emit(Component $component, RenderContext $context, string $html): void
    {
        foreach ($component->getNativeCallbacks('pdf') as $callback) {
            $html = $callback($html, $this->mpdf, $context) ?? $html;
        }

        $this->buffer .= $html;
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

    protected function flush(): void
    {
        if ($this->buffer !== '') {
            $this->write($this->buffer, HTMLParserMode::HTML_BODY);
            $this->buffer = '';
            $this->pageHasContent = true;
        }
    }

    protected function write(string $html, int $mode): void
    {
        if (is_callable($listener = $this->options['html_listener'] ?? null)) {
            $listener($html, $mode);
        }

        $this->mpdf->WriteHTML($html, $mode);
    }

    protected function pageCss(RenderContext $context): string
    {
        $settings = $context->settings;
        $margins = $settings->getMargins();
        $marginCss = sprintf(
            'margin-top: %smm; margin-right: %smm; margin-bottom: %smm; margin-left: %smm; margin-header: %smm; margin-footer: %smm;',
            $margins->top, $margins->right, $margins->bottom, $margins->left, $margins->header, $margins->footer,
        );

        $page = '@page { '.$marginCss
            .($settings->getHeader() ? ' header: html_uxHeader;' : '')
            .($settings->getFooter() ? ' footer: html_uxFooter;' : '').' }';

        if ($settings->getFirstPageHeader() || $settings->getFirstPageFooter()) {
            $page .= ' @page :first { '
                .($settings->getFirstPageHeader() ? ' header: '.($settings->getFirstPageHeader()->isEmpty() ? '_blank' : 'html_uxFirstHeader').';' : '')
                .($settings->getFirstPageFooter() ? ' footer: '.($settings->getFirstPageFooter()->isEmpty() ? '_blank' : 'html_uxFirstFooter').';' : '')
                .' }';
        }

        $body = 'body { '.$this->textCss($settings->getDefaultStyle()).' }';

        return $page.' '.$body.' p, h1, h2, h3, h4, h5, h6 { margin: 0; } td, th { vertical-align: top; }';
    }

    protected function defineHeadersAndFooters(RenderContext $context): void
    {
        $settings = $context->settings;

        foreach ([
            'uxHeader'      => [$settings->getHeader(), 'header'],
            'uxFooter'      => [$settings->getFooter(), 'footer'],
            'uxFirstHeader' => [$settings->getFirstPageHeader(), 'header'],
            'uxFirstFooter' => [$settings->getFirstPageFooter(), 'footer'],
        ] as $name => [$headerFooter, $type]) {
            if ($headerFooter === null || $headerFooter->isEmpty()) {
                continue;
            }

            $html = $this->headerFooterHtml($headerFooter, $context);
            $type === 'header' ? $this->mpdf->DefHTMLHeaderByName($name, $html) : $this->mpdf->DefHTMLFooterByName($name, $html);
        }
    }

    protected function headerFooterHtml(HeaderFooter $headerFooter, RenderContext $context): string
    {
        $tokens = ['page' => '{PAGENO}', 'pages' => '{nbpg}', 'date' => date('d/m/Y')];
        $style = $context->settings->getDefaultStyle()->merge($headerFooter->style);
        $html = '<table style="width: 100%; border-collapse: collapse; '.$this->textCss($style).'"><tr>';

        foreach ($headerFooter->slots() as $slot => $template) {
            $html .= '<td style="width: 33.33%; text-align: '.$slot.'; '.$this->textCss($style).'">'
                .$this->escape($context->text($template, $tokens)).'</td>';
        }

        return $html.'</tr></table>';
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
     * mPDF font keys are the lowercase family name without spaces.
     */
    protected function fontKey(string $family): string
    {
        return strtolower(str_replace(' ', '', $family));
    }

    protected function escape(string $text): string
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }
}
