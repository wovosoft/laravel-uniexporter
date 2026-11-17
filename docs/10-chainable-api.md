# Chainable API

Besides document classes and `components([...])`, a document can be written as one chain: page setup, content and data, then the export. Each content method creates the same component as the schema API and appends it, so every driver renders it the same way and everything in the other pages applies.

```php
use Wovosoft\LaravelUniexporter\Components\{Column, Columns, Group, Heading, Table, Text};
use Wovosoft\LaravelUniexporter\Facades\Uniexporter;

return Uniexporter::document()                       // or Document::make()
    ->paper('A4')->landscape()->margins(10)
    ->font('Nikosh', 10)
    ->title('Employees of {office}')
    ->filename('employees')
    ->footer(right: '{page}/{pages}')
    ->with('office', $office->name)
    ->withData(['employees' => $office->employees()->with('person')->lazy()])
    ->heading('Employees of {office}', tap: fn (Heading $heading) => $heading->center())
    ->table('employees', fn (Table $table) => $table
        ->column(label: 'SL', tap: fn (Column $column) => $column->rowNumber())
        ->column('job_id', 'PF No.', fn (Column $column) => $column->text())
        ->column('person.name', 'Name', fn (Column $column) => $column->width(2.5))
        ->column('basic_salary', 'Basic', fn (Column $column) => $column->money()->total())
        ->totalLabel('Total'))
    ->columns(fn (Columns $columns) => $columns
        ->block(fn (Group $block) => $block->text('Prepared by'))
        ->block(fn (Group $block) => $block->text('Approved by'), width: 2))
    ->exportToPdf()
    ->inline();
```

## Page setup

Every `Settings` method that sets something can be called on the document: `paper()`, `orientation()`, `portrait()`, `landscape()`, `margins()`, `font()`, `fontSize()`, `color()`, `title()`, `author()`, `subject()`, `keywords()`, `header()`, `footer()`, `firstPageHeader()`, `firstPageFooter()`, `headerContent()`, `footerContent()`, `watermark()`, `fitToWidth()`, `withoutHeader()`, `withoutFooter()`, `filename()`, `native()` and `on()`. Each call is recorded with `configure()`, so the calls run in order after `settings()`, and per-format adjustments work as usual:

```php
Document::make()
    ->landscape()
    ->on('xlsx', fn (Settings $settings) => $settings->portrait()->withoutFooter());
```

`headerContent()` and `footerContent()` also take a closure that fills a `Group` with the content methods below:

```php
->headerContent(fn (Group $header) => $header
    ->text('Bangladesh Krishi Bank', fn (Text $text) => $text->bold()->center())
    ->text('{office}', fn (Text $text) => $text->center()))
```

See [Page setup](06-page-setup.md) for what each setting does.

## Content

Each method appends a component and returns the document. The optional last closure receives the new component to configure it (any method of that component); if it returns a component, that one is added instead.

