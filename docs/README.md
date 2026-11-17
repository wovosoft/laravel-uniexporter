# Laravel Uniexporter documentation

Describe a document once, as a schema of components, and export it to **PDF**, **DOCX**, **XLSX** or **HTML**. Each format is rendered by a driver; drivers can be swapped or added.

```php
use Wovosoft\LaravelUniexporter\Components\{Column, Heading, Table};
use Wovosoft\LaravelUniexporter\Document;

return Document::make()
    ->components([
        Heading::make('Employees'),
        Table::make('employees')->columns([
            Column::make()->rowNumber()->label('SL'),
            Column::make('name'),
            Column::make('basic_salary')->money()->total(),
        ]),
    ])
    ->data(['employees' => Employee::query()->lazy()])
    ->exportToExcel()
    ->download();
```

Or as one chain, with the data attached:

```php
return Document::make()
    ->heading('Employees')
    ->table('employees', fn (Table $table) => $table
        ->column(label: 'SL', tap: fn (Column $column) => $column->rowNumber())
        ->column('name')
        ->column('basic_salary', tap: fn (Column $column) => $column->money()->total()))
    ->withData(['employees' => Employee::query()->lazy()])
    ->exportToExcel()
    ->download();
```

## Contents

1. [Getting started](01-getting-started.md): installation, configuration, a first export, responses
2. [Documents and data](02-documents-and-data.md): document classes, data, placeholders, named styles, render hooks
3. [Components](03-components.md): every building block and how each format renders it
4. [Tables and columns](04-tables-and-columns.md): rows, columns, value formats, totals, header rows
5. [Styling](05-styling.md): the `Style` object, inheritance, defaults, units
6. [Page setup](06-page-setup.md): paper, margins, fonts, metadata, headers and footers, watermarks
7. [Formats](07-formats.md): what is specific to PDF, DOCX, XLSX and HTML, and per-format adjustments
8. [Extending](08-extending.md): native hooks, custom renderers, custom drivers
9. [Testing](09-testing.md): running the package tests and testing your own exports
10. [Chainable API](10-chainable-api.md): page setup, content and data in one chain

## How it works

```
Document (settings + styles + schema)      Data (array / Arrayable)
                 \                          /
                  PendingExport ── export('pdf' | 'docx' | 'xlsx' | 'html')
                         │
                 UniexporterManager ── picks the driver for the format
                         │
          RenderContext (settings for that format, data, styles, temp files)
                         │
        Driver renders each component ── writes a temporary file
                         │
                   ExportedFile ── download() / inline() / store() / save()
```

- A **document** holds page settings, named styles and a schema (a list of components). It is format independent.
- **Data** is passed at export time; component text reads it through `{placeholders}` and tables read rows from it.
- The **driver** for the requested format walks the schema. Components a driver cannot render are skipped, or throw in strict mode.
- The result is always a **temporary file** wrapped in `ExportedFile`, so exports never write to PHP's output (safe under Octane).
