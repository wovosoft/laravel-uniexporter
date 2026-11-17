<?php

namespace Wovosoft\LaravelUniexporter\Drivers\OpenSpout;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\ImageCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Cell\TextRunCell;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border as SpoutBorder;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style as SpoutStyle;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\HeaderFooter as SpoutHeaderFooter;
use OpenSpout\Writer\XLSX\Options\PageMargin;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;
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
use Wovosoft\LaravelUniexporter\Components\ValueFormat;
use Wovosoft\LaravelUniexporter\Drivers\AbstractDriver;
use Wovosoft\LaravelUniexporter\Drivers\RenderContext;
use Wovosoft\LaravelUniexporter\HeaderFooter;
use Wovosoft\LaravelUniexporter\Style\Align;
use Wovosoft\LaravelUniexporter\Style\Border;
use Wovosoft\LaravelUniexporter\Style\BorderLine;
use Wovosoft\LaravelUniexporter\Style\Paper;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Style\VerticalAlign;
use Wovosoft\LaravelUniexporter\Support\TableHeader;
use Wovosoft\LaravelUniexporter\Support\TableRows;
use Wovosoft\LaravelUniexporter\Support\Units;

/**
 * XLSX through OpenSpout. Rows stream to the file with constant memory (openToFile, never
 * openToBrowser). Content flows down the sheet: headings and text become rows merged across
 * the sheet's width, tables become header, data and totals rows with native number and date
 * formats. Page breaks are ignored; Sheet starts a new worksheet.
 *
 * Native callbacks: a component's ->native('xlsx', fn (Writer $writer, \OpenSpout\Writer\Common\Entity\Sheet $sheet, array $rows, RenderContext $context))
 * gets the 1-based first and last row it wrote (['from' => int, 'to' => int]). Native components get
 * (Writer $writer, RenderContext $context). Document hooks get (Writer $writer, RenderContext $context).
 *
 * Driver options: 'options' (named arguments for OpenSpout\Writer\XLSX\Options).
 */
class OpenSpoutDriver extends AbstractDriver
{
    private const string FALLBACK_FONT = 'Calibri';

    private Writer $writer;

    private Options $spoutOptions;

    /**
     * @var array<string, SpoutStyle> one OpenSpout style per distinct style (OpenSpout registers styles per object)
     */
    private array $styles = [];

    /**
     * @var list<int> merged width (in columns) of each worksheet section, from a pre-scan of the schema
     */
    private array $sectionSpans = [];

    private int $sheetIndex = 0;

    private int $rowCount = 0;

    private bool $columnWidthsSet = false;

    private bool $printTitlesSet = false;

