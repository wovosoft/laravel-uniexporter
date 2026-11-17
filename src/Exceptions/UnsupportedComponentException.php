<?php

namespace Wovosoft\LaravelUniexporter\Exceptions;

use Wovosoft\LaravelUniexporter\Components\Component;

/**
 * Thrown in strict mode when a driver has no renderer for a component.
 */
class UnsupportedComponentException extends UniexporterException
{
    public static function for(Component $component, string $format): self
    {
        return new self(sprintf('The [%s] driver cannot render [%s].', $format, $component::class));
    }
}
