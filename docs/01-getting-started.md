# Getting started

## Requirements

- PHP 8.4+
- Laravel 12 or 13 (`illuminate/support`, `illuminate/filesystem`)
- The library of each format you use:

| Format | Driver | Install |
|--------|--------|---------|
| `pdf`  | `Drivers\Mpdf\MpdfDriver` | `composer require mpdf/mpdf:^8.2` |
| `docx` | `Drivers\PhpWord\PhpWordDriver` | `composer require phpoffice/phpword:^1.2` |
| `xlsx` | `Drivers\OpenSpout\OpenSpoutDriver` | `composer require openspout/openspout:^5.0` |
| `html` | `Drivers\Html\HtmlDriver` | nothing |

Exporting to a format whose library is missing throws `MissingDependencyException`, naming the package to install.

## Installation

```bash
composer require wovosoft/laravel-uniexporter
php artisan vendor:publish --tag=uniexporter.config
```

The service provider and the `Uniexporter` facade are auto-discovered.

## Configuration

`config/uniexporter.php`:

```php
return [
    // format => driver class (or register more with Uniexporter::extend())
    'formats' => [
        'pdf'  => MpdfDriver::class,
        'docx' => PhpWordDriver::class,
        'xlsx' => OpenSpoutDriver::class,
        'html' => HtmlDriver::class,
    ],

    // Where exports and the libraries' temporary files go (default: storage/app/uniexporter)
    'temp_dir' => env('UNIEXPORTER_TEMP_DIR'),

    // Throw instead of skipping components a driver cannot render (useful in tests)
    'strict' => env('UNIEXPORTER_STRICT', false),

    // Options handed to each driver; Settings::native() overrides them per document
    'drivers' => [
        'pdf' => [
            'config'    => [],   // merged into the mPDF constructor config
            'font_dir'  => [],   // extra font directories
            'font_data' => [],   // mPDF 'fontdata' entries, keys lowercase
        ],
        'docx' => [],
        'html' => [],
        'xlsx' => [
            'options' => [],     // named arguments for OpenSpout\Writer\XLSX\Options
        ],
    ],
];
```

Add the temporary directory to `.gitignore` (for example `/storage/app/uniexporter`).

### Fonts for Bengali and other complex scripts

mPDF needs every font it uses registered, with OpenType layout enabled for correct shaping:

```php
'pdf' => [
    'font_dir'  => [storage_path('fonts')],
    'font_data' => [
        'nikosh'       => ['R' => 'Nikosh.ttf', 'B' => 'Shonarb.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
        'kalpurush'    => ['R' => 'kalpurush.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
        'solaimanlipi' => ['R' => 'SolaimanLipi.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
    ],
],
```

The key is the font family in lowercase without spaces: `->font('SolaimanLipi')` in a document uses the `solaimanlipi` entry. DOCX and XLSX files do not embed fonts; readers need the fonts installed. The HTML page asks the browser for the family name and falls back to `sans-serif`.

## AI agent skill

The package ships a skill and a guideline for AI coding agents (Claude Code, Copilot, Codex, Junie, Cursor...) in `resources/boost/`:

- `resources/boost/skills/laravel-uniexporter/SKILL.md`: how to build, serve, migrate and test exports with this package.
- `resources/boost/skills/laravel-uniexporter/references/api.md`: every public method with its signature, generated from the source. Regenerate it after changing the public API: `php scripts/skill-reference.php`.
- `resources/boost/guidelines/core.blade.php`: a short rule added to the agents' guidelines, pointing them to the skill.

