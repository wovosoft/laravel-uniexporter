<?php

namespace Wovosoft\LaravelUniexporter\Support;

use Illuminate\Contracts\Support\Arrayable;
use Wovosoft\LaravelUniexporter\Components\ValueFormat;

/**
 * The data an export is rendered with. Values are read by dot path (arrays, objects and models),
 * and text templates get their `{path}` placeholders filled from it.
 */
final class Data
{
    private const string PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_.\-]*)\}/';

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly array $values = []) {}

    /**
     * @param array<string, mixed>|Arrayable<string, mixed>|Data|null $data
     */
    public static function from(array|Arrayable|self|null $data): self
    {
        return match (true) {
            $data instanceof self      => $data,
            $data instanceof Arrayable => new self($data->toArray()),
            default                    => new self($data ?? []),
        };
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->values, $path, $default);
    }

    public function has(string $path): bool
    {
        $missing = new \stdClass();

        return data_get($this->values, $path, $missing) !== $missing;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Replaces `{path}` placeholders with data values. `$extra` takes precedence; placeholders
     * that match nothing are left as they are, so literal braces survive.
     *
     * @param array<string, string> $extra
     */
    public function interpolate(string $template, array $extra = []): string
    {
        if (! str_contains($template, '{')) {
            return $template;
        }

        $text = ValueFormat::text();

        return preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($extra, $text): string {
            $path = $match[1];

            if (array_key_exists($path, $extra)) {
                return $extra[$path];
            }

            return $this->has($path) ? $text->toText($this->get($path)) : $match[0];
        }, $template) ?? $template;
    }

    /**
     * Resolves a row source: a data path to an iterable, or the iterable itself.
     *
     * @param string|iterable<mixed>|null $source
     * @return iterable<mixed>
     */
    public function iterable(string|iterable|null $source): iterable
    {
        $rows = is_string($source) ? $this->get($source) : $source;

        return is_iterable($rows) ? $rows : [];
    }
}
