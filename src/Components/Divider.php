<?php

namespace Wovosoft\LaravelUniexporter\Components;

use Wovosoft\LaravelUniexporter\Style\Border;
use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * A horizontal rule. Its look comes from the style's border (default 0.5pt grey).
 */
class Divider extends Component
{
    public function defaultStyle(): Style
    {
        return Style::make()->border(new Border(0.5, '999999'))->spaceBefore(1)->spaceAfter(2);
    }
}
