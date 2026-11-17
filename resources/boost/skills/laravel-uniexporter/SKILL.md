---
name: laravel-uniexporter
description: "Build PDF, Word (DOCX), Excel (XLSX), CSV and HTML exports with wovosoft/laravel-uniexporter: describe one Document (a class-based schema of components, or the chainable heading/table/withData API) and every format renders it through its driver (mPDF, PhpWord, OpenSpout, HTML, CSV); serve all formats from one ExportsByFormat controller on an export/{format} route. Use this skill whenever you add, change, debug, review or migrate a report, list, statement, sheet or file download in a project that has laravel-uniexporter, or touch Wovosoft\\LaravelUniexporter classes, a *Document class or an export controller/route, even if the user only says 'add a PDF button', 'export this to Excel', 'the Word file looks wrong' or 'move this Blade PDF to the exporter'. Also use it when replacing hand-written mPDF/Blade, PhpWord or OpenSpout exports."
---

# laravel-uniexporter

Describe a document once (page settings, components, data) and export it to `pdf` (mPDF), `docx` (PhpWord), `xlsx` (OpenSpout), `csv` (OpenSpout) or `html`. Each element is a **component** (`Heading`, `Text`, `Table`, `Columns`, `Group`, `KeyValue`, `ListBlock`, `Image`, `Link`, `Divider`, `Spacer`, `PageBreak`, `Sheet`, `Native`), and each **driver** has its own rendering code for each component. A document is format-independent unless you adjust it with `hiddenOn()`, `visibleOn()` or `on()`.

## Where the full API is

