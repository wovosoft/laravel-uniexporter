<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\Heading;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

class CsvExportTest extends TestCase
{
    public function test_it_writes_the_table_data_only(): void
    {
        $document = Document::make()->components([
            Heading::make('Salary Statement'),
            Table::make('rows')
                ->totalLabel('Total')
                ->columns([
                    Column::make()->rowNumber()->label('SL'),
                    Column::make('name')->label('Name'),
                    Column::make('joined')->label('Joined')->date(),
                    Column::make('amount')->label('Amount')->money()->total(),
                    Column::make('pdf_only')->hiddenOn('csv'),
                ]),
        ]);

        $export = $this->export($document, ['rows' => [
            ['name' => 'Rahim, Uddin', 'joined' => '2015-03-01', 'amount' => 1500.5, 'pdf_only' => 'x'],
            ['name' => '=HYPERLINK("x")', 'joined' => null, 'amount' => 2000, 'pdf_only' => 'x'],
        ]], 'csv');

        $this->assertSame('text/csv', $export->mimeType());
        $this->assertSame([
            ['SL', 'Name', 'Joined', 'Amount'],
            ['1', 'Rahim, Uddin', '01/03/2015', '1500.5'],
            ['2', "'=HYPERLINK(\"x\")", '', '2000'],
            ['Total', '', '', '3500.5'],
        ], array_map(
            fn (string $line): array => str_getcsv($line, escape: ''),
            explode("\n", trim(str_replace("\u{FEFF}", '', (string) file_get_contents($export->path())))),
        ));
    }
}
