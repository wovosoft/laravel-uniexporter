<?php

namespace Wovosoft\LaravelUniexporter;

use Wovosoft\LaravelUniexporter\Support\Data;

/**
 * A document paired with its data, ready to export to any registered format.
 */
final class PendingExport
{
    private ?UniexporterManager $manager = null;

    public function __construct(private readonly Document $document, private readonly Data $data) {}

    /**
     * Uses a specific manager instead of the container's (useful outside Laravel and in tests).
     */
    public function using(UniexporterManager $manager): self
    {
        $this->manager = $manager;

        return $this;
    }

    public function exportToPdf(): ExportedFile
    {
        return $this->export('pdf');
    }

    public function exportToDocx(): ExportedFile
    {
        return $this->export('docx');
    }

    public function exportToExcel(): ExportedFile
    {
        return $this->export('xlsx');
    }

    public function exportToCsv(): ExportedFile
    {
        return $this->export('csv');
    }

    public function export(string $format): ExportedFile
    {
        return ($this->manager ?? UniexporterManager::resolve())->export($this->document, $this->data, $format);
    }
}