The package documents itself in its `docs/` directory (`vendor/wovosoft/laravel-uniexporter/docs/`, or the package's path when it is a local path repository). Read the page you need instead of guessing method names:

| Need | Page |
|---|---|
| Config, fonts, `ExportedFile` responses, `ExportsByFormat` controllers | `01-getting-started.md` |
| Document classes, data, `{placeholders}`, named styles, render hooks | `02-documents-and-data.md` |
| Every component and how each format renders it | `03-components.md` |
| Tables: columns, value formats, totals, formulas, header rows, spans, sub-rows | `04-tables-and-columns.md` |
| `Style`, inheritance, borders | `05-styling.md` |
| Paper, margins, fonts, headers and footers, watermark | `06-page-setup.md` |
| What each format does differently, per-format adjustments | `07-formats.md` |
| Custom components, renderers and drivers | `08-extending.md` |
| Package tests, testing your exports | `09-testing.md` |
| The chainable API | `10-chainable-api.md` |

Source: `src/Document.php`, `src/Settings.php`, `src/Components/`, `src/Drivers/`, `src/Concerns/ExportsByFormat.php`.

## Adding or migrating an export

1. **Keep the data source.** Reuse the existing query or report class; only the rendering moves. Pass lazy collections or a paginator's `items()` for big lists: tables consume rows one at a time.
2. **Write the document**, as a class next to the project's other documents (look for an existing `*Document` and shared base classes first), or inline with the chainable API for small exports.
3. **Serve it from one controller** with `ExportsByFormat` and one `export/{format}` route (below), not one class or route per format.
4. **Link to the route** with the format as a route parameter (with Wayfinder: `Report.export.url({format: 'pdf'}, {query: filters})`; a route named `export` is generated as `exportMethod` for named imports).
5. **Remove what it replaced**: Blade views, per-format classes, old routes. Grep the old names in PHP, the frontend and tests.
6. **Test it and look at it** (see Testing). Layout bugs don't show in assertions; render real data and look at the PDF.

## Writing a document

**Class-based**: `settings()` is the page setup, `schema()` the components; data comes through `data()`.

```php
use Wovosoft\LaravelUniexporter\Components\{Column, Heading, Table};
use Wovosoft\LaravelUniexporter\{Document, Settings};
use Wovosoft\LaravelUniexporter\Style\{Border, Paper};

class SalariesDocument extends Document
{
    public function settings(Settings $settings): Settings
    {
        return $settings
            ->paper(Paper::A4)->landscape()
            ->margins(10)
            ->font('Nikosh', 10)
            ->title('Salaries of {office}')
            ->filename('salaries')
            ->footer(right: '{page}/{pages}');
    }

    public function schema(): array
    {
        return [
            Heading::make('Salaries of {office}')->center(),
            Table::make('employees')
                ->borders(new Border(0.75, '000000'))
                ->repeatHeader()
                ->columns([
                    Column::make()->rowNumber()->label('SL'),
                    Column::make('job_id')->label('PF No.')->text(),
                    Column::make('person.name')->label('Name')->width(2),
                    Column::make('basic_salary')->label('Basic')->money()->total(),
                ])
                ->totalLabel('Total'),
        ];
    }
}

(new SalariesDocument)->data(['office' => $office->name, 'employees' => $rows])->exportToPdf()->inline();
```

**Chainable**: the same components, appended one by one; page settings are forwarded to `Settings`.

```php
use Wovosoft\LaravelUniexporter\Facades\Uniexporter;

Uniexporter::document()                                   // or Document::make()
    ->paper('A4')->landscape()->font('Nikosh', 10)->filename('salaries')
    ->with('office', $office->name)
    ->withData(['employees' => $rows])
    ->heading('Salaries of {office}', tap: fn (Heading $heading) => $heading->center())
    ->table('employees', fn (Table $table) => $table
        ->column(label: 'SL', tap: fn (Column $column) => $column->rowNumber())
        ->column('person.name', 'Name')
        ->column('basic_salary', 'Basic', fn (Column $column) => $column->money()->total()))
    ->columns(fn (Columns $columns) => $columns
        ->block(fn (Group $block) => $block->divider()->text('Prepared by'))
        ->block(fn (Group $block) => $block->divider()->text('Approved by')))
    ->exportToPdf();
```

Data passed to `data()` is merged over data attached with `withData()`/`with()`.

## One controller, one route

```php
use Wovosoft\LaravelUniexporter\Concerns\ExportsByFormat;
use Wovosoft\LaravelUniexporter\PendingExport;

class SalariesExport
{
    use ExportsByFormat;

    public const array FORMATS = ['pdf', 'excel', 'docx', 'csv'];

    public function __invoke(SalariesRequest $request, string $format): BinaryFileResponse
    {
        return $this->exportAs($request, $format);
    }

    protected function document(SalariesRequest $request, string $format): PendingExport
    {
        return (new SalariesDocument)->data(['employees' => $request->query()->lazy()]);
    }
}

Route::get('salaries/export/{format}', SalariesExport::class)
    ->whereIn('format', SalariesExport::FORMATS)         // any other format is a 404
    ->name('salaries.export');
```

- `exportAs()` calls `pdf()` / `html()` (inline, opens in the browser) or `excel()` / `docx()` / `csv()` (download). URL formats: `pdf`, `html`, `excel` (or `xlsx`), `docx`, `csv`.
- `document()` is not abstract in the trait, so type the request as your form request. Use `$format` when formats need different data (a paged PDF and a full spreadsheet).
- Exports are temporary files sent as `BinaryFileResponse` and deleted after sending, which is safe under Octane. Don't write to PHP's output.

## Rules worth knowing

- **Name helpers in Document subclasses differently.** `Document` has public `table()`, `text()`, `heading()`, `columns()`, `group()`, `list()`, `image()`, `link()`, `add()`, `each()`, `when()`... A subclass method with one of these names is a fatal signature clash; use names like `reportTable()`.
- **Fonts**: mPDF needs every font registered in `config/uniexporter.php` (`drivers.pdf.font_data`, lowercase family without spaces, `useOTL => 0xFF` for scripts that need shaping, such as Bengali). Word and Excel reference fonts by name; readers need them installed.
- **Text in non-Latin digits** (Bengali numbers, formatted dates): produce it in `formatUsing()` and mark the column `->text()`, so spreadsheets keep it as text. Columns people sum in Excel stay `->money()` / `->number()`.

## Format pitfalls

- **XLSX column widths come from the first table on a sheet.** A small info table first makes later tables use its widths. Show such info as `Text` in spreadsheets (`hiddenOn('pdf', 'docx', 'html')` on the text, `visibleOn` on the bordered table) or set `Column::characters()`.
- **`freezeHeader()` applies to the first frozen table of a sheet only.** Use it on single-table documents.
- **CSV writes tables only**: headings, text and key/values disappear. In sectioned reports, add context columns visible only in CSV: `Column::make()->label('Office')->formatUsing(fn () => $office)->visibleOn('csv')`.
- **Missing glyphs render as boxes in PDF** (e.g. `∑`, which browsers render for HTML). Use words or characters the font has.
- **Spreadsheets ignore `PageBreak`**; `Sheet::make('Name')` starts a worksheet. For "N records per printed page" with one full sheet: per-page tables `hiddenOn('xlsx', 'csv')` plus one full table `visibleOn('xlsx', 'csv')`.
- **Header spans**: `HeaderCell::make('x')->rowspan(2)` spans down into the label row; `->colspan(n)` groups columns. `headerRows()` aren't adjusted for hidden columns.
- **One cell spanning many rows** (a remarks cell beside a list): one parent row with `->subRows('items')`, per-item columns `->fromSubRow()`.
- **Newlines** in values and `Text` work in every format.
- **Empty results**: give tables `emptyText()`, and make sectioned documents render their title and a "No records found." line rather than a blank file.

## Testing

- **Package tests**: `vendor/bin/phpunit -c <package path>/phpunit.xml` (no database needed). Run components in strict mode (`'strict' => true` in the manager config) so unsupported components throw.
- **Your exports**: hit the route in a feature test, assert the content type and disposition, and look for a label in the content: XLSX/DOCX are zip files (`xl/worksheets/sheet1.xml`, `xl/sharedStrings.xml`, `word/document.xml`), CSV and HTML are text, PDF starts with `%PDF`. Pick needles that exist without data (column labels, titles).
- **Look at the output**: render real data to a file and inspect it:

```php
$file = (new SalariesDocument)->data($data)->export('pdf');
file_put_contents($out.'/salaries.pdf', $file->contents());   // save() can't move across filesystems
$file->delete();
```

Then `pdftoppm -r 60 -png -f 1 -l 1 salaries.pdf page` and view the image; `unzip -p salaries.xlsx xl/worksheets/sheet1.xml` for formulas and frozen panes.

## Extending

A new kind of element is a new component class plus a renderer for each driver (`MpdfDriver::renderUsing()`, `PhpWordDriver::renderUsing()`, `OpenSpoutDriver::renderUsing()`, `HtmlDriver::renderUsing()`, registered at boot; `MpdfDriver` doesn't inherit `HtmlDriver` renderers). Often composing existing components in a static `make()` is enough. Escape hatches: `Native` content, `Component::native()`, `beforeRender()` / `afterRender()`. See `08-extending.md`.
