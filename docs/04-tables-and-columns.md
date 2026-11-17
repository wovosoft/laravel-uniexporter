# Tables and columns

```php
Table::make('employees')
    ->columns([
        Column::make()->rowNumber()->label('SL')->width(0.5),
        Column::make('job_id')->label('PF No.')->text(),
        Column::make('person.name')->label('Name')->width(2.5),
        Column::make('designation.name')->label('Designation')->width(2),
        Column::make('joined_at')->label('Joined')->date('d/m/Y'),
        Column::make('basic_salary')->label('Basic')->money()->total(),
        Column::make('remarks')->emptyValue('-'),
    ])
    ->headerRows([[HeaderCell::make('Employee')->colspan(3), HeaderCell::make('Service')->colspan(4)]])
    ->striped()
    ->totalLabel('Grand Total')
    ->emptyText('No employees found.');
```

## Rows

`Table::make($rows)` / `->rows($rows)` takes a data path (`'employees'`, `'office.employees'`) or an iterable. Rows may be arrays, objects or Eloquent models. Iterables are consumed once, row by row, so lazy collections keep memory flat.

## Columns

`Column::make('dot.path')` reads `data_get($row, 'dot.path')`. Without `label()` the header is the headline of the key (`basic_salary` → `Basic Salary`).

| Method | Effect |
|--------|--------|
| `label(string)` | header text (placeholders allowed) |
| `width(float $weight)` | relative width (default 1); converted to percentages of the page width |
| `characters(float)` | fixed spreadsheet width in characters; other formats keep the weight |
| `rowNumber()` | 1, 2, 3… instead of a value (the label defaults to `#`) |
| `formatUsing(fn ($value, $row, Data $data) => ...)` | transform the value; receives the row and all data |
| `text()` | always text (keeps leading zeros; never a number or formula in spreadsheets) |
| `number(int $decimals = 0, bool $thousands = true)` | number; native number format in spreadsheets |
| `money(int $decimals = 2, string $prefix = '')` | number with decimals and thousands separators |
| `date(string $format = 'd/m/Y')` | date (DateTime, Carbon or parseable string); a real date cell in spreadsheets |
| `total()` | sums the column into a totals row |
| `total(fn ($sum, Data $data) => ...)` | sums the column and shows the closure's result (a number, or a text such as `'৫০ দিন'`) |
| `formula('={days}*{rate}')` | a formula per cell in spreadsheets (see below) |
| `emptyValue(string)` | text for empty values |
| `headerStyle(Style)` | style of this column's header cell |
| style methods (`center()`, `bold()`...) | style of the column's body cells |

Formatting happens in two steps: `formatUsing()` produces the value, then the value format turns it into display text (PDF, DOCX, HTML) or a typed cell (XLSX). For text in a non-Latin script, such as Bengali digits, return a string from `formatUsing()` and use `text()`.

```php
Column::make('salary')->formatUsing(fn ($value) => Number::format($value, locale: 'bn'))->text();
Column::make('designation')->formatUsing(fn ($value, $row) => "{$row->designation} ({$row->code})");
```

User data that starts with `=` is never written as a spreadsheet formula.

## Header

| Method | Effect |
|--------|--------|
| `showHeader(false)` | no header rows |
| `repeatHeader(bool = true)` | repeat the header on every page (PDF, DOCX) and as print titles (XLSX). On by default |
| `headerRows([[...], ...])` | extra rows above the column labels; cells are strings or `HeaderCell::make($text)->colspan($n)` |
| `headerStyle(Style)` | merged over the default header style |
| `headerStyle(fn (Style $current) => Style::make()...)` | replaces it (e.g. no fill) |
| `freezeHeader()` | spreadsheets keep the header rows visible while scrolling (the first frozen table of a sheet) |

The default header style is bold, vertically centered, on a light grey fill (`E7E6E6`). Header labels are aligned like their column, unless the header style sets an alignment, which then applies to every label:

```php
->headerStyle(fn () => Style::make()->align('center')->background('F5D997')->wrap(false))
```

`wrap(false)` keeps labels on one line (PDF/HTML `white-space: nowrap`), which also guides automatic column widths.

## Body

| Method | Effect |
|--------|--------|
| `striped(bool = true, string $color = 'F7F7F7')` | alternate row fill |
| `rowStyleUsing(fn ($row, int $index): ?Style => ...)` | style per row (e.g. highlight retirees) |
| `borders(Border\|bool)` | cell borders; default 0.5 pt `A6A6A6`; `false` for none |
| `padding(float $mm)` | cell padding (default 1 mm) |
| `emptyText(string)` | a full-width row when there are no rows |
| `autoWidths()` | PDF and HTML size columns from their content instead of the weights |
| style methods on the table | base style of all cells (font, size, `wrap(false)`, `verticalAlign()`...) |

```php
->rowStyleUsing(fn ($row) => $row->job_status === 'prl' ? Style::make()->background('FFF2CC') : null)
```

Body cells sit at the top and wrap long text in every format. A table or column style can change that, for example `->style(Style::make()->wrap(false)->verticalAlign('bottom'))`.

## Totals

Columns with `total()` are summed (numeric values only) into a totals row after the data. The label goes in the first column without a total; set it with `totalLabel()` and style the row with `totalStyle()` (default bold on `F2F2F2`). In spreadsheets totals are numbers with the column's number format.

- `total(fn ($sum, Data $data) => ...)` changes what the totals cell shows. Return a number to keep it numeric, or a text.
- `totalLabel('Total', colspan: 3)` widens the label over the next columns, up to the first total.
- `totalFormulas()` makes spreadsheets write numeric totals as `=SUM(...)` over the body rows, so they follow edits.

## Formulas

`formula()` writes a spreadsheet formula in each body cell of the column. `{key}` is the cell of the column with that key in the same row and `{row}` the row number:

```php
Column::make('payable')
    ->formula('={days}*{rate}-{deduction}')                                   // spreadsheets
    ->formatUsing(fn ($value, $row) => $row->days * $row->rate - $row->deduction)  // other formats
    ->total();
```

Other formats show the column's value as usual. Only formulas set this way are written: user data starting with `=` stays text.

## Column widths per format

- **PDF / HTML**: weights become percentages of the table width; with `autoWidths()` the content decides.
- **DOCX**: weights become percentages of the page width.
- **XLSX**: the first table of a sheet sets the sheet's column widths, from `characters()` or from the weights scaled to the printable width.

## Hiding columns in some formats

Columns support `hiddenOn()`, `visibleOn()` and `on()` like any component; the table drops hidden columns for that format:

```php
Column::make('internal_note')->hiddenOn('xlsx');
Column::make('designation')->on('pdf', fn (Column $c) => $c->formatUsing(fn ($v, $row) => $row->designation_code));
```

`headerRows()` are not adjusted when columns are hidden; set their colspans for the visible columns.
