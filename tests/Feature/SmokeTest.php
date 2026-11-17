<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SalaryStatement;
use Wovosoft\LaravelUniexporter\Tests\Fixtures\SampleData;
use Wovosoft\LaravelUniexporter\Tests\TestCase;

class SmokeTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function formats(): array
    {
        return ['pdf' => ['pdf'], 'docx' => ['docx'], 'xlsx' => ['xlsx']];
    }

    #[DataProvider('formats')]
    public function test_every_component_exports_in_strict_mode(string $format): void
    {
        $export = $this->export(new SalaryStatement(), SampleData::report(), $format, $this->manager(['strict' => true]));

        $this->assertFileExists($export->path());
        $this->assertGreaterThan(1000, filesize($export->path()));
        $this->assertSame('salary-statement.'.$format, $export->filename());
    }
}
