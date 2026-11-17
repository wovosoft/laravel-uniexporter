# laravel-uniexporter API reference

Generated from the source by `scripts/skill-reference.php`; do not edit by hand. Getters and driver internals are left out.
Methods return the object (fluent) unless noted. `{path}` placeholders in any text are filled from the data with dot notation.

## Document

`Document::make()`, or extend it and override `settings()`, `schema()`, `styles()`. Page settings called on a document are forwarded to `Settings` (see below).

- `::make()`
- `schema()`: The document's components. Override in subclasses; inline documents use components().
- `settings(Settings $settings)`: Page setup, defaults and metadata. Override in subclasses; inline documents use configure().
- `styles()`: Named styles that components reference with ->style('name'). Override in subclasses.
- `components(array $components)`
- `withData(Arrayable|Data|array $data)`: Attaches data to the document, so it exports without data(); later calls merge over earlier ones.
- `with(string $key, mixed $value)`: Attaches one value to the document's data: ->with('office', $office->name).
- `configure(Closure $configurator)`
- `withStyles(array $styles)`
- `beforeRender(string $format, Closure $callback)`: Runs before any content is rendered, with the driver's native document object (pdf: Mpdf, docx: PhpWord, xlsx: OpenSpout Writer) and the render context.
- `afterRender(string $format, Closure $callback)`: Runs after all content is rendered, before the file is written.
- `data(Arrayable|Data|array $data = [])`: Pairs the document with its data (merged over data attached with withData()/with()).
- `exportToPdf()`
- `exportToDocx()`
- `exportToExcel()`
- `exportToCsv()`
- `export(string $format)`

## Content methods

On `Document` and `Group`. Each appends a component and returns the container; the closure receives the new component.

- `add(Component ...$components)`: Appends the component(s) to this container.
- `heading(string $text, int $level = 1, ?Closure $tap = null)`
- `text(Closure|string $text = '', ?Closure $tap = null)`: A paragraph. Pass a closure instead of the text to build it from runs: ->text(fn (Text $text) => $text->run('Note: ', fn (Run $run) => $run->bold())->run('see the policy')).
- `link(string $text, string $url, ?Closure $tap = null)`
- `image(string $source, ?Closure $tap = null)`: An image from a path, a `{placeholder}` or raw bytes.
- `list(array $items = [], ?Closure $tap = null)`: A bulleted list (->ordered() for numbers); items may also come from data with ->source('path').
- `keyValue(array $items = [], ?Closure $tap = null)`: Label/value pairs, e.g. ['Month' => '{month}'], or added with ->item($label, $value).
- `table(Traversable|array|string $rows, ?Closure $build = null)`: A table of a data path or the rows themselves; the closure adds its columns: ->table('rows', fn (Table $table) => $table->column('name', 'Name')->column('salary', 'Salary', fn (Column $c) => $c->money()->total())).
- `columns(Closure $build)`: Blocks side by side; the closure adds them with ->block(): ->columns(fn (Columns $columns) => $columns->block(fn (Group $block) => $block->text('Prepared by'))->block(...)->widths([2, 1])).
- `group(Closure $build)`: A container whose text styles its children inherit; the closure fills it with the same API: ->group(fn (Group $group) => $group->heading('Signatures', 2)->text('...')->keepTogether()).
- `spacer(float $height = 5.0)`: Vertical space in millimetres.
- `divider(?Closure $tap = null)`
- `pageBreak()`
- `sheet(string $name = '')`: A new worksheet in spreadsheets, a new page elsewhere.
- `nativeContent(Closure $build)`: Content written with a driver's own library: ->nativeContent(fn (Native $native) => $native->for('pdf', fn (Mpdf $mpdf) => ...)).
- `each(iterable $items, Closure $callback)`: Calls the closure for each item, with this container, the item and its key, e.g. a table per office: ->each($offices, fn (Document $document, Office $office) => $document->heading($office->name)->table($office->employees, ...)).

## PendingExport

Returned by `$document->data($data)`.

- `using(UniexporterManager $manager)`: Uses a specific manager instead of the container's (useful outside Laravel and in tests).
- `exportToPdf()`
- `exportToDocx()`
- `exportToExcel()`
- `exportToCsv()`
- `export(string $format)`

## ExportedFile

A rendered temporary file. `Responsable` (download).

- `download(?string $filename = null)`
- `inline(?string $filename = null)`
- `store(string $path, Filesystem|string|null $disk = null)`: Writes the file to a filesystem disk and removes the temporary file.
- `save(string $path)`: Moves the file to a local path and returns it.
- `contents()`
- `path()`
- `extension()`
- `mimeType()`
- `filename(?string $name = null)`
- `delete()`

