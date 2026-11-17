<?php

namespace Wovosoft\LaravelUniexporter\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Wovosoft\LaravelUniexporter\Components\Table;
use Wovosoft\LaravelUniexporter\Concerns\ExportsByFormat;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Drivers\Csv\CsvDriver;
use Wovosoft\LaravelUniexporter\Exceptions\UnknownFormatException;
use Wovosoft\LaravelUniexporter\PendingExport;
use Wovosoft\LaravelUniexporter\Tests\TestCase;
use Wovosoft\LaravelUniexporter\UniexporterManager;

/**
 * One controller serves every format of a document through exportAs().
 */
class ExportsByFormatTest extends TestCase
{
    /**
     * @var list<string> files of the responses, deleted after each test
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function controller(UniexporterManager $manager): PeopleExport
    {
        return new PeopleExport($manager);
    }

    private function call(object $controller, Request $request, string $format): BinaryFileResponse
    {
        $response = $controller($request, $format);
        $this->files[] = $response->getFile()->getPathname();

        return $response;
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
        $controller = $this->controller($this->manager());
        $response = $this->call($controller, Request::create('/people/export/'.$format), $format);

        $this->assertSame($mimeType, $response->headers->get('Content-Type'));
        $this->assertStringStartsWith($disposition, (string) $response->headers->get('Content-Disposition'));
        $this->assertSame([$format], $controller->requestedFormats, 'document() receives the format as requested.');
    }

    public function test_the_request_reaches_the_document(): void
    {
        $response = $this->call($this->controller($this->manager()), Request::create('/people/export/csv', parameters: ['name' => 'Karim']), 'csv');

        $this->assertStringContainsString('Karim', (string) file_get_contents($response->getFile()->getPathname()));
    }

    public function test_formats_outside_the_class_formats_are_refused_even_without_a_route_constraint(): void
    {
        $controller = new class($this->manager()) extends PeopleExport
        {
            public const array FORMATS = ['pdf'];
        };

        $this->assertUnsupported(fn () => $controller(Request::create('/people/export/csv'), 'csv'));
        $this->assertSame([], $controller->requestedFormats, 'The document is never built.');
    }

    public function test_formats_registered_with_the_manager_are_served(): void
    {
        $manager = $this->manager()->extend('tsv', CsvDriver::class);
        $controller = $this->controller($manager);

        $response = $this->call($controller, Request::create('/people/export/tsv'), 'tsv');

        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['tsv'], $controller->requestedFormats);
    }

    public function test_unknown_formats_are_refused(): void
    {
        $controller = $this->controller($this->manager());

        $this->assertUnsupported(fn () => $controller(Request::create('/people/export/odt'), 'odt'));
    }

    public function test_the_per_format_methods_are_not_routable_actions(): void
    {
        foreach (['pdf', 'html', 'excel', 'docx', 'csv'] as $method) {
            $this->assertFalse((new ReflectionMethod(PeopleExport::class, $method))->isPublic(), "{$method}() must not be public.");
        }
    }

    /**
     * A 404 where symfony/http-kernel is installed (Laravel), an UnknownFormatException otherwise.
     */
    private function assertUnsupported(callable $export): void
    {
        try {
            $export();
        } catch (NotFoundHttpException|UnknownFormatException $exception) {
            $this->assertStringContainsString('Unsupported export format', $exception->getMessage());

            return;
        } catch (Throwable $exception) {
            $this->fail('Unexpected '.$exception::class.': '.$exception->getMessage());
        }

        $this->fail('The unsupported format was exported.');
    }
}

/**
 * A controller using the trait, with the test's manager.
 */
class PeopleExport
{
    use ExportsByFormat;

    /**
     * @var list<string>
     */
    public array $requestedFormats = [];

    public function __construct(private readonly UniexporterManager $manager) {}

    public function __invoke(Request $request, string $format): BinaryFileResponse
    {
        return $this->exportAs($request, $format);
    }

    protected function exportManager(): UniexporterManager
    {
        return $this->manager;
    }

    protected function document(Request $request, string $format): PendingExport
    {
        $this->requestedFormats[] = $format;

        return Document::make()
            ->filename('people')
            ->table('people', fn (Table $table) => $table->column('name'))
            ->data(['people' => [['name' => $request->query->get('name', 'Rahim')]]]);
    }
}
