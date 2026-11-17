<?php

namespace Wovosoft\LaravelUniexporter\Components;

/**
 * Side-by-side blocks, e.g. two signature blocks. Rendered as a borderless table in paged
 * formats and as cells of one row in spreadsheets.
 */
class Columns extends Component
{
    /**
     * @var list<Group>
     */
    protected array $blocks = [];

    /**
     * @var list<float> relative widths
     */
    protected array $widths = [];

    /**
     * @param list<Group|Component|list<Component>> $blocks each block is a Group, one component or a list of components
     */
    public function __construct(array $blocks = [])
    {
        $this->blocks($blocks);
    }

    /**
     * @param list<Group|Component|list<Component>> $blocks
     */
    public function blocks(array $blocks): static
    {
        $this->blocks = array_map(fn (mixed $block): Group => match (true) {
            $block instanceof Group     => $block,
            $block instanceof Component => new Group([$block]),
            default                     => new Group($block),
        }, array_values($blocks));

        return $this;
    }

    /**
     * Relative widths per block, e.g. [2, 1].
     *
     * @param list<float> $widths
     */
    public function widths(array $widths): static
    {
        $this->widths = $widths;

        return $this;
    }

    /**
     * @return list<Group>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * Widths as percentages that add up to 100.
     *
     * @return list<float>
     */
    public function getWidthPercentages(): array
    {
        $count = count($this->blocks);
        $weights = array_map(fn (int $index): float => (float) ($this->widths[$index] ?? 1), range(0, max($count - 1, 0)));
        $total = array_sum($weights) ?: 1;

        return $count === 0 ? [] : array_map(fn (float $weight): float => $weight / $total * 100, $weights);
    }

    public function getChildren(): array
    {
        return $this->blocks;
    }
}
