<?php

namespace Wovosoft\LaravelUniexporter\Drivers\PhpWord;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Footer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as PhpWordSettings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\SimpleType\VerticalJc;
use PhpOffice\PhpWord\Style\ListItem;
use PhpOffice\PhpWord\Style\Table as TableStyle;
use Wovosoft\LaravelUniexporter\Components\Column;
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
use Wovosoft\LaravelUniexporter\Components\Sheet;
use Wovosoft\LaravelUniexporter\Components\Spacer;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Components\Text;
use Wovosoft\LaravelUniexporter\Drivers\AbstractDriver;
use Wovosoft\LaravelUniexporter\Drivers\RenderContext;
use Wovosoft\LaravelUniexporter\HeaderFooter;
use Wovosoft\LaravelUniexporter\Style\Align;
use Wovosoft\LaravelUniexporter\Style\Border;
use Wovosoft\LaravelUniexporter\Style\BorderLine;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Style\VerticalAlign;
use Wovosoft\LaravelUniexporter\Support\TableHeader;
use Wovosoft\LaravelUniexporter\Support\TableRows;
use Wovosoft\LaravelUniexporter\Support\Units;

/**
 * DOCX through PhpWord, saved with the Word2007 writer to a file (never to output).
 *
 * Native callbacks: a component's ->native('docx', fn ($element, AbstractContainer $container, RenderContext $context))
 * receives the PhpWord element it produced (Table, TextRun, Image, ...). Native components get
 * (AbstractContainer $container, RenderContext $context). Document hooks get (PhpWord $phpWord, RenderContext $context).
 *
 * DOCX does not embed fonts: readers need the document's fonts (e.g. Nikosh) installed.
 */
class PhpWordDriver extends AbstractDriver
{
    private PhpWord $phpWord;

    private Section $section;

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
        return 'docx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function render(RenderContext $context, string $path): void
    {
        $this->ensureInstalled(PhpWord::class, 'phpoffice/phpword');

        // PhpWord settings are global: set what this export needs and restore them afterwards,
        // so other PhpWord code in the same (Octane) worker is not affected.
        $previousEscaping = PhpWordSettings::isOutputEscapingEnabled();
        $previousTempDir = PhpWordSettings::getTempDir();
        PhpWordSettings::setOutputEscapingEnabled(true);
        PhpWordSettings::setTempDir($context->temporaryDirectory);

        try {
            $this->phpWord = new PhpWord();
            $this->configureDocument($context);
            $this->section = $this->phpWord->addSection($this->sectionStyle($context));
            $this->addHeadersAndFooters($context);

            $this->runCallbacks($context->document->getBeforeRender('docx'), $this->phpWord, $context);

            foreach ($context->document->schema() as $component) {
                $this->renderComponent($component, $context, $this->section, null);
            }

            $this->runCallbacks($context->document->getAfterRender('docx'), $this->phpWord, $context);

            IOFactory::createWriter($this->phpWord, 'Word2007')->save($path);
        } finally {
            PhpWordSettings::setOutputEscapingEnabled($previousEscaping);
            PhpWordSettings::setTempDir($previousTempDir);
        }
    }

