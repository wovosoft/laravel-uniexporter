<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Wovosoft\LaravelUniexporter\Components\Link;
use Wovosoft\LaravelUniexporter\Components\Run;
use Wovosoft\LaravelUniexporter\Components\Text;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

/**
 * Link URLs come from data, so only safe schemes become links; anything else renders as plain text.
 */
class LinkSafetyTest extends TestCase
{
    private function document(): Document
    {
        return Document::make()->components([
            Link::make('Profile', '{profile}'),
            Text::make()->runs([Run::make('Site')->link('{site}')]),
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'javascript'           => ['javascript:alert(document.cookie)'],
            'upper case'           => ['JavaScript:alert(1)'],
            'tab inside the scheme' => ["java\tscript:alert(1)"],
            'leading spaces'       => ['   javascript:alert(1)'],
            'data'                 => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript'             => ['vbscript:msgbox(1)'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_are_not_linked_in_html(string $url): void
    {
        $html = $this->export($this->document(), ['profile' => $url, 'site' => $url], 'html')->contents();

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('Profile', $html, 'The text stays.');
    }

    public function test_safe_urls_are_linked_in_html(): void
    {
        $html = $this->export($this->document(), ['profile' => 'https://example.com/people/7', 'site' => '/about'], 'html')->contents();

        $this->assertStringContainsString('href="https://example.com/people/7"', $html);
        $this->assertStringContainsString('href="/about"', $html);
    }

    public function test_mailto_and_tel_links_are_kept(): void
    {
        $html = $this->export($this->document(), ['profile' => 'mailto:hr@example.com', 'site' => 'tel:+8801700000000'], 'html')->contents();

        $this->assertStringContainsString('href="mailto:hr@example.com"', $html);
        $this->assertStringContainsString('href="tel:+8801700000000"', $html);
    }

    public function test_unsafe_urls_are_not_linked_in_word_or_excel(): void
    {
        $data = ['profile' => 'javascript:alert(1)', 'site' => 'javascript:alert(2)'];

        $docx = $this->export($this->document(), $data, 'docx');
        $xlsx = $this->export($this->document(), $data, 'xlsx');

        $this->assertStringNotContainsString('javascript:', $this->zipEntry($docx->path(), 'word/_rels/document.xml.rels'));
        $this->assertStringNotContainsString('<hyperlink', $this->zipEntry($xlsx->path(), 'xl/worksheets/sheet1.xml'));
    }

    public function test_safe_urls_are_linked_in_word(): void
    {
        $docx = $this->export($this->document(), ['profile' => 'https://example.com', 'site' => 'https://example.org'], 'docx');

        $this->assertStringContainsString('https://example.com', $this->zipEntry($docx->path(), 'word/_rels/document.xml.rels'));
    }
}