    private bool $headerFrozen = false;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private readonly array $options = []) {}

    public function extension(): string
    {
        return 'xlsx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function render(RenderContext $context, string $path): void
    {
        $this->ensureInstalled(Writer::class, 'openspout/openspout');

        $components = $context->document->schema();
        $this->sectionSpans = $this->scanSections($components, $context);
        $this->spoutOptions = new Options(...$this->writerOptions($context));
        $this->writer = new Writer($this->spoutOptions);
        $this->writer->openToFile($path);

        try {
            $this->runCallbacks($context->document->getBeforeRender('xlsx'), $this->writer, $context);

            foreach ($components as $component) {
                $this->renderComponent($component, $context, null);
            }

            $this->runCallbacks($context->document->getAfterRender('xlsx'), $this->writer, $context);
        } finally {
            $this->writer->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function writerOptions(RenderContext $context): array
    {
        $settings = $context->settings;
        $margins = $settings->getMargins();

        return array_replace([
            'FALLBACK_STYLE' => $this->spoutStyle($settings->getDefaultStyle()),
            'tempFolder'     => $context->temporaryDirectory,
            'pageMargin'     => new PageMargin(
                Units::mmToInch($margins->top),
                Units::mmToInch($margins->right),
                Units::mmToInch($margins->bottom),
                Units::mmToInch($margins->left),
                Units::mmToInch($margins->header),
                Units::mmToInch($margins->footer),
            ),
            'pageSetup'      => new PageSetup(
                $settings->isLandscape() ? PageOrientation::LANDSCAPE : PageOrientation::PORTRAIT,
                $this->paperSize($settings->getPaper()),
                fitToHeight: $settings->shouldFitToWidth() ? 0 : null,
                fitToWidth: $settings->shouldFitToWidth() ? 1 : null,
            ),
            'headerFooter'   => $this->headerFooter($context),
            'properties'     => new Properties(
                title: $context->text($settings->getTitle()) ?: null,
                subject: $context->text($settings->getSubject()) ?: null,
                application: 'Laravel Uniexporter',
                creator: $context->text($settings->getAuthor()) ?: null,
                keywords: $context->text($settings->getKeywords()) ?: null,
            ),
        ], (array) ($this->options['options'] ?? []));
    }

    protected function renderHeading(Heading $heading, RenderContext $context, ?Style $inherited): void
    {
        $style = $context->style($heading, $inherited);
        $from = $this->rowCount + 1;
        $this->writeMergedRow(new StringCell($context->text($heading->getText()), $this->spoutStyle($style)), $this->rowHeight($style));
        $this->finish($heading, $context, $from);
    }

    protected function renderText(Text $text, RenderContext $context, ?Style $inherited): void
    {
        $style = $context->style($text, $inherited);
        $runs = $text->getRuns();
        $from = $this->rowCount + 1;

        if (count($runs) === 1 && $runs[0]->getInlineStyle() === null && $runs[0]->getStyleName() === null) {
            $value = $context->text($runs[0]->getText());
            $cell = $runs[0]->getUrl() !== null
                ? Cell::fromValue($value, $this->spoutStyle($style), null, $context->text($runs[0]->getUrl()))
                : new StringCell($value, $this->spoutStyle($style));
        } else {
            $textRuns = array_map(function ($run) use ($style, $context): TextRun {
                $runStyle = $style->merge($this->runStyle($run, $context));

                return new TextRun(
                    $context->text($run->getText()),
                    (int) round($runStyle->fontSize ?? 11),
                    $runStyle->color ?? '000000',
                    $runStyle->fontFamily ?? self::FALLBACK_FONT,
                    (bool) $runStyle->bold,
                    (bool) $runStyle->italic,
                );
            }, $runs);
            $cell = new TextRunCell($textRuns, $this->spoutStyle($style), null);
        }

        $this->writeMergedRow($cell, $this->rowHeight($style));
        $this->finish($text, $context, $from);
    }

    protected function renderTable(Table $table, RenderContext $context, ?Style $inherited): void
    {
        $style = $context->style($table, $inherited);
        $columns = $table->getColumns();
        $border = $table->getCellBorder();
        $headerStyle = $style->merge($table->getHeaderStyle());
        $rows = new TableRows($table, $context->data);
        $from = $this->rowCount + 1;

        $this->applyColumnWidths($table, $context);

        if ($table->shouldShowHeader()) {
            $headerFrom = $this->rowCount + 1;

            $header = TableHeader::of($table);
            $coverStyles = [];

            foreach ($header->rows as $r => $entries) {
                $cells = [];
                $sheetRow = $this->rowCount + 1;

                foreach ($entries as ['cell' => $cell, 'position' => $position, 'colspan' => $colspan, 'rowspan' => $rowspan]) {
                    $isLabel = $cell instanceof Column;
                    $cellStyle = $this->spoutStyle($isLabel
                        ? $this->columnHeaderStyle($style, $table, $cell, Style::make()->wrap())
                        : $this->headerCellStyle($cell, $headerStyle, $context)->merge(Style::make()->wrap()), $border);
                    $cells[$position] = new StringCell($context->text($isLabel ? $cell->getLabel() : $cell->getText()), $cellStyle);

                    // Cells under a span stay empty but keep the style, so borders and fill run across the merge.
                    for ($x = $position; $x < $position + $colspan; $x++) {
                        $coverStyles[$x] = $cellStyle;
                        if ($x > $position) {
                            $cells[$x] = new EmptyCell(null, $cellStyle);
                        }
                    }
                    if ($colspan > 1 || $rowspan > 1) {
                        $this->mergeRange($position, $sheetRow, $position + $colspan - 1, $sheetRow + $rowspan - 1);
                    }
                }

                foreach ($header->covered[$r] as $position) {
                    $cells[$position] = new EmptyCell(null, $coverStyles[$position] ?? null);
                }

                ksort($cells);
                $this->addRow($this->fillGaps($cells));
            }

            if ($table->shouldRepeatHeader() && ! $this->printTitlesSet) {
                $this->writer->getCurrentSheet()->setPrintTitleRows('$'.$headerFrom.':$'.$this->rowCount);
                $this->printTitlesSet = true;
            }

            if ($table->shouldFreezeHeader() && ! $this->headerFrozen) {
                $this->writer->getCurrentSheet()->setSheetView((new SheetView())->withFreezeRow($this->rowCount + 1));
                $this->headerFrozen = true;
            }
        }

        // Body cells wrap and sit at the top, as table cells do in the PDF and DOCX output, unless the table or column says otherwise.
        $bodyStyle = Style::make()->wrap()->verticalAlign(VerticalAlign::Top)->merge($style);
        $columnStyles = array_map(fn ($column): Style => $bodyStyle->merge($column->getInlineStyle()), $columns);

        foreach ($rows->rows() as $row) {
            $stripe = $table->getStripeColor() !== null && $row->index % 2 === 1 ? Style::make()->background($table->getStripeColor()) : null;
            $cells = [];
            $sheetRow = $this->rowCount + 1;
            foreach ($columns as $position => $column) {
                $cellStyle = $columnStyles[$position]->merge($stripe)->merge($row->styleFor($position));
                $span = $row->span($position);
                $cells[] = $span === 0
                    ? new EmptyCell(null, $this->spoutStyle($cellStyle, $border))
                    : $this->valueCell($row->values[$position], $row->texts[$position], $column->getFormat(), $cellStyle, $border);
                if ($span > 1) {
                    $this->mergeRange($position, $sheetRow, $position, $sheetRow + $span - 1);
                }
            }
            $this->addRow($cells);
        }

        if ($rows->count() === 0 && $table->getEmptyText() !== '') {
            $cellStyle = $this->spoutStyle($style->merge(Style::make()->align(Align::Center)), $border);
            $cells = [new StringCell($context->text($table->getEmptyText()), $cellStyle)];
            for ($position = 1; $position < count($columns); $position++) {
                $cells[] = new EmptyCell(null, $cellStyle);
            }
            $this->merge(0, count($columns) - 1, $this->rowCount + 1);
            $this->addRow($cells);
        }

        if ($table->hasTotals()) {
            $totalStyle = $style->merge($table->getTotalStyle());
            $labelColumn = $rows->totalLabelColumn();
            $cells = [];
            foreach ($rows->totals() as $position => $total) {
                $column = $columns[$position];
                $cellStyle = $totalStyle->merge(Style::make()->align($column->getInlineStyle()?->align ?? Align::Left));
                $cells[] = match (true) {
                    $total !== null            => $this->valueCell($total, $column->getFormat()->toText($total), $column->getFormat(), $cellStyle, $border),
                    $position === $labelColumn => new StringCell($context->text($table->getTotalLabel()), $this->spoutStyle($cellStyle, $border)),
                    default                    => new EmptyCell(null, $this->spoutStyle($cellStyle, $border)),
                };
            }
            $this->addRow($cells);
        }

        $this->finish($table, $context, $from);
    }

    protected function renderKeyValue(KeyValue $keyValue, RenderContext $context, ?Style $inherited): void
    {
        $style = $context->style($keyValue, $inherited);
        $labelStyle = $this->spoutStyle($style->merge($keyValue->getLabelStyle()));
        $valueStyle = $this->spoutStyle($style);
        $from = $this->rowCount + 1;

        foreach (array_chunk($keyValue->getItems(), $keyValue->getColumns(), true) as $pair) {
            $cells = [];
            foreach ($pair as $label => $value) {
                $cells[] = new StringCell($context->text($label).$keyValue->getSeparator(), $labelStyle);
                $cells[] = new StringCell($context->text($value), $valueStyle);
            }
            $this->addRow($cells);
        }

        $this->finish($keyValue, $context, $from);
    }

    protected function renderListBlock(ListBlock $list, RenderContext $context, ?Style $inherited): void
    {
        $style = $this->spoutStyle($context->style($list, $inherited));
        $from = $this->rowCount + 1;

        $this->writeListItems($this->listItems($list, $context), $list->isOrdered(), 0, $style, $context);
        $this->finish($list, $context, $from);
    }

    protected function renderImage(Image $image, RenderContext $context, ?Style $inherited): void
    {
        $path = $context->imagePath($image->getSource());

        if ($path === null) {
            return;
        }

        [$width, $height] = $this->imageSize($path, $image->getWidth(), $image->getHeight());
        $from = $this->rowCount + 1;

        $this->writer->addRow(new Row(
            [new ImageCell($path, Units::mmToPixel($width), Units::mmToPixel($height))],
            Units::mmToPoint($height) + 2,
        ));
        $this->rowCount++;
        $this->finish($image, $context, $from);
    }

    protected function renderColumns(Columns $columns, RenderContext $context, ?Style $inherited): void
    {
        $style = $context->style($columns, $inherited);
        $blocks = $columns->getBlocks();
        $span = max($this->currentSpan(), count($blocks));
        $cells = [];
        $from = $this->rowCount + 1;

        foreach ($blocks as $position => $block) {
            $start = intdiv($position * $span, count($blocks));
            $end = intdiv(($position + 1) * $span, count($blocks)) - 1;
            $blockStyle = $this->spoutStyle($context->style($block, $style)->merge(Style::make()->wrap()->verticalAlign(VerticalAlign::Top)));

            $cells[$start] = new StringCell($this->plainText($block, $context), $blockStyle);
            for ($column = $start + 1; $column <= $end; $column++) {
                $cells[$column] = new EmptyCell(null, $blockStyle);
            }
            if ($end > $start) {
                $this->merge($start, $end, $this->rowCount + 1);
            }
        }

        $this->addRow($cells);
        $this->finish($columns, $context, $from);
    }

    protected function renderGroup(Group $group, RenderContext $context, ?Style $inherited): void
    {
        $style = $context->style($group, $inherited);
        $from = $this->rowCount + 1;

        foreach ($group->getChildren() as $child) {
            $this->renderComponent($child, $context, $style);
        }

        $this->finish($group, $context, $from);
    }

    protected function renderSpacer(Spacer $spacer, RenderContext $context, ?Style $inherited): void
    {
        $this->addRow([], Units::mmToPoint($spacer->getHeight()));
    }

    protected function renderDivider(Divider $divider, RenderContext $context, ?Style $inherited): void
    {
        $border = $context->style($divider, $inherited)->border ?? new Border(0.5, '999999');
        $style = $this->cachedStyle('divider.'.$border->color.$border->width, fn (): SpoutStyle => new SpoutStyle(
            fontSize: 11,
            fontName: self::FALLBACK_FONT,
            border: new SpoutBorder(new BorderPart(BorderName::BOTTOM, $border->color, $this->borderWidth($border), $this->borderStyle($border))),
        ));

        $this->addRow(array_map(fn (): EmptyCell => new EmptyCell(null, $style), range(1, $this->currentSpan())));
    }

    protected function renderPageBreak(PageBreak $break, RenderContext $context, ?Style $inherited): void
    {
        // Spreadsheets have no page flow; use Sheet for a new worksheet.
    }

    protected function renderSheet(Sheet $sheet, RenderContext $context, ?Style $inherited): void
    {
        if ($this->rowCount > 0) {
            $this->writer->addNewSheetAndMakeItCurrent();
            $this->sheetIndex++;
            $this->rowCount = 0;
            $this->columnWidthsSet = false;
            $this->printTitlesSet = false;
            $this->headerFrozen = false;
        }

        if ($sheet->getName() !== '') {
            $this->writer->getCurrentSheet()->setName($this->sheetName($context->text($sheet->getName())));
        }
    }

    protected function renderNative(Native $native, RenderContext $context, ?Style $inherited): void
    {
        if (($renderer = $native->getRenderer('xlsx')) !== null) {
            $renderer($this->writer, $context);
            $this->rowCount = $this->writer->getCurrentSheet()->getWrittenRowCount();
        }
    }

    protected function finish(Component $component, RenderContext $context, int $from): void
    {
        $this->runCallbacks(
            $component->getNativeCallbacks('xlsx'),
            $this->writer,
            $this->writer->getCurrentSheet(),
            ['from' => $from, 'to' => max($from, $this->rowCount)],
            $context,
        );
    }

    /**
     * @param array<int, Cell> $cells
     */
    protected function addRow(array $cells, ?float $height = null): void
    {
        ksort($cells);
        $this->writer->addRow(new Row($cells, $height ?? Row::DEFAULT_HEIGHT));
        $this->rowCount++;
    }

    protected function writeMergedRow(Cell $cell, ?float $height = null): void
    {
        $span = $this->currentSpan();
        $cells = [$cell];

        if ($span > 1) {
            for ($column = 1; $column < $span; $column++) {
                $cells[] = new EmptyCell(null, $cell->style);
            }
            $this->merge(0, $span - 1, $this->rowCount + 1);
        }

        $this->addRow($cells, $height);
    }

    /**
     * Merges 0-based columns on a 1-based row of the current sheet.
     */
    protected function merge(int $fromColumn, int $toColumn, int $row): void
    {
        $this->spoutOptions->mergeCells($fromColumn, $row, $toColumn, $row, $this->sheetIndex);
    }

    /**
     * Merges a rectangle of cells (0-based columns, 1-based rows) on the current sheet.
     */
    protected function mergeRange(int $fromColumn, int $fromRow, int $toColumn, int $toRow): void
    {
        $this->spoutOptions->mergeCells($fromColumn, $fromRow, $toColumn, $toRow, $this->sheetIndex);
    }

    /**
     * @param array<int, Cell> $cells keyed by column position
     * @return list<Cell> with empty cells in any gaps
     */
    protected function fillGaps(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        $filled = [];
        for ($position = 0, $last = max(array_keys($cells)); $position <= $last; $position++) {
            $filled[] = $cells[$position] ?? new EmptyCell(null, null);
        }

        return $filled;
    }

    /**
     * @param list<string|array{text: string, items?: list<mixed>}> $items
     */
    protected function writeListItems(array $items, bool $ordered, int $depth, SpoutStyle $style, RenderContext $context): void
    {
        foreach (array_values($items) as $position => $item) {
            $text = is_array($item) ? (string) ($item['text'] ?? '') : (string) $item;
            $marker = $ordered ? ($position + 1).'. ' : '• ';
            $this->writeMergedRow(new StringCell(str_repeat('    ', $depth).$marker.$context->text($text), $style));

            if (is_array($item) && ! empty($item['items'])) {
                $this->writeListItems($item['items'], $ordered, $depth + 1, $style, $context);
            }
        }
    }

    /**
     * Sets the sheet's column widths from the first table on it, scaled to the printable width.
     *
     * Columns with Column::characters() keep that width.
     */
    protected function applyColumnWidths(Table $table, RenderContext $context): void
    {
        if ($this->columnWidthsSet) {
            return;
        }

        $total = Units::mmToExcelWidth($context->settings->contentWidth());
        $sheet = $this->writer->getCurrentSheet();
        $columns = $table->getColumns();

        foreach ($table->getWidthPercentages() as $position => $percentage) {
            $width = $columns[$position]->getCharacters() ?? max(4, round($total * $percentage / 100, 2));
            $sheet->setColumnWidth($width, $position + 1);
        }

        $this->columnWidthsSet = true;
    }

    protected function valueCell(mixed $value, string $text, ValueFormat $format, Style $style, ?Border $border): Cell
    {
        $typed = $format->toSpreadsheetValue($value);
        $numberFormat = is_int($typed) || is_float($typed) || $typed instanceof DateTimeInterface ? $format->spreadsheetFormat() : null;
        $spoutStyle = $this->spoutStyle($style, $border, $numberFormat);

        return match (true) {
            $typed === null                                       => $text === '' ? new EmptyCell(null, $spoutStyle) : new StringCell($text, $spoutStyle),
            is_int($typed), is_float($typed), $typed instanceof DateTimeInterface => Cell::fromValue($typed, $spoutStyle),
            // Strings are always text cells, so values starting with "=" never become formulas.
            default                                               => new StringCell((string) $typed, $spoutStyle),
        };
    }

    /**
     * One OpenSpout style per distinct (style, border, number format), always with an explicit
     * font, since OpenSpout does not merge cell styles with the fallback style.
     */
    protected function spoutStyle(Style $style, ?Border $border = null, ?string $numberFormat = null): SpoutStyle
    {
        $border = $style->border ?? $border;

        return $this->cachedStyle($style->key().'|'.serialize($border).'|'.$numberFormat, fn (): SpoutStyle => new SpoutStyle(
            fontBold: (bool) $style->bold,
            fontItalic: (bool) $style->italic,
            fontUnderline: (bool) $style->underline,
            fontStrikethrough: (bool) $style->strike,
            fontSize: (int) round($style->fontSize ?? 11),
            fontColor: $style->color ?? '000000',
            fontName: $style->fontFamily ?? self::FALLBACK_FONT,
            cellAlignment: match ($style->align) {
                Align::Left    => CellAlignment::LEFT,
                Align::Center  => CellAlignment::CENTER,
                Align::Right   => CellAlignment::RIGHT,
                Align::Justify => CellAlignment::JUSTIFY,
                null           => null,
            },
            cellVerticalAlignment: match ($style->verticalAlign) {
                VerticalAlign::Top    => CellVerticalAlignment::TOP,
                VerticalAlign::Middle => CellVerticalAlignment::CENTER,
                VerticalAlign::Bottom => CellVerticalAlignment::BOTTOM,
                null                  => null,
            },
            shouldWrapText: $style->wrap,
            border: $border?->isVisible() ? $this->allSides($border) : null,
            backgroundColor: $style->background,
            format: $numberFormat,
        ));
    }

    /**
     * @param callable(): SpoutStyle $factory
     */
    protected function cachedStyle(string $key, callable $factory): SpoutStyle
    {
        return $this->styles[$key] ??= $factory();
    }

    protected function allSides(Border $border): SpoutBorder
    {
        $width = $this->borderWidth($border);
        $line = $this->borderStyle($border);

        return new SpoutBorder(...array_map(
            fn (BorderName $side): BorderPart => new BorderPart($side, $border->color, $width, $line),
            [BorderName::TOP, BorderName::RIGHT, BorderName::BOTTOM, BorderName::LEFT],
        ));
    }

    protected function borderWidth(Border $border): BorderWidth
    {
        return match (true) {
            $border->width <= 0.75 => BorderWidth::THIN,
            $border->width <= 1.5  => BorderWidth::MEDIUM,
            default                => BorderWidth::THICK,
        };
    }

    protected function borderStyle(Border $border): BorderStyle
    {
        return match ($border->line) {
            BorderLine::Solid  => BorderStyle::SOLID,
            BorderLine::Dashed => BorderStyle::DASHED,
            BorderLine::Dotted => BorderStyle::DOTTED,
            BorderLine::Double => BorderStyle::DOUBLE,
            BorderLine::None   => BorderStyle::NONE,
        };
    }

    protected function headerFooter(RenderContext $context): ?SpoutHeaderFooter
    {
        $header = $context->settings->getHeader();
        $footer = $context->settings->getFooter();

        if ($header === null && $footer === null) {
            return null;
        }

        return new SpoutHeaderFooter(
            oddHeader: $header ? $this->headerFooterCode($header, $context) : null,
            oddFooter: $footer ? $this->headerFooterCode($footer, $context) : null,
        );
    }

    /**
     * Builds an Excel header/footer code (&L…&C…&R…). Literal "&" is doubled, page tokens become
     * &P, &N and &D, and the result is XML-escaped (OpenSpout writes it unescaped).
     */
    protected function headerFooterCode(HeaderFooter $headerFooter, RenderContext $context): string
    {
        $placeholders = ['page' => "\u{E000}P", 'pages' => "\u{E000}N", 'date' => "\u{E000}D"];
        $size = (int) round($headerFooter->style->fontSize ?? 8);
        $code = '';

        foreach (['left' => '&L', 'center' => '&C', 'right' => '&R'] as $slot => $prefix) {
            $template = $headerFooter->slots()[$slot];

            if ($template === '') {
                continue;
            }

            $text = str_replace('&', '&&', $context->text($template, $placeholders));
            $code .= $prefix.'&'.$size.' '.str_replace("\u{E000}", '&', $text);
        }

        return htmlspecialchars($code, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected function paperSize(Paper $paper): PaperSize
    {
        return match ($paper) {
            Paper::A3     => PaperSize::A3,
            Paper::A4     => PaperSize::A4,
            Paper::A5     => PaperSize::A5,
            Paper::B4     => PaperSize::B4,
            Paper::Letter => PaperSize::Letter,
            Paper::Legal  => PaperSize::Legal,
        };
    }

    protected function rowHeight(Style $style): ?float
    {
        return $style->fontSize !== null && $style->fontSize > 12 ? round($style->fontSize * 1.5, 1) : null;
    }

    protected function currentSpan(): int
    {
        return $this->sectionSpans[$this->sheetIndex] ?? 1;
    }

    /**
     * Excel sheet names: at most 31 characters, none of \ / ? * : [ ].
     */
    protected function sheetName(string $name): string
    {
        return mb_substr(str_replace(['\\', '/', '?', '*', ':', '[', ']'], ' ', $name), 0, 31) ?: 'Sheet'.($this->sheetIndex + 1);
    }

    /**
     * The widest component of each worksheet section, in columns, so headings and text can be
     * merged across the sheet before the tables below them are written.
     *
     * @param list<Component> $components
     * @return list<int>
     */
    protected function scanSections(array $components, RenderContext $context): array
    {
        $spans = [1];
        $section = 0;
        $hasRows = false;

        $measure = function (Component $component) use (&$measure, $context): int {
            if (! $component->isVisibleFor($context->format)) {
                return 0;
            }

            return match (true) {
                $component instanceof Table    => count($component->getColumns()),
                $component instanceof KeyValue => $component->getColumns() * 2,
                $component instanceof Columns  => count($component->getBlocks()),
                $component instanceof Group    => max([0, ...array_map($measure, $component->getChildren())]),
                default                        => 0,
            };
        };

        foreach ($components as $component) {
            if (! $component->isVisibleFor($context->format)) {
                continue;
            }

            if ($component instanceof Sheet) {
                if ($hasRows) {
                    $spans[++$section] = 1;
                }
                continue;
            }

            $hasRows = true;
            $spans[$section] = max($spans[$section], $measure($component));
        }

        return $spans;
    }

    /**
     * Text content of a container, one line per child, for cells that hold several components.
     */
    protected function plainText(Component $component, RenderContext $context): string
    {
        return match (true) {
            $component instanceof Heading  => $context->text($component->getText()),
            $component instanceof Text     => implode('', array_map(fn ($run): string => $context->text($run->getText()), $component->getRuns())),
            $component instanceof KeyValue => implode("\n", array_map(
                fn (string $label, string $value): string => $context->text($label).$component->getSeparator().' '.$context->text($value),
                array_keys($component->getItems()),
                $component->getItems(),
            )),
            $component instanceof ListBlock => implode("\n", array_map(
                fn (mixed $item): string => '• '.$context->text(is_array($item) ? (string) ($item['text'] ?? '') : (string) $item),
                $this->listItems($component, $context),
            )),
            default => implode("\n", array_filter(array_map(
                fn (Component $child): string => $child->isVisibleFor($context->format) ? $this->plainText($child, $context) : '',
                $component->getChildren(),
            ), fn (string $line): bool => $line !== '')),
        };
    }
}
