<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use Mpdf\Mpdf;
use Wovosoft\LaravelUniexporter\Components\Column;
use Wovosoft\LaravelUniexporter\Components\Native;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Components\Text;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Drivers\RenderContext;
use Wovosoft\LaravelUniexporter\Settings;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SalaryStatement;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SampleData;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

class PdfExportTest extends TestCase
{
    public function test_it_writes_a_pdf_with_html_built_from_the_schema(): void
    {
        $html = '';
        $manager = $this->manager(['drivers' => ['pdf' => ['html_listener' => function (string $chunk) use (&$html): void {
            $html .= $chunk;
        }]]]);

        $export = $this->export(new SalaryStatement(), SampleData::report(), 'pdf', $manager);

        $this->assertStringStartsWith('%PDF-', $export->contents());
        $this->assertSame('application/pdf', $export->mimeType());

        $this->assertStringContainsString('html_uxHeader', $html);
        $this->assertStringContainsString('html_uxFooter', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<td style=', $html, 'mPDF gets inline cell styles.');
        $this->assertStringContainsString('colspan="3"', $html);
        $this->assertStringContainsString('Grand Total', $html);
        $this->assertStringContainsString('115,500.75', $html);
        $this->assertStringContainsString('Karim &amp; Sons &lt;script&gt;', $html, 'Data must be HTML-escaped.');
        $this->assertStringContainsString('01/03/2015', $html);
        $this->assertStringContainsString('<pagebreak />', $html);
        $this->assertStringContainsString('Only in PDF', $html);
        $this->assertStringContainsString('font-family: freeserif', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_the_text_and_page_numbers_are_in_the_pdf(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext is not installed.');
        }

        $export = $this->export(new SalaryStatement(), SampleData::report(), 'pdf');
        $text = shell_exec('pdftotext -layout '.escapeshellarg($export->path()).' -');
        $text = strtr($text, ['ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl']);

        $this->assertStringContainsString('Salary Statement', $text);
        $this->assertStringContainsString('Head Office, Dhaka', $text);
        $this->assertStringContainsString('Grand Total', $text);
        $this->assertStringContainsString('115,500.75', $text);
        $this->assertStringContainsString('Page 1 of 2', $text);
        $this->assertStringContainsString('Page 2 of 2', $text, 'A sheet right after a page break must not add a blank page.');
    }

    public function test_large_tables_span_pages(): void
    {
        $document = Document::make()->components([
            Table::make('rows')->columns([Column::make()->rowNumber(), Column::make('name'), Column::make('amount')->money()->total()]),
        ]);

        $export = $this->export($document, ['rows' => SampleData::lazyEmployees(300)], 'pdf');

        $this->assertGreaterThan(3, preg_match_all('/\/Type\s*\/Page[^s]/', $export->contents()));
    }

    public function test_native_hooks_receive_mpdf_and_can_replace_html(): void
    {
        $calls = [];
        $html = '';
        $manager = $this->manager(['drivers' => ['pdf' => ['html_listener' => function (string $chunk) use (&$html): void {
            $html .= $chunk;
        }]]]);

        $document = Document::make()
            ->components([
                Text::make('original')->native('pdf', function (string $markup, Mpdf $mpdf, RenderContext $context) use (&$calls): string {
                    $calls[] = 'component';

                    return str_replace('original', 'replaced', $markup);
                }),
                Native::make()->for('pdf', function (Mpdf $mpdf, RenderContext $context) use (&$calls): void {
                    $calls[] = 'native';
                    $mpdf->WriteHTML('<p>written natively</p>');
                }),
            ])
            ->beforeRender('pdf', function (Mpdf $mpdf) use (&$calls): void {
                $calls[] = 'before';
            })
            ->afterRender('pdf', function (Mpdf $mpdf) use (&$calls): void {
                $calls[] = 'after';
            });

        $this->export($document, [], 'pdf', $manager);

        $this->assertSame(['before', 'component', 'native', 'after'], $calls);
        $this->assertStringContainsString('replaced', $html);
        $this->assertStringNotContainsString('original', $html);
    }

    public function test_native_settings_reach_the_mpdf_constructor(): void
    {
        $document = Document::make()
            ->configure(fn (Settings $settings) => $settings->native('pdf', ['config' => ['shrink_tables_to_fit' => 1.5]]))
            ->beforeRender('pdf', function (Mpdf $mpdf): void {
                $this->assertSame(1.5, $mpdf->shrink_tables_to_fit);
            })
            ->components([Text::make('x')]);

        $this->export($document, [], 'pdf');
    }

    public function test_a_wide_totals_label_renders(): void
    {
        $document = Document::make()->components([
            Table::make('rows')->totalLabel('Total', 2)->columns([Column::make('name'), Column::make('note'), Column::make('days')->total()]),
        ]);

        $path = $this->export($document, ['rows' => [['name' => 'A', 'note' => '', 'days' => 2], ['name' => 'B', 'note' => '', 'days' => 3]]], 'pdf')->path();

        $this->assertStringStartsWith('%PDF', (string) file_get_contents($path, length: 4));
    }
}
