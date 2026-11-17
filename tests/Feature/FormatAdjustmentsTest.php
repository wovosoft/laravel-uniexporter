<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\Heading;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Settings;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

/**
 * Settings::on() and Component::on(): one schema, adjusted per export format.
 */
class FormatAdjustmentsTest extends TestCase
{
    private const array DATA = ['rows' => [['name' => 'Rahim', 'phone' => '0171', 'note' => 'internal']]];

    public function test_page_headers_and_footers_can_be_dropped_for_one_format(): void
    {
        $document = Document::make()
            ->configure(fn (Settings $settings) => $settings
                ->header(center: 'Contacts')
                ->footer(center: 'Page {page} of {pages}')
                ->on('xlsx', fn (Settings $settings) => $settings->withoutHeader()->withoutFooter()))
            ->components([Table::make('rows')->columns([Column::make('name')])]);

        $sheet = $this->zipEntry($this->export($document, self::DATA, 'xlsx')->path(), 'xl/worksheets/sheet1.xml');
        $word = $this->zipEntry($this->export($document, self::DATA, 'docx')->path(), 'word/header1.xml');

        $this->assertStringNotContainsString('<headerFooter', $sheet);
        $this->assertStringContainsString('Contacts', $word, 'Other formats keep the header.');
    }

    public function test_a_table_can_drop_its_header_row_and_columns_for_one_format(): void
    {
        $document = Document::make()->components([
            Heading::make('Contacts')->hiddenOn('xlsx'),
            Table::make('rows')
                ->columns([Column::make('name'), Column::make('phone'), Column::make('note')->on('xlsx', fn (Column $column) => $column->hiddenOn('xlsx'))])
                ->on('xlsx', fn (Table $table) => $table->showHeader(false)),
        ]);

        $rows = $this->readSheets($this->export($document, self::DATA, 'xlsx')->path());

        $this->assertSame([['Rahim', '0171']], array_values($rows)[0], 'Only the data, without the header row or the hidden column.');

        $html = $this->export($document, self::DATA, 'html')->contents();

        $this->assertStringContainsString('<th', $html, 'The same document keeps its header in other formats.');
        $this->assertStringContainsString('internal', $html);
        $this->assertStringContainsString('Contacts', $html);
    }

    public function test_adjustments_can_be_chosen_when_exporting(): void
    {
        $table = Table::make('rows')->columns([Column::make('name')]);
        $document = Document::make()->components([$table]);

        $withHeader = $this->readSheets($this->export($document, self::DATA, 'xlsx')->path());
        $table->on('xlsx', fn (Table $table) => $table->showHeader(false));
        $withoutHeader = $this->readSheets($this->export($document, self::DATA, 'xlsx')->path());

        $this->assertSame([['Name'], ['Rahim']], array_values($withHeader)[0]);
        $this->assertSame([['Rahim']], array_values($withoutHeader)[0]);
        $this->assertTrue($table->shouldShowHeader(), 'Adjustments apply to a copy, never to the schema itself.');
    }
}
