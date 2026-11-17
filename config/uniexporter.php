<?php

use Wovosoft\LaravelUniexporter\Drivers\Mpdf\MpdfDriver;
use Wovosoft\LaravelUniexporter\Drivers\OpenSpout\OpenSpoutDriver;
use Wovosoft\LaravelUniexporter\Drivers\PhpWord\PhpWordDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Formats
    |--------------------------------------------------------------------------
    |
    | Export format => driver class. A driver implements
    | Wovosoft\LaravelUniexporter\Drivers\Driver; swap one (e.g. a PhpSpreadsheet
    | driver for 'xlsx') or add formats here or with Uniexporter::extend().
    |
    */

    'formats' => [
        'pdf'  => MpdfDriver::class,
        'docx' => PhpWordDriver::class,
        'xlsx' => OpenSpoutDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary directory
    |--------------------------------------------------------------------------
    |
    | Where exports and the libraries' temporary files are written. Defaults to
    | storage/app/uniexporter in Laravel, or the system temp directory.
    |
    */

    'temp_dir' => env('UNIEXPORTER_TEMP_DIR'),

    /*
    |--------------------------------------------------------------------------
    | Strict mode
    |--------------------------------------------------------------------------
    |
    | When a driver cannot render a component it skips it. In strict mode it
    | throws instead, which is useful in tests.
    |
    */

    'strict' => env('UNIEXPORTER_STRICT', false),

    /*
    |--------------------------------------------------------------------------
    | Driver options
    |--------------------------------------------------------------------------
    |
    | Per-format options handed to each driver. Settings::native() merges over
    | these for a single document.
    |
    */

    'drivers' => [
        'pdf' => [
            // Merged into the mPDF constructor config.
            'config'    => [],
            // Extra font directories and mPDF 'fontdata' entries (keys lowercase), e.g.
            // 'nikosh' => ['R' => 'Nikosh.ttf', 'useOTL' => 0xFF] for correct Bangla shaping.
            'font_dir'  => [],
            'font_data' => [],
        ],
        'docx' => [],
        'xlsx' => [
            // Named arguments for OpenSpout\Writer\XLSX\Options (e.g. 'SHOULD_USE_INLINE_STRINGS' => false).
            'options' => [],
        ],
    ],

];
