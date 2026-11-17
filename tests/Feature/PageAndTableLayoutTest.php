<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use Mpdf\Mpdf;
use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\PageBreak;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Components\Text;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Settings;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SampleData;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

/**
 * Watermarks, component page headers, header alignment, auto widths and spreadsheet column/print setup.
 */
class PageAndTableLayoutTest extends TestCase
{
    private const array DATA = ['rows' => [['name' => 'A long name for a narrow column', 'code' => '0201']]];

    public function test_component_headers_repeat_on_every_pdf_page_and_appear_once_in_html(): void
    {
        $document = Document::make()
            ->configure(fn (Settings $settings) => $settings
                ->headerContent([Text::make('Letterhead line')->bold()->center()])
                ->footer(center: 'Page {page}'))
            ->components([Text::make('first page'), PageBreak::make(), Text::make('second page')]);

        if ($this->commandExists('pdftotext')) {
            $text = (string) shell_exec('pdftotext '.escapeshellarg($this->export($document, [], 'pdf')->path()).' -');
            $this->assertSame(2, substr_count($text, 'Letterhead line'), 'Once per PDF page.');
        }

        $this->assertSame(1, substr_count($this->export($document, [], 'html')->contents(), 'Letterhead line'));
        $this->assertStringContainsString('Letterhead line', $this->zipEntry($this->export($document, [], 'docx')->path(), 'word/header1.xml'));
    }

    public function test_line_height_reaches_html_and_word(): void
    {
        $document = Document::make()->components([Text::make('tight lines')->lineHeight(1.15)]);

        $this->assertStringContainsString('line-height: 1.15', $this->export($document, [], 'html')->contents());
        $this->assertMatchesRegularExpression('/<w:spacing [^>]*w:line="276"/', $this->zipEntry($this->export($document, [], 'docx')->path(), 'word/document.xml'));
    }

    public function test_a_watermark_is_drawn_behind_pdf_pages(): void
    {
        $mpdf = null;
        $document = Document::make()
            ->configure(fn (Settings $settings) => $settings->watermark(SampleData::pngPath(), 0.2, 80, 80))
            ->components([Text::make('content')])
            ->afterRender('pdf', function (Mpdf $instance) use (&$mpdf): void {
                $mpdf = $instance;
            });

        $this->export($document, [], 'pdf');

        $this->assertTrue($mpdf->showWatermarkImage);
        $this->assertSame(SampleData::pngPath(), $mpdf->watermarkImage);
        $this->assertSame(0.2, $mpdf->watermarkImageAlpha);
    }

    public function test_header_labels_can_be_centered_over_left_aligned_columns_and_widths_left_to_content(): void
    {
        $document = Document::make()->components([
            Table::make('rows')
                ->autoWidths()
                ->headerStyle(fn (Style $style) => Style::make()->align('center')->wrap(false))
                ->columns([Column::make('name'), Column::make('code')->right()]),
        ]);

        $html = $this->export($document, self::DATA, 'html')->contents();

        preg_match_all('/<th class="(c\d+)"/', $html, $headers);
        foreach ($headers[1] as $class) {
            preg_match('/\.'.$class.'\{([^}]*)\}/', $html, $rule);
            $this->assertStringContainsString('text-align: center', $rule[1], 'The header style alignment wins over the column alignment.');
            $this->assertStringNotContainsString('width:', $rule[1], 'Auto widths leave sizing to the content.');
            $this->assertStringNotContainsString('background', $rule[1], 'A replaced header style drops the default fill.');
            $this->assertStringContainsString('white-space: nowrap', $rule[1], 'wrap(false) keeps labels on one line.');
        }
        $this->assertCount(2, $headers[1]);
        $this->assertMatchesRegularExpression('/\.c\d+\{[^}]*text-align: right[^}]*\}/', $html, 'Body cells keep the column alignment.');
    }

    public function test_spreadsheet_columns_print_setup_and_wrapping(): void
    {
        $document = Document::make()
            ->configure(fn (Settings $settings) => $settings->fitToWidth()->header(center: 'Contact Information'))
            ->components([
                Table::make('rows')
                    ->freezeHeader()
                    ->style(Style::make()->wrap(false))
                    ->columns([Column::make('name')->characters(25), Column::make('code')]),
            ]);

        $path = $this->export($document, self::DATA, 'xlsx')->path();
        $sheet = $this->zipEntry($path, 'xl/worksheets/sheet1.xml');
        $styles = $this->zipEntry($path, 'xl/styles.xml');

        $this->assertMatchesRegularExpression('/<col min="1" max="1" width="25"/', $sheet, 'Fixed character width.');
        $this->assertStringContainsString('fitToPage="true"', $sheet);
        $this->assertStringContainsString('<oddHeader>&amp;C&amp;8 Contact Information</oddHeader>', $sheet);
        $this->assertStringContainsString('fitToWidth="1"', $sheet);
        $this->assertMatchesRegularExpression('/<pane [^>]*ySplit="1"[^>]*state="frozen"/', $sheet, 'The header row is frozen.');

        preg_match('/<c r="A2"[^>]* s="(\d+)"/', $sheet, $cell);
        preg_match_all('/<xf [^>]*?(?:\/>|>.*?<\/xf>)/s', explode('<cellXfs', $styles)[1], $formats);
        $this->assertStringNotContainsString('wrapText="1"', $formats[0][$cell[1]], 'The table turned wrapping off.');
    }
}
