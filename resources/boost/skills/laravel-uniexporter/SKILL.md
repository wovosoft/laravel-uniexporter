---
name: laravel-uniexporter
description: "Build PDF, DOCX, XLSX, CSV and HTML exports with wovosoft/laravel-uniexporter: one Document of components rendered by a driver per format, served from one ExportsByFormat controller. Use it whenever you add, change, debug or migrate a report, list or file download in a project that has this package, or touch Wovosoft\\LaravelUniexporter code, a *Document class or an export route — including requests like 'add a PDF button' or 'export this to Excel'."
---

# laravel-uniexporter

One document, every format: `pdf` (mPDF), `docx` (PhpWord), `xlsx` and `csv` (OpenSpout), `html`. A document is a list of **components** (`Heading`, `Text`, `Table`, `Columns`, `Group`, `KeyValue`, `ListBlock`, `Image`, `Link`, `Divider`, `Spacer`, `PageBreak`, `Sheet`, `Native`) plus page **settings**; each **driver** renders each component its own way. Text anywhere may contain `{dot.path}` placeholders, filled from the export data.

**Exact signatures for every class are in [references/api.md](references/api.md)** — read it instead of guessing method names; it is generated from the source. Long-form explanations are in the package's `docs/` (`vendor/wovosoft/laravel-uniexporter/docs/`), only needed for edge cases.

## Writing a document

A class for real reports (`settings()` = page setup, `schema()` = components); data arrives through `data()`:

```php
class SalariesDocument extends Document
{
    public function settings(Settings $settings): Settings
    {
        return $settings->paper('A4')->landscape()->margins(10)->font('Nikosh', 10)
            ->title('Salaries of {office}')->filename('salaries')->footer(right: '{page}/{pages}');
    }

    public function schema(): array
    {
        return [
            Heading::make('Salaries of {office}')->center(),
            Table::make('employees')->repeatHeader()->borders(new Border(0.75, '000000'))->columns([
                Column::make()->rowNumber()->label('SL'),
                Column::make('job_id')->label('PF No.')->text(),
                Column::make('person.name')->label('Name')->width(2),
                Column::make('basic_salary')->label('Basic')->money()->total(),
            ])->totalLabel('Total'),
        ];
    }
}

(new SalariesDocument)->data(['office' => $office->name, 'employees' => $query->lazy()])->exportToPdf()->inline();
```

Or the same thing as one chain (small or one-off exports); page settings called on the document go to `Settings`:

```php
Document::make()->paper('A4')->landscape()->filename('salaries')
    ->withData(['office' => $office->name, 'employees' => $rows])
    ->heading('Salaries of {office}', tap: fn (Heading $h) => $h->center())
    ->table('employees', fn (Table $t) => $t
        ->column(label: 'SL', tap: fn (Column $c) => $c->rowNumber())
        ->column('basic_salary', 'Basic', fn (Column $c) => $c->money()->total()))
    ->exportToExcel();
```

`schema()` never sees the export data (it only reaches components through `{placeholders}` and table row paths). When the *shape* depends on the data — one heading and table per office, a variable number of sections — pass that data to the document's constructor and loop in `schema()` (or use `->each($items, fn ($document, $item) => ...)` in a chain). A computed column has no key: `Column::make()->label('Total')->formatUsing(fn ($value, $row) => $row->male + $row->female)` (`$value` is null; read the row).

Reuse the project's existing `*Document` classes and base classes when there are any; keep the existing query and only move the rendering. Pass lazy collections or a paginator's `items()`: tables consume rows one at a time.

## Serving it: one controller, one route

