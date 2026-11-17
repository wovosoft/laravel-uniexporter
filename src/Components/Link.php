<?php

namespace Wovosoft\LaravelUniexporter\Components;

/**
 * A paragraph that is a single hyperlink.
 */
class Link extends Text
{
    public function __construct(string $text = '', protected string $url = '')
    {
        parent::__construct();

        $this->runs = [(new Run($text))->link($url)->color('0563C1')->underline()];
    }

    public function getUrl(): string
    {
        return $this->url;
    }
}
