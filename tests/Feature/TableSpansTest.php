<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\HeaderCell;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

/**
 * Sub-rows (cells spanning a row's sub-rows) and header cells spanning rows into the label row.
 */
class TableSpansTest extends TestCase
{
    private const array DATA = ['employees' => [
        ['name' => 'Rahim', 'salary' => 100, 'degrees' => [['exam' => 'SSC', 'result' => 5], ['exam' => 'HSC', 'result' => 4.5], ['exam' => 'BSc', 'result' => 3.2]]],
        ['name' => 'Karim', 'salary' => 50, 'degrees' => []],
    ]];

    private function document(): Document
    {
        return Document::make()->components([
            Table::make('employees')
                ->subRows('degrees')
                ->headerRows([[
                    HeaderCell::make('SL')->rowspan(2),
                    HeaderCell::make('Employee')->rowspan(2),
                    HeaderCell::make('Education')->colspan(2),
                    HeaderCell::make('Salary')->rowspan(2),
                ]])
                ->columns([
                    Column::make()->rowNumber(),
                    Column::make('name'),
                    Column::make('exam')->label('Exam')->fromSubRow(),
                    Column::make('result')->label('Result')->fromSubRow(),
                    Column::make('salary')->number()->total(),
                ])
                ->totalLabel('Total'),
        ]);
    }

    public function test_html_spans_rows_and_header_cells(): void
    {
        $html = $this->export($this->document(), self::DATA, 'html')->contents();

        $this->assertSame(3, substr_count($html, '<th rowspan="2"'), 'SL, Employee and Salary span both header rows.');
        $this->assertStringContainsString('<th colspan="2"', $html);
        $this->assertStringNotContainsString('>Name</th>', $html, 'A label covered by a header cell is left out.');
        $this->assertStringContainsString('>Exam</th>', $html);

        preg_match('/<tbody>(.*)<\/tbody>/s', $html, $body);
        $this->assertSame(5, substr_count($body[1], '<tr>'), 'Three sub-rows, one row without items, one totals row.');
        $this->assertSame(3, substr_count($body[1], 'rowspan="3"'), 'Row number, name and salary span the sub-rows.');
        $this->assertMatchesRegularExpression('/>2<\/td><td[^>]*>Karim</', $body[1], 'Row numbers count rows, not sub-rows.');
        $this->assertStringContainsString('>150<', $body[1], 'Totals count each row once.');
    }

    public function test_spreadsheet_merges_spanned_cells(): void
    {
        $path = $this->export($this->document(), self::DATA, 'xlsx')->path();
        $rows = array_values($this->readSheets($path))[0];
        $sheet = $this->zipEntry($path, 'xl/worksheets/sheet1.xml');

        $this->assertSame(['SL', 'Employee', 'Education', '', 'Salary'], $rows[0]);
        $this->assertSame(['', '', 'Exam', 'Result', ''], $rows[1]);
        $this->assertSame([1, 'Rahim', 'SSC', 5, 100], $rows[2]);
        $this->assertSame(['', '', 'HSC', 4.5, ''], $rows[3]);
        $this->assertSame([2, 'Karim', '', '', 50], array_slice($rows[5], 0, 5));

        foreach (['A1:A2', 'B1:B2', 'C1:D1', 'E1:E2', 'A3:A5', 'B3:B5', 'E3:E5'] as $range) {
            $this->assertStringContainsString('<mergeCell ref="'.$range.'"/>', $sheet, "Merges $range.");
        }
    }

    public function test_word_merges_cells_vertically(): void
    {
        $xml = $this->zipEntry($this->export($this->document(), self::DATA, 'docx')->path(), 'word/document.xml');

        $this->assertSame(3 + 3, substr_count($xml, '<w:vMerge w:val="restart"/>'), 'Three header cells and three body cells start a merge.');
        $this->assertSame(3 + 6, substr_count($xml, '<w:vMerge w:val="continue"/>'), 'One header row and two sub-rows continue them.');
        $this->assertStringContainsString('<w:gridSpan w:val="2"/>', $xml);
    }

    public function test_pdf_renders_spans_in_strict_mode(): void
    {
        $export = $this->export($this->document(), self::DATA, 'pdf', $this->manager(['strict' => true]));

        $this->assertStringStartsWith('%PDF', $export->contents());
    }
}
