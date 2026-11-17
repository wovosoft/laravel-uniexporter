<?php

namespace Wovosoft\LaravelUniexporter\Components;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * How a column value is shown: as display text in paged formats, and as a typed value with
 * a native number format in spreadsheets.
 */
final readonly class ValueFormat
{
    public const string TEXT = 'text';

    public const string NUMBER = 'number';

    public const string DATE = 'date';

    private function __construct(
        public string $type,
        public int $decimals = 0,
        public bool $thousands = true,
        public string $prefix = '',
        public string $dateFormat = 'd/m/Y',
    ) {}

    public static function text(): self
    {
        return new self(self::TEXT);
    }

    public static function number(int $decimals = 0, bool $thousands = true, string $prefix = ''): self
    {
        return new self(self::NUMBER, $decimals, $thousands, $prefix);
    }

    public static function date(string $format = 'd/m/Y'): self
    {
        return new self(self::DATE, dateFormat: $format);
    }

    /**
     * Display text for paged formats.
     */
    public function toText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($this->type) {
            self::NUMBER => is_numeric($value)
                ? $this->prefix.number_format((float) $value, $this->decimals, '.', $this->thousands ? ',' : '')
                : (string) $value,
            self::DATE   => ($date = $this->toDate($value)) ? $date->format($this->dateFormat) : (string) $value,
            default      => $this->stringify($value),
        };
    }

    /**
     * Typed value for spreadsheets: numbers stay numbers and dates become date cells.
     */
    public function toSpreadsheetValue(mixed $value): int|float|string|DateTimeInterface|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this->type) {
            self::NUMBER => is_numeric($value) ? $value + 0 : (string) $value,
            self::DATE   => $this->toDate($value) ?? (string) $value,
            default      => is_int($value) || is_float($value) ? $value : $this->stringify($value),
        };
    }

    /**
     * Excel number format code, or null for general.
     */
    public function spreadsheetFormat(): ?string
    {
        return match ($this->type) {
            self::NUMBER => ($this->prefix !== '' ? '"'.str_replace('"', '', $this->prefix).'"' : '')
                .($this->thousands ? '#,##0' : '0')
                .($this->decimals > 0 ? '.'.str_repeat('0', $this->decimals) : ''),
            self::DATE   => self::excelDateFormat($this->dateFormat),
            default      => null,
        };
    }

    /**
     * Converts the common PHP date() tokens to an Excel format code.
     */
    public static function excelDateFormat(string $phpFormat): string
    {
        $map = [
            'd' => 'dd', 'j' => 'd', 'D' => 'ddd', 'l' => 'dddd',
            'm' => 'mm', 'n' => 'm', 'M' => 'mmm', 'F' => 'mmmm',
            'Y' => 'yyyy', 'y' => 'yy',
            'H' => 'hh', 'G' => 'h', 'h' => 'hh', 'g' => 'h',
            'i' => 'mm', 's' => 'ss', 'A' => 'AM/PM', 'a' => 'AM/PM',
        ];

        $result = '';
        foreach (str_split($phpFormat) as $character) {
            $result .= $map[$character] ?? $character;
        }

        return $result;
    }

    private function toDate(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        try {
            return is_string($value) ? new DateTimeImmutable($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_bool($value)                      => $value ? 'Yes' : 'No',
            $value instanceof \BackedEnum        => (string) $value->value,
            $value instanceof \UnitEnum          => $value->name,
            $value instanceof DateTimeInterface  => $value->format($this->dateFormat),
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default                              => json_encode($value, JSON_UNESCAPED_UNICODE) ?: '',
        };
    }
}
