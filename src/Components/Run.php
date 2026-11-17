<?php

namespace Wovosoft\LaravelUniexporter\Components;

/**
 * A styled piece of text inside a {@see Text} paragraph (e.g. one bold word mid-sentence).
 */
class Run extends Component
{
    protected ?string $url = null;

    public function __construct(protected string $text = '') {}

    public function text(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Makes the run a hyperlink. The URL is a template too.
     */
    public function link(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }
}
