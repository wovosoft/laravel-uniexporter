<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Writer;
use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\Heading;
use Wovosoft\LaravelUniexporter\Components\Native;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Settings;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SalaryStatement;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SampleData;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

class XlsxExportTest extends TestCase
{
    public function test_it_writes_typed_rows_across_sheets(): void
    {
        $export = $this->export(new SalaryStatement(), SampleData::report(), 'xlsx');
        $sheets = $this->readSheets($export->path());

        $this->assertSame(['Summary', 'Notes'], array_keys($sheets));

        $summary = $sheets['Summary'];
        $this->assertContains(['Salary Statement'], array_map(fn (array $row): array => array_slice($row, 0, 1), $summary));

        $headerIndex = array_search(['SL', 'PF No', 'Name', 'Designation', 'Joined', 'Basic', 'Remarks'], $summary, true);
        $this->assertIsInt($headerIndex, 'The column label row is missing.');
        $this->assertSame(['Employee', '', '', 'Service', '', ''], array_slice($summary[$headerIndex - 1], 0, 6));

        $first = $summary[$headerIndex + 1];
        $this->assertSame(1, $first[0]);
        $this->assertSame('রহিম উদ্দিন', $first[2]);
        $this->assertInstanceOf(DateTimeInterface::class, $first[4]);
        $this->assertSame('2015-03-01', $first[4]->format('Y-m-d'));
        $this->assertSame(35500.5, $first[5]);
        $this->assertSame('-', $summary[$headerIndex + 2][6], 'Empty values use the column emptyValue.');

        $totals = $summary[$headerIndex + 4];
        $this->assertSame('Grand Total', $totals[0]);
        $this->assertEqualsWithDelta(115500.75, $totals[5], 0.001);

        $notes = array_merge(...$sheets['Notes']);
        $this->assertContains('Notes', $notes);
        $this->assertNotContains('Only in PDF', $notes);
        $this->assertNotContains('Hidden in spreadsheets', $notes);
    }

    public function test_spreadsheet_specific_features_are_written(): void
    {
        $export = $this->export(new SalaryStatement(), SampleData::report(), 'xlsx');
        $sheetXml = $this->zipEntry($export->path(), 'xl/worksheets/sheet1.xml');

        $this->assertStringNotContainsString('<f>', $sheetXml, 'User text starting with "=" must not become a formula.');
        $this->assertStringContainsString('HYPERLINK', $sheetXml);
        $this->assertStringContainsString('<mergeCell ', $sheetXml);
        $this->assertStringContainsString('&amp;P', $sheetXml);
        $this->assertStringContainsString('&amp;N', $sheetXml);
        $this->assertStringContainsString('Print_Titles', $this->zipEntry($export->path(), 'xl/workbook.xml'));

        $styles = $this->zipEntry($export->path(), 'xl/styles.xml');
        $this->assertStringContainsString('numFmtId="4"', $styles, 'Money uses the built-in "#,##0.00" format.');
        $this->assertStringContainsString('dd/mm/yyyy', $styles);
        $this->assertStringContainsString('FreeSerif', $styles);

        $this->assertNotEmpty(array_filter($this->zipEntries($export->path()), fn (string $name): bool => str_starts_with($name, 'xl/media/')));
    }

    public function test_styles_are_shared_instead_of_one_per_row(): void
    {
        $document = Document::make()->components([
            Table::make('rows')->columns([Column::make('name'), Column::make('amount')->money()]),
        ]);

        $export = $this->export($document, ['rows' => SampleData::lazyEmployees(500)], 'xlsx');

        preg_match('/<cellXfs count="(\d+)"/', $this->zipEntry($export->path(), 'xl/styles.xml'), $match);
        $this->assertLessThan(15, (int) $match[1]);
    }

    public function test_native_options_and_hooks(): void
    {
        $calls = [];

        $document = Document::make()
            ->configure(fn (Settings $settings) => $settings->native('xlsx', ['options' => ['SHOULD_USE_INLINE_STRINGS' => false]]))
            ->components([
                Heading::make('Title')->native('xlsx', function (Writer $writer, Sheet $sheet, array $rows) use (&$calls): void {
                    $calls[] = 'heading:'.$rows['from'].'-'.$rows['to'];
                }),
                Native::make()->for('xlsx', function (Writer $writer) use (&$calls): void {
                    $calls[] = 'native';
                    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['written natively']));
                }),
                Table::make([['name' => 'A']])->columns([Column::make('name')])
                    ->native('xlsx', function (Writer $writer, Sheet $sheet, array $rows) use (&$calls): void {
                        $calls[] = 'table:'.$rows['from'].'-'.$rows['to'];
                    }),
            ]);

        $export = $this->export($document, [], 'xlsx');

        $this->assertSame(['heading:1-1', 'native', 'table:3-4'], $calls);
        $this->assertContains('xl/sharedStrings.xml', $this->zipEntries($export->path()));
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function readSheets(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);
        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $sheets[$sheet->getName()][] = array_map(
                        fn ($cell): mixed => $cell instanceof \OpenSpout\Common\Entity\Cell\FormulaCell ? $cell->getValue() : $cell->getValue(),
                        $row->cells,
                    );
                }
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }
}
