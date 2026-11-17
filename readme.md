# Laravel Uniexporter

Describe a document once as a schema of components, then export it to PDF, DOCX, XLSX, HTML or CSV. Each format is a driver, so you can replace one or add your own.

| Format | Default driver | Library (install it yourself) |
|--------|----------------|-------------------------------|
| `pdf`  | `MpdfDriver`     | `mpdf/mpdf` ^8.2 |
| `docx` | `PhpWordDriver`  | `phpoffice/phpword` ^1.2 |
| `xlsx` | `OpenSpoutDriver`| `openspout/openspout` ^5.0 |
| `html` | `HtmlDriver`     | none |
| `csv`  | `CsvDriver`      | `openspout/openspout` ^5.0 (table data only) |

The HTML driver writes a standalone page (images embedded, table cell styles as shared classes) for viewing or printing in a browser. It is also the markup base of the PDF driver, so PDF and HTML render the same schema the same way. Page headers and footers are PDF/DOCX/XLSX only.

Full documentation: [docs/](docs/README.md): [getting started](docs/01-getting-started.md), [documents and data](docs/02-documents-and-data.md), [components](docs/03-components.md), [tables](docs/04-tables-and-columns.md), [styling](docs/05-styling.md), [page setup](docs/06-page-setup.md), [formats](docs/07-formats.md), [extending](docs/08-extending.md), [testing](docs/09-testing.md).

## Installation

```bash
composer require wovosoft/laravel-uniexporter
php artisan vendor:publish --tag=uniexporter.config
```

## A document

Extend `Document` and describe the page setup, named styles and components. Strings can use `{placeholders}`, which are read from the data with dot notation.

```php
use Wovosoft\LaravelUniexporter\Components\{Column, Heading, Table, Text};
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Settings;
use Wovosoft\LaravelUniexporter\Style\Style;

class SalaryStatement extends Document
{
    public function settings(Settings $settings): Settings
    {
        return $settings
            ->paper('A4')->landscape()
            ->margins(15, 10)                        // mm, CSS shorthand order
            ->font('Nikosh', 10)
            ->title('{title} - {month}')
            ->filename('salary-statement')
            ->header(left: '{office.name}', right: 'Printed: {date}')
            ->footer(center: 'Page {page} of {pages}');
    }

    public function styles(): array
    {
        return ['muted' => Style::make()->color('666666')->italic()];
    }

    public function schema(): array
    {
        return [
            Heading::make('{title}')->center(),
            Text::make('Office: {office.name}')->style('muted'),
            Table::make('employees')                 // data key, or any iterable
                ->repeatHeader()
                ->striped()
                ->columns([
                    Column::make()->rowNumber()->label('SL')->width(0.5),
                    Column::make('name')->width(2.5),
                    Column::make('joined_at')->date('d/m/Y'),
                    Column::make('basic_salary')->money()->total(),
                ])
                ->totalLabel('Grand Total'),
        ];
    }
}
```

Small documents can be built inline instead:

```php
Document::make()
    ->configure(fn (Settings $settings) => $settings->landscape())
    ->components([Heading::make('Report'), Table::make('rows')->columns([...])]);
```

## Exporting

```php
$export = (new SalaryStatement())->data([
    'title'     => 'Salary Statement',
    'office'    => $office,
    'employees' => $query->lazy(),           // iterables are consumed while rendering
])->exportToPdf();                            // exportToDocx(), exportToExcel(), export('html')

return $export->inline();                     // or download(), or return $export (Responsable)
$export->store('exports/salary.pdf', 's3');   // a disk; the temporary file is removed
$export->save(storage_path('salary.pdf'));    // a local path
```

Every export is written to a temporary file and returned as a `BinaryFileResponse` that deletes the file after sending, so it is safe under Octane.

## Components

| Component | Notes |
|-----------|-------|
| `Heading::make($text, $level = 1)` | |
| `Text::make($text)` / `->runs([Run::make('a')->bold(), Run::make('b')->link($url)])` | `\n` starts a new line |
| `Link::make($text, $url)` | |
| `Image::make($pathOrPlaceholder)->width($mm)->height($mm)` | |
| `ListBlock::make($items)->source('data.key')->ordered()` | |
| `KeyValue::make(['Label' => '{value}'])->columns(2)` | |
| `Table::make($rows)` | `columns()`, `headerRows([[HeaderCell::make('Group')->colspan(3)]])`, `repeatHeader()`, `striped()`, `borders()`, `totalLabel()`, `emptyText()`, `rowStyleUsing(fn ($row, int $index) => ?Style)` |
| `Column::make('dot.key')` | `label()`, `width($weight)`, `text()`, `number()`, `money()`, `date()`, `formatUsing(fn ($value, $row, $data) => …)`, `total()`, `rowNumber()`, `emptyValue()` |
| `Columns::make([[...], Group::make([...])])->widths([1, 1])` | side-by-side blocks |
| `Group::make([...])->keepTogether()` | |
| `Spacer::make($mm)`, `Divider::make()`, `PageBreak::make()` | |
| `Sheet::make('Name')` | new worksheet in XLSX, new page elsewhere |
| `Native::make()->for('pdf', fn (Mpdf $mpdf, $context) => …)` | write with the underlying library |

