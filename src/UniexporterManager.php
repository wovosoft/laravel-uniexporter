<?php

namespace Wovosoft\LaravelUniexporter;

use Closure;
use Illuminate\Container\Container;
use Throwable;
use Wovosoft\LaravelUniexporter\Drivers\Driver;
use Wovosoft\LaravelUniexporter\Drivers\RenderContext;
use Wovosoft\LaravelUniexporter\Exceptions\UniexporterException;
use Wovosoft\LaravelUniexporter\Exceptions\UnknownFormatException;
use Wovosoft\LaravelUniexporter\Support\Data;

/**
 * Format registry and export runner. Each export gets a fresh driver instance, so drivers may
 * keep per-export state without leaking between requests (Octane).
 */
class UniexporterManager
{
    /**
     * @var array<string, class-string<Driver>|Closure(array<string, mixed>, self): Driver>
     */
    private array $formats;

    /**
     * @param array<string, mixed> $config see config/uniexporter.php
     */
    public function __construct(private readonly array $config = [])
    {
        $this->formats = $config['formats'] ?? [];
    }

    /**
     * The container's manager in Laravel, or one with the package's default config.
     */
    public static function resolve(): self
    {
        if (class_exists(Container::class) && Container::getInstance()->bound(self::class)) {
            return Container::getInstance()->make(self::class);
        }

        return new self(require __DIR__.'/../config/uniexporter.php');
    }

    /**
     * Registers or replaces the driver for a format.
     *
     * @param class-string<Driver>|Closure(array<string, mixed> $options, self $manager): Driver $driver
     */
    public function extend(string $format, string|Closure $driver): static
    {
        $this->formats[$format] = $driver;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function formats(): array
    {
        return array_keys($this->formats);
    }

    public function hasFormat(string $format): bool
    {
        return isset($this->formats[$format]);
    }

    /**
     * @param array<string, mixed> $options overrides merged over the configured driver options
     */
    public function driver(string $format, array $options = []): Driver
    {
        $driver = $this->formats[$format] ?? throw UnknownFormatException::format($format, $this->formats());
        $options = array_replace_recursive($this->config['drivers'][$format] ?? [], $options);

        $instance = $driver instanceof Closure ? $driver($options, $this) : new $driver($options);

        if (! $instance instanceof Driver) {
            throw new UniexporterException(sprintf('The driver for [%s] must implement %s.', $format, Driver::class));
        }

        return $instance;
    }

    public function export(Document $document, Data $data, string $format): ExportedFile
    {
        $settings = $document->resolveSettings();
        $options = array_replace_recursive($this->config['drivers'][$format] ?? [], $settings->getNative($format));
        $driver = $this->driver($format, $settings->getNative($format));

        $context = new RenderContext(
            document: $document,
            settings: $settings,
            data: $data,
            format: $format,
            driverOptions: $options,
            strict: (bool) ($this->config['strict'] ?? false),
            temporaryDirectory: $this->temporaryDirectory(),
        );

        $path = $context->temporaryDirectory.DIRECTORY_SEPARATOR.'export_'.bin2hex(random_bytes(8)).'.'.$driver->extension();

        try {
            $driver->render($context, $path);
        } catch (Throwable $exception) {
            if (is_file($path)) {
                @unlink($path);
            }

            throw $exception;
        } finally {
            $context->cleanup();
        }

        if (! is_file($path)) {
            throw new UniexporterException(sprintf('The [%s] driver did not write a file.', $format));
        }

        return new ExportedFile($path, $driver->extension(), $driver->mimeType(), $settings->getFilename());
    }

    public function temporaryDirectory(): string
    {
        $directory = rtrim($this->config['temp_dir'] ?? '', DIRECTORY_SEPARATOR)
            ?: sys_get_temp_dir().DIRECTORY_SEPARATOR.'uniexporter';

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new UniexporterException("Could not create the temporary directory [{$directory}].");
        }

        return $directory;
    }
}
