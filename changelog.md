# Changelog

All notable changes to `laravel-uniexporter` are documented in this file.

## 2.0.1 - 2026-11-18

### Security
- Link URLs (`Link`, `Run::link()`) only become links for `http`, `https`, `mailto`, `tel` and relative URLs, in every format. Other schemes, such as a `javascript:` URL coming from data, render as plain text; HTML exports served inline could otherwise run script on the application's origin. New `RenderContext::url()`.

### Fixed
- `ExportsByFormat`: formats missing from the class's `FORMATS` are refused (a 404 in Laravel) even when the route has no `whereIn()`; unknown formats are a 404 instead of a 500.
- `ExportsByFormat`: `pdf()`, `html()`, `excel()`, `docx()` and `csv()` are protected, so they can't be routed around the controller's form request.
- `ExportsByFormat`: formats registered with `Uniexporter::extend()` can be served; `document()` receives `xlsx` when `xlsx` was requested; new `exportManager()` hook.
- `UniexporterManager::extend()` has its docblock again.


### Upgrading from 1.x
- `Document` now has public content methods (`table()`, `text()`, `heading()`, `columns()`, `group()`, `list()`, `image()`, `link()`, `spacer()`, `divider()`, `pageBreak()`, `sheet()`, `nativeContent()`, `keyValue()`, `add()`, `each()`, `when()`, `unless()`, `with()`, `withData()`). A subclass that declares a method with one of these names fails with a fatal error; rename such helpers (for example `table()` to `reportTable()`).

### Added
- CSV format (`csv`, OpenSpout): table data only, with formula-safe text.
- Table sub-rows (`Table::subRows()`, `Column::fromSubRow()`) and header cells spanning rows (`HeaderCell::rowspan()`).
- Spreadsheet formulas per column (`Column::formula()`), totals computed by a closure, `Table::totalFormulas()`, and a totals label spanning columns (`totalLabel($label, $colspan)`).
- Cell styles by value (`Column::styleUsing()`).
- B4 paper.
- Web fonts for HTML exports (`native('html', ['font_faces' => [...]])`).
- Chainable API: documents and groups append components with `heading()`, `text()`, `link()`, `image()`, `list()`, `keyValue()`, `table()`, `columns()`, `group()`, `spacer()`, `divider()`, `pageBreak()`, `sheet()`, `nativeContent()`, `add()` and `each()`, plus `when()`/`unless()`.
- Page settings called on a document (`paper()`, `landscape()`, `font()`, `footer()`...) are forwarded to `Settings`.
- `Document::withData()` and `with()` attach data, so a document exports without `data()`; `data()` merges over it.
- `Table::column()` and `Table::headerRow()`, `Columns::block()`, `Text::run()`, and `headerContent()`/`footerContent()` closures.
- `Uniexporter::document()`.
- `Concerns\ExportsByFormat`: one controller serves every format of a document from an `export/{format}` route.
- An AI agent skill and guideline in `resources/boost/`, discovered by Laravel Boost, or published with `vendor:publish --tag=uniexporter.skills`. The skill's API reference is generated from the source by `scripts/skill-reference.php`.

### Changed
- `Document` has public `table()`, `text()`, `heading()`... methods; subclasses can no longer declare methods with these names (see Upgrading).
- Big top-level tables are written to mPDF in chunks of `drivers.pdf.table_chunk_rows` rows (default 200), each repeating the header.
- Word tables share cell styles instead of creating one per cell.

### Fixed
- Empty tables render in PDFs.
- The totals label is aligned by the totals style.
- Sheet headings span the columns actually shown, ignoring hidden components when sizing.

## 1.0.0 - 2026-11-17

### Added
- Document schema with components: headings, text and runs, links, images, lists, key/value pairs, tables, side-by-side columns, groups, spacers, dividers, page breaks, sheets and native content.
- Drivers for PDF (mPDF), DOCX (PhpWord), XLSX (OpenSpout) and HTML; the PDF driver extends the HTML driver.
- Per-format adjustments with `Settings::on()` and `Component::on()`.
- Page setup: paper, margins, fonts, metadata, text and component headers and footers, watermarks, fit-to-width spreadsheets.
- Tables: value formats, totals, header rows, row styles, content-sized columns, spreadsheet widths in characters, frozen headers.
- Styles: inheritance, named styles, line height, no-wrap cells.
- Exports as temporary files with download, inline, store and save responses (Octane safe).
