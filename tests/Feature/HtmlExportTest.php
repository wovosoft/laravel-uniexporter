<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use Wovosoft\LaravelUniexporter\Components\Native;
use Wovosoft\LaravelUniexporter\Components\Text;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SalaryStatement;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SampleData;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

class HtmlExportTest extends TestCase
{
    public function test_it_writes_a_standalone_page_from_the_schema(): void
    {
        $export = $this->export(new SalaryStatement(), SampleData::report(), 'html');
        $html = $export->contents();

        $this->assertSame('text/html; charset=UTF-8', $export->mimeType());
        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringEndsWith('</html>', $html);
        $this->assertStringContainsString('<title>Salary Statement - November 2026</title>', $html);
        $this->assertStringContainsString('@page { size: A4 landscape;', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('রহিম উদ্দিন', $html);
        $this->assertStringContainsString('115,500.75', $html);
        $this->assertStringContainsString('Karim &amp; Sons &lt;script&gt;', $html, 'Data must be HTML-escaped.');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString("font-family: 'FreeSerif', serif", $html);
        $this->assertStringContainsString('src="data:image/png;base64,', $html, 'Images are embedded, not linked to server paths.');
        $this->assertStringContainsString('page-break-after: always', $html);
        $this->assertStringNotContainsString('<pagebreak', $html, 'mPDF-only markup stays out of HTML.');
        $this->assertStringNotContainsString('autosize=', $html);
        $this->assertSame(1, substr_count($html, 'page-break-after: always'), 'A sheet right after a page break adds no second break.');
    }

    public function test_table_cells_share_style_classes_defined_before_use(): void
    {
        $html = $this->export(new SalaryStatement(), ['employees' => SampleData::lazyEmployees(200)] + SampleData::report(), 'html')->contents();

        $this->assertLessThan(5, substr_count($html, '<td style='), 'Only layout cells (Columns) keep inline styles; data cells do not.');
        $this->assertMatchesRegularExpression('/<td class="c\\d+">/', $html);
        preg_match_all('/class="(c\\d+)"/', $html, $used);

        foreach (array_unique($used[1]) as $class) {
            $definedAt = strpos($html, '.'.$class.'{');
            $this->assertNotFalse($definedAt, "Class [$class] has no rule.");
            $this->assertLessThan(strpos($html, 'class="'.$class.'"'), $definedAt, "Class [$class] is defined after its first use.");
        }

        $this->assertLessThan(40, count(array_unique($used[1])), 'Rows reuse a handful of classes.');
    }

    public function test_native_hooks_receive_the_html(): void
    {
        $document = Document::make()->components([
            Text::make('original')->native('html', fn (string $html): string => str_replace('original', 'replaced', $html)),
            Native::make()->for('html', fn (): string => '<p>written natively</p>'),
        ]);

        $html = $this->export($document, [], 'html')->contents();

        $this->assertStringContainsString('replaced', $html);
        $this->assertStringNotContainsString('original', $html);
        $this->assertStringContainsString('<p>written natively</p>', $html);
    }
}
