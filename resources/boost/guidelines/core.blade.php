## Laravel Uniexporter

- Build PDF, DOCX, XLSX, CSV and HTML exports with one `Wovosoft\LaravelUniexporter\Document` (a class with `settings()` and `schema()`, or the chainable `Document::make()->heading()->table()->withData()` API) instead of writing a Blade view, a PhpWord builder and an OpenSpout writer per format.
- Serve every format of a document from one controller using `Wovosoft\LaravelUniexporter\Concerns\ExportsByFormat` and one `export/{format}` route constrained with `->whereIn('format', Controller::FORMATS)`.
- Each element is a component and each driver renders it; for a new kind of element add a component and a renderer per driver.
- Activate the `laravel-uniexporter` skill when adding, changing, migrating or debugging any export or report download.