**With [Laravel Boost](https://github.com/laravel/boost)**, nothing needs publishing: Boost discovers both in every direct dependency. Run `php artisan boost:install` (or `boost:update`, which asks about newly found packages) and select `wovosoft/laravel-uniexporter`, or add it to `packages` in `boost.json` and run `php artisan boost:update`. Boost writes the skill for every configured agent and keeps it in sync on each update.

**Without Boost**, publish the skill into the project:

```bash
php artisan vendor:publish --tag=uniexporter.skills     # .claude/skills/laravel-uniexporter
```

Project-specific conventions (your base report classes, fonts, routes) belong in a separate project skill, for example `.ai/skills/<project>-exports/SKILL.md` with Boost; a project skill named `laravel-uniexporter` would replace the package's.

## A first export

```php
use Wovosoft\LaravelUniexporter\Components\{Column, Heading, Table, Text};
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Settings;

class EmployeeList extends Document
{
    public function settings(Settings $settings): Settings
    {
        return $settings
            ->paper('A4')->landscape()
            ->font('Nikosh', 11)
            ->title('Employees of {office}')
            ->filename('employees')
            ->footer(center: 'Page {page} of {pages}');
    }

    public function schema(): array
    {
        return [
            Heading::make('Employees of {office}')->center(),
            Text::make('Printed on {date}')->right(),
            Table::make('employees')->columns([
                Column::make()->rowNumber()->label('SL'),
                Column::make('job_id')->label('PF No.')->text(),
                Column::make('person.name')->label('Name')->width(2),
                Column::make('joining_date')->date('d/m/Y'),
                Column::make('basic_salary')->money()->total(),
            ]),
        ];
    }
}
```

In a controller:

```php
public function __invoke(Request $request, Office $office)
{
    return (new EmployeeList())
        ->data([
            'office'    => $office->name,
            'date'      => now()->format('d/m/Y'),
            'employees' => $office->employees()->with('person')->lazy(),
        ])
        ->export($request->input('format', 'pdf'));   // an ExportedFile is a Responsable (download)
}
```

## Exporting

```php
$pending = $document->data($data);   // PendingExport

$file = $pending->exportToPdf();     // or exportToDocx(), exportToExcel(), export('html')
```

A document without data can export directly: `$document->exportToPdf()`.

`ExportedFile` is the rendered file in the temporary directory:

| Method | Result |
|--------|--------|
| `download(?string $name = null)` | `BinaryFileResponse` as an attachment; the temporary file is deleted after sending |
| `inline(?string $name = null)` | the same, shown in the browser (PDF, HTML) |
| returning it from a controller | same as `download()` (it is `Responsable`) |
| `store(string $path, $disk = null)` | writes it to a filesystem disk (name, `Filesystem` or default), deletes the temporary file, returns `$path` |
| `save(string $path)` | moves it to a local path |
| `contents()`, `path()` | read it |
| `filename(?string $name)` | the download name with the right extension (`Settings::filename()` by default) |
| `extension()`, `mimeType()` | `pdf` / `application/pdf`, etc. |
| `delete()` | removes the file |

Download names may contain any Unicode characters; an ASCII fallback is sent for old clients.

### One controller for every format

Instead of a controller per format, the `ExportsByFormat` trait serves every format of a document from one controller and one route. Build the document once in `document($request, $format)`, route `{format}` to the controller with `whereIn()`, and call `exportAs()`:

```php
use Wovosoft\LaravelUniexporter\Concerns\ExportsByFormat;
use Wovosoft\LaravelUniexporter\PendingExport;

class EmployeesExport
{
    use ExportsByFormat;

    public const array FORMATS = ['pdf', 'excel', 'docx'];

    public function __invoke(EmployeesRequest $request, string $format): BinaryFileResponse
    {
        return $this->exportAs($request, $format);
    }

    protected function document(EmployeesRequest $request, string $format): PendingExport
    {
        return EmployeesDocument::make()->data(['employees' => Employee::query()->filter($request)->lazy()]);
    }
}

Route::get('employees/export/{format}', EmployeesExport::class)
    ->whereIn('format', EmployeesExport::FORMATS)      // other formats are a 404
    ->name('employees.export');

route('employees.export', ['format' => 'pdf', 'office_id' => 5]);
```

- `exportAs()` calls the protected `pdf()`, `html()`, `excel()` (also `xlsx`), `docx()` or `csv()` method, which export the document: PDF and HTML open in the browser (`inline()`), the others download. They are protected so they can't be routed directly, around your form request. Any other format registered with `Uniexporter::extend()` downloads too.
- `document()` is not declared abstract, so it can take your form request. `$format` is the format as requested (`excel` or `xlsx`, `pdf`...), for exports whose data differs per format (for example a paged PDF and a full spreadsheet).
- Formats missing from the class's `FORMATS` are refused even if a route forgets `whereIn()`: a 404 in Laravel (`NotFoundHttpException`), an `UnknownFormatException` elsewhere. Override `exportManager()` to render with a manager other than the application's.

### Octane and queues

Drivers always write to a file, never to PHP's output, and the response streams that file. This works the same under PHP-FPM and Octane (FrankenPHP, Swoole, RoadRunner). For very large exports, render in a queued job and `store()` the file:

```php
$path = (new EmployeeList())->data($data)->exportToExcel()->store("exports/employees-{$id}.xlsx", 's3');
```

Pass lazy collections (`->lazy()`, `->cursor()`) as table rows: tables consume rows one at a time, and the XLSX and HTML drivers write as they go.