```php
class SalariesExport
{
    use ExportsByFormat;                                    // Wovosoft\LaravelUniexporter\Concerns

    public const array FORMATS = ['pdf', 'excel', 'docx'];

    public function __invoke(SalariesRequest $request, string $format): BinaryFileResponse
    {
        return $this->exportAs($request, $format);            // pdf/html inline, excel/docx/csv download
    }

    protected function document(SalariesRequest $request, string $format): PendingExport
    {
        return (new SalariesDocument)->data([...]);           // $format: for data that differs per format
    }
}

Route::get('salaries/export/{format}', SalariesExport::class)->whereIn('format', SalariesExport::FORMATS)->name('salaries.export');
```

Don't make one class or route per format. Responses are temporary files (`BinaryFileResponse`), Octane-safe; never write to PHP output. With Wayfinder, link with `salaries.export.url({format: 'pdf'}, {query: filters})` (named import: `exportMethod`).

## Per-format adjustments

`->hiddenOn('xlsx', 'csv')`, `->visibleOn('pdf')`, and `->on('xlsx', fn (Table $t) => $t->freezeHeader())` work on any component, column and on `Settings` (`$settings->on('xlsx', fn (Settings $s) => $s->withoutFooter())`).

## Pitfalls (each cost a debugging round before)

- **Don't name helpers `table()`, `text()`, `heading()`, `columns()`, `group()`, `list()`, `image()`, `link()`, `add()`, `each()`, `when()` in a Document subclass**: `Document` has public methods with those names, so it is a fatal error. Use `reportTable()` etc.
- **XLSX column widths come from the first table on a sheet**, and only that table's `freezeHeader()` applies. Put the main table first in spreadsheets (show small info tables as `Text` there) or set `Column::characters()`.
- **CSV keeps only tables** (headings, text, key/values vanish): in sectioned reports add context columns with `->visibleOn('csv')`, e.g. `Column::make()->label('Office')->formatUsing(fn () => $office)->visibleOn('csv')`.
- **Non-Latin digits or formatted dates**: produce them in `formatUsing()` and mark the column `->text()`; keep `money()`/`number()` for values users sum.
- **mPDF needs every font registered** in `config/uniexporter.php` (`drivers.pdf.font_data`, lowercase family without spaces, `useOTL => 0xFF` for shaped scripts such as Bengali). Missing glyphs (e.g. `∑`) render as boxes.
- **Spreadsheets ignore `PageBreak`**; `Sheet::make('Name')` starts a worksheet. "N rows per printed page" + one full sheet: per-page tables `hiddenOn('xlsx', 'csv')` and one full table `visibleOn('xlsx', 'csv')`.
- **Spans**: `HeaderCell::make('x')->rowspan(2)` reaches into the label row (that column's label is dropped), `->colspan(n)` groups columns; `headerRows()` aren't adjusted for hidden columns. One cell spanning many rows: `->subRows('items')` with per-item columns `->fromSubRow()`.
- **Empty data**: set `emptyText()`, and give sectioned documents a title and a "No records found." line instead of a blank file.

## Testing and checking

- Feature-test the route: assert status, `Content-Type`, `Content-Disposition`, and a label in the content (XLSX/DOCX are zips: `xl/worksheets/sheet1.xml` + `xl/sharedStrings.xml`, `word/document.xml`; CSV/HTML are text; PDF starts with `%PDF`). Use needles that exist without data.
- Look at real output before calling layout done: `$f = $doc->data($data)->export('pdf'); file_put_contents($path, $f->contents()); $f->delete();` (`save()` can't move across filesystems), then `pdftoppm -r 60 -png -f 1 -l 1 file.pdf page` and view the image.
- Package tests: `vendor/bin/phpunit -c <package>/phpunit.xml`; use strict mode (`'strict' => true` in the manager config) so unsupported components throw.

## Extending

A new kind of element = a component class + a renderer per driver (`MpdfDriver::renderUsing()`, `HtmlDriver::renderUsing()`, `PhpWordDriver::renderUsing()`, `OpenSpoutDriver::renderUsing()` at boot; MpdfDriver doesn't inherit HtmlDriver's). Composing existing components in a static `make()` is usually enough. See `docs/08-extending.md`.
