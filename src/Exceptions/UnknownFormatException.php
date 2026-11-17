<?php

namespace Wovosoft\LaravelUniexporter\Exceptions;

class UnknownFormatException extends UniexporterException
{
    /**
     * @param list<string> $available
     */
    public static function format(string $format, array $available): self
    {
        return new self(sprintf('No driver is registered for [%s]. Registered formats: %s.', $format, implode(', ', $available) ?: 'none'));
    }
}
