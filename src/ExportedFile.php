<?php

namespace Wovosoft\LaravelUniexporter;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * A rendered export in a temporary file. Turn it into a response, store it, or read it.
 * download() and inline() delete the temporary file after sending; store() and save() move it.
 */
final class ExportedFile implements Responsable
{
    public function __construct(
        private string $path,
        private readonly string $extension,
        private readonly string $mimeType,
        private readonly string $defaultName,
    ) {}

    public function download(?string $filename = null): BinaryFileResponse
    {
        return $this->response($filename, HeaderUtils::DISPOSITION_ATTACHMENT);
    }

    public function inline(?string $filename = null): BinaryFileResponse
    {
        return $this->response($filename, HeaderUtils::DISPOSITION_INLINE);
    }

    /**
     * Writes the file to a filesystem disk and removes the temporary file.
     *
     * @param string|Filesystem|null $disk a disk name, a Filesystem instance, or null for the default disk
     */
    public function store(string $path, string|Filesystem|null $disk = null): string
    {
        $filesystem = $disk instanceof Filesystem ? $disk : Storage::disk($disk);
        $stream = fopen($this->path, 'rb');

        try {
            if (! $filesystem->writeStream($path, $stream)) {
                throw new RuntimeException("Could not store the export at [{$path}].");
            }
        } finally {
            is_resource($stream) && fclose($stream);
        }

        $this->delete();

        return $path;
    }

    /**
     * Moves the file to a local path and returns it.
     */
    public function save(string $path): string
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create directory [{$directory}].");
        }

        if (! rename($this->path, $path)) {
            throw new RuntimeException("Could not move the export to [{$path}].");
        }

        $this->path = $path;

        return $path;
    }

    public function contents(): string
    {
        return (string) file_get_contents($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function filename(?string $name = null): string
    {
        $name ??= $this->defaultName;

        return str_ends_with(strtolower($name), '.'.$this->extension) ? $name : $name.'.'.$this->extension;
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function toResponse($request): BinaryFileResponse
    {
        return $this->download();
    }

    private function response(?string $filename, string $disposition): BinaryFileResponse
    {
        $response = new BinaryFileResponse($this->path, 200, ['Content-Type' => $this->mimeType]);
        $name = $this->filename($filename);

        $response->setContentDisposition($disposition, $name, $this->asciiFallback($name));

        return $response->deleteFileAfterSend();
    }

    private function asciiFallback(string $name): string
    {
        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'export';

        return str_replace(['/', '\\', '%'], '_', $fallback);
    }
}
