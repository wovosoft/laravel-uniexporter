<?php

namespace Wovosoft\LaravelUniexporter\Components;

use Closure;
use Wovosoft\LaravelUniexporter\Style\Style;

/**
 * A paragraph: either plain text or a list of styled {@see Run}s. Newlines start a new line.
 */
class Text extends Component
{
    /**
     * @var list<Run>
     */
    protected array $runs = [];

    public function __construct(string $text = '')
    {
        if ($text !== '') {
            $this->text($text);
        }
    }

    public function text(string $text): static
    {
        $this->runs = [new Run($text)];

        return $this;
    }

    /**
     * @param list<Run|string> $runs
     */
    public function runs(array $runs): static
    {
        $this->runs = array_map(fn (Run|string $run): Run => is_string($run) ? new Run($run) : $run, $runs);

        return $this;
    }

    /**
     * Appends a run; the closure styles it: ->run('Note: ', fn (Run $run) => $run->bold()).
     */
    public function run(string $text, ?Closure $tap = null): static
    {
        $run = new Run($text);

        if ($tap !== null) {
            $tap($run);
        }

        $this->runs[] = $run;

        return $this;
    }

    /**
     * @return list<Run>
     */
    public function getRuns(): array
    {
        return $this->runs;
    }

    public function defaultStyle(): Style
    {
        return Style::make()->spaceAfter(1.5);
    }
}
