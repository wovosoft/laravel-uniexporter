# Page setup

Override `settings()` in a document class, or use `configure()` on any document. Every method returns the settings for chaining.

```php
public function settings(Settings $settings): Settings
{
    return $settings
        ->paper('A4')->portrait()
        ->margins(10, 13, 10, 13)
        ->font('Nikosh', 10)
        ->title('{title}')->author('HR Division')->subject('Contacts')->keywords('contacts, hr')
        ->filename('contacts')
        ->header(left: '{office.name}', right: 'Printed: {date}')
        ->footer(center: 'Page {page} of {pages}')
        ->watermark(public_path('logo.png'), 0.2, 80, 80);
}
```

On an inline document the same methods can be called on the document itself (`Document::make()->paper('A4')->landscape()->footer(right: '{page}/{pages}')`); see [Chainable API](10-chainable-api.md#page-setup).

## Paper and orientation

| Method | Values |
|--------|--------|
| `paper(Paper\|string)` | `A3`, `A4` (default), `A5`, `B4`, `Letter`, `Legal` |
| `portrait()` / `landscape()` / `orientation(Orientation\|string)` | portrait by default |

## Margins

Millimetres, in CSS shorthand order:

```php
->margins(10)                  // all sides
->margins(15, 10)              // top/bottom, left/right
->margins(8, 13, 10, 13)       // top, right, bottom, left
->margins(Margins::of(19.05, 17.78)->withHeaderFooter(7.62, 7.62))
```

`header` and `footer` (5 mm by default) are the distances from the page edge to the header and footer text.

## Default text style

```php
->font('Nikosh', 11)      // family and optional size
->fontSize(10)
->color('333333')
```

This is the base of every component's style. In PDFs the family must be registered (see [fonts](01-getting-started.md#fonts-for-bengali-and-other-complex-scripts)).

## Metadata and file name

`title()`, `author()`, `subject()` and `keywords()` set the document properties (PDF, DOCX, XLSX) and the HTML `<title>`; they accept placeholders. `filename()` is the default download name without extension (the driver adds it) and is used as written; pass a computed name to `download()` / `inline()` instead, e.g. `->download("contacts-{$office->code}")`.

## Text headers and footers

Three slots, left, center and right, repeated on every page:

```php
->header(left: 'Acme Bank Ltd.', right: '{date}')
->footer(center: 'Page {page} of {pages}', style: Style::make()->size(9)->color('000000'))
->firstPageHeader()                         // first page: no header
->firstPageFooter(center: 'Confidential')   // first page: a different footer
->withoutHeader()                           // removes the header (including first-page and component headers)
->withoutFooter()
```

Tokens: `{page}`, `{pages}`, `{date}` (today, `d/m/Y`), plus any data placeholder. The default style is 8 pt grey (`595959`); pass `style:` to change it.

| Format | Text headers and footers |
|--------|--------------------------|
| PDF | on every page |
| DOCX | on every page (Word fields for page numbers) |
| XLSX | the sheet's print header/footer (`&P`, `&N`, `&D`) |
| HTML | not rendered (a browser page has no pages) |

## Component headers and footers

A letterhead, a title line or anything else built from components, repeated on every page:

```php
->headerContent([
    Columns::make([Image::make(public_path('logo.png'))->width(13), Text::make('Acme Bank Ltd.')->bold()])->widths([1, 4]),
    Text::make('Subject: {subject}')->underline(),
])
->footerContent([Text::make('This is a system generated document.')->size(8)->center()])
```

Or built with the content methods, from a closure that fills a `Group`:

```php
->headerContent(fn (Group $header) => $header
    ->text('Acme Bank Ltd.', fn (Text $text) => $text->bold())
    ->text('Subject: {subject}', fn (Text $text) => $text->underline()))
```

- **PDF**: on every page; the top (or bottom) margin grows to fit the content. Text slots from `header()` render below the components, `footer()` slots above them.
- **DOCX**: in the page header and footer.
- **HTML**: once, at the top and the bottom of the page.
- **XLSX**: skipped (use a text header, or put the components in the schema with `visibleOn('xlsx')`).

When the letterhead belongs only on the first page, put it at the top of the schema instead.

## Watermark

```php
->watermark(public_path('logo.png'), alpha: 0.2, width: 80, height: 80)
```

An image centered behind every PDF page, at the given opacity and size in millimetres (its own size when omitted). The path may be a placeholder. Other formats ignore it: Word cannot fade a watermark image.

## Spreadsheet printing

```php
->fitToWidth()     // print one page wide, as many pages tall as needed
```

Tables can also freeze their header rows and repeat them as print titles; see [Tables](04-tables-and-columns.md#header).

## Driver options for one document

```php
->native('pdf', ['config' => ['defaultPageNumStyle' => 'bengali']])   // Bengali page numbers in mPDF
->native('xlsx', ['options' => ['SHOULD_USE_INLINE_STRINGS' => false]])
```

These merge over the `drivers` options in the config file. See [Formats](07-formats.md) for each driver's options.

## Settings per format

```php
->on('xlsx', fn (Settings $settings) => $settings
    ->margins(Margins::of(19.05, 17.78)->withHeaderFooter(7.62, 7.62))
    ->font('Kalpurush', 10)
    ->withoutFooter()
    ->header(center: 'Contact Information', style: Style::make()->size(11)))
->on('html', fn (Settings $settings) => $settings->fontSize(9.75))
```

The closure receives a copy of the settings when exporting to that format. See [Per-format adjustments](07-formats.md#per-format-adjustments).
