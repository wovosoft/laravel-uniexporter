<?php

namespace Wovosoft\LaravelUniexporter\Components;

use Closure;

/**
 * Format-specific content written directly with the underlying library, in schema order.
 * Formats without a callback render nothing.
 *
 * Native::make()
 *     ->for('pdf', fn (\Mpdf\Mpdf $mpdf, RenderContext $context) => $mpdf->WriteHTML('<barcode code="123" />'))
 *     ->for('docx', fn (\PhpOffice\PhpWord\Element\AbstractContainer $container, RenderContext $context) => ...)
 */
class Native extends Component
{
    /**
     * @var array<string, Closure>
     */
    protected array $renderers = [];

    public function for(string $format, Closure $renderer): static
    {
        $this->renderers[$format] = $renderer;

        return $this;
    }

    public function getRenderer(string $format): ?Closure
    {
        return $this->renderers[$format] ?? null;
    }
}