## ExportsByFormat (trait)

The using class implements `document($request, string $format): PendingExport` and calls `exportAs()` from its action; the per-format methods are protected (not routable).

- `exportAs(Request $request, string $format)`: Exports in `$format`: pdf, html, excel (or xlsx), docx, csv, or any format registered with Uniexporter::extend(). When the class declares a FORMATS constant, other formats are a 404 even if the route forgot its whereIn().
- `exportManager()`: The manager that renders the exports: the application's (the container's) by default.
- `pdf(Request $request)`
- `html(Request $request)`
- `excel(Request $request, string $format = 'excel')`
- `docx(Request $request)`
- `csv(Request $request)`

## Settings

Page setup, in `Document::settings()`, `configure()` or chained on the document. Lengths in millimetres.

- `paper(Paper|string $paper)`
- `orientation(Orientation|string $orientation)`
- `portrait()`
- `landscape()`
- `margins(Margins|float ...$margins)`: A Margins object, or 1/2/4 values in millimetres (CSS shorthand order).
- `font(string $family, ?float $size = null)`: Default font for the whole document. Use the family name as installed (e.g. 'Nikosh'); the PDF driver maps it to its lowercase font key.
- `fontSize(float $points)`
- `color(string $color)`
- `title(string $title)`
- `author(string $author)`
- `subject(string $subject)`
- `keywords(string $keywords)`
- `header(string $left = '', string $center = '', string $right = '', ?Style $style = null)`
- `footer(string $left = '', string $center = '', string $right = '', ?Style $style = null)`
- `firstPageHeader(string $left = '', string $center = '', string $right = '', ?Style $style = null)`: Header for the first page only; call without arguments to leave the first page blank.
- `firstPageFooter(string $left = '', string $center = '', string $right = '', ?Style $style = null)`
- `filename(string $filename)`: Default download name, without extension (the driver adds it).
- `native(string $format, array $options)`: Options passed straight to the driver for `$format`, merged over its config (e.g. mPDF constructor config for 'pdf', OpenSpout Options arguments for 'xlsx').
- `on(array|string $formats, Closure $adjust)`: Adjusts the settings for some formats only, e.g. no page header in spreadsheets: ->on('xlsx', fn (Settings $settings) => $settings->withoutHeader()).
- `headerContent(Closure|array $components)`: Components (a letterhead, a title line...) repeated at the top of every page, above any text header. PDF and DOCX repeat them per page; HTML shows them once at the top; spreadsheets skip them. The PDF top margin grows to fit them. A closure builds them with the content API: ->headerContent(fn (Group $header) => $header->text('Bank name')->text('{office}')).
- `footerContent(Closure|array $components)`: Components repeated at the bottom of every page (PDF, DOCX), shown once at the end in HTML.
- `watermark(string $imagePath, float $alpha = 0.2, ?float $width = null, ?float $height = null)`: An image behind the content of every page, centered (PDF and DOCX).
- `fitToWidth(bool $fit = true)`: Prints spreadsheets scaled to one page wide (as many pages tall as needed).
- `withoutHeader()`: Removes the page header, including the first-page one.
- `withoutFooter()`: Removes the page footer, including the first-page one.

## Component (every component)

Plus the style methods below.

- `::make(mixed ...$arguments)`
- `visibleOn(string ...$formats)`: Renders the component only for the given formats (e.g. 'pdf', 'docx').
- `hiddenOn(string ...$formats)`
- `on(array|string $formats, Closure $adjust)`: Adjusts the component for some formats only, on a copy, e.g. a table without its header row in spreadsheets: ->on('xlsx', fn (Table $table) => $table->showHeader(false)).
- `native(string $format, Closure $callback)`: Escape hatch: after rendering this component, the driver for `$format` calls the callback with the native object(s) it produced. See each driver for the arguments it passes.

## Style methods (every component, Run, Style)

Applied in order: default style, named style, inline style; children of a Group inherit text properties.

