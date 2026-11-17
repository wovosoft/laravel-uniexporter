# Styling

## The Style object

`Style\Style` is immutable and format independent. Every property is optional; `null` means "inherit".

```php
Style::make()
    ->font('Nikosh', 11)            // family, optional size in points
    ->size(10)                      // points
    ->bold()->italic()->underline()->strike()
    ->color('035203')               // hex: 3 or 6 digits, with or without '#'
    ->background('F5D997')
    ->align('center')               // Align::Left|Center|Right|Justify or the string
    ->verticalAlign('middle')       // VerticalAlign::Top|Middle|Bottom
    ->border(0.75, '000000')        // points and color, or a Border object
    ->padding(1)                    // millimetres
    ->spaceBefore(2)->spaceAfter(2) // millimetres
    ->wrap(false)                   // keep text on one line
    ->lineHeight(1.15);             // multiple of the font size (PDF, HTML, DOCX)
```

Units: font sizes in points, everything else in millimetres, borders in points.

Components take the same methods directly (`Text::make('x')->bold()->center()`), plus:

- `style(Style $style)` merges a style object,
- `style('name')` uses a [named style](02-documents-and-data.md#named-styles),
- `style(fn (Style $current): Style => ...)` transforms the current inline style,
- `left()`, `center()`, `right()`, `justify()` as shortcuts.

## How a component's style is resolved

From weakest to strongest:

1. the document's default style (`Settings::font()`, `fontSize()`, `color()`),
2. text properties inherited from the container (a `Group`, a `Columns` block): font, size, bold, italic, underline, strike, color, alignment, line height,
3. the component's default style (for example the heading size, a table's padding),
4. its named style,
5. its inline style.

`$a->merge($b)` returns a style where every property set on `$b` replaces `$a`'s. Because `null` means "inherit", merging cannot remove a property (such as a fill); replace the style instead, for example `Table::headerStyle(fn () => Style::make()...)`.

## Defaults

| Component | Default style |
|-----------|---------------|
| document | 10 pt, black |
| `Heading` | bold, size by level (1: 16 pt, 2: 14 pt...), 2 mm after |
| `Text` | 1.5 mm after |
| `KeyValue` | 2 mm after |
| `Table` | 1 mm cell padding, 3 mm after; header bold on `E7E6E6`; totals bold on `F2F2F2`; borders 0.5 pt `A6A6A6` |
| `Divider` | 0.5 pt `999999` rule, 1 mm before, 2 mm after |
| page header/footer text | 8 pt, `595959` |

## Borders

```php
use Wovosoft\LaravelUniexporter\Style\{Border, BorderLine};

new Border(0.75, '000000');                         // width in points, color
new Border(1, 'CC0000', BorderLine::Dashed);        // Solid, Dashed, Dotted, Double, None
Border::none();
```

In spreadsheets widths map to thin (≤ 0.75 pt), medium (≤ 1.5 pt) and thick borders.

## Format differences

- **XLSX** has no padding, spacing or line height; text sizes are rounded to whole points; `wrap` maps to "wrap text".
- **DOCX** has no cell-level padding per side; spacing maps to paragraph spacing; fonts are not embedded.
- **HTML / PDF** render the same CSS. mPDF supports a subset of CSS; the drivers only emit what it handles.

Adjust styles per format with `on()`: see [Formats](07-formats.md#per-format-adjustments).
