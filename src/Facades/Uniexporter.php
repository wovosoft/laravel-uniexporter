<?php

namespace Wovosoft\LaravelUniexporter\Facades;

use Illuminate\Support\Facades\Facade;
use Wovosoft\LaravelUniexporter\UniexporterManager;

/**
 * @method static \Wovosoft\LaravelUniexporter\Document document()
 * @method static UniexporterManager extend(string $format, string|\Closure $driver)
 * @method static list<string> formats()
 * @method static bool hasFormat(string $format)
 * @method static \Wovosoft\LaravelUniexporter\Drivers\Driver driver(string $format, array $options = [])
 *
 * @see UniexporterManager
 */
class Uniexporter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return UniexporterManager::class;
    }
}
