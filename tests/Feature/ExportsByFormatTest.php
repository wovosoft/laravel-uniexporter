<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Concerns\ExportsByFormat;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Exceptions\UnknownFormatException;
use Wovosoft\LaravelUniexporter\PendingExport;
use Wovosoft\LaravelUniexporter\Tests\TestCase;
use Wovosoft\LaravelUniexporter\UniexporterManager;

/**
 * One controller serves every format of a document through exportAs().
 */
class ExportsByFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The trait exports through UniexporterManager::resolve(): the container's manager in Laravel,
        // a default one in a standalone install (illuminate/container is not a dependency).
        if (class_exists(Container::class)) {
            Container::getInstance()->instance(UniexporterManager::class, $this->manager());
        }
    }

    protected function tearDown(): void
    {
        if (class_exists(Container::class)) {
            Container::setInstance(null);
        }

        parent::tearDown();
    }

    private function controller(): object
    {
        return new class
        {
            use ExportsByFormat;

            /**
             * @var list<string>
             */
            public array $requestedFormats = [];

            public function __invoke(Request $request, string $format): BinaryFileResponse
            {
                return $this->exportAs($request, $format);
            }

            protected function document(Request $request, string $format): PendingExport
            {
                $this->requestedFormats[] = $format;

                return Document::make()
                    ->filename('people')
                    ->table('people', fn (Table $table) => $table->column('name'))
                    ->data(['people' => [['name' => $request->query->get('name', 'Rahim')]]]);
            }
        };
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function formats(): array
    {
        return [
            'pdf'   => ['pdf', 'application/pdf', 'inline; filename=people.pdf'],
            'html'  => ['html', 'text/html; charset=UTF-8', 'inline; filename=people.html'],
            'excel' => ['excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'attachment; filename=people.xlsx'],
            'xlsx'  => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'attachment; filename=people.xlsx'],
            'docx'  => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'attachment; filename=people.docx'],
            'csv'   => ['csv', 'text/csv', 'attachment; filename=people.csv'],
        ];
    }

    #[DataProvider('formats')]
    public function test_each_format_is_served_from_the_one_document(string $format, string $mimeType, string $disposition): void
    {
        $controller = $this->controller();
        $response = $controller(Request::create('/people/export/'.$format), $format);

        $this->assertSame($mimeType, $response->headers->get('Content-Type'));
        $this->assertStringStartsWith($disposition, (string) $response->headers->get('Content-Disposition'));
        $this->assertSame([$format === 'xlsx' ? 'excel' : $format], $controller->requestedFormats);

        @unlink($response->getFile()->getPathname());
    }

    public function test_the_request_reaches_the_document(): void
    {
        $controller = $this->controller();
        $response = $controller(Request::create('/people/export/csv', parameters: ['name' => 'Karim']), 'csv');

        $this->assertStringContainsString('Karim', (string) file_get_contents($response->getFile()->getPathname()));

        @unlink($response->getFile()->getPathname());
    }

    public function test_unknown_formats_throw(): void
    {
        $this->expectException(UnknownFormatException::class);

        ($this->controller())(Request::create('/people/export/odt'), 'odt');
    }
}
