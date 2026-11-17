# Extending

From lightest to heaviest: change a component's native output, write native content, teach a driver a new component, or add a driver.

## Native callbacks on a component

After rendering a component, the driver calls its native callbacks with the objects it produced:

```php
Table::make('rows')
    ->columns([...])
    ->native('xlsx', function (Writer $writer, Sheet $sheet, array $rows, RenderContext $context): void {
        // $rows = ['from' => first row, 'to' => last row], 1-based
        $sheet->setAutoFilter(...);
    })
    ->native('docx', function (\PhpOffice\PhpWord\Element\Table $table, AbstractContainer $container, RenderContext $context): void {
        // adjust the PhpWord table
    })
    ->native('pdf', fn (string $html, Mpdf $mpdf, RenderContext $context): ?string => str_replace('<table', '<table class="x"', $html))
    ->native('html', fn (string $html, RenderContext $context): ?string => $html);
```

| Format | Callback arguments | Return |
|--------|--------------------|--------|
| `pdf`  | `string $html, Mpdf $mpdf, RenderContext $context` | replacement HTML, or `null` to keep it |
| `html` | `string $html, RenderContext $context` | replacement HTML, or `null` |
| `docx` | `$element` (the PhpWord `Table`, `TextRun`, `Image`...), `AbstractContainer $container, RenderContext $context` | — |
| `xlsx` | `Writer $writer, Sheet $sheet, array $rows, RenderContext $context` | — |

## Native components

Content written directly with the library, in schema order; see [Components](03-components.md#native).

## Render hooks

`beforeRender()` / `afterRender()` per format; see [Documents and data](02-documents-and-data.md#render-hooks).

## Custom components

Create a component class, then teach each driver to render it. A renderer receives the component, the render context, the driver, and the driver's extra arguments:

| Driver | Extra arguments |
|--------|-----------------|
| `MpdfDriver`, `HtmlDriver` | `?Style $inherited, bool $inline` (inline: inside a `Columns` block) |
| `PhpWordDriver` | `AbstractContainer $container, ?Style $inherited` |
| `OpenSpoutDriver` | `?Style $inherited` |


```php
use Wovosoft\LaravelUniexporter\Components\Component;

class Signature extends Component
{
    public function __construct(public string $name = '', public string $title = '') {}
}

// in a service provider's boot()
MpdfDriver::renderUsing(Signature::class, function (Signature $s, RenderContext $context, MpdfDriver $driver): void {
    $driver->getMpdf()->WriteHTML('<p>'.e($s->name).'<br>'.e($s->title).'</p>');
});
PhpWordDriver::renderUsing(Signature::class, fn (Signature $s, RenderContext $context, PhpWordDriver $driver, AbstractContainer $container, ?Style $inherited) => ...);
```

Usually it is simpler to build the custom component out of existing ones, which needs no renderers:

```php
class Letterhead
{
    public static function make(): Columns
    {
        return Columns::make([
            [Image::make(public_path('logo.png'))->width(13)],
            Group::make([Text::make('Acme Bank Ltd.')->bold()->color('035203'), Text::make('Head Office')])->center(),
        ])->widths([1, 4])->hiddenOn('xlsx');
    }
}
```

Notes:

- The PDF renderer above writes straight to mPDF, which keeps schema order for top-level components; inside a `Group` or `Columns` block, prefer composing existing components (above).

- Renderers are registered per driver class: `MpdfDriver` extends `HtmlDriver`, but a renderer for `HtmlDriver` does not apply to `MpdfDriver`; register both.
- Registration is global to the PHP process (static); do it once at boot, not per request. `flushCustomRenderers()` clears a driver's renderers (tests).
- Without a renderer, a driver falls back to the renderer of the component's parent class, then skips the component (or throws `UnsupportedComponentException` in strict mode).

## Custom drivers

Implement `Drivers\Driver`, or extend `Drivers\AbstractDriver` for `render<Component>()` dispatch:

```php
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Drivers\AbstractDriver;
use Wovosoft\LaravelUniexporter\Drivers\RenderContext;
use Wovosoft\LaravelUniexporter\Support\TableRows;

class CsvDriver extends AbstractDriver
{
    public function __construct(private readonly array $options = []) {}

    public function extension(): string { return 'csv'; }

    public function mimeType(): string { return 'text/csv'; }

    public function render(RenderContext $context, string $path): void
    {
        $file = fopen($path, 'wb');
        foreach ($context->document->schema() as $component) {
            $this->renderComponent($component, $context, $file);
        }
        fclose($file);
    }

    protected function renderTable(Table $table, RenderContext $context, $file): void
    {
        $rows = new TableRows($table, $context->data);
        fputcsv($file, array_map(fn ($column) => $context->text($column->getLabel()), $table->getColumns()));
        foreach ($rows->rows() as $row) {
            fputcsv($file, $row->texts);
        }
    }
}
```

`renderComponent()` applies the component's per-format adjustments and visibility before dispatching. `Support\TableRows` gives table rows with formatted texts, typed values, row styles and totals.

Register the driver in `config/uniexporter.php` (`'formats' => ['csv' => CsvDriver::class]`) or at boot:

```php
use Wovosoft\LaravelUniexporter\Facades\Uniexporter;

Uniexporter::extend('csv', CsvDriver::class);
Uniexporter::extend('pdf', fn (array $options, $manager) => new MyPdfDriver($options));   // replace a driver
```

The manager is a singleton: call `extend()` from a service provider, not per request. Then `$document->export('csv')`.

Driver contract:

- `render()` writes the complete file to `$path`; it must never write to PHP's output.
- Driver options from the config and `Settings::native()` are passed to the constructor and available as `$context->option()`.
- Use `$context->temporaryFile()` for scratch files; they are removed after the export.
