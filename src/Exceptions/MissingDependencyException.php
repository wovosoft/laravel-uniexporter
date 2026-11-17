<?php

namespace Wovosoft\LaravelUniexporter\Exceptions;

class MissingDependencyException extends UniexporterException
{
    public static function package(string $package, string $format): self
    {
        return new self("The [{$format}] driver needs [{$package}]. Install it with: composer require {$package}");
    }
}
