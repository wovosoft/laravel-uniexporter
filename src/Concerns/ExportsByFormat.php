<?php

namespace Wovosoft\LaravelUniexporter\Concerns;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Wovosoft\LaravelUniexporter\Exceptions\UnknownFormatException;
use Wovosoft\LaravelUniexporter\PendingExport;
use Wovosoft\LaravelUniexporter\UniexporterManager;

/**
 * One export controller for every format of a document: build the document once in document(),
 * route `export/{format}` to the controller with ->whereIn('format', self::FORMATS), and dispatch
 * with exportAs(). PDF and HTML open in the browser; Excel, Word, CSV and other formats download.
 *
 * The using class implements `document($request, string $format): PendingExport`, the document with
 * its data; `$format` is the format as requested (`excel` or `xlsx`, `pdf`...), for exports whose data
 * differs per format. It is not declared abstract here, so the class can type the request as its own
 * form request, which then validates and authorizes the export.
 *
 *     class EmployeesExport
 *     {
 *         use ExportsByFormat;
 *
 *         public const array FORMATS = ['pdf', 'excel', 'docx'];
 *
 *         public function __invoke(EmployeesRequest $request, string $format): BinaryFileResponse
 *         {
 *             return $this->exportAs($request, $format);
 *         }
 *
 *         protected function document(EmployeesRequest $request, string $format): PendingExport
 *         {
 *             return EmployeesDocument::make()->data(['employees' => ...]);
 *         }
 *     }
 *
 *     Route::get('export/{format}', EmployeesExport::class)->whereIn('format', EmployeesExport::FORMATS)->name('export');
 */
trait ExportsByFormat
{
    /**
     * Exports in `$format`: pdf, html, excel (or xlsx), docx, csv, or any format registered with
     * Uniexporter::extend(). When the class declares a FORMATS constant, other formats are a 404 even
     * if the route forgot its whereIn().
     *
     * @throws NotFoundHttpException|UnknownFormatException
     */
    protected function exportAs(Request $request, string $format): BinaryFileResponse
    {
        if (defined(static::class.'::FORMATS') && ! in_array($format, (array) constant(static::class.'::FORMATS'), true)) {
            $this->unsupportedFormat($format);
        }

        return match ($format) {
            'pdf'           => $this->pdf($request),
            'html'          => $this->html($request),
            'excel', 'xlsx' => $this->excel($request, $format),
            'docx'          => $this->docx($request),
            'csv'           => $this->csv($request),
            default         => $this->exportManager()->hasFormat($format)
                ? $this->pendingExport($request, $format)->export($format)->download()
                : $this->unsupportedFormat($format),
        };
    }

    /**
     * The manager that renders the exports: the application's (the container's) by default.
     */
    protected function exportManager(): UniexporterManager
    {
        return UniexporterManager::resolve();
    }

    protected function pdf(Request $request): BinaryFileResponse
    {
        return $this->pendingExport($request, 'pdf')->exportToPdf()->inline();
    }

    protected function html(Request $request): BinaryFileResponse
    {
        return $this->pendingExport($request, 'html')->export('html')->inline();
    }

    /**
     * @param 'excel'|'xlsx' $format the name the format was requested by
     */
    protected function excel(Request $request, string $format = 'excel'): BinaryFileResponse
    {
        return $this->pendingExport($request, $format)->exportToExcel()->download();
    }

    protected function docx(Request $request): BinaryFileResponse
    {
        return $this->pendingExport($request, 'docx')->exportToDocx()->download();
    }

    protected function csv(Request $request): BinaryFileResponse
    {
        return $this->pendingExport($request, 'csv')->exportToCsv()->download();
    }

    private function pendingExport(Request $request, string $format): PendingExport
    {
        return $this->document($request, $format)->using($this->exportManager());
    }

    /**
     * A 404 in an HTTP application; an UnknownFormatException elsewhere.
     *
     * @throws NotFoundHttpException|UnknownFormatException
     */
    private function unsupportedFormat(string $format): never
    {
        if (class_exists(NotFoundHttpException::class)) {
            throw new NotFoundHttpException("Unsupported export format [{$format}].");
        }

        throw new UnknownFormatException("Unsupported export format [{$format}].");
    }
}