    protected function configureDocument(RenderContext $context): void
    {
        $settings = $context->settings;
        $default = $settings->getDefaultStyle();

        if ($default->fontFamily !== null) {
            $this->phpWord->setDefaultFontName($default->fontFamily);
        }
        if ($default->fontSize !== null) {
            $this->phpWord->setDefaultFontSize($default->fontSize);
        }

        $info = $this->phpWord->getDocInfo();
        $info->setTitle($context->text($settings->getTitle()));
        $info->setCreator($context->text($settings->getAuthor()));
        $info->setSubject($context->text($settings->getSubject()));
        $info->setKeywords($context->text($settings->getKeywords()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sectionStyle(RenderContext $context): array
    {
        $settings = $context->settings;
        $margins = $settings->getMargins();
        [$width, $height] = $settings->getPaper()->dimensionsFor($settings->getOrientation());

        return [
            'orientation'  => $settings->isLandscape() ? 'landscape' : 'portrait',
            'pageSizeW'    => Units::mmToTwip($width),
            'pageSizeH'    => Units::mmToTwip($height),
            'marginTop'    => Units::mmToTwip($margins->top),
            'marginRight'  => Units::mmToTwip($margins->right),
            'marginBottom' => Units::mmToTwip($margins->bottom),
            'marginLeft'   => Units::mmToTwip($margins->left),
            'headerHeight' => Units::mmToTwip($margins->header),
            'footerHeight' => Units::mmToTwip($margins->footer),
        ];
    }

    protected function addHeadersAndFooters(RenderContext $context): void
    {
        $settings = $context->settings;

        foreach ([
            [$settings->getHeader(), Footer::AUTO, true],
            [$settings->getFooter(), Footer::AUTO, false],
            [$settings->getFirstPageHeader(), Footer::FIRST, true],
            [$settings->getFirstPageFooter(), Footer::FIRST, false],
        ] as [$headerFooter, $type, $isHeader]) {
            $components = $type === Footer::AUTO ? ($isHeader ? $settings->getHeaderContent() : $settings->getFooterContent()) : [];

            if ($headerFooter === null && $components === []) {
                continue;
            }

            $container = $isHeader ? $this->section->addHeader($type) : $this->section->addFooter($type);

            if ($isHeader) {
                $this->writeComponents($container, $components, $context);
            }
            if ($headerFooter !== null && ! $headerFooter->isEmpty()) {
                $this->writeHeaderFooter($container, $headerFooter, $context);
            }
            if (! $isHeader) {
                $this->writeComponents($container, $components, $context);
            }
        }
    }

    /**
     * @param list<Component> $components
     */
    protected function writeComponents(AbstractContainer $container, array $components, RenderContext $context): void
    {
        foreach ($components as $component) {
            $this->renderComponent($component, $context, $container, null);
        }
    }

    protected function writeHeaderFooter(AbstractContainer $container, HeaderFooter $headerFooter, RenderContext $context): void
    {
        $style = $context->settings->getDefaultStyle()->merge($headerFooter->style);
        $tokens = ['page' => '{PAGE}', 'pages' => '{NUMPAGES}', 'date' => date('d/m/Y')];
        $table = $container->addTable(['width' => 5000, 'unit' => TblWidth::PERCENT, 'layout' => TableStyle::LAYOUT_FIXED]);
        $row = $table->addRow();

        foreach ($headerFooter->slots() as $slot => $template) {
            $cell = $row->addCell(1667, ['unit' => TblWidth::PERCENT]);
            $text = $context->text($template, $tokens);
            $paragraph = ['alignment' => $this->alignment(Align::from($slot)), 'spaceAfter' => 0];

            str_contains($text, '{PAGE}') || str_contains($text, '{NUMPAGES}')
                ? $cell->addPreserveText($text, $this->fontStyle($style), $paragraph)
                : $cell->addText($text, $this->fontStyle($style), $paragraph);
        }
    }

    protected function renderHeading(Heading $heading, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        $style = $context->style($heading, $inherited);
        $run = $container->addTextRun($this->paragraphStyle($style, keepNext: true));
        $this->addLines($run, $context->text($heading->getText()), $this->fontStyle($style));
        $this->finish($heading, $context, $run, $container);
    }

    protected function renderText(Text $text, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        $style = $context->style($text, $inherited);
        $paragraph = $container->addTextRun($this->paragraphStyle($style));

        foreach ($text->getRuns() as $run) {
            $font = $this->fontStyle($style->merge($this->runStyle($run, $context)));
            $content = $context->text($run->getText());

            $run->getUrl() !== null
                ? $paragraph->addLink($context->text($run->getUrl()), $content, $font)
                : $this->addLines($paragraph, $content, $font);
        }

        $this->finish($text, $context, $paragraph, $container);
    }

    protected function renderTable(Table $table, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        $style = $context->style($table, $inherited);
        $columns = $table->getColumns();
        $widths = $table->getWidthPercentages();
        $border = $table->getCellBorder();
        $headerStyle = $style->merge($table->getHeaderStyle());
        $rows = new TableRows($table, $context->data);

        $word = $container->addTable($this->tableStyle($border, $style->padding ?? 1));
        $cellWidth = fn (int $position): int => (int) round($widths[$position] * 50);

        if ($table->shouldShowHeader()) {
            $rowStyle = ['tblHeader' => $table->shouldRepeatHeader(), 'cantSplit' => true];

            $header = TableHeader::of($table);
            // Word merges vertically with a "restart" cell and "continue" cells below it, one per covered row.
            $continuations = [];

            foreach ($header->rows as $r => $entries) {
                $wordRow = $word->addRow(null, $rowStyle);
                $cells = [];

                foreach ($entries as ['cell' => $cell, 'position' => $position, 'colspan' => $colspan, 'rowspan' => $rowspan]) {
                    $cellStyle = $cell instanceof Column ? $this->columnHeaderStyle($style, $table, $cell) : $this->headerCellStyle($cell, $headerStyle, $context);
                    $cells[$position] = [$cell instanceof Column ? $cell->getLabel() : $cell->getText(), $cellStyle, $colspan, $rowspan > 1 ? 'restart' : null];
                    for ($y = $r + 1; $y < $r + $rowspan; $y++) {
                        $continuations[$y][$position] = [$cellStyle, $colspan];
                    }
                }
                foreach ($continuations[$r] ?? [] as $position => [$cellStyle, $colspan]) {
                    $cells[$position] = [null, $cellStyle, $colspan, 'continue'];
                }

                ksort($cells);
                foreach ($cells as $position => [$text, $cellStyle, $colspan, $vMerge]) {
                    $width = array_sum(array_map($cellWidth, range($position, min($position + $colspan, count($columns)) - 1)));
                    $wordCell = $wordRow->addCell($width, $this->cellStyle($cellStyle, $colspan, $vMerge));
                    if ($text !== null) {
                        $this->addCellText($wordCell, $context->text($text), $cellStyle);
                    }
                }
            }
        }

        $columnStyles = array_map(fn ($column): Style => $style->merge($column->getInlineStyle()), $columns);

        foreach ($rows->rows() as $row) {
            $stripe = $table->getStripeColor() !== null && $row->index % 2 === 1 ? Style::make()->background($table->getStripeColor()) : null;
            $wordRow = $word->addRow(null, ['cantSplit' => true]);
            foreach ($row->texts as $position => $text) {
                $cellStyle = $columnStyles[$position]->merge($stripe)->merge($row->styleFor($position));
                $span = $row->span($position);
                $wordCell = $wordRow->addCell($cellWidth($position), $this->cellStyle($cellStyle, vMerge: match (true) {
                    $span === 0 => 'continue',
                    $span > 1   => 'restart',
                    default     => null,
                }));
                if ($span !== 0) {
                    $this->addCellText($wordCell, $text, $cellStyle);
                }
            }
        }

        if ($rows->count() === 0 && $table->getEmptyText() !== '') {
            $cellStyle = $style->merge(Style::make()->align(Align::Center));
            $this->addCellText($word->addRow()->addCell(5000, $this->cellStyle($cellStyle, count($columns))), $context->text($table->getEmptyText()), $cellStyle);
        }

        if ($table->hasTotals()) {
            $totalStyle = $style->merge($table->getTotalStyle());
            $wordRow = $word->addRow(null, ['cantSplit' => true]);
            foreach ($rows->totalCells() as $position => $cell) {
                if ($cell === null) {
                    continue;
                }
                $text = $cell['label'] ? $context->text($table->getTotalLabel()) : $cell['text'];
                $cellStyle = $totalStyle->merge(Style::make()->align($columns[$position]->getInlineStyle()?->align ?? Align::Left));
                $width = array_sum(array_map($cellWidth, range($position, $position + $cell['colspan'] - 1)));
                $this->addCellText($wordRow->addCell($width, $this->cellStyle($cellStyle, $cell['colspan'])), $text, $cellStyle);
            }
        }

        $this->addSpacingAfter($container, $style);
        $this->finish($table, $context, $word, $container);
    }

    protected function renderKeyValue(KeyValue $keyValue, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        $style = $context->style($keyValue, $inherited);
        $labelStyle = $style->merge($keyValue->getLabelStyle());
        $table = $container->addTable($this->tableStyle(null, $style->padding ?? 0.5));
        $pairWidth = (int) round(5000 / $keyValue->getColumns());

        foreach (array_chunk($keyValue->getItems(), $keyValue->getColumns(), true) as $pair) {
            $row = $table->addRow();
            foreach ($pair as $label => $value) {
                $this->addCellText($row->addCell((int) round($pairWidth * 0.35), $this->cellStyle($labelStyle)), $context->text($label).$keyValue->getSeparator(), $labelStyle);
                $this->addCellText($row->addCell((int) round($pairWidth * 0.65), $this->cellStyle($style)), $context->text($value), $style);
            }
        }

        $this->addSpacingAfter($container, $style);
        $this->finish($keyValue, $context, $table, $container);
    }

    protected function renderListBlock(ListBlock $list, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        $style = $context->style($list, $inherited);
        $listStyle = ['listType' => $list->isOrdered() ? ListItem::TYPE_NUMBER_NESTED : ListItem::TYPE_BULLET_FILLED];

        $this->addListItems($container, $this->listItems($list, $context), 0, $listStyle, $style, $context);
        $this->addSpacingAfter($container, $style);
        $this->finish($list, $context, null, $container);
    }

    protected function renderImage(Image $image, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        $path = $context->imagePath($image->getSource());

        if ($path === null) {
            return;
        }

        $style = $context->style($image, $inherited);
        [$width, $height] = $this->imageSize($path, $image->getWidth(), $image->getHeight());

        $element = $container->addImage($path, [
            'width'     => Units::mmToPoint($width),
            'height'    => Units::mmToPoint($height),
            'alignment' => $this->alignment($style->align ?? Align::Left),
        ]);

        $this->finish($image, $context, $element, $container);
    }

    protected function renderColumns(Columns $columns, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        $style = $context->style($columns, $inherited);
        $table = $container->addTable($this->tableStyle(null, 0));
        $row = $table->addRow();

        foreach ($columns->getBlocks() as $position => $block) {
            $blockStyle = $context->style($block, $style);
            $cell = $row->addCell((int) round($columns->getWidthPercentages()[$position] * 50), $this->cellStyle($blockStyle->merge(Style::make()->padding(0))));

            foreach ($block->getChildren() as $child) {
                $this->renderComponent($child, $context, $cell, $blockStyle);
            }

            if ($block->getChildren() === []) {
                $cell->addText('');
            }
        }

        $this->addSpacingAfter($container, $style);
        $this->finish($columns, $context, $table, $container);
    }

    protected function renderGroup(Group $group, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        $style = $context->style($group, $inherited);

        foreach ($group->getChildren() as $child) {
            $this->renderComponent($child, $context, $container, $style);
        }

        $this->finish($group, $context, null, $container);
    }

    protected function renderSpacer(Spacer $spacer, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        $container->addText('', ['size' => 1], ['spaceBefore' => 0, 'spaceAfter' => Units::mmToTwip($spacer->getHeight()), 'lineHeight' => 1.0]);
        $this->finish($spacer, $context, null, $container);
    }

    protected function renderDivider(Divider $divider, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        $style = $context->style($divider, $inherited);
        $border = $style->border ?? new Border(0.5, '999999');

        $line = $container->addLine([
            'weight'      => max($border->width, 0.25),
            'width'       => Units::mmToPoint($context->settings->contentWidth()),
            'height'      => 0,
            'color'       => '#'.$border->color,
            'dash'        => $border->line === BorderLine::Dashed ? 'dash' : null,
        ]);

        $this->finish($divider, $context, $line, $container);
    }

    protected function renderPageBreak(PageBreak $break, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        if ($container instanceof Section) {
            $container->addPageBreak();
            $this->pageHasContent = false;
        }
    }

    protected function renderSheet(Sheet $sheet, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        if ($container instanceof Section && $this->pageHasContent) {
            $container->addPageBreak();
            $this->pageHasContent = false;
        }
    }

    protected function renderNative(Native $native, RenderContext $context, AbstractContainer $container, ?Style $inherited): void
    {
        if (($renderer = $native->getRenderer('docx')) !== null) {
            $renderer($container, $context);
            $this->pageHasContent = true;
        }
    }

    /**
     * Runs the component's native callbacks and records that content was written.
     */
    protected function finish(Component $component, RenderContext $context, mixed $element, AbstractContainer $container): void
    {
        $this->pageHasContent = true;
        $this->runCallbacks($component->getNativeCallbacks('docx'), $element, $container, $context);
    }

    /**
     * @param list<string|array{text: string, items?: list<mixed>}> $items
     * @param array<string, mixed> $listStyle
     */
    protected function addListItems(AbstractContainer $container, array $items, int $depth, array $listStyle, Style $style, RenderContext $context): void
    {
        foreach ($items as $item) {
            $text = is_array($item) ? (string) ($item['text'] ?? '') : (string) $item;
            $container->addListItem($context->text($text), $depth, $this->fontStyle($style), $listStyle, ['spaceAfter' => 0]);

            if (is_array($item) && ! empty($item['items'])) {
                $this->addListItems($container, $item['items'], $depth + 1, $listStyle, $style, $context);
            }
        }
    }

    protected function addLines(AbstractContainer $run, string $text, array $font): void
    {
        foreach (preg_split('/\R/u', $text) ?: [''] as $index => $line) {
            if ($index > 0) {
                $run->addTextBreak();
            }
            $run->addText($line, $font);
        }
    }

    protected function addCellText(AbstractContainer $cell, string $text, Style $style): void
    {
        $run = $cell->addTextRun(['alignment' => $this->alignment($style->align ?? Align::Left), 'spaceBefore' => 0, 'spaceAfter' => 0]);
        $this->addLines($run, $text, $this->fontStyle($style));
    }

    protected function addSpacingAfter(AbstractContainer $container, Style $style): void
    {
        if (($style->spaceAfter ?? 0) > 0) {
            $container->addText('', ['size' => 1], ['spaceBefore' => 0, 'spaceAfter' => Units::mmToTwip($style->spaceAfter), 'lineHeight' => 1.0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function tableStyle(?Border $border, float $padding): array
    {
        $style = [
            'width'      => 5000,
            'unit'       => TblWidth::PERCENT,
            'layout'     => TableStyle::LAYOUT_FIXED,
            'cellMargin' => Units::mmToTwip($padding),
        ];

        if ($border?->isVisible()) {
            $style['borderSize'] = $this->borderEighths($border);
            $style['borderColor'] = $border->color;
        }

        return $style;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param 'restart'|'continue'|null $vMerge vertical merge: the first cell of a merge, or a cell it covers
     */
    protected function cellStyle(Style $style, int $span = 1, ?string $vMerge = null): array
    {
        $cell = [
            'unit'   => TblWidth::PERCENT,
            'valign' => match ($style->verticalAlign ?? VerticalAlign::Top) {
                VerticalAlign::Top    => VerticalJc::TOP,
                VerticalAlign::Middle => VerticalJc::CENTER,
                VerticalAlign::Bottom => VerticalJc::BOTTOM,
            },
        ];

        if ($span > 1) {
            $cell['gridSpan'] = $span;
        }
        if ($vMerge !== null) {
            $cell['vMerge'] = $vMerge;
        }
        if ($style->background !== null) {
            $cell['bgColor'] = $style->background;
        }
        if ($style->border !== null) {
            $cell['borderSize'] = $style->border->isVisible() ? $this->borderEighths($style->border) : 0;
            $cell['borderColor'] = $style->border->color;
        }

        return $cell;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fontStyle(Style $style): array
    {
        return array_filter([
            'name'          => $style->fontFamily,
            'size'          => $style->fontSize,
            'bold'          => $style->bold,
            'italic'        => $style->italic,
            'underline'     => $style->underline === null ? null : ($style->underline ? 'single' : 'none'),
            'strikethrough' => $style->strike,
            'color'         => $style->color,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function paragraphStyle(Style $style, bool $keepNext = false): array
    {
        return array_filter([
            'alignment'   => $style->align !== null ? $this->alignment($style->align) : null,
            'spaceBefore' => Units::mmToTwip($style->spaceBefore ?? 0),
            'spaceAfter'  => Units::mmToTwip($style->spaceAfter ?? 0),
            'keepNext'    => $keepNext ?: null,
            'lineHeight'  => $style->lineHeight,
            'shading'     => $style->background !== null ? ['fill' => $style->background] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    protected function alignment(Align $align): string
    {
        return match ($align) {
            Align::Left    => Jc::LEFT,
            Align::Center  => Jc::CENTER,
            Align::Right   => Jc::RIGHT,
            Align::Justify => Jc::BOTH,
        };
    }

    /**
     * OOXML border sizes are in eighths of a point.
     */
    protected function borderEighths(Border $border): int
    {
        return max(2, (int) round($border->width * 8));
    }
}