- `style(Style|Closure|string $style)`: Applies a named document style, a Style object, or a closure that receives and returns a Style.
- `font(string $family, ?float $size = null)`
- `size(float $points)`
- `bold(bool $bold = true)`
- `italic(bool $italic = true)`
- `underline(bool $underline = true)`
- `strike(bool $strike = true)`
- `color(string $color)`
- `background(string $color)`
- `align(Align|string $align)`
- `left()`
- `center()`
- `right()`
- `justify()`
- `verticalAlign(VerticalAlign|string $align)`
- `border(Border|float $border = 0.5, string $color = '000000', BorderLine $line = BorderLine::Solid)`
- `padding(float $millimetres)`
- `spaceBefore(float $millimetres)`
- `spaceAfter(float $millimetres)`
- `lineHeight(float $multiple)`: Line height as a multiple of the font size (e.g. 1.15).

## Heading

`Heading::make($text = '', $level = 1)`

- `text(string $text)`
- `level(int $level)`

## Text

`Text::make($text = '')`; `\n` starts a new line.

- `text(string $text)`
- `runs(array $runs)`
- `run(string $text, ?Closure $tap = null)`: Appends a run; the closure styles it: ->run('Note: ', fn (Run $run) => $run->bold()).

## Run

`Run::make($text)`: a styled piece of a Text; has the style methods and:

- `text(string $text)`
- `link(string $url)`: Makes the run a hyperlink. The URL is a template too.

## Link

`Link::make($text, $url)`


## Image

`Image::make($source)`: a path, a `{placeholder}` or raw bytes; sizes in mm.

- `source(string $source)`
- `width(float $millimetres)`
- `height(float $millimetres)`

## ListBlock

`ListBlock::make($items = [])`; items are strings or `['text' => ..., 'items' => [...]]`.

- `items(array $items)`
- `source(string $path)`: Reads the items from a data path (an iterable of strings), after any static items.
- `ordered(bool $ordered = true)`

## KeyValue

`KeyValue::make(['Label' => '{value}'])`

- `items(array $items)`
- `item(string $label, string $value)`
- `columns(int $columns)`: Number of label/value pairs per line.
- `labelStyle(Style $style)`
- `separator(string $separator)`: Text appended to each label (default ':'; '' for none).

## Table

`Table::make($rows)`: a data path or an iterable of arrays/objects/models.

- `rows(Traversable|array|string $rows)`
- `columns(array $columns)`
- `column(string $key = '', ?string $label = null, ?Closure $tap = null)`: Appends a column: ->column('salary', 'Salary', fn (Column $column) => $column->money()->total()). Without a label the header is the headline of the key; an empty key makes a computed column.
- `headerRow(array $cells)`: Appends a header row above the column labels (see headerRows()).
- `headerRows(array $rows)`: Extra header rows above the column labels; cells can span columns.
- `showHeader(bool $show = true)`
- `repeatHeader(bool $repeat = true)`: Repeats the header rows on every page (paged formats) or as print titles (spreadsheets).
- `striped(bool $striped = true, string $color = 'F7F7F7')`
- `borders(Border|bool $border = true)`: Cell borders; pass false for a borderless table.
- `headerStyle(Style|Closure $style)`
- `totalStyle(Style|Closure $style)`
- `totalLabel(string $label, int $colspan = 1)`: The totals row label, in the first column; $colspan widens it over the next columns (up to the first total).
- `totalFormulas(bool $formulas = true)`: Spreadsheets write numeric totals as SUM formulas over the body rows instead of values.
- `emptyText(string $text)`: Text shown in one full-width row when there are no rows.
- `rowStyleUsing(Closure $styler)`: Conditional row styling. Keep it pure: it receives the row and its 0-based index.
- `subRows(Closure|string $source)`: Splits each row into sub-rows, one per item of `$source` (a dot path into the row, or a closure receiving the row and its index). Columns marked fromSubRow() read the sub-row item; the other columns are written once and span the row's sub-rows. A row without items gets one sub-row.
- `autoWidths(bool $auto = true)`: Lets PDF and HTML size the columns from their content instead of the column weights. Spreadsheets and DOCX keep using the weights (or Column::characters()).
- `freezeHeader(bool $freeze = true)`: Keeps the header rows visible while scrolling a spreadsheet (the first frozen table on a sheet wins).

## Column

`Column::make($key = '')`: `$key` is a dot path into the row (`data_get`).

