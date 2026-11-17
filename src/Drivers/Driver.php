<?php

namespace Wovosoft\LaravelUniexporter\Drivers;

/**
 * Renders a document into a file. Drivers must only write to `$path`: never to stdout and
 * never send headers, so exports stay safe under Octane and in queues.
 */
interface Driver
{
    /**
     * File extension without the dot, e.g. 'pdf'.
     */
    public function extension(): string;

    public function mimeType(): string;

    public function render(RenderContext $context, string $path): void;
}
