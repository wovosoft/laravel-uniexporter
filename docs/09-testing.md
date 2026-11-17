# Testing

## The package's tests

In a checkout of the package:

```bash
composer update
composer test          # or vendor/bin/phpunit
```

When the package lives inside an application (`packages/wovosoft/laravel-uniexporter`, installed through a path repository), the tests can run against the application's `vendor/` instead, from the application root:

```bash
php vendor/bin/phpunit -c packages/wovosoft/laravel-uniexporter/phpunit.xml
```

The bootstrap uses the package's own `vendor/` when it exists, otherwise the application's; `UNIEXPORTER_AUTOLOAD` points it at any other `autoload.php`. GitHub Actions runs the tests on PHP 8.4 and 8.5 for every push and pull request. PDF text checks use `pdftotext` (poppler-utils) when installed and are skipped otherwise.

| Test | Covers |
|------|--------|
| `SmokeTest` | every component in every format, in strict mode |
| `PdfExportTest` | HTML sent to mPDF, text and page numbers in the PDF, large tables, native hooks and options |
| `DocxExportTest` | document XML, page breaks, PhpWord global settings, native hooks |
| `XlsxExportTest` | typed rows across sheets, formulas, merges, print setup, shared styles, wrapping, native hooks |
| `HtmlExportTest` | standalone page, escaping, embedded images, class-based cell styles, native hooks |
| `FormatAdjustmentsTest` | `Settings::on()` and `Component::on()` |
| `PageAndTableLayoutTest` | watermarks, component headers, header alignment, auto widths, line height, spreadsheet widths, freezing, fit to width |

Fixtures: `Tests\Fixtures\SalaryStatement` uses every component; `SampleData` provides rows (including Bengali text and lazy generators) and a test image.

## Testing your exports

Call the route and read the file behind the `BinaryFileResponse`:

```php
public function test_the_contacts_list_exports_as_excel(): void
{
    $response = $this->actingAs($user)->get(route('contacts.export-excel', ['per_page' => 20]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $path = $response->baseResponse->getFile()->getPathname();

    try {
        $zip = new ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');   // OpenSpout writes inline strings here
        $zip->close();

        $this->assertStringContainsString('নাম', $sheet);
    } finally {
        @unlink($path);   // tests do not send the response, so the file is not deleted for you
    }
}
```

Or export directly, without HTTP:

```php
$file = (new ContactsList('bn'))->data(['contacts' => $rows])->exportToPdf();

$this->assertStringStartsWith('%PDF', $file->contents());
$file->delete();
```

What to read per format:

| Format | Where the content is |
|--------|---------------------|
| PDF | `pdftotext file.pdf -` (Bengali conjuncts come out as private-use characters; compare IDs, digits and Latin text) |
| DOCX | `word/document.xml`, `word/header1.xml`, `word/footer1.xml` in the zip |
| XLSX | `xl/worksheets/sheetN.xml` (inline strings), `xl/styles.xml`, `xl/workbook.xml`; or read it back with `OpenSpout\Reader\XLSX\Reader` |
| HTML | the file itself |

Set `UNIEXPORTER_STRICT=true` in `phpunit.xml` so components a driver cannot render, and unknown named styles, fail the test instead of being skipped.
