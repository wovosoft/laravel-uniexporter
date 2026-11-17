# Components

Every component is created with `::make(...)`, takes the [style methods](05-styling.md), and can be limited to or adjusted for some formats (see [Formats](07-formats.md#per-format-adjustments)):

```php
Text::make('Internal note')
    ->italic()
    ->hiddenOn('xlsx')                                  // or ->visibleOn('pdf', 'docx')
    ->on('html', fn (Text $text) => $text->size(9));    // adjust a copy for one format
```

## Overview

| Component | PDF / HTML | DOCX | XLSX |
|-----------|------------|------|------|
| `Heading` | `<h1>`–`<h6>` | bold paragraph | merged row |
| `Text` | paragraph | paragraph | merged row (rich text for runs) |
| `Link` | link | link | hyperlink cell |
| `Image` | image | image | image anchored at the next row |
| `ListBlock` | bulleted/numbered list | list | a merged row per item, with `•` or `1.` markers |
| `KeyValue` | label/value grid | table | label/value cells |
| `Table` | table | table | header, data and totals rows with native formats |
| `Columns` | side-by-side blocks | table without borders | blocks as cells of one row |
| `Group` | block | contents | contents |
| `Spacer` | vertical space | empty paragraph | an empty row |
| `Divider` | horizontal rule | line shape | bottom border on a row |
| `PageBreak` | page break | page break | ignored |
| `Sheet` | page break (when the page has content) | page break (same) | new worksheet |
| `Native` | your mPDF/HTML code | your PhpWord code | your OpenSpout code |

## Heading

```php
Heading::make('Salary Statement');      // level 1
Heading::make('Details', 2);            // or ->level(2); levels 1–6
```

Default style: bold, sized by level, 2 mm space after.

## Text and runs

```php
Text::make('Plain paragraph with {placeholders}');
Text::make("Line one\nLine two");       // \n starts a new line in every format

Text::make()->runs([
    Run::make('Note: ')->bold(),
    Run::make('see the '),
    Run::make('policy')->link('https://example.com/policy')->underline(),
    Run::make(' for details.')->italic(),
]);
```

A run takes the style methods; it inherits the paragraph's style. Default style: 1.5 mm space after.

## Link

```php
Link::make('Open the portal', 'https://portal.example.com/{path}');
```

## Image

```php
Image::make(public_path('logo.png'))->width(20);   // millimetres; height follows the aspect ratio
Image::make('{logo}')->width(20)->height(20);     // from data: a file path or the image's raw bytes
Image::make('{logo}')->center();
```

Image data can be a path or binary contents (for example from storage); binary images are written to a temporary file. A missing image is skipped. The HTML driver embeds images as data URIs.

## ListBlock

```php
ListBlock::make(['First', 'Second']);
ListBlock::make()->source('notes')->ordered();      // items from data
ListBlock::make([['text' => 'Parent', 'items' => ['Child']]]);   // nested items
```

Static items come first, then the items from `source()`.

## KeyValue

```php
KeyValue::make(['Month' => '{month}', 'Prepared by' => '{prepared}'])
    ->columns(2)                                     // pairs per row
    ->separator(': ')
    ->labelStyle(Style::make()->bold());

KeyValue::make()->item('Office', '{office.name}')->item('Code', '{office.code}');
```

## Table

See [Tables and columns](04-tables-and-columns.md).

## Columns

Blocks side by side (a layout table without borders):

```php
Columns::make([
    [Text::make('Prepared by'), Text::make('{prepared}')->bold()],     // a list becomes a Group
    Group::make([Text::make('Approved by')])->right(),
    Image::make('{signature}')->width(30),                              // a single component
])->widths([2, 1, 1]);                                                  // relative widths
```

Each block is a `Group`; style a block to align or color all of its contents. Text in blocks inherits the block's alignment.

## Group

```php
Group::make([Heading::make('Signatures', 2), Columns::make([...])])
    ->keepTogether()          // avoid splitting across pages (PDF/HTML print)
    ->font('Nikosh');         // inherited by the children
```

Children inherit the group's text properties (font, size, color, bold, italic, alignment, line height); box properties (padding, borders, spacing) are not inherited.

## Spacer, Divider, PageBreak, Sheet

```php
Spacer::make(8);                        // millimetres
Divider::make();                        // 0.5 pt grey rule; ->border(1, '000000') to change it
PageBreak::make();
Sheet::make('Summary');                 // new worksheet in XLSX; new page elsewhere
```

A `Sheet` right after a page break (or at the top of the document) does not add an empty page.

## Native

Format-specific content written with the underlying library, in schema order. Formats without a renderer render nothing:

```php
Native::make()
    ->for('pdf', fn (\Mpdf\Mpdf $mpdf, RenderContext $context) => $mpdf->WriteHTML('<barcode code="'.e($context->text('{pf}')).'" type="QR" />'))
    ->for('docx', fn (\PhpOffice\PhpWord\Element\AbstractContainer $container, RenderContext $context) => $container->addText('...'))
    ->for('xlsx', fn (\OpenSpout\Writer\XLSX\Writer $writer, RenderContext $context) => $writer->addRow(Row::fromValues(['...'])))
    ->for('html', fn (RenderContext $context): string => '<p>...</p>');   // returns HTML
```
