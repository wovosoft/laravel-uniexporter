<?php

namespace Wovosoft\LaravelUniexporter\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Drivers\Mpdf\MpdfDriver;
use Wovosoft\LaravelUniexporter\Drivers\OpenSpout\OpenSpoutDriver;
use Wovosoft\LaravelUniexporter\Drivers\PhpWord\PhpWordDriver;
use Wovosoft\LaravelUniexporter\ExportedFile;
use Wovosoft\LaravelUniexporter\UniexporterManager;
use ZipArchive;

abstract class TestCase extends BaseTestCase
{
    protected string $temporaryDirectory;

    /**
     * @var list<ExportedFile>
     */
    private array $exports = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uniexporter_tests_'.getmypid();
    }

    protected function tearDown(): void
    {
        foreach ($this->exports as $export) {
            $export->delete();
        }

        MpdfDriver::flushCustomRenderers();
        PhpWordDriver::flushCustomRenderers();
        OpenSpoutDriver::flushCustomRenderers();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function manager(array $overrides = []): UniexporterManager
    {
        $config = require dirname(__DIR__).'/config/uniexporter.php';

        return new UniexporterManager(array_replace_recursive($config, ['temp_dir' => $this->temporaryDirectory], $overrides));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function export(Document $document, array $data, string $format, ?UniexporterManager $manager = null): ExportedFile
    {
        return $this->exports[] = $document->data($data)->using($manager ?? $this->manager())->export($format);
    }

    protected function zipEntry(string $path, string $entry): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, "Could not open [{$path}] as a zip archive.");
        $contents = $zip->getFromName($entry);
        $zip->close();

        $this->assertIsString($contents, "Missing [{$entry}] in [{$path}].");

        return $contents;
    }

    /**
     * @return list<string>
     */
    protected function zipEntries(string $path): array
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = $zip->getNameIndex($index);
        }
        $zip->close();

        return $names;
    }
}