| Method | Adds | Example |
|--------|------|---------|
| `heading($text, $level = 1, $tap)` | `Heading` | `->heading('Report', 2)` |
| `text($text, $tap)` | `Text` | `->text('Office: {office}', fn (Text $t) => $t->right())` |
| `text(fn (Text $text) => ...)` | `Text` from runs | `->text(fn (Text $t) => $t->run('Note: ', fn (Run $r) => $r->bold())->run('see below'))` |
| `link($text, $url, $tap)` | `Link` | `->link('Portal', 'https://portal.example.com/{id}')` |
| `image($source, $tap)` | `Image` | `->image(public_path('logo.png'), fn (Image $i) => $i->width(20))` |
| `list($items, $tap)` | `ListBlock` | `->list(['One', 'Two'], fn (ListBlock $l) => $l->ordered())` |
| `keyValue($items, $tap)` | `KeyValue` | `->keyValue(['Month' => '{month}'], fn (KeyValue $k) => $k->columns(2))` |
| `table($rows, $build)` | `Table` | see [Tables](#tables) |
| `columns($build)` | `Columns` | see [Side by side](#side-by-side-and-groups) |
| `group($build)` | `Group` | see [Side by side](#side-by-side-and-groups) |
| `spacer($mm = 5)` | `Spacer` | `->spacer(10)` |
| `divider($tap)` | `Divider` | `->divider(fn (Divider $d) => $d->border(1, '000000'))` |
| `pageBreak()` | `PageBreak` | |
| `sheet($name)` | `Sheet` | `->sheet('Summary')` |
| `nativeContent($build)` | `Native` | `->nativeContent(fn (Native $n) => $n->for('pdf', fn (Mpdf $mpdf) => ...))` |
| `add(...$components)` | any components | `->add(Heading::make('A'), $myComponent)` |

Placeholders, named styles (`->text('...', fn (Text $t) => $t->style('muted'))`), `hiddenOn()`, `visibleOn()` and `on()` work in the closures as on any component.

### Tables

`table()` takes a data path or the rows, and a closure that adds the columns. `Table::column($key, $label, $tap)` appends a column; without a label the header is the headline of the key, and an empty key makes a computed or row-number column. `Table::headerRow([...])` appends a header row above the labels.

```php
->table('employees', fn (Table $table) => $table
    ->subRows('degrees')
    ->headerRow([HeaderCell::make('Employee')->colspan(3), HeaderCell::make('Basic')->rowspan(2)])
    ->column(label: 'SL', tap: fn (Column $column) => $column->rowNumber())
    ->column('person.name', 'Name')
    ->column('exam', 'Exam', fn (Column $column) => $column->fromSubRow())
    ->column('basic_salary', 'Basic', fn (Column $column) => $column->money()->total())
    ->repeatHeader()
    ->striped())
```

All table and column options are in [Tables and columns](04-tables-and-columns.md); `columns([...])` and `headerRows([...])` still replace the lists at once.

### Side by side and groups

`columns()` passes a `Columns` component; `Columns::block()` appends a block, built by a closure that fills a `Group`, or given as a component or a list of components. The second argument is the block's relative width (1 by default).

```php
->columns(fn (Columns $columns) => $columns
    ->block(fn (Group $block) => $block->divider()->text('Prepared by'))
    ->block(fn (Group $block) => $block->divider()->text('Approved by'))
    ->block(Image::make('{signature}'), width: 0.5))
```

`group()` passes a `Group`, which has the same content methods as the document, so groups nest:

```php
->group(fn (Group $group) => $group
    ->heading('Signatures', 3)
    ->columns(fn (Columns $columns) => ...)
    ->keepTogether()
    ->font('Nikosh'))
```

### Conditions and loops

`when()` and `unless()` (Laravel's `Conditionable`) and `each()` keep the chain going on documents and groups:

```php
Document::make()
    ->heading('Transfers')
    ->when($request->boolean('with_summary'), fn (Document $document) => $document->keyValue(['Total' => '{total}']))
    ->each($offices, fn (Document $document, Office $office) => $document
        ->heading($office->name, 2)
        ->table($office->transfers, fn (Table $table) => $table->column('person.name', 'Name')->column('date', 'Date', fn (Column $c) => $c->date()))
        ->pageBreak());
```

## Data

`withData($data)` attaches data to the document (an array, an `Arrayable` or a `Support\Data`); later calls merge over earlier ones. `with($key, $value)` attaches one value. A document with attached data exports directly:

```php
$document->withData(['rows' => $rows])->exportToExcel();
```

Data given to `data()` at export time is merged over the attached data, so a document can carry defaults:

```php
$document->with('office', 'Head Office')->data(['office' => $office->name])->exportToPdf();
```

Rows of tables built with `table($rows)` can also be given directly instead of through data.

## Mixing the APIs

The chain appends to the same list as `components([...])`, so both can be used on one document (`components()` replaces the list, so call it first). A document class can use the content methods too: the default `schema()` returns what they added, and a class that overrides `schema()` can merge it with `[...parent::schema(), ...]`.

Because `Document` now has public `table()`, `text()`, `heading()`... methods, a subclass cannot declare a helper with one of these names; name helpers differently (for example `reportTable()`).