- `label(string $label)`
- `width(float $weight)`: Relative width: a column with width 2 is twice as wide as one with width 1.
- `characters(float $characters)`: A fixed spreadsheet column width in characters, instead of a share of the page width. Other formats keep using the weight.
- `fromSubRow(bool $fromSubRow = true)`: Reads the value from the sub-row item instead of the row (see Table::subRows()).
- `styleUsing(Closure $styler)`: Conditional style per cell, e.g. a long tenure in red. Receives the value (after formatUsing()) and the row (the sub-row item for fromSubRow() columns); returns a style or null.
- `text()`
- `number(int $decimals = 0, bool $thousands = true)`
- `money(int $decimals = 2, string $prefix = '')`
- `date(string $format = 'd/m/Y')`
- `formatUsing(Closure $formatter)`: Transforms the raw value before formatting. Keep it pure: it receives the value, the row and the export data, and must not fetch anything.
- `total(Closure|bool $total = true)`: Adds the column's sum to the table's totals row. A closure receives the sum and the data and returns what the totals cell shows: a number (formatted like the column) or a text.
- `formula(?string $formula)`: A formula for the column's cells in spreadsheets, e.g. '={days}*{rate}-{deduction}'. {key} is the cell of the column with that key in the same row, {row} the row number. Other formats show the column's value as usual.
- `rowNumber(bool $rowNumber = true)`: Shows the 1-based row number instead of a value from the row.
- `emptyValue(string $value)`: Text shown when the value is null or empty.
- `headerStyle(Style $style)`

## HeaderCell

`HeaderCell::make($text)`, in `Table::headerRows()` / `headerRow()`.

- `text(string $text)`
- `colspan(int $columns)`
- `rowspan(int $rows)`: Spans header rows downwards. Reaching the column-label row replaces those columns' labels.

## Columns

`Columns::make($blocks = [])`: blocks side by side; each block is a Group, a component or a list.

- `blocks(array $blocks)`
- `block(Closure|Component|array $content, ?float $width = null)`: Appends a block: a closure filling a Group with the content API, a component or a list of components. ->block(fn (Group $block) => $block->text('Prepared by')->text('{prepared}', fn (Text $t) => $t->bold())).
- `widths(array $widths)`: Relative widths per block, e.g. [2, 1].

## Group

`Group::make($children = [])`; also has the content methods.

- `children(array $children)`
- `keepTogether(bool $keepTogether = true)`: Avoids splitting the group across pages where the format allows it.

## Spacer, Divider, PageBreak, Sheet, Native

`Spacer::make($mm = 5)`, `Divider::make()` (style it with `border()`), `PageBreak::make()`, `Sheet::make($name)` (new worksheet in XLSX, new page elsewhere), `Native::make()->for($format, $renderer)` (pdf: `fn (Mpdf $mpdf, RenderContext $context)`, docx: `fn (AbstractContainer $container, RenderContext $context)`, xlsx: `fn (Writer $writer, RenderContext $context)`, html: `fn (RenderContext $context): string`).

## Style

`Style::make()`; the same fluent properties as the style methods, for `headerStyle()`, `totalStyle()`, `rowStyleUsing()`, named styles.

- `::make()`
- `font(string $family, ?float $size = null)`
- `size(float $points)`
- `bold(bool $bold = true)`
- `italic(bool $italic = true)`
- `underline(bool $underline = true)`
- `strike(bool $strike = true)`
- `color(string $color)`
- `background(string $color)`
- `align(Align|string $align)`
- `verticalAlign(VerticalAlign|string $align)`
- `border(Border|float $border = 0.5, string $color = '000000', BorderLine $line = BorderLine::Solid)`
- `padding(float $millimetres)`
- `spaceBefore(float $millimetres)`
- `spaceAfter(float $millimetres)`
- `wrap(bool $wrap = true)`
- `lineHeight(float $multiple)`: Line height as a multiple of the font size (e.g. 1.15). Spreadsheets ignore it.

## Border

`new Border($width = 0.5 /* pt */, $color = '000000', BorderLine $line = BorderLine::Solid)`

- `::none()`

## Margins

Also accepted as `margins($top, $right, $bottom, $left)` in CSS shorthand order.

- `::of(float ...$values)`: CSS-style shorthand: one value for all sides, two for vertical/horizontal, or four for top/right/bottom/left.
- `withHeaderFooter(float $header, float $footer)`

## Enums

Methods typed with these enums also take the string value.

- `Paper`: A3 ('A3'), A4 ('A4'), A5 ('A5'), B4 ('B4'), Letter ('Letter'), Legal ('Legal')
- `Orientation`: Portrait ('portrait'), Landscape ('landscape')
- `Align`: Left ('left'), Center ('center'), Right ('right'), Justify ('justify')
- `VerticalAlign`: Top ('top'), Middle ('middle'), Bottom ('bottom')
- `BorderLine`: Solid ('solid'), Dashed ('dashed'), Dotted ('dotted'), Double ('double'), None ('none')
