<?php

namespace Wovosoft\LaravelUniexporter\Drivers\Mpdf;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Wovosoft\LaravelUniexporter\Components\Component;
use Wovosoft\LaravelUniexporter\Components\Native;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Drivers\Html\HtmlDriver;
use Wovosoft\LaravelUniexporter\Drivers\RenderContext;
use Wovosoft\LaravelUniexporter\HeaderFooter;
use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * PDF through mPDF. The schema becomes the same HTML/CSS as the HTML driver, written to mPDF one
 * top-level component at a time, and the PDF is saved with Destination::FILE (never sent to output).
 *
 * Native callbacks: a component's ->native('pdf', fn (string $html, Mpdf $mpdf, RenderContext $context): ?string)
 * may return replacement HTML. Native components get (Mpdf $mpdf, RenderContext $context).
 * Document hooks get (Mpdf $mpdf, RenderContext $context).
 *
 * Driver options: 'config' (mPDF constructor config), 'font_dir', 'font_data', 'table_chunk_rows'
 * (rows per table written to mPDF at once, default 200, to bound its memory on big tables) and
 * 'html_listener' (a callable receiving each HTML chunk, for debugging and tests).
 */
class MpdfDriver extends HtmlDriver
{
    private Mpdf $mpdf;

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

        if (($watermark = $settings->getWatermark()) !== null && ($image = $context->imagePath($watermark['path'])) !== null) {
            $size = $watermark['width'] !== null || $watermark['height'] !== null
                ? $this->imageSize($image, $watermark['width'], $watermark['height'])
                : 'D';
            $this->mpdf->SetWatermarkImage($image, $watermark['alpha'], $size, 'P');
            $this->mpdf->showWatermarkImage = true;
        }

        $this->runCallbacks($context->document->getBeforeRender('pdf'), $this->mpdf, $context);

        $this->writeHtml($this->pageCss($context), HTMLParserMode::HEADER_CSS);
        $this->defineHeadersAndFooters($context);

        foreach ($context->document->schema() as $component) {
            $this->renderComponent($component, $context, null, false);
            $this->flush();
        }

        $this->runCallbacks($context->document->getAfterRender('pdf'), $this->mpdf, $context);

        $this->mpdf->Output($path, Destination::FILE);
    }

    protected function tableChunkRows(): ?int
    {
        return max(1, (int) ($this->options['table_chunk_rows'] ?? 200));
    }

    protected function afterTableChunk(): void
    {
        $this->flush();
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
            // Saves memory on large tables, but mPDF's border pass breaks on rowspans when it is on.
            'packTableData'        => ! $this->hasSpannedTables($context->document->schema()),
        ];

        // Component headers/footers have a height only known once rendered: grow the margins to fit them.
        if ($settings->getHeaderContent() !== []) {
            $config['setAutoTopMargin'] = 'stretch';
            $config['autoMarginPadding'] = 2;
        }
        if ($settings->getFooterContent() !== []) {
            $config['setAutoBottomMargin'] = 'stretch';
            $config['autoMarginPadding'] = 2;
        }

        if ($default->fontFamily !== null) {
            $config['default_font'] = $this->fontKey($default->fontFamily);
        }

        return array_replace($config, (array) ($this->options['config'] ?? []));
    }

    /**
     * @param list<Component> $components
     */
    protected function hasSpannedTables(array $components): bool
    {
        foreach ($components as $component) {
            if ($component instanceof Table) {
                if ($component->getSubRows() !== null || $component->getEmptyText() !== '' || ($component->hasTotals() && $component->getTotalLabelColspan() > 1)) {
                    return true;
                }
                foreach ($component->getHeaderRows() as $headerRow) {
                    foreach ($headerRow as $cell) {
                        if ($cell->getRowspan() > 1) {
                            return true;
                        }
                    }
                }
            }

            if ($this->hasSpannedTables($component->getChildren())) {
                return true;
            }
        }

        return false;
    }

    protected function pageBreakHtml(): string
    {
        return '<pagebreak />';
    }

    /**
     * mPDF lays out inline styles most reliably, so cells keep them.
     */
    protected function cellAttribute(string $css): string
    {
        return ' style="'.$css.'"';
    }

    /**
     * Lets mPDF shrink a table's font when a column cannot otherwise fit.
     */
    protected function tableAttributes(): string
    {
        return ' autosize="1"';
    }

    /**
     * mPDF reads images from the file system directly.
     */
    protected function imageSource(string $path): string
    {
        return $path;
    }

    protected function renderNative(Native $native, RenderContext $context, ?Style $inherited, bool $inline): void
    {
        if (($renderer = $native->getRenderer('pdf')) !== null) {
            $this->flush();
            $renderer($this->mpdf, $context);
            $this->pageHasContent = true;
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

    protected function write(string $html): void
    {
        $this->writeHtml($html, HTMLParserMode::HTML_BODY);
    }

    protected function writeHtml(string $html, int $mode): void
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
            .($settings->getHeader() || $settings->getHeaderContent() !== [] ? ' header: html_uxHeader;' : '')
            .($settings->getFooter() || $settings->getFooterContent() !== [] ? ' footer: html_uxFooter;' : '').' }';

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

        // Component content goes above (header) or below (footer) the text slots, on every page.
        foreach (['uxHeader' => [$settings->getHeaderContent(), $settings->getHeader(), 'header'], 'uxFooter' => [$settings->getFooterContent(), $settings->getFooter(), 'footer']] as $name => [$components, $text, $type]) {
            if ($components === []) {
                continue;
            }

            $content = $this->capture(function () use ($components, $context): void {
                foreach ($components as $component) {
                    $this->renderComponent($component, $context, null, false);
                }
            });
            $slots = $text !== null && ! $text->isEmpty() ? $this->headerFooterHtml($text, $context) : '';
            $html = $type === 'header' ? $content.$slots : $slots.$content;

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
     * mPDF font keys are the lowercase family name without spaces.
     */
    protected function fontKey(string $family): string
    {
        return strtolower(str_replace(' ', '', $family));
    }
}
