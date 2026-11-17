# Documents and data

## Document classes

Extend `Document` and override what you need:

```php
class SalaryStatement extends Document
{
    public function settings(Settings $settings): Settings { /* page setup, see 06-page-setup.md */ }

    /** @return array<string, Style> */
    public function styles(): array { /* named styles */ }

    /** @return list<Component> */
    public function schema(): array { /* the components */ }
}
```

A document can take constructor arguments for things that change its shape (language, options); data that only fills it goes through `data()`:

```php
class ContactsList extends Document
{
    public function __construct(private readonly string $lang = 'bn') {}
    // settings() and schema() branch on $this->lang
}

(new ContactsList('en'))->data(['contacts' => $rows])->exportToPdf();
```

## Inline documents

For one-off exports, build the document fluently:

```php
Document::make()
    ->configure(fn (Settings $settings) => $settings->landscape()->filename('report'))
    ->withStyles(['muted' => Style::make()->color('666666')])
    ->components([
        Heading::make('Report'),
        Table::make('rows')->columns([...]),
    ])
    ->data(['rows' => $rows])
    ->exportToPdf();
```

- `components(array)` sets the schema (`schema()` returns it unless overridden).
- `configure(Closure)` runs after `settings()`; several calls run in order. The closure receives the `Settings` and may return it or nothing. Use it to adjust a class-based document from the outside:

```php
$document = new SalaryStatement();

if ($request->boolean('data_only')) {
    $document->configure(fn (Settings $settings) => $settings->withoutHeader()->withoutFooter());
}
```

- `withStyles(array)` adds named styles to those of `styles()`.

The same document can also be written as one chain of page settings, content and data (`->landscape()->heading('Report')->table('rows', fn (Table $table) => ...)->withData($data)`); see [Chainable API](10-chainable-api.md).

## Data

`data()` accepts an array, an `Arrayable` (collections, models) or a `Support\Data`. Data can also be attached to the document beforehand with `withData()` and `with($key, $value)`; `data()` then merges over it, and a document with attached data can export directly ([Chainable API](10-chainable-api.md#data)). It is read in two ways.

### Placeholders

Any text (headings, text, runs, links, list items, key/value pairs, header cells, column labels, page header and footer slots, document title and metadata, image sources) may contain `{path}` placeholders. Paths use dot notation into the data:

```php
Text::make('Office: {office.name} ({office.code})');
```

- Values are formatted as text: numbers and strings as is, dates as `d/m/Y`, backed enums by value, booleans as `Yes`/`No`, `null` as an empty string.
- A placeholder that matches nothing is left as written, so literal braces are safe.
- In page headers and footers, `{page}`, `{pages}` and `{date}` (today) are filled per page by each driver.

### Row sources

Tables and lists read rows from a data path or take them directly:

```php
Table::make('employees');                 // $data['employees']
Table::make('office.employees');          // nested path
Table::make($rowsIterable);               // rows given directly
ListBlock::make()->source('notes');       // list items from $data['notes']
```

Rows can be arrays, objects or models; columns read them with `data_get()` (dot paths). Any iterable works, including generators and lazy collections, which are consumed once while rendering.

## Named styles

```php
public function styles(): array
{
    return [
        'muted' => Style::make()->color('666666')->italic(),
        'total' => Style::make()->bold()->background('F2F2F2'),
    ];
}

Text::make('Generated automatically')->style('muted');
```

A component's named style applies before its inline style. In strict mode an unknown style name throws.

## Render hooks

Run code with the driver's native object before or after rendering a format:

```php
$document
    ->beforeRender('pdf', fn (\Mpdf\Mpdf $mpdf, RenderContext $context) => $mpdf->SetProtection(['print']))
    ->afterRender('docx', fn (\PhpOffice\PhpWord\PhpWord $word, RenderContext $context) => ...);
```

| Format | Hook arguments |
|--------|----------------|
| `pdf`  | `Mpdf $mpdf, RenderContext $context` |
| `docx` | `PhpWord $phpWord, RenderContext $context` |
| `xlsx` | `OpenSpout\Writer\XLSX\Writer $writer, RenderContext $context` |
| `html` | `RenderContext $context` |

More native access is described in [Extending](08-extending.md).

## The render context

Drivers, hooks and custom renderers receive a `Drivers\RenderContext`:

| Member | Use |
|--------|-----|
| `$context->format` | the format being rendered |
| `$context->settings` | the `Settings` for this format (after `on()` adjustments) |
| `$context->data` | the `Support\Data` |
| `$context->document` | the document |
| `text(string $template, array $extra = [])` | fills placeholders |
| `style(Component $component, ?Style $inherited)` | the effective style of a component |
| `option(string $key, $default)` | a driver option (dot notation) |
| `imagePath(string $source)` | resolves an image source to a local file |
| `temporaryFile(string $contents, string $extension)` | writes a file that is removed after the export |
