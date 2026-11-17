<?php

namespace Wovosoft\LaravelUniexporter\Concerns;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Wovosoft\LaravelUniexporter\Exceptions\UnknownFormatException;

/**
 * One export controller for every format of a document: build the document once in document(),
 * route `export/{format}` to the controller with ->whereIn('format', self::FORMATS), and dispatch
 * with exportAs(). PDF and HTML open in the browser; Excel, Word and CSV download.
 *
 * The using class implements `document($request, string $format): PendingExport`, the document with
 * its data; `$format` is the requested format, for exports whose data differs per format. It is not
 * declared abstract here, so the class can type the request as its own form request.
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
     * Exports in `$format`: pdf, html, excel (or xlsx), docx or csv.
     *
     * @throws UnknownFormatException
     */
    protected function exportAs(Request $request, string $format): BinaryFileResponse
    {
        return match ($format) {
            'pdf'           => $this->pdf($request),
            'html'          => $this->html($request),
            'excel', 'xlsx' => $this->excel($request),
            'docx'          => $this->docx($request),
            'csv'           => $this->csv($request),
            default         => throw UnknownFormatException::format($format, ['pdf', 'html', 'excel', 'docx', 'csv']),
        };
    }

    public function pdf(Request $request): BinaryFileResponse
    {
        return $this->document($request, 'pdf')->exportToPdf()->inline();
    }

    public function html(Request $request): BinaryFileResponse
    {
        return $this->document($request, 'html')->export('html')->inline();
    }

    public function excel(Request $request): BinaryFileResponse
    {
        return $this->document($request, 'excel')->exportToExcel()->download();
    }

    public function docx(Request $request): BinaryFileResponse
    {
        return $this->document($request, 'docx')->exportToDocx()->download();
    }

    public function csv(Request $request): BinaryFileResponse
    {
        return $this->document($request, 'csv')->exportToCsv()->download();
    }
}
