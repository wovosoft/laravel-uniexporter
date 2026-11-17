# Changelog

All notable changes to `laravel-uniexporter` are documented in this file.

## Unreleased

### Added
- Document schema with components: headings, text and runs, links, images, lists, key/value pairs, tables, side-by-side columns, groups, spacers, dividers, page breaks, sheets and native content.
- Drivers for PDF (mPDF), DOCX (PhpWord), XLSX (OpenSpout) and HTML; the PDF driver extends the HTML driver.
- Per-format adjustments with `Settings::on()` and `Component::on()`.
- Page setup: paper, margins, fonts, metadata, text and component headers and footers, watermarks, fit-to-width spreadsheets.
- Tables: value formats, totals, header rows, row styles, content-sized columns, spreadsheet widths in characters, frozen headers.
- Styles: inheritance, named styles, line height, no-wrap cells.
- Exports as temporary files with download, inline, store and save responses (Octane safe).
