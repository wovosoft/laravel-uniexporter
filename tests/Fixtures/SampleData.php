<?php

namespace Wovosoft\LaravelUniexporter\Tests\Fixtures;

use DateTimeImmutable;
use Generator;

/**
 * Sample data for the tests, independent of the host application's database.
 */
final class SampleData
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function employees(): array
    {
        return [
            ['pf_number' => 'PF-1001', 'name' => 'রহিম উদ্দিন', 'designation' => ['name' => 'Senior Officer'], 'joined_at' => new DateTimeImmutable('2015-03-01'), 'basic_salary' => 35500.5, 'remarks' => '=HYPERLINK("http://example.com")'],
            ['pf_number' => 'PF-1002', 'name' => 'Karim & Sons <script>', 'designation' => ['name' => 'Officer'], 'joined_at' => '2018-07-15', 'basic_salary' => 28000, 'remarks' => null],
            ['pf_number' => 'PF-1003', 'name' => 'Salma Begum', 'designation' => ['name' => 'Principal Officer'], 'joined_at' => new DateTimeImmutable('2010-01-10'), 'basic_salary' => 52000.25, 'remarks' => 'On leave'],
        ];
    }

    /**
     * A one-pass source: iterating it a second time throws, which proves drivers read rows once.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public static function lazyEmployees(int $count = 3): Generator
    {
        $employees = self::employees();

        for ($index = 0; $index < $count; $index++) {
            yield $employees[$index % count($employees)];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function report(?iterable $employees = null): array
    {
        return [
            'title'     => 'Salary Statement',
            'office'    => ['name' => 'Head Office, Dhaka', 'code' => '0001'],
            'month'     => 'November 2026',
            'prepared'  => 'HR Division',
            'notes'     => ['Figures are in BDT.', 'Generated for testing.'],
            'logo'      => self::pngPath(),
            'employees' => $employees ?? self::employees(),
        ];
    }

    /**
     * A small PNG generated with GD into the system temp directory.
     */
    public static function pngPath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uniexporter_test_logo.png';

        if (! is_file($path)) {
            $image = imagecreatetruecolor(40, 20);
            imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 60));
            imagepng($image, $path);
        }

        return $path;
    }
}
