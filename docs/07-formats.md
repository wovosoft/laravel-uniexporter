# Formats

The same schema renders in every format. This page covers what each format does differently, and how to adjust a document for one format.

## Per-format adjustments

Three tools, from simplest to most flexible:

```php
// 1. Show or hide a component
Heading::make('Notes')->hiddenOn('xlsx');
Text::make('Only in print')->visibleOn('pdf', 'docx');

// 2. Adjust a component for some formats (on a copy; the schema never changes)
Table::make('rows')
    ->on('xlsx', fn (Table $table) => $table->showHeader(false)->freezeHeader())
    ->on(['pdf', 'html'], fn (Table $table) => $table->autoWidths());

Column::make('designation')
    ->center()
    ->on('xlsx', fn (Column $column) => $column->left());

// 3. Adjust the settings for some formats
$settings->on('xlsx', fn (Settings $settings) => $settings->withoutHeader()->withoutFooter());
```

The closures may return the object or nothing. Several `on()` calls for the same format run in order. To decide at export time, call `on()` (or `configure()`) on the document before exporting:

```php
$document = new ContactsList();

if ($request->boolean('without_headers')) {
    $document->configure(fn (Settings $s) => $s->on('xlsx', fn (Settings $s) => $s->withoutHeader()));
}
```

## PDF (`pdf`, mPDF)

- The schema becomes HTML and CSS, written to mPDF one top-level component at a time, and saved to a file.
- Fonts must be registered in `drivers.pdf.font_data` (keys are the lowercase family without spaces) with `useOTL => 0xFF` for scripts that need shaping, such as Bengali.
- Wide tables keep their column widths: `shrink_tables_to_fit` is off and tables use `autosize`, so mPDF only shrinks a table that cannot fit.
- `repeatHeader()` repeats table headers on every page; `Group::keepTogether()` avoids page breaks inside a block.
- Supports watermarks, component headers and footers, and first-page headers and footers.

Driver options (`drivers.pdf` or `Settings::native('pdf', [...])`):

| Option | Use |
|--------|-----|
| `config` | merged into the mPDF constructor config (`tempDir`, `defaultPageNumStyle`, `default_font`, ...) |
| `font_dir` | extra font directories |
| `font_data` | mPDF `fontdata` entries |
| `html_listener` | a callable receiving each HTML chunk and mPDF parser mode (debugging, tests) |

Page numbers: `{page}` and `{pages}` use Latin digits; pass `['config' => ['defaultPageNumStyle' => 'bengali']]` for Bengali digits.

## DOCX (`docx`, PhpWord)

- One section with the document's paper, orientation and margins; `PageBreak` and `Sheet` add page breaks.
- Text headers and footers use Word fields (`PAGE`, `NUMPAGES`); component headers render in the header part.
- Fonts are referenced, not embedded: readers need them installed.
- PhpWord's global settings (output escaping, default font) are restored after each export.
- No watermark (Word cannot fade it) and no HTML.

## XLSX (`xlsx`, OpenSpout)

- Rows stream to the file with constant memory.
- Content flows down the sheet: headings and text become rows merged across the sheet's width; tables become header, data and totals rows; `Columns` becomes one row of cells.
- Values are typed: `number()`/`money()` columns are numbers with a number format, `date()` columns are date cells (`d/m/Y` becomes `dd/mm/yyyy`), `text()` columns are text. Text starting with `=` is never a formula.
- The first table of a sheet sets the column widths: `Column::characters()` or the weights scaled to the printable width.
- `PageBreak` is ignored; `Sheet::make('Name')` starts a new worksheet (names are sanitised to Excel's rules).
- `repeatHeader()` sets print titles; `freezeHeader()` freezes the header rows; `Settings::fitToWidth()` prints one page wide.
- Page headers and footers become the sheet's print header and footer. Component headers, watermarks, padding, spacing and line height have no spreadsheet equivalent and are skipped.
- Styles are shared: thousands of rows reuse a handful of cell styles.

Driver options: `options` (named arguments for `OpenSpout\Writer\XLSX\Options`, e.g. `SHOULD_USE_INLINE_STRINGS`).

## HTML (`html`)

- A standalone page: `<!doctype html>`, inline CSS, images embedded as data URIs, so it needs nothing from the server.
- The page is up to 1200 px wide on screen; printing uses the document's paper, orientation and margins (`@page`), page breaks and repeated table headers.
- Table cell styles become shared CSS classes, defined just before the HTML that first uses them, which keeps large tables small.
- Fonts are requested by family name with a `sans-serif` fallback; the browser needs the font installed (or add an `@font-face` through a `Native` component).
- Text page headers and footers are not rendered; component headers and footers appear once at the top and bottom.
- The PDF driver extends the HTML driver, so a schema looks the same in both.

Driver options: `html_listener` (a callable receiving each HTML chunk).

## Choosing a response per format

```php
$export = $document->data($data)->export($format);

return in_array($format, ['pdf', 'html'], true) ? $export->inline() : $export->download();
```
