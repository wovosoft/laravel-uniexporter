<?php

namespace Wovosoft\LaravelUniexporter\Style;

enum BorderLine: string
{
    case Solid = 'solid';
    case Dashed = 'dashed';
    case Dotted = 'dotted';
    case Double = 'double';
    case None = 'none';
}
