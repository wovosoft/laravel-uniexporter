<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table as WordTable;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings as PhpWordSettings;
use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\Native;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Drivers\RenderContext;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SalaryStatement;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SampleData;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

class DocxExportTest extends TestCase
{
    public function test_it_writes_a_word_document_from_the_schema(): void
    {
        $export = $this->export(new SalaryStatement(), SampleData::report(), 'docx');

        $this->assertInstanceOf(PhpWord::class, IOFactory::load($export->path()));

        $xml = $this->zipEntry($export->path(), 'word/document.xml');

        $this->assertStringContainsString('Salary Statement', $xml);
        $this->assertStringContainsString('রহিম উদ্দিন', $xml);
        $this->assertStringContainsString('Karim &amp; Sons &lt;script&gt;', $xml, 'Text must be XML-escaped.');
        $this->assertStringContainsString('115,500.75', $xml);
        $this->assertStringContainsString('Grand Total', $xml);
        $this->assertStringContainsString('01/03/2015', $xml);
        $this->assertStringContainsString('<w:tblHeader', $xml);
        $this->assertSame(1, substr_count($xml, 'w:type="page"'), 'A sheet right after a page break must not add a blank page.');
        $this->assertStringContainsString('<w:gridSpan w:val="3"', $xml);
        $this->assertStringContainsString('w:orient="landscape"', $xml);
        $this->assertStringContainsString('<w:br w:type="page"/>', $xml);
        $this->assertStringContainsString('Hidden in spreadsheets', $xml);
        $this->assertStringNotContainsString('Only in PDF', $xml);
        $this->assertStringContainsString('w:ascii="FreeSerif"', $this->zipEntry($export->path(), 'word/styles.xml'));
        $this->assertNotEmpty(array_filter($this->zipEntries($export->path()), fn (string $name): bool => str_starts_with($name, 'word/media/')));

        $footers = implode('', array_map(
            fn (string $name): string => $this->zipEntry($export->path(), $name),
            array_filter($this->zipEntries($export->path()), fn (string $name): bool => str_starts_with($name, 'word/footer')),
        ));
        $this->assertStringContainsString('NUMPAGES', $footers);
    }

    public function test_global_phpword_settings_are_restored(): void
    {
        PhpWordSettings::setOutputEscapingEnabled(false);

        $this->export(new SalaryStatement(), SampleData::report(), 'docx');

        $this->assertFalse(PhpWordSettings::isOutputEscapingEnabled());
    }

    public function test_native_hooks_receive_phpword_objects(): void
    {
        $calls = [];

        $document = Document::make()
            ->components([
                Table::make([['name' => 'A']])->columns([Column::make('name')])
                    ->native('docx', function (WordTable $table, AbstractContainer $container, RenderContext $context) use (&$calls): void {
                        $calls[] = 'table:'.count($table->getRows());
                    }),
                Native::make()->for('docx', function (AbstractContainer $container) use (&$calls): void {
                    $calls[] = 'native';
                    $container->addText('written natively');
                }),
            ])
            ->beforeRender('docx', function (PhpWord $phpWord) use (&$calls): void {
                $calls[] = 'before';
            });

        $export = $this->export($document, [], 'docx');

        $this->assertSame(['before', 'table:2', 'native'], $calls);
        $this->assertStringContainsString('written natively', $this->zipEntry($export->path(), 'word/document.xml'));
    }
}
