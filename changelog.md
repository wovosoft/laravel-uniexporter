# Changelog

All notable changes to `laravel-uniexporter` are documented in this file.

## Unreleased

### Added
- Chainable API: documents and groups append components with `heading()`, `text()`, `link()`, `image()`, `list()`, `keyValue()`, `table()`, `columns()`, `group()`, `spacer()`, `divider()`, `pageBreak()`, `sheet()`, `nativeContent()`, `add()` and `each()`, plus `when()`/`unless()`.
- Page settings called on a document (`paper()`, `landscape()`, `font()`, `footer()`...) are forwarded to `Settings`.
- `Document::withData()` and `with()` attach data, so a document exports without `data()`; `data()` merges over it.
- `Table::column()` and `Table::headerRow()`, `Columns::block()`, `Text::run()`, and `headerContent()`/`footerContent()` closures.
- `Uniexporter::document()`.
- `Concerns\ExportsByFormat`: one controller serves every format of a document from an `export/{format}` route.

### Changed
- `Document` has public `table()`, `text()`, `heading()`... methods; subclasses can no longer declare methods with these names.

## 1.0.0 - 2026-11-17

### Added
- Document schema with components: headings, text and runs, links, images, lists, key/value pairs, tables, side-by-side columns, groups, spacers, dividers, page breaks, sheets and native content.
- Drivers for PDF (mPDF), DOCX (PhpWord), XLSX (OpenSpout) and HTML; the PDF driver extends the HTML driver.
- Per-format adjustments with `Settings::on()` and `Component::on()`.
- Page setup: paper, margins, fonts, metadata, text and component headers and footers, watermarks, fit-to-width spreadsheets.
- Tables: value formats, totals, header rows, row styles, content-sized columns, spreadsheet widths in characters, frozen headers.
- Styles: inheritance, named styles, line height, no-wrap cells.
- Exports as temporary files with download, inline, store and save responses (Octane safe).