Every component takes the style methods (`bold()`, `size()`, `color()`, `background()`, `center()`, `border()`, `padding()`, `spaceAfter()`, `style('named')`…) and can be limited to some formats with `visibleOn('pdf')` or `hiddenOn('xlsx')`.

## Page and table layout

```php
$settings
    ->watermark(public_path('logo.png'), alpha: 0.2, width: 80, height: 80) // PDF, centered behind every page
    ->headerContent([Letterhead::make(), Text::make('Subject')])           // components on every page (PDF, DOCX); once on top in HTML
    ->footerContent([...])
    ->fitToWidth();                                                         // spreadsheets print one page wide

Table::make('rows')
    ->autoWidths()                                       // PDF/HTML size columns from their content
    ->freezeHeader()                                     // spreadsheets keep the header row in view
    ->headerStyle(fn () => Style::make()->align('center')->background('F5D997')->wrap(false))
    ->columns([Column::make('name')->characters(25)]);  // spreadsheet width in characters
```

- `headerStyle()` with a closure replaces the default header style (bold, grey fill); a header style that sets an alignment centers every label, whatever the column alignment.
- `wrap(false)` on a table or column keeps cells on one line (spreadsheets do not wrap; PDF/HTML use `white-space: nowrap`).
- `lineHeight(1.15)` on any component or style sets the line height (PDF, HTML, DOCX).
- The PDF grows the top/bottom margin to fit `headerContent()` / `footerContent()`.

## Per-format adjustments

One schema serves every format; `on()` adjusts it for some formats only. Adjustments apply to a copy while exporting, so the schema itself never changes.

```php
// Settings: e.g. spreadsheets without the page header and footer
$settings->on('xlsx', fn (Settings $settings) => $settings->withoutHeader()->withoutFooter());

// Any component: e.g. a data-only sheet (no header row, no internal column)
Table::make('rows')
    ->columns([
        Column::make('name'),
        Column::make('note')->on('xlsx', fn (Column $column) => $column->hiddenOn('xlsx')),
    ])
    ->on(['xlsx', 'html'], fn (Table $table) => $table->showHeader(false));
```

To decide at export time, adjust the document before exporting:

```php
$document = new ContactsList();

if ($request->boolean('data_only')) {
    $document->configure(fn (Settings $settings) => $settings->on('xlsx', fn (Settings $s) => $s->withoutHeader()));
}

return $document->data($data)->exportToExcel();
```

`visibleOn()` / `hiddenOn()` remain the shortcut for showing or hiding a whole component per format. Header rows set with `headerRows()` are not adjusted when columns are hidden, so set their colspans for the visible columns.

## Format-specific code

- `->native('pdf', fn (string $html, Mpdf $mpdf, $context) => $html)` on a component changes its HTML; for DOCX and XLSX the callback receives the created element or the written row range.
- `$document->beforeRender('docx', fn (PhpWord $word) => …)` and `afterRender(...)` run around the whole render.
- `$settings->native('pdf', ['config' => [...]])` passes driver options for one document.

## Configuration

`config/uniexporter.php` maps formats to drivers and holds driver options. For Bengali text in PDFs, register the fonts with OpenType layout enabled:

```php
'drivers' => [
    'pdf' => [
        'font_dir'  => [storage_path('fonts')],
        'font_data' => [
            'nikosh' => ['R' => 'Nikosh.ttf', 'B' => 'Shonarb.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
        ],
    ],
],
```

Set `UNIEXPORTER_STRICT=true` (for example in tests) to throw when a driver cannot render a component instead of skipping it.

## Custom drivers

Implement `Wovosoft\LaravelUniexporter\Drivers\Driver` (`extension()`, `mimeType()`, `render(RenderContext $context, string $path)`), or extend `AbstractDriver` to get a `render{Component}()` dispatch, then register it:

```php
Uniexporter::extend('tsv', TsvDriver::class);
```

## Testing

```bash
composer update
composer test
```

When the package is developed inside an application (`packages/wovosoft/laravel-uniexporter`), the tests can also run against the application's `vendor/`, from its root:

```bash
php vendor/bin/phpunit -c packages/wovosoft/laravel-uniexporter/phpunit.xml
```

## License

MIT, see [license.md](license.md).
