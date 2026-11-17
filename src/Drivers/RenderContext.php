<?php

namespace Wovosoft\LaravelUniexporter\Drivers;

use Wovosoft\LaravelUniexporter\Components\Component;
use Wovosoft\LaravelUniexporter\Document;
use Wovosoft\LaravelUniexporter\Exceptions\UniexporterException;
use Wovosoft\LaravelUniexporter\Settings;
use Wovosoft\LaravelUniexporter\Style\Style;
use Wovosoft\LaravelUniexporter\Support\Data;

/**
 * Everything a driver needs for one export: the document, its resolved settings, the data,
 * style resolution, and temporary files that are removed after the export.
 */
final class RenderContext
{
    /**
     * @var list<string>
     */
    private array $temporaryFiles = [];

    /**
     * @var array<string, Style>
     */
    private readonly array $namedStyles;

    /**
     * @param array<string, mixed> $driverOptions driver config merged with Settings::native()
     */
    public function __construct(
        public readonly Document $document,
        public readonly Settings $settings,
        public readonly Data $data,
        public readonly string $format,
        public readonly array $driverOptions = [],
        public readonly bool $strict = false,
        public readonly string $temporaryDirectory = '',
    ) {
        $this->namedStyles = $document->styles();
    }

    /**
     * The effective style of a component: document defaults < inherited < component default
     * < named style < inline style.
     */
    public function style(Component $component, ?Style $inherited = null): Style
    {
        $style = $this->settings->getDefaultStyle()
            ->merge($inherited?->inheritable())
            ->merge($component->defaultStyle());

        if (($name = $component->getStyleName()) !== null) {
            $style = $style->merge($this->namedStyle($name));
        }

        return $style->merge($component->getInlineStyle());
    }

    public function namedStyle(string $name): ?Style
    {
        if (! isset($this->namedStyles[$name]) && $this->strict) {
            throw new UniexporterException("Unknown named style [{$name}].");
        }

        return $this->namedStyles[$name] ?? null;
    }

    /**
     * Fills `{path}` placeholders from the data.
     *
     * @param array<string, string> $extra
     */
    public function text(string $template, array $extra = []): string
    {
        return $this->data->interpolate($template, $extra);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->driverOptions, $key, $default);
    }

    /**
     * Writes contents to a temporary file that is deleted after the export.
     */
    public function temporaryFile(string $contents, string $extension = 'tmp'): string
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'uniexporter_'.bin2hex(random_bytes(8)).'.'.$extension;
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * Resolves an image source template to a local file path (binary contents go to a temp file).
     */
    public function imagePath(string $source): ?string
    {
        $value = str_starts_with($source, '{') && str_ends_with($source, '}') && substr_count($source, '{') === 1
            ? $this->data->get(substr($source, 1, -1))
            : $this->text($source);

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (strlen($value) < 4096 && ! str_contains($value, "\0") && is_file($value)) {
            return $value;
        }

        $info = @getimagesizefromstring($value);

        if ($info === false) {
            return null;
        }

        return $this->temporaryFile($value, image_type_to_extension($info[2], false) ?: 'img');
    }

    public function cleanup(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temporaryFiles = [];
    }
}
